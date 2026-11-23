<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../app/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan GET', 405);
}

require_login(['admin', 'owner']);
header('Cache-Control: private, no-store');

try {
    $stmt = $pdo->query(
        "SELECT
            r.rental_id,
            b.booking_code,
            u.full_name,
            d.device_name,
            r.end_time,
            r.returned_at
         FROM rental r
         JOIN booking b ON b.booking_id = r.booking_id
         JOIN users u ON u.user_id = b.customer_id
         JOIN device d ON d.device_id = r.device_id
         WHERE r.rental_status = 'Returned'
           AND r.returned_at IS NOT NULL
           AND r.returned_at > r.end_time
           AND r.late_fee_paid_at IS NULL
         ORDER BY r.returned_at DESC"
    );

    $result = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lateFee = calculate_late_fee(
            $row['end_time'],
            $row['returned_at']
        );

        $result[] = [
            'rental_id' => (int) $row['rental_id'],
            'booking_code' => $row['booking_code'],
            'full_name' => $row['full_name'],
            'device_name' => $row['device_name'],
            'late_fee' => $lateFee,
            'late_fee_status' => 'Unpaid'
        ];
    }

    json_response([
        'success' => true,
        'data' => $result
    ]);

} catch (Throwable $e) {
    error_log('Unpaid Late Fees Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Gagal mengambil daftar denda', 500);
}
