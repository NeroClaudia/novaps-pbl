<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('METHOD_NOT_ALLOWED', 'Gunakan POST', 405);

admin_device_authorize($pdo);

admin_device_require_csrf();

$input = read_json_body();

$id = filter_var($input['device_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($id === false || $id === null) json_error('VALIDATION_ERROR', 'Device ID tidak valid', 422);

$data = admin_device_fields($input);

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT status FROM device WHERE device_id=:id FOR UPDATE');

    $stmt->execute(['id' => $id]);

    $old = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$old) {
        $pdo->rollBack();
        json_error('NOT_FOUND', 'Perangkat tidak ditemukan', 404);
    }

    // Changing to maintenance is prohibited while any reservation/rental is active.
    if ($data['status'] === 'Maintenance' && admin_device_has_active_reservation($pdo, $id)) {
        $pdo->rollBack();
        json_error('DEVICE_IN_USE', 'Perangkat masih memiliki booking/rental aktif', 409);
    }

    $data['device_id'] = $id;

    $stmt = $pdo->prepare("UPDATE device SET device_name=:device_name,category=:category,serial_number=:serial_number,
        price_per_day=:price_per_day,status=:status WHERE device_id=:device_id");

    $stmt->execute($data);

    $pdo->commit();

    json_response(['success' => true, 'data' => ['device_id' => $id]]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    if ($e->getCode() === '23505') json_error('CONFLICT', 'Serial number sudah digunakan', 409);

    error_log('Update device: ' . $e->getMessage());

    json_error('SERVER_ERROR', 'Gagal memperbarui perangkat', 500);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    error_log('Update device: ' . $e->getMessage());
    
    json_error('SERVER_ERROR', 'Gagal memperbarui perangkat', 500);
}
