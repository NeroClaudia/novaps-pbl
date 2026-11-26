<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../../app/includes/storage.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan metode POST', 405);
}

$auth = require_login(['customer']);
$customerId = (int) ($auth['user_id'] ?? 0);

if ($customerId < 1) {
    json_error('UNAUTHORIZED', 'Sesi tidak valid', 401);
}

// Tolak request lintas origin dari browser.
$fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';

if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    json_error('FORBIDDEN', 'Origin tidak diizinkan', 403);
}

$rawId = $_POST['extension_id'] ?? null;

$extensionId = is_string($rawId)
    ? filter_var(
        $rawId,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    )
    : false;

if ($extensionId === false) {
    json_error('VALIDATION_ERROR', 'extension_id tidak valid', 422);
}

// Validasi file.
$file = $_FILES['payment_proof'] ?? null;
$error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
$maxBytes = 2 * 1024 * 1024;

if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
    json_error('FILE_TOO_LARGE', 'Ukuran maksimal 2 MB', 413);
}

if (in_array(
    $error,
    [
        UPLOAD_ERR_NO_TMP_DIR,
        UPLOAD_ERR_CANT_WRITE,
        UPLOAD_ERR_EXTENSION
    ],
    true
)) {
    error_log('Extension upload error: ' . $error);
    json_error('SERVER_ERROR', 'Upload gagal', 500);
}

$tmpPath = $file['tmp_name'] ?? null;
$size = $file['size'] ?? null;

if (
    $error !== UPLOAD_ERR_OK ||
    !is_string($tmpPath) ||
    !is_int($size) ||
    $size < 1 ||
    !is_uploaded_file($tmpPath)
) {
    json_error('VALIDATION_ERROR', 'Pilih satu bukti pembayaran yang valid',422);
}

if ($size > $maxBytes) {
    json_error('FILE_TOO_LARGE', 'Ukuran maksimal 2 MB',413);
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);

$allowedMime = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png'
];

if (!isset($allowedMime[$mime])) {
    json_error('INVALID_FILE_TYPE', 'Bukti pembayaran harus JPG atau PNG', 422);
}

$imageInfo = @getimagesize($tmpPath);

if ($imageInfo === false || ($imageInfo['mime'] ?? null) !== $mime) {
    json_error('INVALID_IMAGE', 'File bukan gambar yang valid',422);
}

$uploadedPath = null;
$committed = false;
$commitAttempted = false;

try {
    $pdo->beginTransaction();

    // Kunci pengajuan dan validasi pemiliknya.
    $stmt = $pdo->prepare(
        "SELECT
            e.extension_id,
            e.status,
            e.payment_proof_url,
            e.created_at,
            r.rental_id,
            r.rental_status,
            r.end_time,
            b.fulfillment_status,
            p.payment_status AS original_payment_status
         FROM rental_extension e
         JOIN v_rental r ON r.rental_id = e.rental_id
         JOIN booking b ON b.booking_id = r.booking_id
         JOIN payment p ON p.booking_id = b.booking_id
         WHERE e.extension_id = :extension_id
           AND b.customer_id = :customer_id
         FOR UPDATE OF e"
    );

    $stmt->execute([
        'extension_id' => $extensionId,
        'customer_id' => $customerId
    ]);

    $extension = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$extension) {
        $pdo->rollBack();
        json_error('NOT_FOUND', 'Pengajuan Extend tidak ditemukan', 404);
    }

    if ($extension['status'] !== 'PendingPayment') {
        $pdo->rollBack();
        json_error('INVALID_STATUS_TRANSITION', 'Pengajuan tidak lagi menunggu pembayaran', 409);
    }

    // Deadline dihitung dari created_at, bukan kolom baru.
    $deadline = new DateTimeImmutable(
        $extension['created_at']
    );

    $deadline = $deadline->modify('+5 minutes');

    if ($deadline->getTimestamp() <= time()) {
        // Simpan perubahan Expired sebelum mengembalikan error.
        $expire = $pdo->prepare(
            "UPDATE rental_extension
             SET status = 'Expired'
             WHERE extension_id = :id
               AND status = 'PendingPayment'"
        );

        $expire->execute(['id' => $extensionId]);
        $pdo->commit();

        json_error('PAYMENT_EXPIRED', 'Batas pembayaran Extend sudah habis', 409);
    }

    if (
        $extension['rental_status'] !== 'Rented' ||
        $extension['fulfillment_status'] !== 'Complete' ||
        $extension['original_payment_status'] !== 'Paid' ||
        strtotime($extension['end_time']) <= time()
    ) {
        $pdo->rollBack();

        json_error('RENTAL_NOT_ACTIVE', 'Rental sudah tidak memenuhi syarat Extend',409);
    }

    if ($extension['payment_proof_url'] !== null) {
        $pdo->rollBack();

        json_error('PROOF_ALREADY_UPLOADED', 'Bukti pembayaran sudah pernah diunggah', 409);
    }

    // Simpan di bucket payment yang sudah tersedia.
    $uploadedPath = storage_new_object_path(
        'extensions/' . $extensionId,
        $allowedMime[$mime]
    );

    storage_upload(
        storage_payment_bucket(),
        $uploadedPath,
        $tmpPath,
        $mime
    );

    // Update hanya jika deadline masih berlaku.
    // clock_timestamp() membaca waktu aktual saat statement berjalan.
    $update = $pdo->prepare(
        "UPDATE rental_extension
         SET payment_proof_url = :proof,
             status = 'AwaitingVerification'
         WHERE extension_id = :id
           AND status = 'PendingPayment'
           AND payment_proof_url IS NULL
           AND created_at + INTERVAL '5 minutes'
               > clock_timestamp()"
    );

    $update->execute([
        'proof' => $uploadedPath,
        'id' => $extensionId
    ]);

    if ($update->rowCount() !== 1) {
        $pdo->rollBack();

        // File dihapus bila database tidak menerima upload.
        storage_delete(
            storage_payment_bucket(),
            $uploadedPath
        );

        $uploadedPath = null;

        json_error('PAYMENT_EXPIRED', 'Batas pembayaran sudah berakhir', 409);
    }

    $commitAttempted = true;
    $pdo->commit();
    $committed = true;

} catch (Throwable $e) {
    $rolledBack = false;

    if ($pdo->inTransaction()) {
        $rolledBack = $pdo->rollBack();
    }

    if (!$committed && (!$commitAttempted || $rolledBack) && $uploadedPath !== null) {
        try {
            storage_delete(
                storage_payment_bucket(),
                $uploadedPath
            );
        } catch (Throwable $cleanupError) {
            error_log(
                'Extension Storage Cleanup Error: ' .
                $cleanupError->getMessage()
            );
        }
    }

    error_log(
        'Extension Payment Proof Error: ' .
        $e->getMessage()
    );

    json_error('SERVER_ERROR', 'Gagal menyimpan bukti pembayaran Extend', 500);
}

json_response([
    'success' => true,
    'data' => [
        'extension_id' => $extensionId,
        'extension_status' => 'AwaitingVerification',
        'payment_proof_uploaded' => true
    ]
]);
