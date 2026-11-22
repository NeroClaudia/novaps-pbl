<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../app/includes/bootstrap.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('METHOD_NOT_ALLOWED', 'Gunakan method GET', 405);
}

$auth = require_login(['admin', 'owner']);

$stmt = $pdo->prepare(
    "SELECT 1
     FROM users
     WHERE user_id = :id AND role = :role"
);

$stmt->execute([
    'id' => (int) $auth['user_id'],
    'role' => $auth['role']
]);

if (!$stmt->fetchColumn()) {
    json_error('FORBIDDEN', 'Akses admin ditolak', 403);
}

$search = $_GET['search'] ?? '';

if (!is_string($search) || mb_strlen(trim($search)) > 100) {
    json_error('VALIDATION_ERROR', 'Pencarian tidak valid', 422);
}

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($page === false) {
    json_error('VALIDATION_ERROR', 'Halaman tidak valid', 422);
}

$perPage = 20;
$offset = ($page - 1) * $perPage;

$search = trim($search);
$escaped = str_replace( ['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
$pattern = '%' . $escaped . '%';

try {
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM users u
         WHERE u.role = 'customer'
           AND (
             u.full_name ILIKE :name
             OR COALESCE(u.email, '') ILIKE :email
             OR COALESCE(u.phone, '') ILIKE :phone
        )"
    );

    $params = [
        'name' => $pattern,
        'email' => $pattern,
        'phone' => $pattern
    ];

    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT
            u.user_id,
            u.full_name,
            u.email,
            u.phone,
            u.address,
            u.identity_doc_type,
            u.identity_doc_verified,
            (u.identity_doc_url IS NOT NULL) AS has_identity_doc,
            COUNT(b.booking_id) AS total_orders,
            COUNT(b.booking_id) FILTER (
                WHERE b.fulfillment_status = 'Complete'
            ) AS completed_orders
         FROM users u
         LEFT JOIN booking b
           ON b.customer_id = u.user_id
         WHERE u.role = 'customer'
           AND (
             u.full_name ILIKE :name
             OR COALESCE(u.email, '') ILIKE :email
             OR COALESCE(u.phone, '') ILIKE :phone
           )
         GROUP BY u.user_id
         ORDER BY u.user_id DESC
         LIMIT :limit OFFSET :offset"
    );

    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }

    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $truthy = static fn(mixed $value): bool =>
        in_array($value, [true, 1, '1', 't', 'true'], true);
    
    $data = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $data[] = [
            'user_id' => (int) $row['user_id'],
            'full_name' => $row['full_name'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'address' => $row['address'],
            'identity_doc_type' => $row['identity_doc_type'],
            'identity_doc_verified' => $truthy(
                $row['identity_doc_verified']
            ),
            'has_identity_doc' => $truthy(
                $row['has_identity_doc']
            ),
            'total_orders' => (int) $row['total_orders'],
            'completed_orders' => (int) $row['completed_orders']
        ];
    }

    json_response([
        'success' => true,
        'data' => $data,
        'meta' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / $perPage))
        ]
    ]);

} catch (Throwable $e) {
    error_log('Admin Users List Error: ' . $e->getMessage());
    json_error('SERVER_ERROR', 'Gagal mengambil daftar customer', 500);
}