
<?php

/**
 * NovaPS - Customer Registration
 *
 * POST multipart/form-data
 * Menyimpan akun di PostgreSQL dan dokumen
 * identitas di private Supabase Storage.
 */

require __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/storage.php';

header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan method POST', 405);
}

/* ---------- Request validation ---------- */

$fullName = $_POST['full_name'] ?? null;
$rawPhone = $_POST['phone'] ?? null;
$rawEmail = $_POST['email'] ?? null;
$address = $_POST['address'] ?? null;
$password = $_POST['password'] ?? null;
$docType = $_POST['identity_doc_type'] ?? null;

if (
    !is_string($fullName) ||
    !is_string($rawPhone) ||
    !is_string($rawEmail) ||
    !is_string($address) ||
    !is_string($password) ||
    !is_string($docType)
) {
    json_error('VALIDATION_ERROR', 'Semua data registrasi wajib diisi', 422);
}

$fullName = trim($fullName);
$phone = normalize_phone($rawPhone);
$email = strtolower(trim($rawEmail));
$address = trim($address);
$docType = trim($docType);

if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100
) {
    json_error('VALIDATION_ERROR', 'Nama harus terdiri dari 2 sampai 100 karakter', 422);
}

if (!preg_match('/^08\d{8,13}$/', $phone)) {
    json_error('VALIDATION_ERROR', 'Nomor HP tidak valid', 422);
}

if (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('VALIDATION_ERROR', 'Email tidak valid', 422);
}

if (mb_strlen($address) < 1 || mb_strlen($address) > 255) {
    json_error('VALIDATION_ERROR', 'Alamat wajib diisi, maksimal 255 karakter', 422);
}

if (strlen($password) < 8 || strlen($password) > 72 || !preg_match('/\d/', $password)) {
    json_error('VALIDATION_ERROR', 'Password harus 8-72 byte dan mengandung angka', 422);
}

$allowedDocTypes = [
    'KTP',
    'SIM',
    'KTM',
    'Kartu Pelajar'
];

if (!in_array($docType, $allowedDocTypes, true)) {
    json_error('VALIDATION_ERROR', 'Jenis dokumen identitas tidak valid', 422);
}

/* ---------- File validation ---------- */

$file = $_FILES['identity_doc'] ?? null;

if (!is_array($file)) {
    json_error('IDENTITY_DOC_REQUIRED', 'Foto identitas wajib diunggah', 422);
}

if (isset($file['error']) && in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
    json_error('FILE_TOO_LARGE', 'Ukuran foto maksimal 2 MB', 413);
}

if (($file['error'] ?? null) !== UPLOAD_ERR_OK) {
    json_error('UPLOAD_ERROR', 'Gagal menerima foto identitas', 422);
}

$tmpPath = $file['tmp_name'] ?? null;
$fileSize = $file['size'] ?? null;

if (!is_string($tmpPath) || !is_uploaded_file($tmpPath) || !is_int($fileSize) || $fileSize < 1) {
    json_error('VALIDATION_ERROR', 'File identitas tidak valid', 422);
}

if ($fileSize > STORAGE_MAX_FILE_SIZE) {
    json_error('FILE_TOO_LARGE', 'Ukuran foto maksimal 2 MB', 413);
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);

$extensions = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png'
];

$extension = $extensions[$mime] ?? null;

if ($extension === null) {
    json_error('INVALID_FILE_TYPE', 'Foto identitas harus berupa JPG atau PNG', 422);
}

// Pastikan file memang dapat dibaca sebagai gambar.
$imageInfo = @getimagesize($tmpPath);

if ($imageInfo === false || ($imageInfo['mime'] ?? null) !== $mime) {
    json_error('INVALID_IMAGE', 'File gambar tidak valid atau rusak', 422
    );
}

/* ---------- Registration ---------- */

$uploadedPath = null;
$uploadAttempted = false;
$committed = false;
$stage = 'database';

try {
    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    $pdo->beginTransaction();

    // Buat akun terlebih dahulu agar memperoleh user_id.
    // Jika email/HP duplikat, transaksi akan gagal
    // sebelum melakukan upload ke Supabase Storage.
    $stmt = $pdo->prepare(
        "INSERT INTO users (
            role,
            full_name,
            phone,
            email,
            address,
            password_hash,
            identity_doc_type,
            identity_doc_verified
        )
        VALUES (
            'customer',
            :full_name,
            :phone,
            :email,
            :address,
            :password_hash,
            :identity_doc_type,
            FALSE
        )
        RETURNING
            user_id,
            full_name,
            phone,
            email"
    );

    $stmt->execute([
        'full_name' => $fullName,
        'phone' => $phone,
        'email' => $email,
        'address' => $address,
        'password_hash' => $passwordHash,
        'identity_doc_type' => $docType
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException('Gagal mengambil akun baru');
    }

    $userId = (int) $user['user_id'];

    // Nama unik, sesuai pola yang sudah digunakan
    // oleh endpoint My Account.
    $uploadedPath = storage_new_object_path('customers/' . $userId, $extension);

    // Upload dokumen ke bucket private.
    $stage = 'storage';
    $uploadAttempted = true;

    storage_upload(storage_identity_bucket(), $uploadedPath, $tmpPath, $mime);

    // Simpan object path, bukan public URL.
    $stage = 'database';

    $update = $pdo->prepare(
        "UPDATE users
         SET identity_doc_url = :identity_doc_url
         WHERE user_id = :user_id
           AND role = 'customer'"
    );

    $update->execute([
        'identity_doc_url' => $uploadedPath,
        'user_id' => $userId
    ]);

    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Gagal menyimpan lokasi foto identitas');
    }

    $pdo->commit();
    $committed = true;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Kompensasi ketika upload berhasil tetapi
    // transaksi database gagal.
    // Penghapusan juga dicoba jika hasil upload
    // tidak pasti, karena path-nya dibuat unik.
    if (!$committed && $uploadAttempted && $uploadedPath !== null) {
        try {
            storage_delete(
                storage_identity_bucket(),
                $uploadedPath
            );
        } catch (Throwable $cleanupError) {
            error_log('Register Storage Cleanup Error: ' . $cleanupError->getMessage());
        }
    }

    if ($e instanceof PDOException && $e->getCode() === '23505') {
        json_error('DUPLICATE_ACCOUNT', 'Email atau nomor HP sudah terdaftar', 409);
    }

    error_log('Customer Registration Error [' . $stage . ']: ' . $e->getMessage());

    if ($stage === 'storage') {
        json_error('STORAGE_ERROR', 'Gagal menyimpan foto identitas. Silakan coba lagi.', 502
        );
    }

    json_error('SERVER_ERROR', 'Registrasi gagal diproses', 500);
}

/* ---------- Success response ---------- */

// Tidak otomatis login.
// Customer tetap masuk lewat auth/login.php.
json_response([
    'success' => true,
    'message' => 'Akun berhasil dibuat',
    'data' => [
        'user_id' => $userId,
        'full_name' => $user['full_name'],
        'phone' => $user['phone'],
        'email' => $user['email'],
        'identity_doc_type' => $docType,
        'identity_doc_uploaded' => true,
        'identity_doc_verified' => false
    ]
], 201);
