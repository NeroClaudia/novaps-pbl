<?php
declare(strict_types=1);
/**
 * Supabase Storage helper untuk NovaPS.
 *
 * Gunakan setelah includes/bootstrap.php.
 * Semua bucket harus private.
 * Jangan pernah mengirim secret key ke frontend.
 */

const STORAGE_MAX_FILE_SIZE = 2 * 1024 * 1024;

/**
 * Membaca konfigurasi dari Railway atau .env lokal.
 */
function storage_config(string $name): string {
    $value = $_ENV[$name] ?? getenv($name);

    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException("Konfigurasi Storage belum tersedia: {$name}");
    }

    return trim($value);
}

/**
 * Mendapatkan nama bucket dari konfigurasi.
 */
function storage_identity_bucket(): string {
    return storage_config('SUPABASE_IDENTITY_BUCKET');
}

function storage_payment_bucket(): string {
    return storage_config('SUPABASE_PAYMENT_BUCKET');
}

/**
 * Memastikan URL project valid dan menggunakan HTTPS.
 */
function storage_base_url(): string {
    $url = rtrim(storage_config('SUPABASE_URL'), '/');
    $parts = parse_url($url);

    $scheme = $parts['scheme'] ?? '';
    $host = $parts['host'] ?? '';

    if (
        $scheme !== 'https' ||
        !preg_match('/^[a-z0-9-]+\.supabase\.co$/i', $host) ||
        isset($parts['user']) ||
        isset($parts['pass']) ||
        isset($parts['query']) ||
        isset($parts['fragment']) ||
        isset($parts['port']) ||
        !in_array($parts['path'] ?? '', ['', '/'], true)
    ) {
        throw new RuntimeException('SUPABASE_URL tidak valid.');
    }

    return $url;
}

/**
 * Memvalidasi nama bucket dan object path.
 * Path tidak boleh mengandung ../ atau karakter URL berbahaya.
 */
function storage_validate_path(string $bucket, string $objectPath): void {
    $allowedBuckets = [
        storage_identity_bucket(),
        storage_payment_bucket()
    ];

    if (!in_array($bucket, $allowedBuckets, true)) {
        throw new InvalidArgumentException('Bucket Storage tidak diizinkan.');
    }

    if (strlen($objectPath) > 500 || !preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_.-]+)*$~D', $objectPath)) {
        throw new InvalidArgumentException('Object path tidak valid.');
    }

    foreach (explode('/', $objectPath) as $segment) {
        if ($segment === '.' || $segment === '..') {
            throw new InvalidArgumentException('Object path tidak aman.');
        }
    }
}

/**
 * Membentuk URL object Storage.
 */
function storage_object_url(string $bucket, string $objectPath, bool $authenticated = false): string {
    storage_validate_path($bucket, $objectPath);

    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $objectPath)));

    $prefix = $authenticated ? '/storage/v1/object/authenticated/' : '/storage/v1/object/';

    return storage_base_url()
        . $prefix
        . rawurlencode($bucket)
        . '/'
        . $encodedPath;
}

/**
 * Request HTTP internal ke Supabase Storage.
 *
 * Secret key hanya digunakan di server.
 * Output dibatasi agar file besar tidak memenuhi memory PHP.
 */
function storage_request(string $method, string $url, ?string $body = null, ?string $contentType = null): array {
    $secret = storage_config('SUPABASE_SECRET_KEY');

    $headers = [
        'apikey: ' . $secret,
    ];

    if ($contentType !== null) {
        $headers[] = 'Content-Type: ' . $contentType;
    }

    if ($method === 'POST') {
        $headers[] = 'x-upsert: false';
    }

    $responseBody = '';
    $maxResponseBytes = STORAGE_MAX_FILE_SIZE + 65536;

    $curl = curl_init($url);

    if ($curl === false) {
        throw new RuntimeException('Gagal membuat koneksi Storage.');
    }

    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => function ($handle,string $chunk) use (&$responseBody, $maxResponseBytes): int {
            if (strlen($responseBody) + strlen($chunk) > $maxResponseBytes) {
                return 0;
            }

            $responseBody .= $chunk;
            return strlen($chunk);
        },
    ]);

    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $success = curl_exec($curl);
    $status = (int) curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );
    $error = curl_error($curl);

    curl_close($curl);

    if ($success === false) {
        throw new RuntimeException('Koneksi Supabase Storage gagal: ' . $error);
    }

    return [
        'status' => $status,
        'body' => $responseBody
    ];
}

