<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../../app/functions/bookings.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan method GET', 405);
}

$auth = require_login(['customer']);

$customerId = (int) ($auth['user_id'] ?? 0);

if ($customerId < 1) {
    json_error('UNAUTHORIZED', 'Sesi pelanggan tidak valid', 401);
}

try {
    expire_unpaid_bookings($pdo);

    $booking = get_unpaid_booking($pdo, $customerId);

    json_response([
        'success' => true,
        'data' => $booking
    ]);

} catch (Throwable $e) {
    error_log('Unpaid Booking API Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Gagal mengambil informasi pembayaran', 500);
}