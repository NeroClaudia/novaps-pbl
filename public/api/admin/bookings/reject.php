<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../app/includes/bootstrap.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan method POST', 405);
}

$auth = require_login(['admin', 'owner']);
$input = read_json_body();

$bookingId = filter_var($input['booking_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$reason = $input['reason'] ?? null;

if ($bookingId === false || $bookingId === null) {
    json_error('VALIDATION_ERROR', 'Booking ID tidak valid', 422);
}

if (!is_string($reason)) {
    json_error('VALIDATION_ERROR', 'Alasan penolakan wajib diisi', 422);
}

$reason = trim($reason);

if (mb_strlen($reason) < 5 || mb_strlen($reason) > 200) {
    json_error('VALIDATION_ERROR', 'Alasan penolakan harus minimal 5 sampai 200 karakter', 422);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT
            b.booking_id,
            b.booking_code,
            b.fulfillment_status,
            p.payment_id,
            p.payment_method,
            p.payment_status
         FROM booking b
         JOIN payment p
           ON p.booking_id = b.booking_id
         WHERE b.booking_id = :booking_id
         FOR UPDATE OF b, p"
    );

    $stmt->execute(['booking_id' => $bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $pdo->rollBack();
        json_error('NOT_FOUND', 'Booking tidak ditemukan', 404);
    }

    if ($booking['fulfillment_status'] !== 'Pending') {
        $pdo->rollBack();
        json_error('INVALID_STATUS_TRANSITION', 'Hanya booking Pending yang dapat ditolak', 409);
    }

    $allowedPayment = match ($booking['payment_method']) {
        'QRIS' => $booking['payment_status'] === 'AwaitingVerification',
        'Cash' => $booking['payment_status'] === 'Pending',
        default => false
    };

    if (!$allowedPayment) {
        $pdo->rollBack();
        json_error('INVALID_STATUS_TRANSITION', 'Pembayaran tidak dapat ditolak pada status sekarang', 409);
    }

    //Pastikan semua perangkat belum mulai disewa
    $stmt = $pdo->prepare(
        "SELECT rental_id, rental_status
         FROM rental
         WHERE booking_id = :booking_id
         ORDER BY rental_id
         FOR UPDATE"
    );

    $stmt->execute(['booking_id' => $bookingId]);
    $rentals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rentals) {
        $pdo->rollBack();
        json_error('INVALID_STATUS_TRANSITION', 'Booking tidak memiliki rental', 409);
    }

    foreach ($rentals as $rental) {
        if ($rental['rental_status'] !== 'Booked') {
            $pdo->rollBack();
            json_error('INVALID_STATUS_TRANSITION', 'Booking memiliki perangkat yang sudah diproses', 409);
        }
    }

    $stmt = $pdo->prepare(
        "UPDATE payment
         SET payment_status = 'Rejected'
         WHERE payment_id = :payment_id AND payment_status = :old_status"
    );

    $stmt->execute([
        'payment_id' => $booking['payment_id'], 
        'old_status' => $booking['payment_status']
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Gagal menolak pembayaran');
    }

    $stmt = $pdo->prepare(
        "UPDATE rental
         SET rental_status = 'Cancelled'
         WHERE booking_id = :booking_id AND rental_status = 'Booked'"
    );

    $stmt->execute(['booking_id' => $bookingId]);

    if ($stmt->rowCount() !== count($rentals)) {
        throw new RuntimeException('Gagal membatalkan rental');
    }

    $stmt = $pdo->prepare(
        "UPDATE booking
         SET fulfillment_status = 'Cancelled',
             cancel_reason = :reason,
             cancelled_at = clock_timestamp(),
             staff_id = :staff_id
         WHERE booking_id = :booking_id
           AND fulfillment_status = 'Pending'"
    );

    $stmt->execute([
        'reason' => $reason,
        'staff_id' => (int) $auth['user_id'],
        'booking_id' => $bookingId
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Gagal memperbarui booking');
    }

    $pdo->commit();

    json_response([
        'success' => true,
        'data' => [
            'booking_id' => $bookingId,
            'booking_code' => $booking['booking_code'],
            'payment_status' => 'Rejected',
            'fulfillment_status' => 'Cancelled',
            'cancel_reason' => $reason
        ]
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Admin Reject Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Gagal menolak booking', 500);
}