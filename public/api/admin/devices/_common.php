<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../app/includes/bootstrap.php';

header('Cache-Control: private, no-store');

function admin_device_authorize(PDO $pdo): array
{
    $auth = require_login(['admin', 'owner']);

    $id = filter_var($auth['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($id === false || $id === null) json_error('UNAUTHORIZED', 'Session tidak valid', 401);

    $stmt = $pdo->prepare("SELECT role FROM users WHERE user_id=:id AND role IN ('admin','owner')");

    $stmt->execute(['id' => $id]);

    if ($stmt->fetchColumn() !== ($auth['role'] ?? null)) json_error('FORBIDDEN', 'Akun admin tidak valid', 403);

    return $auth;
}

function admin_device_require_csrf(): void
{
    $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';

    if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
        json_error('FORBIDDEN', 'Cross-site request ditolak', 403);
    }

    $expected = $_SESSION['admin_csrf'] ?? '';

    $supplied = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($expected) || strlen($expected) !== 64 || !is_string($supplied) || !hash_equals($expected, $supplied)) {
        json_error('FORBIDDEN', 'CSRF token tidak valid', 403);
    }
}


function admin_device_fields(array $input): array
{
    $name = $input['device_name'] ?? null;
    $category = $input['category'] ?? null;
    $price = $input['price_per_day'] ?? null;
    $status = $input['status'] ?? null;

    if (!is_string($name) || !is_string($category) || !is_string($status) ||!is_int($price)) {
        json_error('VALIDATION_ERROR', 'Tipe data perangkat tidak valid', 422);
    }

    $name = trim($name);

    if (
        $name === '' ||
        mb_strlen($name) > 50 ||
        !in_array($category, ['PS4', 'PS5'], true) ||
        !in_array($status, ['Available', 'Maintenance'], true) ||
        $price < 1 ||
        $price > 99999999
    ) {
        json_error('VALIDATION_ERROR', 'Nama, kategori, harga, atau status tidak valid', 422);
    }

    return [
        'device_name' => $name,
        'category' => $category,
        'price_per_day' => $price,
        'status' => $status
    ];
}

function admin_device_has_active_reservation(PDO $pdo, int $deviceId): bool
{
    $stmt = $pdo->prepare("SELECT EXISTS (
        SELECT 1 FROM rental r
        JOIN booking b ON b.booking_id=r.booking_id
        JOIN payment p ON p.booking_id=b.booking_id
        WHERE r.device_id=:device_id AND r.rental_status IN ('Booked','Rented','Overdue')
          AND b.fulfillment_status <> 'Cancelled'
          AND p.payment_status IN ('Pending','AwaitingVerification','Paid')
          AND (p.payment_status <> 'Pending' OR p.payment_expires_at IS NULL OR p.payment_expires_at > statement_timestamp())
    )");

    $stmt->execute(['device_id' => $deviceId]);
    
    return in_array($stmt->fetchColumn(), [true, 1, '1', 't'], true);
}
