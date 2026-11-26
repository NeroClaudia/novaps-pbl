CREATE TABLE rental
(
    rental_id SERIAL PRIMARY KEY,
    booking_id INT NOT NULL REFERENCES booking (booking_id) ON DELETE CASCADE,
    device_id INT NOT NULL REFERENCES device (device_id),
    start_time TIMESTAMPTZ NOT NULL,
    duration_hours INT NOT NULL CHECK (duration_hours >= 24 AND MOD(duration_hours, 24) = 0),
    price_per_day NUMERIC(10, 2) NOT NULL CHECK (price_per_day > 0),
    rental_status VARCHAR(20) NOT NULL DEFAULT 'Booked'
        CHECK (rental_status IN (
                              'Booked',
                              'Rented',
                              'Overdue',
                              'Returned',
                              'Cancelled')
        ),
    rented_at TIMESTAMPTZ,
    overdue_at TIMESTAMPTZ,
    returned_at TIMESTAMPTZ,
    condition_at_return VARCHAR(100),
    late_fee_paid_at TIMESTAMPTZ,
    late_fee_payment_method VARCHAR(20) CHECK (late_fee_payment_method IN ('Cash', 'QRIS')),
    
    CONSTRAINT uq_booking_device UNIQUE (booking_id, device_id),
    CONSTRAINT chk_late_fee_payment_consistency CHECK (
        (late_fee_paid_at IS NULL) = (late_fee_payment_method IS NULL)
    )
);

CREATE VIEW v_rental
WITH (security_invoker = true) AS
    SELECT
        r.*,
        start_time + duration_hours * INTERVAL '1 hour' AS end_time,
        ((duration_hours / 24.0) * price_per_day)::NUMERIC(12, 2) AS total_cost
    FROM rental r;

CREATE INDEX idx_rental_booking ON rental (booking_id);
CREATE INDEX idx_rental_device ON rental (device_id);
CREATE INDEX idx_rental_status ON rental (rental_status);
CREATE INDEX idx_rental_start ON rental (start_time);
