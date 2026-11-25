<?php

require __DIR__ . '/../../../../app/includes/bootstrap.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error(
        'METHOD_NOT_ALLOWED',
        'Gunakan metode GET',
        405
    );
}

require_login(['admin', 'owner']);

// Validasi parameter booking_id.
$rawBookingId = $_GET['booking_id'] ?? null;

$bookingId = is_string($rawBookingId)
    ? filter_var(
        $rawBookingId,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    )
    : false;

if ($bookingId === false) {
    json_error(
        'VALIDATION_ERROR',
        'booking_id harus berupa bilangan bulat minimal 1',
        422
    );
}

// PostgreSQL mengembalikan boolean sebagai "t" atau "f".
function detailBoolean(mixed $value): bool
{
    return in_array(
        $value,
        [true, 1, '1', 't', 'true'],
        true
    );
}

try {
    // Perbarui booking yang sudah melewati deadline.
    expire_unpaid_bookings($pdo);
    mark_overdue($pdo);

    // 1. Ambil booking, customer, payment dan admin.
    $stmt = $pdo->prepare(
        "SELECT
            b.booking_id,
            b.booking_code,
            b.address_snapshot,
            b.delivery_latitude,
            b.delivery_longitude,
            b.fulfillment_status,
            b.additional_notes,
            b.cancel_reason,
            to_char(
                b.created_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS created_at,
            to_char(
                b.shipped_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS shipped_at,
            to_char(
                b.completed_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS completed_at,

            c.user_id AS customer_id,
            c.full_name AS customer_name,
            c.phone AS customer_phone,
            c.email AS customer_email,
            c.address AS customer_address,
            c.identity_doc_type,
            c.identity_doc_url,
            c.identity_doc_verified,

            p.payment_id,
            p.payment_method,
            p.payment_status,
            p.payment_proof_url,
            to_char(
                p.payment_expires_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS payment_expires_at,
            to_char(
                p.payment_verified_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS payment_verified_at,
            to_char(
                p.paid_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS paid_at,

            CASE
                WHEN p.payment_expires_at IS NULL THEN NULL
                WHEN p.payment_status = 'Pending' THEN
                    GREATEST(
                        0,
                        CEIL(EXTRACT(EPOCH FROM (
                            p.payment_expires_at -
                            statement_timestamp()
                        )))
                    )::INTEGER
                ELSE 0
            END AS seconds_left,

            s.user_id AS staff_id,
            s.full_name AS verified_by

         FROM booking b
         JOIN users c
            ON c.user_id = b.customer_id
         JOIN payment p
            ON p.booking_id = b.booking_id
         LEFT JOIN users s
            ON s.user_id = b.staff_id

         WHERE b.booking_id = :booking_id"
    );

    $stmt->execute([
        'booking_id' => $bookingId
    ]);

    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        json_error(
            'NOT_FOUND',
            'Booking tidak ditemukan',
            404
        );
    }

    // 2. Ambil daftar PS yang disewa.
    $stmt = $pdo->prepare(
        "SELECT
            r.rental_id,
            r.device_id,
            d.device_name,
            d.category,

            to_char(
                r.start_time,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS start_time,
            to_char(
                r.end_time,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS end_time,

            r.duration_hours,
            r.price_per_day,
            r.total_cost,
            r.rental_status,

            to_char(
                r.rented_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS rented_at,
            to_char(
                r.overdue_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS overdue_at,
            to_char(
                r.returned_at,
                'YYYY-MM-DD\"T\"HH24:MI:SSTZH:TZM'
            ) AS returned_at,

            r.condition_at_return

         FROM rental r
         JOIN device d
            ON d.device_id = r.device_id

         WHERE r.booking_id = :booking_id
         ORDER BY r.rental_id ASC"
    );

    $stmt->execute([
        'booking_id' => $bookingId
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    $grandTotal = 0.0;

    foreach ($rows as $row) {
        $durationHours = (int) $row['duration_hours'];
        $itemTotal = (float) $row['total_cost'];

        $grandTotal += $itemTotal;

        $items[] = [
            'rental_id' => (int) $row['rental_id'],
            'device_id' => (int) $row['device_id'],
            'device_name' => $row['device_name'],
            'category' => $row['category'],

            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],

            'duration_days' => intdiv($durationHours, 24),
            'duration_hours' => $durationHours,

            'price_per_day' => $row['price_per_day'] !== null
                ? (float) $row['price_per_day']
                : null,

            'total_cost' => $itemTotal,
            'rental_status' => $row['rental_status'],

            'timeline' => build_timeline(array_merge($row, ['created_at' => $booking['created_at']])),
            'condition_at_return' => $row['condition_at_return']
        ];
    }

    // 3. Status dokumen identitas.
    $identityUploaded = !empty($booking['identity_doc_url']);
    $identityVerified = detailBoolean(
        $booking['identity_doc_verified']
    );

    // 4. Status bukti dan verifikasi pembayaran.
    $proofUploaded = !empty($booking['payment_proof_url']);
    $paymentVerified = $booking['payment_verified_at'] !== null;

    // 5. Tentukan apakah booking masih bisa disetujui.
    $paymentReady = match ($booking['payment_method']) {
        'QRIS' => (
            $booking['payment_status'] === 'AwaitingVerification'
            && $proofUploaded
        ),
        'Cash' => $booking['payment_status'] === 'Pending'
            && (
                $booking['payment_expires_at'] === null
                || (int) $booking['seconds_left'] > 0
            ),
        default => false
    };

    $allBooked = count($items) > 0;

    foreach ($items as $item) {
        if ($item['rental_status'] !== 'Booked') {
            $allBooked = false;
            break;
        }
    }

    $canVerify = $booking['fulfillment_status'] === 'Pending'
        && $identityUploaded
        && !empty($booking['identity_doc_type'])
        && $paymentReady
        && $allBooked;

    $canDeliver = $booking['fulfillment_status'] === 'Shipped'
        && $booking['payment_status'] === 'Paid'
        && $allBooked;

    $canReject = $booking['fulfillment_status'] === 'Pending'
        &&  (($booking['payment_method'] === 'QRIS' && $booking['payment_status'] === 'AwaitingVerification') || 
            ($booking['payment_method'] === 'Cash' && $booking['payment_status'] === 'Pending')) && $allBooked;

    // 6. Kirim detail booking ke dashboard admin.
    json_response([
        'success' => true,
        'data' => [
            'booking_id' => (int) $booking['booking_id'],
            'booking_code' => $booking['booking_code'],
            'created_at' => $booking['created_at'],
            'address_snapshot' => $booking['address_snapshot'],
            'additional_notes' => $booking['additional_notes'],
            'delivery_latitude' => $booking['delivery_latitude'] !== null ? (float) $booking['delivery_latitude'] : null,
            'delivery_longitude' => $booking['delivery_longitude'] !== null ? (float) $booking['delivery_longitude'] : null,
            'fulfillment_status' => $booking['fulfillment_status'],
            'shipped_at' => $booking['shipped_at'],
            'completed_at' => $booking['completed_at'],
            'cancel_reason' => $booking['cancel_reason'],

            'customer' => [
                'user_id' => (int) $booking['customer_id'],
                'full_name' => $booking['customer_name'],
                'phone' => $booking['customer_phone'],
                'email' => $booking['customer_email'],
                'address' => $booking['customer_address'],

                'identity_doc_type' => $booking['identity_doc_type'],
                'identity_doc_uploaded' => $identityUploaded,
                'identity_doc_verified' => $identityVerified
            ],

            'payment' => [
                'payment_id' => (int) $booking['payment_id'],
                'method' => $booking['payment_method'],
                'status' => $booking['payment_status'],

                'expires_at' => $booking['payment_expires_at'],
                'seconds_left' => $booking['seconds_left'] !== null
                    ? (int) $booking['seconds_left']
                    : null,

                'proof_uploaded' => $proofUploaded,
                'verified' => $paymentVerified,
                'verified_at' => $booking['payment_verified_at'],
                'paid_at' => $booking['paid_at'],

                'verified_by' => $booking['verified_by']
            ],

            'verification' => [
                'identity_uploaded' => $identityUploaded,
                'identity_verified' => $identityVerified,
                'payment_proof_uploaded' => $proofUploaded,
                'payment_verified' => $paymentVerified,
                'can_verify' => $canVerify,
                'can_deliver' => $canDeliver,
                'can_reject' => $canReject
            ],

            'verified_by' => $booking['verified_by'],
            'staff_id' => $booking['staff_id'] !== null
                ? (int) $booking['staff_id']
                : null,

            'items_count' => count($items),
            'total_cost' => $grandTotal,
            'items' => $items
        ]
    ]);

} catch (Throwable $e) {
    error_log(
        'Admin Booking Detail API Error: ' . $e->getMessage()
    );

    json_error(
        'SERVER_ERROR',
        'Gagal mengambil detail booking', 500
    );
}
