<?php

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        json_error(
            'BAD_REQUEST',
            'Format request tidak valid. Body harus berupa JSON',
            400
        );
    }

    return $data;
}

function normalize_phone(string $phone): string
{
    $cleaned = preg_replace('/\D+/', '', $phone);

    if (str_starts_with($cleaned, '62')) {
        $cleaned = '0' . substr($cleaned, 2);
    }

    return $cleaned;
}

function lock_device(PDO $pdo, int $deviceId): array|false
{
    $query = $pdo->prepare(
        "SELECT device_id, price_per_day, status
         FROM device
         WHERE device_id = :device_id
         FOR UPDATE"
    );

    $query->execute([
        'device_id' => $deviceId
    ]);

    return $query->fetch();
}

function device_is_held(PDO $pdo, int $deviceId): bool
{
    $stmt = $pdo->prepare(
        "SELECT EXISTS (
            SELECT 1
            FROM rental
            WHERE device_id = :device_id
              AND (
                  rental_status = 'Overdue'
                  OR (
                      rental_status = 'Rented'
                      AND end_time < NOW()
                  )
              )
        )"
    );

    $stmt->execute([
        'device_id' => $deviceId
    ]);

    return (bool)$stmt->fetchColumn();
}

function slot_is_free(
    PDO    $pdo,
    int    $deviceId,
    string $startDb,
    string $endDb
): bool
{
    $stmt = $pdo->prepare(
        "SELECT NOT EXISTS (
            SELECT 1
            FROM rental r
            JOIN booking b
                ON b.booking_id = r.booking_id
            JOIN payment p
                ON p.booking_id = b.booking_id
            WHERE r.device_id = :device_id
              AND r.rental_status IN (
                  'Booked',
                  'Rented',
                  'Overdue'
              )
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
        
                AND ((b.fulfillment_status = 'Shipped' AND p.payment_status = 'Paid') OR r.rental_status = 'Overdue'
                    OR (r.rental_status = 'Rented' AND r.end_time < statement_timestamp())
                    OR (r.start_time < CAST(:end_db AS TIMESTAMPTZ) AND r.end_time > CAST(:start_db AS TIMESTAMPTZ))))"
    );

    $stmt->execute([
        'device_id' => $deviceId,
        'start_db' => $startDb,
        'end_db' => $endDb
    ]);

    return (bool)$stmt->fetchColumn();
}

function mark_overdue(PDO $pdo): void
{
    $pdo->exec(
        "UPDATE rental
         SET rental_status = 'Overdue',
             overdue_at = NOW()
         WHERE rental_status = 'Rented'
           AND end_time < NOW()"
    );
}

function build_timeline(array $row): array
{
    $timeline = [
        [
            'status' => 'Booked',
            'at' => $row['created_at']
        ]
    ];

    if ($row['rented_at'] !== null) {
        $timeline[] = [
            'status' => 'Rented',
            'at' => $row['rented_at']
        ];
    }

    if ($row['overdue_at'] !== null) {
        $timeline[] = [
            'status' => 'Overdue',
            'at' => $row['overdue_at']
        ];
    }

    if ($row['returned_at'] !== null) {
        $timeline[] = [
            'status' => 'Returned',
            'at' => $row['returned_at']
        ];
    }

    return $timeline;
}

function expire_unpaid_bookings(PDO $pdo): void
{
    $pdo->exec(
        "WITH expired AS (
            UPDATE payment
            SET payment_status = 'Expired'
            WHERE payment_status = 'Pending'
              AND payment_expires_at IS NOT NULL
              AND payment_expires_at <= statement_timestamp()
            RETURNING booking_id
        ),
        cancelled_rentals AS (
            UPDATE rental
            SET rental_status = 'Cancelled'
            WHERE booking_id IN (
                SELECT booking_id FROM expired
            )
              AND rental_status = 'Booked'
            RETURNING booking_id
        )
        UPDATE booking
        SET fulfillment_status = 'Cancelled',
            cancel_reason = 'Payment time expired',
            cancelled_at = statement_timestamp()
        WHERE booking_id IN (
            SELECT booking_id FROM expired
        )
          AND fulfillment_status = 'Pending'"
    );
}

function calculate_late_fee(string $endTime, ?string $returnedAt = null, ?DateTimeImmutable $now = null): int
{
    $deadline = new DateTimeImmutable($endTime);

    $actualTime = $returnedAt !== null
        ? new DateTimeImmutable($returnedAt)
        : ($now ?? new DateTimeImmutable('now'));

    $lateSeconds = max(0, $actualTime->getTimestamp() - $deadline->getTimestamp());

    $lateHours = intdiv($lateSeconds + 3599, 3600);

    return $lateHours * 10000;
}

function customer_order_block_reason(PDO $pdo, int $customerId): ?string 
{
    $stmt = $pdo->prepare(
        "SELECT
            EXISTS (
                SELECT 1
                FROM rental r
                JOIN booking b ON b.booking_id = r.booking_id
                WHERE b.customer_id = :customer_overdue
                  AND (
                      r.rental_status = 'Overdue'
                      OR (
                          r.rental_status = 'Rented'
                          AND r.end_time < statement_timestamp()
                      )
                  )
            ) AS has_overdue,
            EXISTS (
                SELECT 1
                FROM rental r
                JOIN booking b ON b.booking_id = r.booking_id
                WHERE b.customer_id = :customer_unpaid
                  AND r.rental_status = 'Returned'
                  AND r.returned_at IS NOT NULL
                  AND r.returned_at > r.end_time
                  AND r.late_fee_paid_at IS NULL
            ) AS has_unpaid_fee"
    );

    $stmt->execute([
        'customer_overdue' => $customerId,
        'customer_unpaid' => $customerId
    ]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $isTrue = static fn($value): bool => in_array(
        $value,
        [true, 1, '1', 't', 'true'],
        true
    );

    if ($isTrue($result['has_overdue'] ?? false)) {
        return 'overdue';
    }

    if ($isTrue($result['has_unpaid_fee'] ?? false)) {
        return 'unpaid_late_fee';
    }

    return null;
}

function customer_has_overdue_rental(PDO $pdo, int $customerId): bool 
{
    return customer_order_block_reason(
        $pdo,
        $customerId
    ) !== null;
}

