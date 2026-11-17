<?php

require __DIR__ . '/../../../app/includes/bootstrap.php';
require_once __DIR__ . '/../../../app/includes/storage.php';

header('Cache-Control: private, no-store');

$auth = require_login(['customer']);
$customerId = (int) ($auth['user_id'] ?? 0);

if ($customerId < 1) {
    json_error('UNAUTHORIZED', 'Sesi tidak valid', 401);
}

function customerProfile(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT user_id, full_name, phone, email, address,
                identity_doc_type, identity_doc_url,
                identity_doc_verified
         FROM users
         WHERE user_id = :id AND role = 'customer'"
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        json_error('NOT_FOUND', 'Profil tidak ditemukan', 404);
    }

    $verified = in_array(
        $user['identity_doc_verified'],
        [true, 1, '1', 't', 'true'],
        true
    );

    return [
        'user_id' => (int) $user['user_id'],
        'full_name' => $user['full_name'],
        'phone' => $user['phone'],
        'email' => $user['email'],
        'address' => $user['address'],
        'identity_doc_type' => $user['identity_doc_type'],
        'identity_doc_uploaded' => !empty($user['identity_doc_url']),
        'identity_doc_verified' => $verified
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

 // Membaca foto identitas melalui private Supabase Storage.
if ($method === 'GET' && ($_GET['image'] ?? '') === '1') {
    try {
        $stmt = $pdo->prepare(
            "SELECT identity_doc_url
             FROM users
             WHERE user_id = :id AND role = 'customer'"
        );
        $stmt->execute(['id' => $customerId]);

        $objectPath = $stmt->fetchColumn();

        if (!$objectPath) {
            json_error(
                'NOT_FOUND',
                'Foto identitas belum tersedia',
                404
            );
        }

        // Pastikan customer hanya dapat mengambil
        // dokumen yang berada dalam folder miliknya.
        $expectedPattern =
            '~^customers/' . $customerId .
            '/[a-f0-9]{32}\.(jpg|png)$~D';

        if (!preg_match($expectedPattern, $objectPath)) {
            json_error(
                'NOT_FOUND',
                'Foto lama belum tersedia di Supabase Storage. Silakan unggah ulang.',
                404
            );
        }

        $image = storage_download(
            storage_identity_bucket(),
            $objectPath
        );

        session_write_close();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $image['mime']);
        header('Content-Length: ' . strlen($image['bytes']));
        header('Content-Disposition: inline');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');

        echo $image['bytes'];
        exit;

    } catch (Throwable $e) {
        error_log(
            'Identity Storage Download Error: ' .
            $e->getMessage()
        );

        json_error(
            'SERVER_ERROR',
            'Gagal membaca foto identitas dari Storage',
            502
        );
    }
}


// Ambil profil langsung dari PostgreSQL.
if ($method === 'GET') {
    try {
        json_response([
            'success' => true,
            'data' => customerProfile($pdo, $customerId)
        ]);
    } catch (PDOException $e) {
        error_log('Profile GET Error: ' . $e->getMessage());
        json_error('SERVER_ERROR', 'Gagal memuat profil', 500);
    }
}

if ($method !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan GET atau POST', 405);
}

// POST menggunakan multipart/form-data.
$name = $_POST['full_name'] ?? null;
$phone = $_POST['phone'] ?? null;
$email = $_POST['email'] ?? null;
$address = $_POST['address'] ?? null;
$docType = $_POST['identity_doc_type'] ?? '';

if (
    !is_string($name) ||
    !is_string($phone) ||
    !is_string($email) ||
    !is_string($address) ||
    !is_string($docType)
) {
    json_error('VALIDATION_ERROR', 'Data profil tidak valid', 422);
}

$name = trim($name);
$phone = normalize_phone($phone);
$email = trim($email);
$address = trim($address);
$docType = trim($docType);

if (
    mb_strlen($name) < 1 ||
    mb_strlen($name) > 100 ||
    !preg_match('/^08\d{8,13}$/', $phone) ||
    mb_strlen($address) < 1 ||
    mb_strlen($address) > 255
) {
    json_error('VALIDATION_ERROR', 'Nama, telepon, atau alamat tidak valid', 422);
}

if (
    strlen($email) > 100 ||
    ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
) {
    json_error('VALIDATION_ERROR', 'Email tidak valid', 422);
}

$allowedTypes = ['Kartu Pelajar', 'KTP', 'SIM', 'KTM'];

if ($docType !== '' && !in_array($docType, $allowedTypes, true)) {
    json_error('VALIDATION_ERROR', 'Jenis identitas tidak valid', 422);
}

$file = $_FILES['identity_doc'] ?? null;
$hasFile = is_array($file) &&
    ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if ($hasFile && ($file['error'] ?? null) !== UPLOAD_ERR_OK) {
    $error = $file['error'];
    json_error(
        in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'FILE_TOO_LARGE' : 'UPLOAD_ERROR',
        'Gagal menerima file identitas',
        in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 413 : 422
    );
}

