<?php

require __DIR__ . '/../../../app/includes/bootstrap.php';

header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan method POST', 405);
}

// memastikan tidak diproses sebagai request cross-site
$fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';

if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
    json_error('FORBIDDEN', 'Permintaan lintas origin ditolak', 403);
}

if ($fetchSite === '') {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($origin !== '') {
        json_error('FORBIDDEN', 'Origin tidak dapat diverifikasi', 403);
    }
}

//hapus data autentikasi dari session aktif
$_SESSION = [];

// Hapus cookie PHP session
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 3600,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?: 'Lax'
        ]
    );
}

// Hapus session pada server
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

json_response([
    'success' => true,
    'message' => 'Logout berhasil'
]);
