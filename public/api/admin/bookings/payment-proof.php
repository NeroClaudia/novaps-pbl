<?php

require __DIR__ . '/../../../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../../../app/includes/storage.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan metode GET', 405);
}

require_login(['admin', 'owner']);

$rawBookingId = $_GET['booking_id'] ?? null;

$bookingId = is_string($rawBookingId) ? filter_var($rawBookingId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

if ($bookingId === false) {
    json_error('VALIDATION_ERROR', 'booking_id harus berupa bilangan bulat minimal 1', 422);
}

try {
    // Ambil bukti pembayaran dari tabel payment.
    $stmt = $pdo->prepare(
        "SELECT b.booking_id, p.payment_proof_url
         FROM booking b
         LEFT JOIN payment p
             ON p.booking_id = b.booking_id
         WHERE b.booking_id = :booking_id"
    );

    $stmt->execute(['booking_id' => $bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        json_error('NOT_FOUND', 'Booking tidak ditemukan', 404);
    }

    $relativePath = $booking['payment_proof_url'];


    if (empty($relativePath)) {
        json_error('NOT_FOUND', 'Bukti pembayaran belum tersedia', 404);
    }

    $expectedPattern =
        '~^bookings/' . $bookingId .
        '/[a-f0-9]{32}\.(jpg|png)$~D';

    if (!preg_match($expectedPattern, $relativePath)) {
        json_error('NOT_FOUND', 'Lokasi bukti pembayaran tidak valid', 404);
    }

    $image = storage_download(
        storage_payment_bucket(),
        $relativePath
    );

    $contents = $image['bytes'];
    $mime = $image['mime'];

    $extension = $mime === 'image/png'
        ? 'png'
        : 'jpg';

} catch (Throwable $e) {
    error_log('Admin Payment Proof API Error: ' . $e->getMessage());

    json_error('SERVER_ERROR', 'Gagal membaca bukti pembayaran', 500
    );
}

// Lepaskan session sebelum mengirim gambar.
session_write_close();

// Bersihkan output buffer untuk respons biner.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($contents));
header('Content-Disposition: inline; filename="payment-proof-' .$bookingId . '.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

echo $contents;
exit;