$extension = null;

if ($hasFile) {
    if (
        !is_string($file['tmp_name'] ?? null) ||
        !is_uploaded_file($file['tmp_name']) ||
        ($file['size'] ?? 0) < 1
    ) {
        json_error('VALIDATION_ERROR', 'File identitas tidak valid', 422);
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        json_error('FILE_TOO_LARGE', 'Maksimal 2 MB', 413);
    }

    if ($docType === '') {
        json_error(
            'VALIDATION_ERROR',
            'Pilih jenis dokumen identitas',
            422
        );
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extension = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png'
    ][$mime] ?? null;

    if (!$extension) {
        json_error(
            'VALIDATION_ERROR',
            'Foto identitas harus JPG atau PNG',
            422
        );
    }
}


$uploadedObjectPath = null;
$oldObjectPath = null;
$responseProfile = null;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT identity_doc_url, identity_doc_type
         FROM users
         WHERE user_id = :id AND role = 'customer'
         FOR UPDATE"
    );

    $stmt->execute(['id' => $customerId]);
    $old = $stmt->fetch();

    if (!$old) {
        $pdo->rollBack();
        json_error('NOT_FOUND', 'Customer tidak ditemukan', 404);
    }

    $oldObjectPath = $old['identity_doc_url'];
    $objectPath = $oldObjectPath;
    $verified = null;

    if ($hasFile) {
        // Nama objek berbeda untuk setiap upload.
        $uploadedObjectPath = storage_new_object_path('customers/' . $customerId, $extension);

        // Upload ke bucket private.
        storage_upload(storage_identity_bucket(), $uploadedObjectPath, $file['tmp_name'], $mime);

        // Simpan path baru hanya setelah upload berhasil.
        $objectPath = $uploadedObjectPath;

        // Dokumen baru harus diverifikasi ulang.
        $verified = false;

    } elseif ($docType !== '' && $docType !== $old['identity_doc_type']) {
        $verified = false;
    }

    $update = $pdo->prepare(
        "UPDATE users
         SET full_name = :name,
             phone = :phone,
             email = :email,
             address = :address,
             identity_doc_type = :doc_type,
             identity_doc_url = :doc_url,
             identity_doc_verified =
                 COALESCE(:verified, identity_doc_verified)
         WHERE user_id = :id
           AND role = 'customer'"
    );

    $update->bindValue(':name', $name);
    $update->bindValue(':phone', $phone);
    $update->bindValue(
        ':email',
        $email === '' ? null : $email
    );
    $update->bindValue(':address', $address);
    $update->bindValue(
        ':doc_type',
        $docType !== ''
            ? $docType
            : ($old['identity_doc_type'] ?: null)
    );
    $update->bindValue(':doc_url', $objectPath);
    $update->bindValue(
        ':verified',
        $verified,
        $verified === null
            ? PDO::PARAM_NULL
            : PDO::PARAM_BOOL
    );
    $update->bindValue(
        ':id',
        $customerId,
        PDO::PARAM_INT
    );

    $update->execute();

    $responseProfile = customerProfile(
        $pdo,
        $customerId
    );

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Kompensasi jika upload sukses tetapi database gagal.
    if ($uploadedObjectPath !== null) {
        try {
            storage_delete(
                storage_identity_bucket(),
                $uploadedObjectPath
            );
        } catch (Throwable $cleanupError) {
            error_log('Identity Storage Cleanup Error: ' . $cleanupError->getMessage());
        }
    }

    if ($e instanceof PDOException && $e->getCode() === '23505') {
        json_error('DUPLICATE_PROFILE', 'Nomor HP atau email sudah digunakan', 409);
    }

    error_log('Profile Storage Update Error: ' . $e->getMessage());

    json_error('SERVER_ERROR', 'Gagal menyimpan profil customer', 500);
}

// Database sudah berhasil disimpan pada titik ini.
$_SESSION['auth']['full_name'] = $name;

// Hapus file Storage lama setelah commit.
// File dari filesystem lokal tidak disentuh.
if (
    $uploadedObjectPath !== null &&
    is_string($oldObjectPath) &&
    $oldObjectPath !== $uploadedObjectPath &&
    preg_match(
        '~^customers/' . $customerId .
        '/[a-f0-9]{32}\.(jpg|png)$~D',
        $oldObjectPath
    )
) {
    try {
        storage_delete(storage_identity_bucket(), $oldObjectPath);
    } catch (Throwable $e) {
        // Gagal menghapus objek lama tidak membatalkan
        // update profil yang sudah berhasil.
        error_log('Old Identity Cleanup Error: ' . $e->getMessage());
    }
}

json_response([
    'success' => true,
    'data' => $responseProfile
]);
