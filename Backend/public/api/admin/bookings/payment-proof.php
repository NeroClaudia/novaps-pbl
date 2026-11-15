
<?php

require __DIR__ . '/../../../../includes/bootstrap.php';

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

    // File upload menggunakan nama acak 32 karakter.
    if (!preg_match('~^payment_proof/[a-f0-9]{32}\.(jpg|png)$~D',$relativePath)) {
        json_error('NOT_FOUND', 'Bukti pembayaran tidak tersedia', 404);
    }

    $directory = realpath(UPLOAD_DIR . '/payment_proof');

    $filePath = realpath(UPLOAD_DIR . '/' . $relativePath);

    if ($directory === false || $filePath === false || !is_file($filePath)) {
        json_error('NOT_FOUND', 'File bukti pembayaran tidak ditemukan', 404);
    }

    // Cegah akses file di luar folder payment_proof.
    $insideDirectory = PHP_OS_FAMILY === 'Windows' ? strcasecmp(dirname($filePath), $directory) === 0 : dirname($filePath) === $directory;

    if (!$insideDirectory) {
        json_error('NOT_FOUND', 'File bukti pembayaran tidak ditemukan', 404);
    }

    $maxBytes = 2 * 1024 * 1024;

    $contents = file_get_contents(
        $filePath,
        false,
        null,
        0,
        $maxBytes + 1
    );

    if ($contents === false || $contents === '' || strlen($contents) > $maxBytes) {
        throw new RuntimeException('File bukti pembayaran tidak valid');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png'
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Format gambar tidak didukung');
    }

    $extension = $extensions[$mime];

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
