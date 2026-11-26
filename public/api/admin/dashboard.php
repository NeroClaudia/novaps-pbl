<?php

require __DIR__ . '/../../../app/includes/bootstrap.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan method GET', 405);
}

require_login(['admin', 'owner']);

try {
    expire_unpaid_bookings($pdo);
    mark_overdue($pdo);

    // Jumlah item rental, bukan jumlah booking.
    $counts = [
        'Booked' => 0,
        'Rented' => 0,
        'Overdue' => 0,
        'Returned' => 0,
        'Cancelled' => 0,
    ];

    $statusQuery = $pdo->query(
        'SELECT rental_status, COUNT(*) AS total
         FROM rental
         GROUP BY rental_status'
    );

    foreach ($statusQuery->fetchAll() as $row) {
        $counts[$row['rental_status']] = (int) $row['total'];
    }

    $fulfillmentCounts = [
        'Pending' => 0,
        'Shipped' => 0,
        'Complete' => 0,
        'Cancelled' => 0,
        'Rejected' => 0
    ];

    $fulfillmentQuery = $pdo->query(
        "SELECT
        b.fulfillment_status,
        p.payment_status,
        COUNT(*) AS total
     FROM booking b
     JOIN payment p
       ON p.booking_id = b.booking_id
     GROUP BY b.fulfillment_status, p.payment_status"
    );

    foreach ($fulfillmentQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $total = (int) $row['total'];

        if ($row['payment_status'] === 'Rejected') {
            $fulfillmentCounts['Rejected'] += $total;
        } else {
            $status = $row['fulfillment_status'];

            if (array_key_exists($status, $fulfillmentCounts)) {
                $fulfillmentCounts[$status] += $total;
            }
        }
    }
    // Total tiap item dijumlahkan satu kali.
    // Zona waktu mengikuti koneksi database: Asia/Jakarta.

    $revenueQuery = $pdo->query(
        "SELECT
            COALESCE(
                SUM(r.total_cost) FILTER (
                    WHERE p.paid_at >=
                        date_trunc('day', statement_timestamp())
                    AND p.paid_at <
                        date_trunc('day', statement_timestamp())
                        + INTERVAL '1 day'
                ),
                0
            ) AS revenue_today,

            COALESCE(
                SUM(r.total_cost) FILTER (
                    WHERE p.paid_at >=
                        date_trunc('month', statement_timestamp())
                    AND p.paid_at <
                        date_trunc('month', statement_timestamp())
                        + INTERVAL '1 month'
                ),
                0
            ) AS revenue_this_month

        FROM payment p
        JOIN v_rental r ON r.booking_id = p.booking_id
        WHERE p.payment_status = 'Paid'"
    );

    $revenue = $revenueQuery->fetch();

    $pendingQuery = $pdo->query(
        "SELECT COUNT(*)
         FROM payment p
            JOIN booking b ON b.booking_id = p.booking_id
         WHERE b.fulfillment_status = 'Pending'
            AND p.payment_verified_at IS NULL
            AND (
                p.payment_status = 'AwaitingVerification'
                OR (
                    p.payment_method = 'Cash'
            AND p.payment_status = 'Pending'
           )
       )"
    );

    $pendingVerifications = (int) $pendingQuery->fetchColumn();

    $identityQuery = $pdo->query(
        "SELECT COUNT(*)
         FROM users
         WHERE role = 'customer'
           AND identity_doc_url IS NOT NULL
           AND identity_doc_verified = FALSE"
    );
    $unverifiedIdentityDocs = (int) $identityQuery->fetchColumn();

    $overdueQuery = $pdo->query(
        "SELECT
            r.rental_id,
            b.booking_code,
            u.full_name,
            u.phone,
            d.device_name,
            to_char(
                r.end_time,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS end_time,
            GREATEST(
                0,
                EXTRACT(EPOCH FROM (
                    statement_timestamp() - r.end_time
                )) / 3600
            ) AS hours_overdue
         FROM v_rental r
         JOIN booking b ON b.booking_id = r.booking_id
         JOIN users u ON u.user_id = b.customer_id
         JOIN device d ON d.device_id = r.device_id
         WHERE r.rental_status = 'Overdue'
         ORDER BY r.end_time ASC, r.rental_id ASC
         LIMIT 20"
    );

    $overdueRentals = [];

    foreach ($overdueQuery->fetchAll() as $row) {
        $overdueRentals[] = [
            'rental_id' => (int) $row['rental_id'],
            'booking_code' => $row['booking_code'],
            'full_name' => $row['full_name'],
            'phone' => $row['phone'],
            'device_name' => $row['device_name'],
            'end_time' => $row['end_time'],
            'hours_overdue' => round((float) $row['hours_overdue'], 2),
        ];
    }

    json_response([
        'success' => true,
        'data' => [
            'counts' => $counts,
            'fulfillment_counts' => $fulfillmentCounts,
            'revenue_today' => (float) $revenue['revenue_today'],
            'revenue_this_month' => (float) $revenue['revenue_this_month'],
            'pending_payment_verifications' => $pendingVerifications,
            'unverified_identity_docs' => $unverifiedIdentityDocs,
            'overdue_rentals' => $overdueRentals,
        ],
    ]);
} catch (Throwable $e) {
    error_log('Admin Dashboard API Error: ' . $e->getMessage());

    json_error(
        'SERVER_ERROR',
        'Ringkasan dashboard gagal diambil',
        500
    );
}