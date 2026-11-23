<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../../../app/includes/storage.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan method GET', 405);
}

require_login(['admin', 'owner']);

$bookingId = filter_var($_GET['booking_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($bookingId === false || $bookingId === null) {
    json_error('VALIDATION_ERROR', 'booking_id tidak valid', 422);
}

try {
    $stmt = $pdo->prepare(
        "SELECT
            b.customer_id,
            u.identity_doc_url
        FROM booking b
            JOIN users u ON u.user_id = b.customer_id
        WHERE b.booking_id = :booking_id AND u.role = 'customer'"
    );
    $stmt->execute(['booking_id' => $bookingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        json_error('NOT_FOUND', 'Booking tidak ditemukan', 404);
    }

    $path = (string) ($row['identity_doc_url'] ?? '');

    if ($path === '') {
        json_error('NOT_FOUND', 'Dokumen identitas belum tersedia', 404);
    }

    //Pastikan path mengarah ke identitas milik customer
    $expectedPath = '~^customers/' . (int) $row['customer_id'] . '/[a-f0-9]{32}\.(jpg|png)$~D';

    if (!preg_match($expectedPath, $path)) {
        json_error('NOT_FOUND', 'Path dokumen identitas tidak valid', 404);
    }

    $file = storage_download(storage_identity_bucket(), $path);

} catch (Throwable $e) {
    error_log('Admin Identity document error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Gagal mengambil dokumen identitas', 500);
}

session_write_close();

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $file['mime']);
header('Content-Length: ' . strlen($file['bytes']));
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Security-Policy: default-src \'none\'; sandbox');
header('Referrer-Policy: no-referrer');

echo $file['bytes'];
exit;