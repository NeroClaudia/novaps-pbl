-- Data contoh pembayaran.
-- Satu booking memiliki satu payment.

INSERT INTO payment (booking_id, payment_method, payment_status, payment_expires_at, payment_proof_url, payment_verified_at, paid_at)
SELECT
    b.booking_id,
    sample.payment_method,
    sample.payment_status,

    CASE
        WHEN sample.payment_status = 'Pending'
            AND sample.payment_method = 'QRIS'
            THEN NOW() + INTERVAL '5 minutes'
        ELSE NULL
    END,

    sample.proof_path,

    CASE
        WHEN sample.payment_status = 'Paid'
            THEN b.shipped_at
        ELSE NULL
    END,

    CASE
        WHEN sample.payment_status = 'Paid'
            THEN b.shipped_at
        ELSE NULL
    END

FROM (
    VALUES
        ('DEMO0001', 'QRIS', 'Paid', 'payment_proof/demo-0001.jpg'),
        ('DEMO0002', 'Cash', 'Paid', NULL),
        ('DEMO0003', 'QRIS', 'Paid', 'payment_proof/demo-0003.jpg'),
        ('DEMO0004', 'QRIS', 'Pending', NULL),
        ('DEMO0005', 'Cash', 'Cancelled', NULL)
) AS sample (
    booking_code,
    payment_method,
    payment_status,
    proof_path
)
JOIN booking b
    ON b.booking_code = sample.booking_code;


-- Data contoh rental.
-- Harga berdasarkan price_per_day.
-- Status Overdue tidak dikenakan denda.

INSERT INTO rental (booking_id, device_id, start_time, duration_hours, end_time, price_per_day, total_cost, rental_status, rented_at, overdue_at, returned_at, condition_at_return
)
SELECT
    b.booking_id,
    d.device_id,

    COALESCE(b.completed_at, b.created_at),

    sample.duration_days * 24,

    COALESCE(b.completed_at, b.created_at)
        + sample.duration_days * INTERVAL '24 hours',

    d.price_per_day,

    sample.duration_days * d.price_per_day,

    sample.rental_status,

    CASE
        WHEN sample.rental_status IN (
            'Rented', 'Overdue', 'Returned'
        )
            THEN b.completed_at
        ELSE NULL
    END,

    CASE
        WHEN sample.rental_status = 'Overdue'
            THEN b.completed_at
                + sample.duration_days * INTERVAL '24 hours'
        ELSE NULL
    END,

    CASE
        WHEN sample.rental_status = 'Returned'
            THEN b.completed_at
                + sample.duration_days * INTERVAL '24 hours'
        ELSE NULL
    END,

    CASE
        WHEN sample.rental_status = 'Returned'
            THEN 'Good'
        ELSE NULL
    END

FROM (
    VALUES
        ('DEMO0001', 'SN-PS4-001', 2, 'Rented'),
        ('DEMO0001', 'SN-PS5-001', 2, 'Rented'),
        ('DEMO0002', 'SN-PS4-002', 1, 'Overdue'),
        ('DEMO0003', 'SN-PS5-002', 2, 'Returned'),
        ('DEMO0004', 'SN-PS5-002', 1, 'Booked'),
        ('DEMO0005', 'SN-PS4-002', 2, 'Cancelled')
) AS sample (
    booking_code,
    serial_number,
    duration_days,
    rental_status
)
JOIN booking b
    ON b.booking_code = sample.booking_code
JOIN device d
    ON d.serial_number = sample.serial_number;
