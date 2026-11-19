<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../app/includes/bootstrap.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan POST', 405);
}

$auth = require_login(['admin', 'owner']);
$input = read_json_body();

$bookingId = filter_var(
    $input['booking_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($bookingId === false || $bookingId === null) {
    json_error('VALIDATION_ERROR', 'booking_id tidak valid', 422);
}

if (($input['delivery_confirmed'] ?? null) !== true) {
    json_error(
        'CONFIRMATION_REQUIRED',
        'Admin wajib mengonfirmasi penerimaan perangkat',
        422
    );
}

try {
    $pdo->beginTransaction();

    // Kunci booking agar tidak bisa diproses dua kali.
    $stmt = $pdo->prepare(
        "SELECT b.booking_id, b.booking_code,
                b.fulfillment_status, p.payment_status
         FROM booking b
         JOIN payment p ON p.booking_id = b.booking_id
         WHERE b.booking_id = :booking_id
         FOR UPDATE OF b, p"
    );

    $stmt->execute(['booking_id' => $bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        $pdo->rollBack();
        json_error('NOT_FOUND', 'Booking tidak ditemukan', 404);
    }

    if (
        $booking['fulfillment_status'] !== 'Shipped' ||
        $booking['payment_status'] !== 'Paid'
    ) {
        $pdo->rollBack();

        json_error(
            'INVALID_STATUS_TRANSITION',
            'Hanya pesanan Shipped yang sudah dibayar dapat diselesaikan',
            409
        );
    }

    // Kunci device agar tidak ada checkout paralel
    // yang menggunakan perangkat yang sama.
    $stmt = $pdo->prepare(
        "SELECT d.device_id
         FROM device d
         JOIN rental r ON r.device_id = d.device_id
         WHERE r.booking_id = :booking_id
         ORDER BY d.device_id
         FOR UPDATE OF d"
    );

    $stmt->execute(['booking_id' => $bookingId]);
    $lockedDevices = $stmt->fetchAll();

    if (!$lockedDevices) {
        throw new RuntimeException('Tidak ada perangkat pada booking');
    }

    $stmt = $pdo->prepare(
        "SELECT rental_id, device_id, duration_hours,
                rental_status
         FROM rental
         WHERE booking_id = :booking_id
         ORDER BY device_id
         FOR UPDATE"
    );

    $stmt->execute(['booking_id' => $bookingId]);
    $rentals = $stmt->fetchAll();

    if (count($rentals) !== count($lockedDevices)) {
        throw new RuntimeException(
            'Jumlah perangkat yang terkunci tidak sesuai'
        );
    }

    foreach ($rentals as $rental) {
        if ($rental['rental_status'] !== 'Booked') {
            $pdo->rollBack();
            json_error(
                'INVALID_STATUS_TRANSITION',
                'Seluruh perangkat harus berstatus Booked',
                409
            );
        }
    }

    // Pakai satu waktu aktivasi untuk semua perangkat.
    $stmt = $pdo->query(
        "SELECT clock_timestamp() AS activated_at"
    );
    $activatedAt = $stmt->fetchColumn();

    // Periksa bentrok dengan booking lain jika jadwal
    // digeser ke waktu penerimaan sesungguhnya.
    $conflictStmt = $pdo->prepare(
        "SELECT EXISTS (
            SELECT 1
            FROM rental other_r
            JOIN booking other_b
              ON other_b.booking_id = other_r.booking_id
            JOIN payment other_p
              ON other_p.booking_id = other_b.booking_id
            WHERE other_r.device_id = :device_id
              AND other_r.booking_id <> :booking_id
              AND other_r.rental_status IN (
                  'Booked', 'Rented', 'Overdue'
              )
              AND other_p.payment_status IN (
                  'Pending', 'AwaitingVerification', 'Paid'
              )
              AND (
                  other_p.payment_status <> 'Pending'
                  OR other_p.payment_expires_at IS NULL
                  OR other_p.payment_expires_at > clock_timestamp()
              )
              AND (
                  (
                      other_b.fulfillment_status = 'Shipped'
                      AND other_p.payment_status = 'Paid'
                  )
                  OR other_r.rental_status = 'Overdue'
                  OR (
                      other_r.rental_status = 'Rented'
                      AND other_r.end_time < clock_timestamp()
                  )
                  OR (
                      other_r.start_time <
                          CAST(:new_end AS TIMESTAMPTZ)
                      AND other_r.end_time >
                          CAST(:new_start AS TIMESTAMPTZ)
                  )
              )
        )"
    );

    $updateRental = $pdo->prepare(
        "UPDATE rental
         SET rental_status = 'Rented',
             rented_at = CAST(:activated_at AS TIMESTAMPTZ),
             start_time = CAST(:start_at AS TIMESTAMPTZ),
             end_time = CAST(:end_at AS TIMESTAMPTZ),
             overdue_at = NULL
         WHERE rental_id = :rental_id
           AND rental_status = 'Booked'"
    );

    $activated = [];

    foreach ($rentals as $rental) {
        $newStart = new DateTimeImmutable($activatedAt);

        $newEnd = $newStart->modify(
            '+' . (int) $rental['duration_hours'] . ' hours'
        );

        $conflictStmt->execute([
            'device_id' => (int) $rental['device_id'],
            'booking_id' => $bookingId,
            'new_start' => $newStart->format(DATE_ATOM),
            'new_end' => $newEnd->format(DATE_ATOM)
        ]);

        if ($conflictStmt->fetchColumn()) {
            $pdo->rollBack();

            json_error(
                'SLOT_TAKEN',
                'Jadwal baru berbenturan dengan reservasi lain. Perlu penanganan admin.',
                409
            );
        }

        $updateRental->execute([
            'activated_at' => $activatedAt,
            'start_at' => $newStart->format(DATE_ATOM),
            'end_at' => $newEnd->format(DATE_ATOM),
            'rental_id' => (int) $rental['rental_id']
        ]);

        if ($updateRental->rowCount() !== 1) {
            throw new RuntimeException(
                'Gagal mengaktifkan rental'
            );
        }

        $activated[] = [
            'rental_id' => (int) $rental['rental_id'],
            'device_id' => (int) $rental['device_id'],
            'start_time' => $newStart->format(DATE_ATOM),
            'end_time' => $newEnd->format(DATE_ATOM),
            'rental_status' => 'Rented'
        ];
    }

    $stmt = $pdo->prepare(
        "UPDATE booking
         SET fulfillment_status = 'Complete',
             completed_at = CAST(:completed_at AS TIMESTAMPTZ)
         WHERE booking_id = :booking_id
           AND fulfillment_status = 'Shipped'"
    );

    $stmt->execute([
        'completed_at' => $activatedAt,
        'booking_id' => $bookingId
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Gagal menyelesaikan pengiriman'
        );
    }

    $pdo->commit();

    json_response([
        'success' => true,
        'data' => [
            'booking_id' => $bookingId,
            'booking_code' => $booking['booking_code'],
            'payment_status' => 'Paid',
            'fulfillment_status' => 'Complete',
            'completed_at' => $activatedAt,
            'items' => $activated
        ]
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Deliver Booking Error: ' . $e->getMessage());

    json_error(
        'SERVER_ERROR',
        'Gagal mengonfirmasi penerimaan perangkat',
        500
    );
}
