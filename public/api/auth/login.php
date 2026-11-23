<?php

require __DIR__ . '/../../../app/includes/bootstrap.php';

// 1. Hanya menerima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan method POST', 405);
}

// 2. Baca json body
$input = read_json_body();


$identifier = $input['identifier'] ?? null;
$password = $input['password'] ?? null;

if (
    !is_string($identifier) ||
    !is_string($password)
) {
    json_error(
        'VALIDATION_ERROR',
        'Email/nomor HP dan password wajib diisi',
        422
    );
}

$identifier = trim($identifier);
$passLen = strlen($password);

if (
    $identifier === '' ||
    $passLen < 1 ||
    $passLen > 200
) {
    json_error(
        'VALIDATION_ERROR',
        'Email/nomor HP atau password tidak valid',
        422
    );
}

$isEmail = str_contains($identifier, '@');

if ($isEmail) {
    $email = strtolower($identifier);

    if (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('VALIDATION_ERROR', 'Format email tidak valid', 422);
    }
} else {
    $phone = normalize_phone($identifier);

    if (!preg_match('/^08\d{8,13}$/', $phone)) {
        json_error('VALIDATION_ERROR', 'Format nomor HP tidak valid', 422);
    }
}

try {
    $stmt = $pdo->prepare(
        "SELECT
            user_id,
            role,
            full_name,
            phone,
            email,
            password_hash,
            address,
            identity_doc_type,
            identity_doc_url,
            identity_doc_verified
         FROM users
         WHERE role = 'customer'
           AND " . (
               $isEmail
                   ? 'lower(email) = :identifier'
                   : 'phone = :identifier'
           ) . "
         LIMIT 1"
    );

    $stmt->execute([
        'identifier' => $isEmail ? $email : $phone
    ]);

    $user = $stmt->fetch();


    // Dummy hash agar verifikasi tetap dilakukan ketika user tidak ditemukan
    $dummyHash = '$2y$12$Fctyqx33xXy4/RRTTRkdheqD22IqSznpEjJTtySlLmZLGBOJG7oZC';

    $hashToVerify = ($user && !empty($user['password_hash']) ? $user['password_hash'] : $dummyHash);
    $passwordValid = password_verify($password, $hashToVerify);

    if (!$user || empty($user['password_hash']) || !$passwordValid) {
        json_error('INVALID_CREDENTIALS', 'Email/nomor HP atau password salah', 401);
    }

    // 6.Regenerasi ID session
    session_regenerate_id(true);

    // 7. Simpan data autentikasi
    $authData = [
        'user_id' => (int) $user['user_id'],
        'full_name' => $user['full_name'],
        'role' => 'customer'
    ];

    $_SESSION['auth'] = $authData;

    // 8. Data tambahan frontend
    $profileComplete = !empty($user['phone']) && !empty($user['address']);
    $identityVerified = filter_var($user['identity_doc_verified'], FILTER_VALIDATE_BOOLEAN);

    // 9. Respons sukses tanpa password_hash
    json_response([
        'success' => true,
        'data' => [
            'user_id' => (int) $user['user_id'],
            'full_name' => $user['full_name'],
            'role' => 'customer',
            'phone' => $user['phone'],
            'email' => $user['email'],
            'address' => $user['address'],
            'profile_complete' => $profileComplete,
            'identity_doc_verified' => $identityVerified
        ]
    ]);
} catch (PDOException $e) {
    error_log('Customer Login API Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Terjadi kesalahan internal pada server', 500);
}