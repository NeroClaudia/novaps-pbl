<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan method POST', 405);
}

admin_device_authorize($pdo);
admin_device_require_csrf();

$data = admin_device_fields(read_json_body());

try {
    $pdo->beginTransaction();

    $category = $data['category'];

    //lock per kategori untuk mencegah nomor ganda saat dua admin menambahkan perangkat bersamaan
    $lock = $pdo->prepare(
        "SELECT pg_advisory_xact_lock(4242, :category_id)"
    );

    $lock->execute(['category_id' => $category === 'PS4' ? 4 : 5]);

    //Cari nomor tertinggi pada kode inventaris kategori yang sama
    $stmt = $pdo->prepare(
        "SELECT COALESCE(
            MAX(
                SUBSTRING(
                    serial_number
                    FROM '^NVPS-PS[45]-([0-9]+)$'
                )::BIGINT
            ),
            0
        )
        FROM device
        WHERE category = :category
          AND serial_number ~ :pattern"
    );

    $stmt->execute([
        'category' => $category,
        'pattern' => '^NVPS-' . $category . '-[0-9]+$'
    ]);

    $nextNumber = (int) $stmt->fetchColumn() + 1;

    $serial = sprintf(
        'NVPS-%s-%03d',
        $category,
        $nextNumber
    );

    $insert = $pdo->prepare(
        "INSERT INTO device (
            device_name,
            category,
            serial_number,
            price_per_day,
            status
        ) VALUES (
            :device_name,
            :category,
            :serial_number,
            :price_per_day,
            :status
        )
        RETURNING device_id"
    );

    $insert->execute([
        'device_name' => $data['device_name'],
        'category' => $category,
        'serial_number' => $serial,
        'price_per_day' => $data['price_per_day'],
        'status' => $data['status']
    ]);

    $deviceId = (int) $insert->fetchColumn();

    $pdo->commit();

    json_response([
        'success' => true,
        'data' => [
            'device_id' => $deviceId,
            'serial_number' => $serial
        ]
    ], 201);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e->getCode() === '23505') {
        json_error('CONFLICT', 'Kode inventaris sudah digunakan', 409);
    }

    error_log('Create device: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Gagal menambah perangkat', 500);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Create device: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Gagal menambah perangkat', 500);
}