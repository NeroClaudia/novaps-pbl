<?php

require __DIR__ . '/../../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../../app/functions/devices.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan method GET', 405);
}

try {
    $devices = get_devices($pdo);

    json_response([
        'success' => true,
        'data' => $devices,
    ]);
} catch (PDOException $e) {
    error_log('Device List API Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Terjadi kesalahan internal pada server', 500);
}