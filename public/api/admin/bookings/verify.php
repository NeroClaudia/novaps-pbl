<?php

require __DIR__ . '/../../../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../../../app/includes/storage.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan metode POST', 405);
}

$auth = require_login(['admin', 'owner']);
$input = read_json_body();

$bookingId = filter_var(
    $input['booking_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($bookingId === false || $bookingId === null) {
    json_error(
        'VALIDATION_ERROR',
        'booking_id harus bilangan bulat minimal 1',
        422
    );
}

// Admin wajib menyatakan bahwa kedua pemeriksaan telah dilakukan.
if (
    ($input['identity_confirmed'] ?? null) !== true ||
    ($input['payment_confirmed'] ?? null) !== true
) {
    json_error(
        'VERIFICATION_REQUIRED',
        'Admin wajib mengonfirmasi identitas dan pembayaran',
        422
    );
}

try {
    $pdo->beginTransaction();

    // 1. Kunci booking dan cari customer.
    $stmt = $pdo->prepare(
        "SELECT booking_id, booking_code, customer_id
         FROM booking
         WHERE booking_id = :booking_id
         FOR UPDATE"
    );
    $stmt->execute(['booking_id' => $bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        $pdo->rollBack();
        json_error('NOT_FOUND', 'Booking tidak ditemukan', 404);
    }

    // 2. Kunci identitas customer agar tidak berubah
    // selama proses persetujuan.
    $stmt = $pdo->prepare(
        "SELECT user_id, identity_doc_type,
                identity_doc_url, identity_doc_verified
         FROM users
         WHERE user_id = :customer_id
           AND role = 'customer'
         FOR UPDATE"
    );
    $stmt->execute([
        'customer_id' => $booking['customer_id']
    ]);
    $customer = $stmt->fetch();

    if (
        !$customer ||
        empty($customer['identity_doc_type']) ||
        empty($customer['identity_doc_url'])
    ) {
        $pdo->rollBack();
        json_error(
            'IDENTITY_REQUIRED',
            'Dokumen identitas customer belum tersedia',
            409
        );
    }

    // Pastikan lokasi file identitas aman dan file tersedia.
    $identityPath = (string) $customer['identity_doc_url'];

    $expectedIdentity =
        '~^customers/' . (int) $booking['customer_id'] .
        '/[a-f0-9]{32}\.(jpg|png)$~D';

    if (!preg_match($expectedIdentity, $identityPath)) {
        $pdo->rollBack();

        json_error('IDENTITY_UNAVAILABLE', 'Lokasi dokumen identitas tidak valid', 409);
    }

    storage_download(
        storage_identity_bucket(),
        $identityPath
    );

    // 3. Kunci data pembayaran dari tabel payment.
    $stmt = $pdo->prepare(
        "SELECT payment_id, payment_method,
                payment_status, payment_proof_url,
                payment_expires_at
         FROM payment
         WHERE booking_id = :booking_id
         FOR UPDATE"
    );
    $stmt->execute(['booking_id' => $bookingId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        $pdo->rollBack();
        json_error(
            'PAYMENT_NOT_FOUND',
            'Data pembayaran tidak ditemukan',
            404
        );
    }

    $method = $payment['payment_method'];
    $status = $payment['payment_status'];

    if ($method === 'QRIS') {
        if (
            $status !== 'AwaitingVerification' ||
            empty($payment['payment_proof_url'])
        ) {
            $pdo->rollBack();
            json_error(
                'PAYMENT_NOT_READY',
                'Bukti pembayaran QRIS belum siap diverifikasi',
                409
            );
        }

        $proofPath = (string) $payment['payment_proof_url'];

        $expectedProof =
            '~^bookings/' . $bookingId .
            '/[a-f0-9]{32}\.(jpg|png)$~D';

        if (!preg_match($expectedProof, $proofPath)) {
            $pdo->rollBack();

            json_error(
                'PAYMENT_PROOF_UNAVAILABLE',
                'Lokasi bukti pembayaran tidak valid',
                409
            );
        }

        storage_download(
            storage_payment_bucket(),
            $proofPath
        );
        
    } elseif ($method === 'Cash') {
        if ($status !== 'Pending') {
            $pdo->rollBack();
            json_error(
                'INVALID_STATUS_TRANSITION',
                'Pembayaran Cash tidak lagi berstatus Pending',
                409
            );
        }

        if (
            $payment['payment_expires_at'] !== null &&
            strtotime($payment['payment_expires_at']) <= time()
        ) {
            $pdo->rollBack();
            json_error(
                'PAYMENT_EXPIRED',
                'Batas waktu pembayaran telah berakhir',
                409
            );
        }
    } else {
        $pdo->rollBack();
        json_error(
            'INVALID_PAYMENT_METHOD',
            'Metode pembayaran tidak didukung',
            409
        );
    }

    // 4. Pastikan seluruh rental masih berstatus Booked.
    $stmt = $pdo->prepare(
        "SELECT rental_id, device_id, rental_status
         FROM rental
         WHERE booking_id = :booking_id
         ORDER BY rental_id
         FOR UPDATE"
    );
    $stmt->execute(['booking_id' => $bookingId]);
    $rentals = $stmt->fetchAll();

    if (!$rentals) {
        $pdo->rollBack();
        json_error(
            'INVALID_STATUS_TRANSITION',
            'Booking tidak memiliki item rental',
            409
        );
    }

    foreach ($rentals as $rental) {
        if ($rental['rental_status'] !== 'Booked') {
            $pdo->rollBack();
            json_error(
                'INVALID_STATUS_TRANSITION',
                'Seluruh item rental harus berstatus Booked',
                409
            );
        }
    }

    // 5. Setujui identitas customer.
    $stmt = $pdo->prepare(
        "UPDATE users
         SET identity_doc_verified = TRUE
         WHERE user_id = :customer_id
           AND role = 'customer'"
    );
    $stmt->execute([
        'customer_id' => $booking['customer_id']
    ]);

    // 6. Setujui pembayaran.
    $stmt = $pdo->prepare(
        "UPDATE payment
     SET payment_status = 'Paid',
         payment_verified_at = NOW(),
         paid_at = COALESCE(paid_at, NOW())
     WHERE payment_id = :payment_id
       AND payment_status = :current_status"
    );
    $stmt->execute([
        'payment_id' => $payment['payment_id'],
        'current_status' => $status
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Status pembayaran gagal diperbarui'
        );
    }

    // 7. Catat admin yang memverifikasi booking.
    $stmt = $pdo->prepare(
        "UPDATE booking
         SET staff_id = :staff_id
         WHERE booking_id = :booking_id"
    );
    $stmt->execute([
        'staff_id' => (int) $auth['user_id'],
        'booking_id' => $bookingId
    ]);

    // 8. Aktifkan rental.
    $stmt = $pdo->prepare(
        "UPDATE rental
         SET rental_status = 'Rented',
             rented_at = NOW()
         WHERE booking_id = :booking_id
           AND rental_status = 'Booked'"
    );
    $stmt->execute(['booking_id' => $bookingId]);

    if ($stmt->rowCount() !== count($rentals)) {
        throw new RuntimeException(
            'Tidak semua item rental berhasil diperbarui'
        );
    }

    $pdo->commit();

    json_response([
        'success' => true,
        'data' => [
            'booking_id' => (int) $booking['booking_id'],
            'booking_code' => $booking['booking_code'],
            'identity_doc_verified' => true,
            'payment_status' => 'Paid',
            'verified_by' => $auth['full_name'],
            'items' => array_map(
                static fn(array $rental): array => [
                    'rental_id' => (int) $rental['rental_id'],
                    'device_id' => (int) $rental['device_id'],
                    'rental_status' => 'Rented'
                ],
                $rentals
            )
        ]
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Admin Booking Verify API Error: ' . $e->getMessage()
    );

    json_error(
        'SERVER_ERROR',
        'Verifikasi booking gagal diproses', 500
    );
}
