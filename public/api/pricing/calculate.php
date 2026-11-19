<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan method POST', 405);
}

$input = read_json_body();

$deviceId = $input['device_id'] ?? null;
$durationDays = $input['duration_days'] ?? null;

if (!is_int($deviceId) || $deviceId < 1) {
    json_error('VALIDATION_ERROR', 'device_id harus berupa bilangan bulat minimal 1', 422);
}

if (array_key_exists('duration_hours', $input) || array_key_exists('package_id', $input)) {
    json_error('VALIDATION_ERROR', 'Gunakan duration_days untuk durasi sewa harian', 422);
}

if (!is_int($durationDays) || $durationDays < 1 || $durationDays > 7) {
    json_error('VALIDATION_ERROR', 'duration_days harus berupa bilangan bulat antara 1 sampai 7', 422);
}

try {
    $deviceQuery = $pdo->prepare(
        "SELECT device_id, price_per_day
         FROM device
         WHERE device_id = :device_id"
    );

    $deviceQuery->execute([
        'device_id' => $deviceId
    ]);

    $device = $deviceQuery->fetch();

    if (!$device) {
        json_error('NOT_FOUND', 'Device tidak ditemukan', 404);
    }

    $pricePerDay = (float) $device['price_per_day'];
    $durationHours = $durationDays * 24;
    $totalCost = $durationDays * $pricePerDay;

    json_response([
        'success' => true,
        'data' => [
            'device_id' => $deviceId,
            'duration_days' => $durationDays,
            'duration_hours' => $durationHours,
            'price_per_day' => $pricePerDay,
            'total_cost' => $totalCost
        ]
    ]);

} catch (Throwable $e) {
    error_log('Price Calculation API Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Terjadi kesalahan internal pada server', 500);
}
