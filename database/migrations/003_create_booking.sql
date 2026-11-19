CREATE TABLE booking (
    booking_id              SERIAL PRIMARY KEY,
    booking_code            VARCHAR(8) NOT NULL UNIQUE
        DEFAULT UPPER(SUBSTR(MD5(RANDOM()::text || CLOCK_TIMESTAMP()::text), 1, 8)),
    customer_id             INT NOT NULL REFERENCES users(user_id),
    staff_id                INT REFERENCES users(user_id),
    booking_channel         VARCHAR(20) NOT NULL DEFAULT 'Online'
        CHECK (booking_channel IN ('Online', 'Walk-in')),
    fulfillment_status      VARCHAR(20) NOT NULL DEFAULT 'Pending'
        CHECK (fulfillment_status IN (
                'Pending',
                'Shipped',
                'Complete',
                'Cancelled'
            )
        ),
    shipped_at              TIMESTAMPTZ,
    completed_at            TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    cancel_reason           VARCHAR(255),
    cancelled_at            TIMESTAMPTZ,
    address_snapshot        VARCHAR(255) NOT NULL
);

CREATE INDEX idx_booking_customer ON booking(customer_id);
CREATE INDEX idx_booking_fulfillment ON booking(fulfillment_status);
