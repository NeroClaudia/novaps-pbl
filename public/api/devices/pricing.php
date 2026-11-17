<?php

require __DIR__ . '/../../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../../app/functions/devices.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('METHOD_NOT_ALLOWED', 'Method HTTP tidak diizinkan. Gunakan method GET', 405);
}

$deviceId = filter_var(
    $_GET['device_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($deviceId === false) {
    json_error('VALIDATION_ERROR', 'Parameter device_id tidak valid. Harus berupa angka lebih dari 0', 422);
}

try {
    $device = get_device_pricing($pdo, $deviceId);

    if ($device === null) {
        json_error('NOT_FOUND', 'Device tidak ditemukan', 404);
    }

    json_response([
        'success' => true,
        'data' => $device,
    ]);

} catch (PDOException $e) {
    error_log('Device Pricing API Error: ' . $e->getMessage());
    json_error('INTERNAL_SERVER_ERROR', 'Terjadi kesalahan internal pada server', 500);
}
