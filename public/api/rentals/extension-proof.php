<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../../app/includes/storage.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan method GET', 405);
}

$auth = require_login(['customer']);
$customerId = (int) ($auth['user_id'] ?? 0);

if ($customerId < 1) {
    json_error('UNAUTHORIZED', 'Sesi tidak valid', 401);
}

$rawId = $_GET['extension_id'] ?? null;

$id = is_string($rawId)
    ? filter_var(
        $rawId,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    )
    : false;

if ($id === false) {
    json_error('VALIDATION_ERROR', 'ID tidak valid', 422);
}

try {
    // Pastikan pengajuan milik customer yang sedang login.
    $stmt = $pdo->prepare(
        "SELECT e.payment_proof_url
         FROM rental_extension e
         JOIN rental r
           ON r.rental_id = e.rental_id
         JOIN booking b
           ON b.booking_id = r.booking_id
         WHERE e.extension_id = :extension_id
           AND b.customer_id = :customer_id"
    );

    $stmt->execute([
        'extension_id' => $id,
        'customer_id' => $customerId
    ]);

    $path = $stmt->fetchColumn();

    if (!is_string($path) || $path === '') {
        json_error(
            'NOT_FOUND',
            'Bukti pembayaran tidak ditemukan',
            404
        );
    }

    // Cocokkan lokasi dengan format yang digunakan
    // oleh extension-payment-proof.php.
    $pattern = '~^extensions/' . $id .
        '/[a-f0-9]{32}\.(jpg|png)$~D';

    if (!preg_match($pattern, $path)) {
        json_error('NOT_FOUND', 'Lokasi bukti pembayaran tidak valid', 404);
    }

    $image = storage_download(
        storage_payment_bucket(),
        $path
    );

    $bytes = $image['bytes'];
    $mime = $image['mime'];

} catch (Throwable $e) {
    error_log(
        'Customer Extension Proof Error: ' .
        $e->getMessage()
    );
    json_error('SERVER_ERROR', 'Gagal membaca bukti pembayaran', 500);
}

// Lepaskan session agar request lain tidak tertahan.
session_write_close();

// Pastikan response hanya berisi bytes gambar.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($bytes));
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

echo $bytes;
exit;