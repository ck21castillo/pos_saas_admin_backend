CREATE SCHEMA IF NOT EXISTS admin;

CREATE TABLE IF NOT EXISTS admin.superadmin_otp (
    id_otp bigserial PRIMARY KEY,
    id_superadmin bigint NOT NULL REFERENCES admin.superadmin_user(id_superadmin) ON DELETE CASCADE,
    email text NOT NULL,
    proposito text NOT NULL DEFAULT 'login',
    code_hash char(64) NOT NULL,
    intent_token char(64) NOT NULL UNIQUE,
    attempts integer NOT NULL DEFAULT 0,
    resend_count integer NOT NULL DEFAULT 0,
    expires_at timestamptz NOT NULL,
    consumed_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_superadmin_otp_intent
    ON admin.superadmin_otp (intent_token);

CREATE INDEX IF NOT EXISTS idx_superadmin_otp_admin_created
    ON admin.superadmin_otp (id_superadmin, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_superadmin_otp_cleanup
    ON admin.superadmin_otp (expires_at, consumed_at);