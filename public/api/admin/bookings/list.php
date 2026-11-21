<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../app/includes/bootstrap.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan metode GET', 405);
}

require_login(['admin', 'owner']);

$where = [];
$params = [];

// Filter rental status.
if (isset($_GET['status'])) {
    $status = $_GET['status'];

    $allowedStatuses = [
        'Booked',
        'Rented',
        'Overdue',
        'Returned',
        'Cancelled'
    ];

    if (!is_string($status) || !in_array($status, $allowedStatuses, true)) {
        json_error('VALIDATION_ERROR', 'Parameter status tidak valid', 422);
    }

    $where[] = "EXISTS (
        SELECT 1
        FROM rental r_sub
        WHERE r_sub.booking_id = b.booking_id
          AND r_sub.rental_status = :status
    )";

    $params['status'] = $status;
}

// Filter payment status.
if (isset($_GET['payment_status'])) {
    $paymentStatus = $_GET['payment_status'];

    $allowedPaymentStatuses = [
        'Pending',
        'AwaitingVerification',
        'Paid',
        'Expired',
        'Rejected',
        'Cancelled'
    ];

    if (!is_string($paymentStatus) || !in_array($paymentStatus, $allowedPaymentStatuses, true)) {
        json_error('VALIDATION_ERROR', 'Parameter payment_status tidak valid', 422);
    }

    $where[] = 'p.payment_status = :payment_status';
    $params['payment_status'] = $paymentStatus;
}

//Khusus filter cancelled, kecuali booking yang ditolak admin
if (isset($_GET['exclude_rejected'])) {
    if ($_GET['exclude_rejected'] !== true) {
        json_error('VALIDATION_ERROR', 'exclude_rejected harus bernilai true', 422);
    }

    $where[] = "p.payment_status <> 'Rejected'";
}

// Filter fulfillment status.
if (isset($_GET['fulfillment_status'])) {
    $fulfillmentStatus = $_GET['fulfillment_status'];

    $allowedFulfillmentStatuses = [
        'Pending',
        'Shipped',
        'Complete',
        'Cancelled'
    ];

    if (!is_string($fulfillmentStatus) || !in_array($fulfillmentStatus, $allowedFulfillmentStatuses, true)) {
        json_error('VALIDATION_ERROR', 'Parameter fulfillment_status tidak valid', 422);
    }

    $where[] = 'b.fulfillment_status = :fulfillment_status';

    $params['fulfillment_status'] = $fulfillmentStatus;
}

// Filter payment verification.
if (isset($_GET['payment_verified'])) {
    $paymentVerified = $_GET['payment_verified'];

    if ($paymentVerified !== 'true' && $paymentVerified !== 'false') {
        json_error('VALIDATION_ERROR', 'Parameter payment_verified harus true atau false', 422);
    }

    $where[] = $paymentVerified === 'true'
        ? 'p.payment_verified_at IS NOT NULL'
        : 'p.payment_verified_at IS NULL';
}

// Search booking code, customer name, atau phone.
if (isset($_GET['search'])) {
    if (!is_string($_GET['search'])) {
        json_error('VALIDATION_ERROR', 'Parameter search harus berupa teks', 422);
    }

    $search = trim($_GET['search']);

    if (mb_strlen($search) > 50) {
        json_error('VALIDATION_ERROR', 'Parameter search maksimal 50 karakter', 422);
    }

    if ($search !== '') {
        $escaped = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $search
        );

        $where[] = "(
            b.booking_code LIKE :search_code
            OR u.full_name ILIKE :search_name
            OR u.phone LIKE :search_phone
        )";

        $params['search_code'] =
            '%' . strtoupper($escaped) . '%';

        $params['search_name'] =
            '%' . $escaped . '%';

        $params['search_phone'] =
            '%' . $escaped . '%';
    }
}

// Pagination.
$page = 1;

if (isset($_GET['page'])) {
    $page = filter_var(
        $_GET['page'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($page === false) {
        json_error('VALIDATION_ERROR', 'Parameter page harus bilangan bulat minimal 1', 422);
    }
}

$perPage = 20;

if (isset($_GET['per_page'])) {
    $perPage = filter_var(
        $_GET['per_page'],
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
                'max_range' => 100
            ]
        ]
    );

    if ($perPage === false) {
        json_error('VALIDATION_ERROR', 'Parameter per_page harus antara 1 sampai 100', 422);
    }
}

$offset = ($page - 1) * $perPage;

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

