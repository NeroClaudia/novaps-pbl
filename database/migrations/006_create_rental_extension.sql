CREATE TABLE rental_extension (
    extension_id                    SERIAL PRIMARY KEY,
    rental_id                       INT NOT NULL REFERENCES rental(rental_id),
    extra_days                      INT NOT NULL CHECK (extra_days BETWEEN 1 AND 6),
    status                          VARCHAR(25) NOT NULL
        DEFAULT 'PendingPayment'
        CHECK (status IN (
            'PendingPayment',
            'AwaitingVerification',
            'Approved',
            'Rejected',
            'Expired'
        )),
    payment_proof_url               VARCHAR(255),
    created_at                      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    reviewed_at                     TIMESTAMPTZ,
    reviewed_by                     INT REFERENCES users(user_id),

    CONSTRAINT chk_extension_review CHECK (
        (
            status IN ('Approved', 'Rejected')
            AND reviewed_at IS NOT NULL
            AND reviewed_by IS NOT NULL
        )
        OR
        (
            status IN (
                'PendingPayment',
                'AwaitingVerification',
                'Expired'
            )
            AND reviewed_at IS NULL
            AND reviewed_by IS NULL
        )
    ),

    CONSTRAINT chk_extension_proof CHECK (
        status NOT IN (
            'AwaitingVerification',
            'Approved'
        )
        OR payment_proof_url IS NOT NULL
    )
);

-- Hanya boleh ada satu pengajuan yang belum selesai untuk setiap rental.
CREATE UNIQUE INDEX uq_active_rental_extension ON rental_extension(rental_id)
WHERE status IN (
    'PendingPayment',
    'AwaitingVerification'
);
CREATE INDEX idx_extension_status ON rental_extension(status);
