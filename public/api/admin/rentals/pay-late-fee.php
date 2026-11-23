<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../app/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan POST', 405);
}

$auth = require_login(['admin', 'owner']);

$fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    json_error('FORBIDDEN', 'Origin tidak valid', 403);
}

$expected = $_SESSION['admin_csrf'] ?? '';
$supplied = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (
    !is_string($expected) ||
    strlen($expected) !== 64 ||
    !is_string($supplied) ||
    !hash_equals($expected, $supplied)
) {
    json_error('FORBIDDEN', 'CSRF token tidak valid', 403);
}

$input = read_json_body();
$rentalId = $input['rental_id'] ?? null;


$paymentMethod = $input['payment_method'] ?? null;

if (!is_string($paymentMethod) || !in_array($paymentMethod, ['Cash', 'QRIS'], true)) {
    json_error('VALIDATION_ERROR', 'Metode pembayaran harus Cash atau QRIS', 422);
}

if (!is_int($rentalId) || $rentalId < 1) {
    json_error('VALIDATION_ERROR', 'rental_id tidak valid', 422);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT
            rental_id,
            rental_status,
            end_time,
            returned_at,
            late_fee_paid_at
         FROM rental
         WHERE rental_id = :rental_id
         FOR UPDATE"
    );

    $stmt->execute(['rental_id' => $rentalId]);
    $rental = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rental) {
        $pdo->rollBack();
        json_error('NOT_FOUND', 'Rental tidak ditemukan', 404);
    }

    if ($rental['rental_status'] !== 'Returned' || $rental['returned_at'] === null) {
        $pdo->rollBack();
        json_error('INVALID_RENTAL_STATUS', 'Perangkat harus dikembalikan terlebih dahulu',409);
    }

    if ($rental['late_fee_paid_at'] !== null) {
        $pdo->rollBack();
        json_error('ALREADY_PAID', 'Denda sudah dilunasi', 409);
    }

    $lateFee = calculate_late_fee(
        $rental['end_time'],
        $rental['returned_at']
    );

    if ($lateFee <= 0) {
        $pdo->rollBack();
        json_error('NO_LATE_FEE', 'Rental ini tidak memiliki denda', 409);
    }

    $update = $pdo->prepare(
        "UPDATE rental
            SET
                late_fee_paid_at = clock_timestamp(),
                late_fee_payment_method = :payment_method
            WHERE rental_id = :rental_id
                AND rental_status = 'Returned'
                AND late_fee_paid_at IS NULL
                AND late_fee_payment_method IS NULL
            RETURNING late_fee_paid_at"
    );

    $update->execute([
        'rental_id' => $rentalId,
        'payment_method' => $paymentMethod
    ]);

    $paidAt = $update->fetchColumn();

    if ($paidAt === false) {
        throw new RuntimeException('Gagal mencatat pembayaran');
    }

    $pdo->commit();

    json_response([
        'success' => true,
        'message' => 'Denda berhasil dilunasi',
        'data' => [
            'rental_id' => $rentalId,
            'late_fee' => $lateFee,
            'payment_method' => $paymentMethod,
            'late_fee_status' => 'Paid',
            'late_fee_paid_at' => $paidAt
        ]
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Late Fee Payment Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Gagal memproses pembayaran denda', 500);
}
