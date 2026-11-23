<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('METHOD_NOT_ALLOWED', 'Gunakan POST', 405);

admin_device_authorize($pdo);

admin_device_require_csrf();

$data = admin_device_fields(read_json_body());

try {
    $stmt = $pdo->prepare("INSERT INTO device (device_name, category, serial_number, booth_code, price_per_day, status)
       VALUES (:device_name,:category,:serial_number,:booth_code,:price_per_day,:status) RETURNING device_id");

    $stmt->execute($data);

    json_response(['success' => true, 'data' => ['device_id' => (int)$stmt->fetchColumn()]], 201);

} catch (PDOException $e) {
    if ($e->getCode() === '23505') json_error('CONFLICT', 'Serial number sudah digunakan', 409);

    error_log('Create device: ' . $e->getMessage());
    
    json_error('SERVER_ERROR', 'Gagal menambah perangkat', 500);
}
