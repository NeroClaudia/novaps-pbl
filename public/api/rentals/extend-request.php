<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/includes/bootstrap.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan POST', 405);
}

$auth = require_login(['customer']);

$input = read_json_body();

$rentalId = $input['rental_id'] ?? null;
$extraDays = $input['extra_days'] ?? null;

if (!is_int($rentalId) || $rentalId < 1) {
    json_error('VALIDATION_ERROR', 'Rental ID tidak valid', 422);
}

if (!is_int($extraDays) || $extraDays < 1 || $extraDays > 6) {
    json_error('VALIDATION_ERROR', 'Tambahan hari tidak valid', 422);
}

$customerId = (int) $auth['user_id'];

try {
    $pdo->beginTransaction();

    // Kunci rental dan pastikan customer adalah pemiliknya.
    $stmt = $pdo->prepare(
        "SELECT
            r.rental_id,
            r.booking_id,
            r.device_id,
            r.rental_status,
            r.duration_hours,
            r.end_time,
            r.price_per_day,
            b.fulfillment_status,
            p.payment_status
         FROM rental r
         JOIN booking b ON b.booking_id = r.booking_id
         JOIN payment p ON p.booking_id = b.booking_id
         WHERE r.rental_id = :rental_id
           AND b.customer_id = :customer_id
         FOR UPDATE OF r"
    );

    $stmt->execute([
        'rental_id' => $rentalId,
        'customer_id' => $customerId
    ]);

    $rental = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rental) {
        $pdo->rollBack();
        json_error('NOT_FOUND', 'Rental tidak ditemukan', 404);
    }

    if ($rental['rental_status'] !== 'Rented' || $rental['fulfillment_status'] !== 'Complete' || $rental['payment_status'] !== 'Paid') {
        $pdo->rollBack();
        json_error('INVALID_RENTAL_STATUS', 'Hanya rental aktif yang belum overdue dapat diperpanjang', 409);
    }

    $currentDays = intdiv((int) $rental['duration_hours'],24);

    if ($currentDays + $extraDays > 7) {
        $pdo->rollBack();
        json_error('MAX_DURATION_EXCEEDED', 'Total sewa maksimal 7 hari', 422);
    }

    $oldEnd = new DateTimeImmutable(
        $rental['end_time']
    );

    if ($oldEnd->getTimestamp() <= time()) {
        $pdo->rollBack();
        json_error('RENTAL_OVERDUE', 'Rental sudah melewati batas pengembalian', 409);
    }

    $newEnd = $oldEnd->modify("+{$extraDays} days");

    // Cek pengajuan lain yang masih berjalan.
    $expireStmt = $pdo->prepare(
        "UPDATE rental_extension
         SET status = 'Expired'
         WHERE rental_id = :rental_id
            AND status = 'PendingPayment'
            AND created_at + INTERVAL '5 minutes' <= NOW()"
    );

    $expireStmt->execute(['rental_id' => $rentalId]);

    $stmt = $pdo->prepare(
        "SELECT extension_id
         FROM rental_extension
         WHERE rental_id = :rental_id
           AND status IN (
               'PendingPayment',
               'AwaitingVerification'
           )
         FOR UPDATE"
    );

    $stmt->execute(['rental_id' => $rentalId]);

    if ($stmt->fetchColumn()) {
        $pdo->rollBack();
        json_error('EXTENSION_PENDING', 'Masih ada pengajuan perpanjangan aktif', 409);
    }

    // Periksa jadwal rental lain untuk perangkat sama.
    $stmt = $pdo->prepare(
        "SELECT EXISTS (
            SELECT 1
            FROM rental other_r
            JOIN booking other_b
              ON other_b.booking_id = other_r.booking_id
            JOIN payment other_p
              ON other_p.booking_id = other_b.booking_id
            WHERE other_r.device_id = :device_id
              AND other_r.rental_id <> :rental_id
              AND other_r.rental_status IN (
                  'Booked', 'Rented', 'Overdue'
              )
              AND other_p.payment_status IN (
                  'Pending',
                  'AwaitingVerification',
                  'Paid'
              )
              AND (
                  other_p.payment_status <> 'Pending'
                  OR other_p.payment_expires_at IS NULL
                  OR other_p.payment_expires_at > NOW()
              )
              AND other_r.start_time < :new_end
              AND other_r.end_time > :old_end
        )"
    );

    $stmt->execute([
        'device_id' => (int) $rental['device_id'],
        'rental_id' => $rentalId,
        'old_end' => $oldEnd->format(DATE_ATOM),
        'new_end' => $newEnd->format(DATE_ATOM)
    ]);

    if ($stmt->fetchColumn()) {
        $pdo->rollBack();
        json_error('SLOT_TAKEN', 'Jadwal perpanjangan berbenturan dengan rental lain', 409);
    }

    $price = (float) $rental['price_per_day'];
    $total = $price * $extraDays;

    $stmt = $pdo->prepare(
        "INSERT INTO rental_extension (
            rental_id,
            extra_days
        ) VALUES (
            :rental_id,
            :extra_days
        ) RETURNING extension_id, created_at"
    );

    $stmt->execute([
        'rental_id' => $rentalId,
        'extra_days' => $extraDays
    ]);

    $extension = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$extension) {
        throw new RuntimeException('Gagal membuat pengajuan perpanjangan');
    }

    $pdo->commit();

    json_response([
        'success' => true,
        'data' => [
            'extension_id' => (int) $extension['extension_id'],
            'rental_id' => $rentalId,
            'extra_days' => $extraDays,
            'total_cost' => $total,
            'payment_status' => 'PendingPayment',
            'payment_expires_at' => (new DateTimeImmutable($extension['created_at']))->modify('+5 minutes')->format(DATE_ATOM)
        ]
    ], 201);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Extend Request Error: ' . $e->getMessage());

    if ((string) $e->getCode() === '23505') {
        json_error('EXTENSION_PENDING', 'Masih ada pengajuan perpanjangan aktif', 409);
    } else {
        json_error('SERVER_ERROR', 'Gagal mengajukan perpanjangan', 500);
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Extend Request Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Gagal mengajukan perpanjangan', 500);
}
