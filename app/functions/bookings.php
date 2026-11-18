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

function get_customer_booking(PDO $pdo, int $customerId, string $bookingCode): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            b.booking_code,
            b.created_at,
            b.cancel_reason,
            b.cancelled_at,
            b.address_snapshot,
            u.full_name,
            p.payment_method,
            p.payment_status,
            to_char(
                p.payment_expires_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS expires_at,
            CASE
                WHEN p.payment_expires_at IS NULL THEN NULL
                WHEN p.payment_status <> 'Pending' THEN 0
                ELSE GREATEST(
                    0,
                    CEIL(EXTRACT(EPOCH FROM (
                        p.payment_expires_at - statement_timestamp()
                    )))
                )::INTEGER
            END AS seconds_left,
            r.device_id,
            d.device_name,
            d.category,
            r.duration_hours,
            r.price_per_day,
            r.total_cost,
            r.rental_status
        FROM booking b
        JOIN users u
            ON u.user_id = b.customer_id
        JOIN payment p
            ON p.booking_id = b.booking_id
        JOIN rental r
            ON r.booking_id = b.booking_id
        JOIN device d
            ON d.device_id = r.device_id
        WHERE b.booking_code = :booking_code
          AND b.customer_id = :customer_id
        ORDER BY r.device_id ASC"
    );

    $stmt->execute(['booking_code' => $bookingCode, 'customer_id' => $customerId]);

    $rows = $stmt->fetchAll();

    if (!$rows) {
        return null;
    }
    
    $first = $rows[0];
    $items = [];
    $grandTotal = 0.0;

    foreach ($rows as $row) {
        $itemCost = (float) $row['total_cost'];

        $items[] = [
            'device_id' => (int) $row['device_id'],
            'device_name' => $row['device_name'],
            'category' => $row['category'],
            'duration_days' => intdiv(
                (int) $row['duration_hours'],
                24
            ),
            'price_per_day' => (float) $row['price_per_day'],
            'total_cost' => $itemCost,
            'rental_status' => $row['rental_status']
        ];

        $grandTotal += $itemCost;
    }

    return [
      'booking_code' => (string) $first['booking_code'],
      'created_at' => $first['created_at'],
      'cancel_reason' => $first['cancel_reason'],
      'cancelled_at' => $first['cancelled_at'],
      'customer' => [
        'full_name' => $first['full_name'],
        'address' => $first['address_snapshot']
      ],
      'payment_method' => $first['payment_method'],
      'payment' => [
        'status' => $first['payment_status'],
        'expires_at' => $first['expires_at'],
        'seconds_left' => $first['seconds_left'] !== null ? (int) $first['seconds_left'] : null
      ],
      'items' => $items,
      'total_cost' => $grandTotal
    ];
}