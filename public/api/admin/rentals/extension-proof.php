<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../../../app/includes/storage.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan GET', 405);
}

$auth = require_login(['admin', 'owner']);

$id = filter_var($_GET['extension_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($id === false || $id === null) {
    json_error('VALIDATION_ERROR', 'ID tidak valid', 422);
}

try {
    $admin = $pdo->prepare(
        "SELECT 1 FROM users
         WHERE user_id = :id AND role = :role"
    );

    $admin->execute([
        'id' => (int) $auth['user_id'],
        'role' => $auth['role']
    ]);

    if (!$admin->fetchColumn()) {
        json_error('FORBIDDEN', 'Akses ditolak', 403);
    }

    $stmt = $pdo->prepare(
        "SELECT payment_proof_url
         FROM rental_extension
         WHERE extension_id = :id"
    );

    $stmt->execute(['id' => $id]);

    $path = $stmt->fetchColumn();

    if (!is_string($path) || $path === '') {
        json_error('NOT_FOUND', 'Bukti tidak ditemukan', 404);
    }

    $pattern = '~^extensions/' . $id .
        '/[a-f0-9]{32}\.(jpg|png)$~D';

    if (!preg_match($pattern, $path)) {
        json_error('NOT_FOUND', 'Path bukti tidak valid', 404);
    }

    $image = storage_download(
        storage_payment_bucket(),
        $path
    );

    $bytes = $image['bytes'];
    $mime = $image['mime'];

} catch (Throwable $e) {
    error_log('Extension Proof Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Gagal membaca bukti', 500);
}

session_write_close();

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
