<?php
declare(strict_types=1);

//Mengambil booking customer yang masih menunggu pembayaran
function get_unpaid_booking(PDO $pdo, int $customerId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            b.booking_code,
            p.payment_method,
            p.payment_status,
            to_char(
                p.payment_expires_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS expires_at,
            CASE
                WHEN p.payment_expires_at IS NULL THEN NULL
                ELSE GREATEST(
                    0,
                    CEIL(EXTRACT(EPOCH FROM (
                        p.payment_expires_at - statement_timestamp()
                    )))
                )::INTEGER
            END AS seconds_left,
            (
                SELECT COALESCE(SUM(r.total_cost), 0)
                FROM rental r
                WHERE r.booking_id = b.booking_id
            ) AS total_cost
        FROM booking b
        JOIN payment p
            ON p.booking_id = b.booking_id
        WHERE b.customer_id = :customer_id
          AND p.payment_status = 'Pending'
          AND (
              p.payment_expires_at IS NULL
              OR p.payment_expires_at > statement_timestamp()
          )
        ORDER BY b.booking_id DESC
        LIMIT 1"
    );

    $stmt->execute(['customer_id' => $customerId]);

    $booking = $stmt->fetch();

    if (!$booking) {
        return null;
    }

    return [
        'booking_code' => (string) $booking['booking_code'],
        'payment_method' => $booking['payment_method'],
        'total_cost' => (float) $booking['total_cost'],
        'payment' => [
            'status' => $booking['payment_status'],
            'expires_at' => $booking['expires_at'],
            'seconds_left' => $booking['seconds_left'] !== null ? (int) $booking['seconds_left'] : null
        ]
    ];
}