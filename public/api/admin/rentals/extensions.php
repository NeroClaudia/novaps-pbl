<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../app/includes/bootstrap.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan method GET', 405);
}

$auth = require_login(['admin', 'owner']);

try {
    //memastikan akun admin masih aktif di database
    $check = $pdo->prepare(
        "SELECT 1
         FROM users
         WHERE user_id = :id
            AND role = :role"
    );

    $check->execute([
        'id' => (int) $auth['user_id'],
        'role' => $auth['role']
    ]);

    if (!$check->fetchColumn()) {
        json_error('FORBIDDEN', 'Akses admin ditolak', 403);
    }

    $stmt = $pdo->prepare(
        "SELECT
            e.extension_id,
            e.rental_id,
            e.extra_days,
            e.status,
            e.created_at,
            r.duration_hours,
            r.price_per_day,
            r.end_time,
            r.rental_status,
            b.booking_code,
            u.full_name,
            u.phone,
            d.device_name,
            d.category
         FROM rental_extension e
         JOIN rental r
           ON r.rental_id = e.rental_id
         JOIN booking b
           ON b.booking_id = r.booking_id
         JOIN users u
           ON u.user_id = b.customer_id
         JOIN device d
           ON d.device_id = r.device_id
         WHERE e.status = 'AwaitingVerification'
           AND e.payment_proof_url IS NOT NULL
         ORDER BY e.created_at ASC
         LIMIT 100"
    );

    $stmt->execute();

    $data = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $extraDays = (int) $row['extra_days'];
        $currentDays = intdiv((int) $row['duration_hours'], 24);

        $end = new DateTimeImmutable($row['end_time']);

        $data[] = [
            'extension_id' => (int) $row['extension_id'],
            'rental_id' => (int) $row['rental_id'],
            'booking_code' => $row['booking_code'],
            'customer_name' => $row['full_name'],
            'phone' => $row['phone'],
            'device_name' => $row['device_name'],
            'category' => $row['category'],
            'rental_status' => $row['rental_status'],
            'extension_status' => $row['extension_status'],
            'current_days' => $currentDays,
            'extra_days' => $extraDays,
            'total_days' => $currentDays + $extraDays,
            'additional_cost' => (float) $row['price_per_day'] * $extraDays,
            'current_end_time' => $end->format(DATE_ATOM),
            'proposed_end_time' => $end->modify("+{$extraDays} days")->format(DATE_ATOM),
            'created_at' => (new DateTimeImmutable($row['created_at']))->format(DATE_ATOM),
            'payment_proof_uploaded' => true
        ];
    }

    json_response([
        'success' => true,
        'data' => $data,
        'meta' => [
            'returned' => count($data),
            'limit' => 100
        ]
    ]);

} catch (Throwable $e) {
    error_log('Admin Extension List Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Gagal mengambil pengajuan Extend', 500);
}