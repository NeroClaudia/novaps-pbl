<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('METHOD_NOT_ALLOWED', 'Gunakan GET', 405);

admin_device_authorize($pdo);

try {
    expire_unpaid_bookings($pdo);
    mark_overdue($pdo);

    require_once __DIR__ . '/../../../../app/functions/devices.php';

    $base = get_devices($pdo);

    $rows = $pdo->query('SELECT device_id, serial_number FROM device')->fetchAll(PDO::FETCH_ASSOC);

    $serials = [];

    foreach ($rows as $row) $serials[(int)$row['device_id']] = $row['serial_number'];

    foreach ($base as &$device) {
        $device['serial_number'] = $serials[$device['device_id']] ?? '';
    }

    unset($device);
    
    json_response(['success' => true, 'data' => $base]);
    
} catch (Throwable $e) {
    error_log('Admin devices list: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Gagal mengambil perangkat', 500);
}
