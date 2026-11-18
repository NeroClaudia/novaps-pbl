<?php
declare(strict_types=1);

// Mengambil informasi profil customer berdasarkan user ID

function get_customer_profile(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            user_id,
            full_name,
            phone,
            email,
            address,
            identity_doc_type,
            identity_doc_url,
            identity_doc_verified
        FROM users
        WHERE user_id = :id
            AND role = 'customer'"
    );

    $stmt->execute(['id' => $userId]);

    $user = $stmt->fetch();

    if (!$user) {
        return null;
    }

    return [
        'user_id' => (int) $user['user_id'],
        'full_name' => $user['full_name'],
        'phone' => $user['phone'],
        'email' => $user['email'],
        'address' => $user['address'],
        'identity_doc_type' => $user['identity_doc_type'],
        'identity_doc_uploaded' => !empty($user['identity_doc_url']),
        'identity_doc_verified' => in_array(
            $user['identity_doc_verified'],
            [true, 1, '1', 't', 'true'],
            true
        ),
    ];
}