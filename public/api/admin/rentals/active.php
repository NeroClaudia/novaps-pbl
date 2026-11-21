<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../app/includes/bootstrap.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan method GET', 405);
}

$auth = require_login(['admin', 'owner']);

//Memastikan akun admin masih valid di database
$stmt = $pdo->prepare(
    "SELECT 1
     FROM users
     WHERE user_id = :user_id AND role = :role"
);

$stmt->execute([
    'user_id' => (int) $auth['user_id'],
    'role' => $auth['role']
]);

if (!$stmt->fetchColumn()) {
    json_error('FORBIDDEN', 'Akun admin tidak valid', 403);
}

try {
    expire_unpaid_bookings($pdo);
    mark_overdue($pdo);

    $stmt = $pdo->prepare(
        "SELECT
            r.rental_id,
            r.booking_id,
            r.device_id,
            r.rental_status,
            r.duration_hours,
            b.booking_code,
            u.full_name,
            u.phone,
            u.email,
            d.device_name,
            d.category,
            d.booth_code,
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

            r.total_cost

         FROM rental r
         JOIN booking b
           ON b.booking_id = r.booking_id
         JOIN payment p
           ON p.booking_id = b.booking_id
         JOIN users u
           ON u.user_id = b.customer_id
         JOIN device d
           ON d.device_id = r.device_id

         WHERE r.rental_status IN ('Rented', 'Overdue')
           AND b.fulfillment_status = 'Complete'
           AND p.payment_status = 'Paid'

         ORDER BY r.end_time ASC, r.rental_id ASC"
    );

    $rentals = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rentals[] = [
            'rental_id' => (int) $row['rental_id'],
            'booking_id' => (int) $row['booking_id'],
            'device_id' => (int) $row['device_id'],
            'booking_code' => $row['booking_code'],
            'full_name' => $row['full_name'],
            'phone' => $row['phone'],
            'email' => $row['email'],
            'device_name' => $row['device_name'],
            'category' => $row['category'],
            'booth_code' => $row['booth_code'],
            'rental_status' => $row['rental_status'],
            'duration_days' => intdiv(
                (int) $row['duration_hours'], 24
            ),
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'rented_at' => $row['rented_at'],
            'total_cost' => (float) $row['total_cost']
        ];
    }

    json_response([
        'success' => true,
        'data' => $rentals,
        'meta' => [
            'total' => count($rentals),
            'server_now' => (new DateTimeImmutable(
                'now', new DateTimeZone('Asia/Jakarta')
            ))->format(DATE_ATOM)
        ]
    ]);

} catch (Throwable $e) {
    error_log('Admin Activate Rentals Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Gagal mengambil rental aktif', 500);
}