-- Rate limit para login inicial del panel admin.
-- Ejecutar en bersano_control. Seguro para ejecutar multiples veces.

CREATE TABLE IF NOT EXISTS admin.admin_auth_rate_limit_bucket (
    key_hash char(64) PRIMARY KEY,
    scope text NOT NULL,
    attempts integer NOT NULL DEFAULT 0,
    window_started_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NOT NULL,
    last_hit_at timestamptz NOT NULL DEFAULT now(),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_admin_auth_rate_limit_scope_expires
    ON admin.admin_auth_rate_limit_bucket (scope, expires_at);

CREATE INDEX IF NOT EXISTS idx_admin_auth_rate_limit_expires
    ON admin.admin_auth_rate_limit_bucket (expires_at);

ANALYZE admin.admin_auth_rate_limit_bucket;