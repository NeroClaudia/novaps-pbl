<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/includes/bootstrap.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(
        'METHOD_NOT_ALLOWED',
        'Gunakan metode POST',
        405
    );
}

$input = read_json_body();

$rawCode = $input['booking_code'] ?? null;
$rawPhone = $input['phone'] ?? null;

if (!is_string($rawCode) || !is_string($rawPhone)) {
    json_error(
        'VALIDATION_ERROR',
        'Kode booking dan nomor HP wajib berupa teks',
        422
    );
}

$bookingCode = strtoupper(trim($rawCode));
$phone = normalize_phone($rawPhone);

if (
    !preg_match('/^[A-Z0-9]{8}$/', $bookingCode) ||
    !preg_match('/^08\d{8,13}$/', $phone)
) {
    json_error(
        'VALIDATION_ERROR',
        'Kode booking atau format nomor HP tidak valid',
        422
    );
}

try {
    expire_unpaid_bookings($pdo);
    mark_overdue($pdo);

    $stmt = $pdo->prepare(
        "SELECT
            b.booking_code,
            b.fulfillment_status,
            to_char(
                b.shipped_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS shipped_at,
            to_char(
                b.completed_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS completed_at,
            b.cancel_reason,
            to_char(
                b.cancelled_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS cancelled_at,
            to_char(
                b.created_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS created_at,

            p.payment_method,
            p.payment_status,
            to_char(
                p.payment_expires_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS payment_expires_at,

            CASE
                WHEN p.payment_expires_at IS NULL THEN NULL
                WHEN p.payment_status = 'Pending' THEN
                    GREATEST(
                        0,
                        CEIL(EXTRACT(EPOCH FROM (
                            p.payment_expires_at -
                            statement_timestamp()
                        )))
                    )::integer
                ELSE 0
            END AS seconds_left,

            (p.payment_proof_url IS NOT NULL)
                AS payment_proof_uploaded,

            (p.payment_verified_at IS NOT NULL)
                AS payment_verified,

            r.device_id,
            d.device_name,
            d.category,
            r.duration_hours,
            r.price_per_day,
            r.total_cost,
            r.rental_status,

            to_char(
                r.start_time,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS start_time,

            to_char(
                r.end_time,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS end_time,

            to_char(
                r.rented_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS rented_at,

            to_char(
                r.overdue_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS overdue_at,

            to_char(
                r.returned_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS returned_at

         FROM booking b

         JOIN users u
            ON u.user_id = b.customer_id

         JOIN payment p
            ON p.booking_id = b.booking_id

         JOIN v_rental r
            ON r.booking_id = b.booking_id

         JOIN device d
            ON d.device_id = r.device_id

         WHERE b.booking_code = :booking_code
           AND u.phone = :phone

         ORDER BY r.device_id ASC"
    );

    $stmt->execute([
        'booking_code' => $bookingCode,
        'phone' => $phone
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        json_error(
            'NOT_FOUND',
            'Pesanan tidak ditemukan. Periksa kode booking dan nomor HP.',
            404
        );
    }

    $first = $rows[0];

    $items = [];
    $grandTotal = 0.0;

    foreach ($rows as $row) {
        $durationHours = (int) $row['duration_hours'];
        $itemCost = (float) $row['total_cost'];

        $grandTotal += $itemCost;

        $timeline = build_timeline($row);

        if (
            $row['rental_status'] === 'Cancelled' &&
            ($first['cancelled_at'] !== null ||
             $first['payment_status'] === 'Expired')
        ) {
            $timeline[] = [
                'status' => 'Cancelled',
                'at' => $first['cancelled_at'] ??
                    $first['payment_expires_at']
            ];
        }

        $items[] = [
            'device_id' => (int) $row['device_id'],
            'device_name' => $row['device_name'],
            'category' => $row['category'],

            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],

            'duration_days' => intdiv($durationHours, 24),
            'duration_hours' => $durationHours,

            'price_per_day' => (float) $row['price_per_day'],
            'total_cost' => $itemCost,

            'rental_status' => $row['rental_status'],
            'timeline' => $timeline
        ];
    }

    $isTruthy = static fn(mixed $value): bool =>
        in_array(
            $value,
            [true, 1, '1', 't', 'true'],
            true
        );

    json_response([
        'success' => true,
        'data' => [
            'booking_code' => (string) $first['booking_code'],
            'created_at' => $first['created_at'],
            'fulfillment_status' => $first['fulfillment_status'],
            'shipped_at' => $first['shipped_at'],
            'completed_at' => $first['completed_at'],

            'cancel_reason' => $first['cancel_reason'],
            'cancelled_at' => $first['cancelled_at'],

            'payment_method' => $first['payment_method'],

            'payment_proof_uploaded' => $isTruthy(
                $first['payment_proof_uploaded']
            ),

            'payment_verified' => $isTruthy(
                $first['payment_verified']
            ),

            'payment' => [
                'status' => $first['payment_status'],
                'expires_at' => $first['payment_expires_at'],
                'seconds_left' =>
                    $first['seconds_left'] !== null
                        ? (int) $first['seconds_left']
                        : null
            ],

            'total_cost' => $grandTotal,
            'items' => $items
        ]
    ]);

} catch (Throwable $e) {
    error_log(
        'Booking Track API Error: ' . $e->getMessage()
    );

    json_error(
        'SERVER_ERROR',
        'Status pesanan gagal diambil',
        500
    );
}
