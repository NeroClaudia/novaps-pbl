<?php

require __DIR__ . '/../../../includes/bootstrap.php';

header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan method POST', 405);
}

$input = read_json_body();

$fullname = $input['full_name'] ?? null;
$rawPhone = $input['phone'] ?? null;
$rawEmail = $input['email'] ?? null;
$address = $input['address'] ?? null;
$password = $input['password'] ?? null;

if (!is_string($fullname) || !is_string($rawPhone) || !is_string($rawEmail) || !is_string($address) || !is_string($password)) {
    json_error('VALIDATION_ERROR', 'Semua field registrasi wajib berupa teks', 422);
}

$fullname = trim($fullname);
$phone = normalize_phone($rawPhone);
$email = strtolower($rawEmail);
$address = trim($address);

if (mb_strlen($fullname) < 2 || mb_strlen($fullname) > 100) {
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

try {
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
            "INSERT INTO users (
            role,
            full_name,
            phone,
            email,
            address,
            password_hash,
            identity_doc_verified
        )
        VALUES (
            'customer',
            :full_name,
            :phone,
            :email,
            :address,
            :password_hash,
            FALSE
        )
        RETURNING user_id, full_name, phone, email");
    
    $stmt->execute([
        'full_name' => $fullname,
        'phone' => $phone,
        'email' => $email,
        'address' => $address,
        'password_hash' => $passwordHash,
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    json_response([
        'success' => true,
        'message' => 'Akun berhasil dibuat',
        'data' => [
            'user_id' => (int) $user['user_id'],
            'full_name' => $user['full_name'],
            'phone' => $user['phone'],
            'email' => $user['email']
        ]
    ], 201);

} catch (PDOException $e) {
    if ($e->getCode() === '23505') {
        json_error('DUPLICATE_ACCOUNT', 'Email atau nomor HP sudah terdaftar', 409);
    }

    error_log('Customer Register Error: ' . $e->getMessage());

    json_error('SERVER_ERROR', 'Registrasi gagal diproses', 500);
}