/**
 * Upload file JPG/PNG ke private bucket.
 *
 * Parameter:
 * - bucket: identity-documents / payment-proofs
 * - objectPath: path unik di bucket
 * - sourceFile: path temporary file PHP
 * - mime: image/jpeg atau image/png
 */
function storage_upload(string $bucket, string $objectPath, string $sourceFile, string $mime): void {
    storage_validate_path($bucket, $objectPath);

    if (!is_file($sourceFile) || !is_readable($sourceFile)) {
        throw new RuntimeException('File upload tidak ditemukan.');
    }

    $size = filesize($sourceFile);

    if ($size === false || $size < 1 || $size > STORAGE_MAX_FILE_SIZE) {
        throw new RuntimeException('Ukuran file tidak valid atau melebihi 2 MB.');
    }

    $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($sourceFile);

    $extensions = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png'
    ];

    if (!isset($extensions[$mime]) || $detectedMime !== $mime || !str_ends_with(strtolower($objectPath), $extensions[$mime])) {
        throw new RuntimeException('File harus JPG atau PNG yang valid.');
    }

    $bytes = file_get_contents($sourceFile);

    if ($bytes === false) {
        throw new RuntimeException('Gagal membaca file upload.');
    }

    $response = storage_request(
        'POST',
        storage_object_url($bucket, $objectPath),
        $bytes,
        $mime
    );

    if (!in_array($response['status'], [200, 201], true)) {
        error_log('Storage upload failed. HTTP ' . $response['status']);

        throw new RuntimeException('Gagal menyimpan file ke Supabase Storage.');
    }
}

/**
 * Membaca file dari private bucket.
 *
 * Pemanggil wajib memverifikasi PHP session
 * dan kepemilikan file sebelum memanggil fungsi ini.
 */
function storage_download(string $bucket, string $objectPath): array {
    $response = storage_request(
        'GET',
        storage_object_url($bucket, $objectPath, true)
    );

    if ($response['status'] !== 200) {
        error_log('Storage download failed. HTTP ' . $response['status']);

        throw new RuntimeException('File Storage tidak dapat dibaca.');
    }

    $bytes = $response['body'];

    if (strlen($bytes) < 1 || strlen($bytes) > STORAGE_MAX_FILE_SIZE) {
        throw new RuntimeException('Ukuran file Storage tidak valid.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        throw new RuntimeException('Format file Storage tidak didukung.');
    }

    return [
        'bytes' => $bytes,
        'mime' => $mime
    ];
}

/**
 * Menghapus file dari private bucket.
 *
 * Hanya panggil setelah otorisasi berhasil.
 * Status 404 dianggap sukses karena file sudah tidak ada.
 */
function storage_delete(string $bucket, string $objectPath): void {
    $response = storage_request(
        'DELETE',
        storage_object_url($bucket, $objectPath)
    );

    if (!in_array($response['status'], [200, 204, 404], true)) {
        error_log('Storage delete failed. HTTP ' . $response['status']);

        throw new RuntimeException('Gagal menghapus file dari Supabase Storage.');
    }
}

/**
 * Menghasilkan nama objek baru untuk mencegah
 * tabrakan nama file dan penimpaan dokumen.
 */
function storage_new_object_path(string $folder, string $extension): string {
    if (!preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $folder) || !in_array($extension, ['jpg', 'png'], true)) {
        throw new InvalidArgumentException('Folder atau ekstensi tidak valid.');
    }

    return $folder
        . '/'
        . bin2hex(random_bytes(16))
        . '.'
        . $extension;
}
