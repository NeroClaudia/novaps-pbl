<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../../../app/includes/storage.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan POST', 405);
}

$auth = require_login(['admin', 'owner']);

$fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';

if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    json_error('FORBIDDEN', 'Origin tidak diizinkan', 403);
}

$expectedToken = $_SESSION['admin_csrf'] ?? '';
$suppliedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (
    !is_string($expectedToken) ||
    strlen($expectedToken) !== 64 ||
    !is_string($suppliedToken) ||
    !hash_equals($expectedToken, $suppliedToken)
) {
    json_error('FORBIDDEN', 'CSRF token tidak valid', 403);
}

$input = read_json_body();

$extensionId = $input['extension_id'] ?? null;
$action = $input['action'] ?? null;

if (!is_int($extensionId) || $extensionId < 1) {
    json_error('VALIDATION_ERROR', 'extension_id tidak valid', 422);
}

if (!in_array($action, ['approve', 'reject'], true)) {
    json_error('VALIDATION_ERROR', 'Action harus approve atau reject', 422);
}

try {
    $pdo->beginTransaction();

    // Cari identifier relasi terlebih dahulu.
    $stmt = $pdo->prepare(
        "SELECT
            r.rental_id,
            r.booking_id,
            r.device_id
         FROM rental_extension e
         JOIN rental r ON r.rental_id = e.rental_id
         WHERE e.extension_id = :id"
    );

    $stmt->execute(['id' => $extensionId]);
    $ids = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ids) {
        $pdo->rollBack();
        json_error('NOT_FOUND', 'Pengajuan Extend tidak ditemukan',404);
    }

    // Lock booking, kemudian device, rental, extension.
    // Urutan ini harus diperhatikan juga pada endpoint lain.
    $stmt = $pdo->prepare(
        "SELECT booking_id, fulfillment_status
         FROM booking
         WHERE booking_id = :id
         FOR UPDATE"
    );
    $stmt->execute(['id' => (int) $ids['booking_id']]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new RuntimeException('Booking tidak ditemukan');
    }

    $stmt = $pdo->prepare(
        "SELECT device_id
         FROM device
         WHERE device_id = :id
         FOR UPDATE"
    );
    $stmt->execute(['id' => (int) $ids['device_id']]);

    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Device tidak ditemukan');
    }

    $stmt = $pdo->prepare(
        "SELECT
            r.rental_id,
            r.booking_id,
            r.device_id,
            r.rental_status,
            r.duration_hours,
            r.end_time,
            r.price_per_day,
            p.payment_status
         FROM rental r
         JOIN payment p ON p.booking_id = r.booking_id
         WHERE r.rental_id = :id
         FOR UPDATE OF r"
    );
    $stmt->execute(['id' => (int) $ids['rental_id']]);
    $rental = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rental) {
        throw new RuntimeException('Rental tidak ditemukan');
    }

    $stmt = $pdo->prepare(
        "SELECT
            extension_id,
            rental_id,
            extra_days,
            status,
            payment_proof_url
         FROM rental_extension
         WHERE extension_id = :id
         FOR UPDATE"
    );
    $stmt->execute(['id' => $extensionId]);
    $extension = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$extension) {
        throw new RuntimeException('Pengajuan tidak ditemukan');
    }

    if ((int) $extension['rental_id'] !== (int) $rental['rental_id'] || $extension['status'] !== 'AwaitingVerification') {
        $pdo->rollBack();
        json_error('INVALID_STATUS_TRANSITION', 'Pengajuan sudah diproses atau berubah status', 409);
    }

    if (empty($extension['payment_proof_url'])) {
        $pdo->rollBack();
        json_error('PAYMENT_PROOF_REQUIRED', 'Bukti pembayaran belum tersedia', 409);
    }

    $adminCheck = $pdo->prepare(
        "SELECT 1
         FROM users
         WHERE user_id = :id
           AND role = :role"
    );
    $adminCheck->execute([
        'id' => (int) $auth['user_id'],
        'role' => $auth['role']
    ]);

    if (!$adminCheck->fetchColumn()) {
        $pdo->rollBack();
        json_error('FORBIDDEN', 'Akun admin tidak valid', 403);
    }

    $result = [
        'extension_id' => $extensionId,
        'rental_id' => (int) $rental['rental_id']
    ];

    if ($action === 'approve') {
        // Validasi rental sebelum memperpanjang.
        if (
            $rental['rental_status'] !== 'Rented' ||
            $booking['fulfillment_status'] !== 'Complete' ||
            $rental['payment_status'] !== 'Paid' ||
            strtotime($rental['end_time']) <= time()
        ) {
            $pdo->rollBack();

            json_error('RENTAL_NOT_ACTIVE', 'Rental sudah tidak aktif atau jatuh tempo', 409);
        }

        $days = (int) $extension['extra_days'];
        $currentDays = intdiv(
            (int) $rental['duration_hours'],
            24
        );

        if ($currentDays + $days > 7) {
            $pdo->rollBack();

            json_error('MAX_DURATION_EXCEEDED', 'Total rental maksimal 7 hari', 409);
        }

        $oldEnd = new DateTimeImmutable(
            $rental['end_time']
        );

        $newEnd = $oldEnd->modify("+{$days} days");

        // Pemeriksaan jadwal ulang.
        $conflict = $pdo->prepare(
            "SELECT EXISTS (
                SELECT 1
                FROM rental r
                JOIN booking b ON b.booking_id = r.booking_id
                JOIN payment p ON p.booking_id = b.booking_id
                WHERE r.device_id = :device_id
                  AND r.rental_id <> :rental_id
                  AND r.rental_status IN (
                      'Booked', 'Rented', 'Overdue'
                  )
                  AND p.payment_status IN (
                      'Pending',
                      'AwaitingVerification',
                      'Paid'
                  )
                  AND (
                      p.payment_status <> 'Pending'
                      OR p.payment_expires_at IS NULL
                      OR p.payment_expires_at > clock_timestamp()
                  )
                  AND r.start_time < CAST(:new_end AS TIMESTAMPTZ)
                  AND r.end_time > CAST(:old_end AS TIMESTAMPTZ)
            )"
        );

        $conflict->execute([
            'device_id' => (int) $rental['device_id'],
            'rental_id' => (int) $rental['rental_id'],
            'old_end' => $oldEnd->format(DATE_ATOM),
            'new_end' => $newEnd->format(DATE_ATOM)
        ]);

        if ($conflict->fetchColumn()) {
            $pdo->rollBack();
            json_error('SLOT_TAKEN', 'Jadwal perangkat berbenturan dengan rental lain', 409);
        }

        // Pastikan file benar-benar tersedia di private bucket.
        $proofPath = (string) $extension['payment_proof_url'];

        $expectedPath = '~^extensions/' . $extensionId .
            '/[a-f0-9]{32}\.(jpg|png)$~D';

        if (!preg_match($expectedPath, $proofPath)) {
            $pdo->rollBack();
            json_error('INVALID_PAYMENT_PROOF', 'Path bukti pembayaran tidak valid', 409);
        }

        // Mengunduh file saja tidak membuktikan pembayaran.
        // Admin wajib meninjau bukti sebelum approve di UI.
        storage_download(storage_payment_bucket(), $proofPath);

        $stmt = $pdo->prepare(
            "UPDATE rental
             SET duration_hours =
                    duration_hours + CAST(:hours AS INTEGER),
                 end_time =
                    end_time + (
                        CAST(:days AS INTEGER) * INTERVAL '1 day'
                    ),
                 total_cost =
                    total_cost + (
                        price_per_day * CAST(:charge_days AS INTEGER)
                    )
             WHERE rental_id = :rental_id
               AND rental_status = 'Rented'
               AND end_time > clock_timestamp()
               AND duration_hours +
                    CAST(:limit_hours AS INTEGER) <= 168"
        );

        $stmt->execute([
            'hours' => $days * 24,
            'days' => $days,
            'charge_days' => $days,
            'rental_id' => (int) $rental['rental_id'],
            'limit_hours' => $days * 24
        ]);

        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();

            json_error(
                'RENTAL_CHANGED',
                'Rental berubah sebelum persetujuan selesai',
                409
            );
        }

        $result['new_end_time'] = $newEnd->format(DATE_ATOM);
        $result['extra_days'] = $days;
        $result['additional_cost'] =
            (float) $rental['price_per_day'] * $days;
    }

    // Berlaku untuk approve maupun reject.
    $nextStatus = $action === 'approve'
        ? 'Approved'
        : 'Rejected';

    $stmt = $pdo->prepare(
        "UPDATE rental_extension
         SET status = :status,
             reviewed_at = clock_timestamp(),
             reviewed_by = :admin_id
         WHERE extension_id = :id
           AND status = 'AwaitingVerification'"
    );

    $stmt->execute([
        'status' => $nextStatus,
        'admin_id' => (int) $auth['user_id'],
        'id' => $extensionId
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Gagal menyimpan keputusan admin'
        );
    }

    $pdo->commit();

    json_response([
        'success' => true,
        'data' => array_merge($result, [
            'extension_status' => $nextStatus,
            'reviewed_by' => (int) $auth['user_id']
        ])
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Admin Extension Decision Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Keputusan Extend gagal diproses', 500);
}
