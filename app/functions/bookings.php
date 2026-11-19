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
            r.rental_status,
            r.start_time,
            r.end_time,
            r.rented_at,
            r.overdue_at,
            r.returned_at
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
            'rental_status' => $row['rental_status'],
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'rented_at' => $row['rented_at'],
            'overdue_at' => $row['overdue_at'],
            'returned_at' => $row['returned_at']
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

//Seluruh riwayat booking milik satu customer
// data diurutkan berdasarkan waktu booking terbaru
function get_customer_bookings(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            b.booking_id,
            b.booking_code,
            b.address_snapshot,
            b.created_at,
            b.cancel_reason,
            p.payment_method,
            p.payment_status,
            p.payment_expires_at,
            p.payment_proof_url,
            COALESCE(
                (
                    SELECT SUM(r.total_cost)
                    FROM rental r
                    WHERE r.booking_id = b.booking_id
                ), 0
            ) AS total_cost
         FROM booking b
         JOIN payment p ON p.booking_id = b.booking_id
         WHERE b.customer_id = :customer_id
         ORDER BY b.created_at DESC, b.booking_id DESC"
    );

    $stmt->execute(['customer_id' => $customerId]);

    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$bookings) {
        return [];
    }

    $itemStmt = $pdo->prepare(
        "SELECT
            r.device_id,
            d.device_name,
            d.category,
            r.duration_hours,
            r.total_cost,
            r.rental_status
         FROM rental r
         JOIN device d ON d.device_id = r.device_id
         WHERE r.booking_id = :booking_id
         ORDER BY r.rental_id ASC"
    );

    $result = [];

    foreach ($bookings as $booking) {
        $itemStmt->execute([
            'booking_id' => (int) $booking['booking_id']
        ]);

        $items = [];
        $rentalStatuses = [];

        foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $rentalStatuses[] = $item['rental_status'];

            $items[] = [
                'device_id' => (int) $item['device_id'],
                'device_name' => $item['device_name'],
                'category' => $item['category'],
                'duration_days' => intdiv(
                    (int) $item['duration_hours'],
                    24
                ),
                'total_cost' => (float) $item['total_cost'],
                'rental_status' => $item['rental_status']
            ];
        }

        $paymentStatus = $booking['payment_status'];

        $isPending = $paymentStatus === 'Pending';
        $hasDeadline = $booking['payment_expires_at'] !== null;

        $expiresAt = $hasDeadline
            ? new DateTimeImmutable($booking['payment_expires_at'])
            : null;

        $secondsLeft = $isPending && $expiresAt !== null
            ? max(0, $expiresAt->getTimestamp() - time())
            : null;

        // Status UI berasal dari payment + kondisi rental.
        if ($paymentStatus === 'Pending') {
            $uiStatus = 'not-paid';
        } elseif ($paymentStatus === 'AwaitingVerification') {
            $uiStatus = 'pending';
        } elseif (in_array($paymentStatus, ['Cancelled', 'Expired'], true)) {
            $uiStatus = 'canceled';
        } elseif ($paymentStatus === 'Rejected') {
            $uiStatus = 'rejected';
        } elseif ($paymentStatus === 'Paid') {
            $uiStatus = 'complete';
        } else {
            $uiStatus = 'pending';
        }

        $result[] = [
            'booking_code' => (string) $booking['booking_code'],
            'created_at' => (new DateTimeImmutable(
                $booking['created_at']
            ))->format(DATE_ATOM),
            'address' => $booking['address_snapshot'],
            'cancel_reason' => $booking['cancel_reason'],
            'payment_method' => $booking['payment_method'],
            'payment_status' => $paymentStatus,
            'status' => $uiStatus,
            'rental_statuses' => $rentalStatuses,
            'total_cost' => (float) $booking['total_cost'],
            'payment' => [
                'expires_at' => $expiresAt?->format(DATE_ATOM),
                'seconds_left' => $secondsLeft,
                'proof_uploaded' => !empty(
                    $booking['payment_proof_url']
                )
            ],
            'items' => $items
        ];
    }

    return $result;
}

//Daftar rental customer yang sudah dibayar dan berstatus Rented, Overdue, atau Returned.
function get_customer_rentals(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            r.rental_id,
            r.device_id,
            r.rental_status,
            r.duration_hours,
            r.total_cost,
            r.end_time,
            r.rented_at,
            r.overdue_at,
            r.returned_at,
            d.device_name,
            d.category,
            b.booking_code,
            b.address_snapshot
         FROM rental r
         JOIN booking b ON b.booking_id = r.booking_id
         JOIN payment p ON p.booking_id = b.booking_id
         JOIN device d ON d.device_id = r.device_id
         WHERE b.customer_id = :customer_id
           AND p.payment_status = 'Paid'
           AND r.rental_status IN (
               'Rented', 'Overdue', 'Returned'
           )
         ORDER BY
           CASE WHEN r.rental_status = 'Returned'
               THEN 1 ELSE 0 END,
           r.end_time ASC,
           r.rental_id DESC"
    );

    $stmt->execute(['customer_id' => $customerId]);

    $result = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $endTime = new DateTimeImmutable($row['end_time']);

        $returnedAt = $row['returned_at'] !== null
            ? new DateTimeImmutable($row['returned_at'])
            : null;

        $result[] = [
            'rental_id' => (int) $row['rental_id'],
            'booking_code' => (string) $row['booking_code'],
            'device_id' => (int) $row['device_id'],
            'device_name' => (string) $row['device_name'],
            'category' => (string) $row['category'],
            'address' => (string) $row['address_snapshot'],
            'duration_days' => intdiv(
                (int) $row['duration_hours'],
                24
            ),
            'total_cost' => (float) $row['total_cost'],
            'rental_status' => (string) $row['rental_status'],
            'end_time' => $endTime->format(DATE_ATOM),
            'end_timestamp' => $endTime->getTimestamp(),
            'returned_at' => $returnedAt?->format(DATE_ATOM),
            'returned_late' => $returnedAt !== null
                && $returnedAt->getTimestamp() >
                   $endTime->getTimestamp()
        ];
    }

    return $result;
}
