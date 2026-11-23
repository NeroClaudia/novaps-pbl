<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/includes/bootstrap.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan metode POST', 405);
}

$auth = require_login(['customer']);
$customerId = (int) ($auth['user_id'] ?? 0);

if ($customerId < 1) {
    json_error('UNAUTHORIZED', 'Sesi customer tidak valid', 401);
}

$input = read_json_body();

$rawCode = $input['booking_code'] ?? null;
$rawReason = $input['reason'] ?? null;

$bookingCode = is_string($rawCode)
    ? strtoupper(trim($rawCode))
    : '';

$reason = is_string($rawReason)
    ? trim($rawReason)
    : '';

if (!preg_match('/^[A-Z0-9]{8}$/', $bookingCode)) {
    json_error('VALIDATION_ERROR', 'Kode booking tidak valid',422);
}

if (mb_strlen($reason) < 5 || mb_strlen($reason) > 200) {
    json_error('VALIDATION_ERROR', 'Alasan pembatalan harus 5 sampai 200 karakter', 422);
}

try {
    expire_unpaid_bookings($pdo);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT
            b.booking_id,
            b.fulfillment_status,
            p.payment_id,
            p.payment_status
         FROM booking b
         JOIN payment p
             ON p.booking_id = b.booking_id
         WHERE b.booking_code = :booking_code
           AND b.customer_id = :customer_id
         FOR UPDATE OF b, p"
    );

    $stmt->execute([
        'booking_code' => $bookingCode,
        'customer_id' => $customerId
    ]);

    $booking = $stmt->fetch();

    if (!$booking) {
        $pdo->rollBack();
        json_error('NOT_FOUND', 'Booking tidak ditemukan', 404);
    }

    if ($booking['payment_status'] !== 'Pending' || $booking['fulfillment_status'] !== 'Pending') {
        $pdo->rollBack();
        json_error('INVALID_STATUS_TRANSITION', 'Booking tidak dapat dibatalkan', 409);
    }

    $bookingId = (int) $booking['booking_id'];

    $stmt = $pdo->prepare(
        "UPDATE payment
         SET payment_status = 'Cancelled'
         WHERE payment_id = :payment_id
           AND payment_status = 'Pending'
           AND (
               payment_expires_at IS NULL
               OR payment_expires_at > clock_timestamp()
           )"
    );

    $stmt->execute([
        'payment_id' => $booking['payment_id']
    ]);

    if ($stmt->rowCount() !== 1) {
        $pdo->rollBack();

        json_error('INVALID_STATUS_TRANSITION', 'Pembayaran sudah diproses atau kedaluwarsa', 409);
    }

    $stmt = $pdo->prepare(
        "UPDATE booking
            SET fulfillment_status  = 'Cancelled',
                cancel_reason = :reason,
                cancelled_at = clock_timestamp()
            WHERE booking_id = :booking_id AND fulfillment_status = 'Pending'"
    );

    $stmt->execute([
        'reason' => $reason,
        'booking_id' => $bookingId
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Status booking gagal diperbarui');
    }

    $stmt = $pdo->prepare(
        "UPDATE rental
         SET rental_status = 'Cancelled'
         WHERE booking_id = :booking_id
           AND rental_status = 'Booked'"
    );

    $stmt->execute([
        'booking_id' => $bookingId
    ]);

    $pdo->commit();

    json_response([
        'success' => true,
        'data' => [
            'booking_code' => $bookingCode,
            'payment_status' => 'Cancelled',
            'fulfillment_status' => 'Cancelled',
            'cancel_reason' => $reason
        ]
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Cancel Booking API Error: ' . $e->getMessage()
    );

    json_error('SERVER_ERROR', 'Gagal membatalkan booking', 500);
}
