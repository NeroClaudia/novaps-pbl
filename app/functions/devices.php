<?php
declare(strict_types=1);

/**
 * Mengambil daftar perangkat beserta status rental aktif.
 */
function get_devices(PDO $pdo): array
{
    $query = $pdo->query(
        "SELECT
            d.device_id,
            d.device_name,
            d.category,
            d.booth_code,
            d.status,
            d.price_per_day,
            (active_rental.device_id IS NOT NULL) AS is_rented,
            to_char(
                active_rental.end_time,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS end_time
         FROM device d
         LEFT JOIN (
             SELECT
                 r.device_id,
                 MAX(r.end_time) AS end_time
             FROM rental r
             JOIN booking b
                 ON b.booking_id = r.booking_id
             JOIN payment p
                 ON p.booking_id = b.booking_id
            WHERE
                r.rental_status IN ('Rented', 'Overdue')

                OR (
                    r.rental_status = 'Booked'
                    AND b.fulfillment_status = 'Shipped'
                    AND p.payment_status = 'Paid'
                )

                OR (
                    r.rental_status = 'Booked'
                    AND r.start_time <= statement_timestamp()
                    AND r.end_time > statement_timestamp()
                    AND p.payment_status IN (
                        'Pending',
                        'AwaitingVerification',
                        'Paid'
                    )
                    AND (
                        p.payment_status <> 'Pending'
                        OR p.payment_expires_at IS NULL
                        OR p.payment_expires_at > statement_timestamp()
                    )
                )
             GROUP BY r.device_id
         ) active_rental
             ON active_rental.device_id = d.device_id
         ORDER BY
             d.category ASC,
             d.device_name ASC,
             d.device_id ASC"
    );

    $devices = [];

    foreach ($query->fetchAll() as $device) {
        $devices[] = [
            'device_id' => (int) $device['device_id'],
            'device_name' => $device['device_name'],
            'category' => $device['category'],
            'booth_code' => $device['booth_code'],
            'status' => $device['status'],
            'price_per_day' => (float) $device['price_per_day'],
            'is_rented' => in_array(
                $device['is_rented'],
                [true, 1, '1', 't', 'true'],
                true
            ),
            'end_time' => $device['end_time'],
        ];
    }

    return $devices;
}

function get_available_devices(PDO $pdo, DateTimeImmutable $startTime, DateTimeImmutable $endTime,?string $category = null): array 
{
    $params = [
        'start_time' => $startTime->format('Y-m-d H:i:sP'),
        'end_time' => $endTime->format('Y-m-d H:i:sP'),
    ];

    $categoryFilter = '';

    if ($category !== null) {
        $categoryFilter = 'AND d.category = :category';
        $params['category'] = $category;
    }

    $query = $pdo->prepare(
        "SELECT
            d.device_id,
            d.device_name,
            d.category,
            d.booth_code,
            d.price_per_day
         FROM device d
         WHERE d.status = 'Available'
           {$categoryFilter}

           AND NOT EXISTS (
               SELECT 1
               FROM rental held
               WHERE held.device_id = d.device_id
                 AND (
                     held.rental_status = 'Overdue'
                     OR (
                         held.rental_status = 'Rented'
                         AND held.end_time < statement_timestamp()
                     )
                 )
           )

           AND NOT EXISTS (
               SELECT 1
               FROM rental r
               JOIN booking b
                   ON b.booking_id = r.booking_id
               JOIN payment p
                   ON p.booking_id = b.booking_id
               WHERE r.device_id = d.device_id
                 AND r.rental_status IN (
                     'Booked', 'Rented', 'Overdue'
                 )
                 AND p.payment_status IN (
                     'Pending', 'AwaitingVerification', 'Paid'
                 )
                 AND (
                     p.payment_status <> 'Pending'
                     OR p.payment_expires_at IS NULL
                     OR p.payment_expires_at > statement_timestamp()
                 )
                AND (
                    (
                        b.fulfillment_status = 'Shipped'
                        AND p.payment_status = 'Paid'
                    )
                    OR (
                        r.start_time < CAST(:end_time AS timestamptz)
                        AND r.end_time > CAST(:start_time AS timestamptz)
                    )
                )
           )
         ORDER BY
             d.category ASC,
             d.device_name ASC,
             d.device_id ASC"
    );

    $query->execute($params);

    $devices = [];

    foreach($query->fetchAll() as $device) {
        $devices[] = [
            'device_id' => (int) $device['device_id'],
            'device_name' => $device['device_name'],
            'category' => $device['category'],
            'booth_code' => $device['booth_code'],
            'price_per_day' => (float) $device['price_per_day'], 
        ];
    }

    return $devices;
}

function get_device_pricing(PDO $pdo, int $deviceId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            device_id,
            device_name,
            category,
            booth_code,
            status,
            price_per_day
         FROM device
         WHERE device_id = :device_id"
    );

    $stmt->execute(['device_id' => $deviceId]);

    $device = $stmt->fetch();

    if (!$device) {
        return null;
    }

    return [
        'device_id' => (int) $device['device_id'],
        'device_name' => $device['device_name'],
        'category' => $device['category'],
        'booth_code' => $device['booth_code'],
        'status' => $device['status'],
        'price_per_day' => (float) $device['price_per_day'],
    ];
}

