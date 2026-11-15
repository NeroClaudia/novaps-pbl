
<?php

require __DIR__ . '/../../../includes/bootstrap.php';
/** @var PDO $pdo */

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

// Membaca foto identitas milik customer yang sedang login.
if ($method === 'GET' && ($_GET['image'] ?? '') === '1') {
    try {
        $stmt = $pdo->prepare(
            "SELECT identity_doc_url
             FROM users
             WHERE user_id = :id AND role = 'customer'"
        );
        $stmt->execute(['id' => $customerId]);
        $relativePath = $stmt->fetchColumn();

        if (!$relativePath) {
            json_error('NOT_FOUND', 'Foto identitas belum tersedia', 404);
        }

        if (!preg_match(
            '~^identity_docs/[a-f0-9]{32}\.(jpg|png)$~D',
            $relativePath
        )) {
            json_error(
                'NOT_FOUND',
                'Format lokasi foto tidak didukung',
                404
            );
        }

        $directory = realpath(UPLOAD_DIR . '/identity_docs');
        $file = realpath(UPLOAD_DIR . '/' . $relativePath);

        if (!$directory || !$file || !is_file($file)) {
            json_error('NOT_FOUND', 'Foto tidak ditemukan', 404);
        }

        $parent = dirname($file);
        $inside = PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($parent, $directory) === 0
            : $parent === $directory;

        if (!$inside) {
            json_error('NOT_FOUND', 'Foto tidak ditemukan', 404);
        }

        $maxBytes = 2 * 1024 * 1024;
        $image = file_get_contents($file, false, null, 0, $maxBytes + 1);

        if ($image === false || strlen($image) > $maxBytes) {
            throw new RuntimeException('File gambar tidak valid');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($image);

        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw new RuntimeException('Format gambar tidak didukung');
        }


        session_write_close();

        // Bersihkan output buffer sebelum mengirim data biner.
        // Jangan lakukan ini jika ada output penting lain yang
        // memang harus dikirim.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($image));
        header('Content-Disposition: inline');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');

        echo $image;
        exit;
        
    } catch (Throwable $e) {
        error_log('Identity Image Error: ' . $e->getMessage());
        json_error('SERVER_ERROR', 'Gagal membaca gambar', 500);
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

$savedPath = null;

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

    $relativePath = $old['identity_doc_url'];
    $verified = null;

    if ($hasFile) {
        $directory = UPLOAD_DIR . '/identity_docs';

        if (
            !is_dir($directory) &&
            !mkdir($directory, 0700, true) &&
            !is_dir($directory)
        ) {
            throw new RuntimeException('Folder upload gagal dibuat');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $relativePath = 'identity_docs/' . $filename;
        $savedPath = $directory . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $savedPath)) {
            throw new RuntimeException('Gagal menyimpan foto identitas');
        }

        // Foto baru harus diverifikasi ulang oleh admin.
        $verified = false;
    } elseif ($docType !== '' && $docType !== $old['identity_doc_type']) {
        // Mengubah jenis identitas juga membatalkan verifikasi lama.
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
         WHERE user_id = :id AND role = 'customer'"
    );

    $update->bindValue(':name', $name);
    $update->bindValue(':phone', $phone);
    $update->bindValue(':email', $email === '' ? null : $email);
    $update->bindValue(':address', $address);
    $update->bindValue(
        ':doc_type',
        $docType !== '' ? $docType : ($old['identity_doc_type'] ?: null)
    );
    $update->bindValue(':doc_url', $relativePath);
    $update->bindValue(':verified', $verified, $verified === null
        ? PDO::PARAM_NULL : PDO::PARAM_BOOL);
    $update->bindValue(':id', $customerId, PDO::PARAM_INT);
    $update->execute();

    $pdo->commit();

    $_SESSION['auth']['full_name'] = $name;

    json_response([
        'success' => true,
        'data' => customerProfile($pdo, $customerId)
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    if ($savedPath && is_file($savedPath)) {
        @unlink($savedPath);
    }

    if ($e->getCode() === '23505') {
        json_error(
            'DUPLICATE_PROFILE',
            'Nomor HP atau email sudah digunakan',
            409
        );
    }

    error_log('Profile POST Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Gagal menyimpan profil', 500);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    if ($savedPath && is_file($savedPath)) {
        @unlink($savedPath);
    }

    error_log('Profile POST Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Gagal menyimpan profil', 500);
}