try {
    expire_unpaid_bookings($pdo);
    mark_overdue($pdo);

    // Hitung jumlah booking sesuai filter.
    $countSql = "SELECT COUNT(DISTINCT b.booking_id)
                 FROM booking b
                 JOIN users u
                    ON u.user_id = b.customer_id
                 JOIN payment p
                    ON p.booking_id = b.booking_id
                 JOIN rental r
                    ON r.booking_id = b.booking_id
                 $whereSql";

    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);

    $total = (int) $stmtCount->fetchColumn();

    // Ambil daftar booking beserta ringkasan rental.
    $listSql = "SELECT
                    b.booking_id,
                    b.booking_code,
                    u.full_name,
                    u.phone,
                    b.booking_channel,
                    b.fulfillment_status,

                    to_char(
                        b.created_at,
                        'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
                    ) AS created_at,

                    to_char(
                        b.shipped_at,
                        'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
                    ) AS shipped_at,

                    to_char(
                        b.completed_at,
                        'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
                    ) AS completed_at,

                    p.payment_method,
                    p.payment_status,

                    to_char(
                        p.payment_expires_at,
                        'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
                    ) AS payment_expires_at,

                    CASE
                        WHEN p.payment_expires_at IS NULL
                            THEN NULL
                        WHEN p.payment_status = 'Pending'
                            THEN GREATEST(
                                0,
                                CEIL(EXTRACT(EPOCH FROM (
                                    p.payment_expires_at -
                                    statement_timestamp()
                                )))
                            )::INTEGER
                        ELSE 0
                    END AS seconds_left,

                    (p.payment_proof_url IS NOT NULL)
                        AS payment_proof_uploaded,

                    (p.payment_verified_at IS NOT NULL)
                        AS payment_verified,

                    COUNT(*) AS items_count,
                    SUM(r.total_cost) AS total_cost,
                    (
                        SELECT COALESCE(
                            json_agg(
                                json_build_object(
                                    'device_id', ri.device_id,
                                    'device_name', di.device_name,
                                    'category', di.category,
                                    'duration_days', ri.duration_hours / 24,
                                    'rental_status', ri.rental_status
                                )
                                ORDER BY ri.rental_id
                            ),
                            '[]'::json
                        )
                        FROM rental ri
                        JOIN device di
                            ON di.device_id = ri.device_id
                        WHERE ri.booking_id = b.booking_id
                    ) AS items_summary,

                    COUNT(*) FILTER (
                        WHERE r.rental_status = 'Booked'
                    ) AS count_booked,

                    COUNT(*) FILTER (
                        WHERE r.rental_status = 'Rented'
                    ) AS count_rented,

                    COUNT(*) FILTER (
                        WHERE r.rental_status = 'Overdue'
                    ) AS count_overdue,

                    COUNT(*) FILTER (
                        WHERE r.rental_status = 'Returned'
                    ) AS count_returned,

                    COUNT(*) FILTER (
                        WHERE r.rental_status = 'Cancelled'
                    ) AS count_cancelled

                FROM booking b

                JOIN users u
                    ON u.user_id = b.customer_id

                JOIN payment p
                    ON p.booking_id = b.booking_id

                JOIN rental r
                    ON r.booking_id = b.booking_id

                $whereSql

                GROUP BY
                    b.booking_id,
                    u.user_id,
                    p.payment_id

                ORDER BY
                    b.created_at DESC,
                    b.booking_id DESC

                LIMIT :limit OFFSET :offset";

    $stmtList = $pdo->prepare($listSql);

    foreach ($params as $key => $value) {
        $stmtList->bindValue(':' . $key, $value);
    }

    $stmtList->bindValue(
        ':limit',
        $perPage,
        PDO::PARAM_INT
    );

    $stmtList->bindValue(
        ':offset',
        $offset,
        PDO::PARAM_INT
    );

    $stmtList->execute();

    $rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    $isTruthy = static fn(mixed $value): bool =>
        in_array(
            $value,
            [true, 1, '1', 't', 'true'],
            true
        );

    $data = [];

    foreach ($rows as $row) {

        $itemsSummary = json_decode((string) ($row['items_summary'] ?? '[]'),true);
        if (!is_array($itemsSummary)) {
            throw new RuntimeException('Format ringkasan perangkat tidak valid');
        }

        $data[] = [
            'booking_id' => (int) $row['booking_id'],
            'booking_code' => $row['booking_code'],
            'full_name' => $row['full_name'],
            'phone' => $row['phone'],
            'booking_channel' => $row['booking_channel'],

            'fulfillment_status' => $row['fulfillment_status'],
            'created_at' => $row['created_at'],
            'shipped_at' => $row['shipped_at'],
            'completed_at' => $row['completed_at'],

            'payment_method' => $row['payment_method'],
            'payment_status' => $row['payment_status'],
            'payment_expires_at' => $row['payment_expires_at'],

            'seconds_left' => $row['seconds_left'] !== null
                ? (int) $row['seconds_left']
                : null,

            'payment_proof_uploaded' => $isTruthy(
                $row['payment_proof_uploaded']
            ),

            'payment_verified' => $isTruthy(
                $row['payment_verified']
            ),

            'items_count' => (int) $row['items_count'],
            'items_summary' => $itemsSummary,
            'total_cost' => (float) $row['total_cost'],

            'status_count' => [
                'Booked' => (int) $row['count_booked'],
                'Rented' => (int) $row['count_rented'],
                'Overdue' => (int) $row['count_overdue'],
                'Returned' => (int) $row['count_returned'],
                'Cancelled' => (int) $row['count_cancelled']
            ]
        ];
    }

    json_response([
        'success' => true,
        'data' => $data,
        'meta' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total
        ]
    ]);

} catch (Throwable $e) {
    error_log('Admin Booking List API Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Gagal mengambil daftar booking',500);
}
