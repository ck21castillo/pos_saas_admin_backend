-- Ejecutar una vez en bersano_control.
-- El listado de salud lee esta instantanea; el sondeo se ejecuta por CLI.

CREATE TABLE IF NOT EXISTS admin.tenant_health_snapshot (
    id_empresa BIGINT PRIMARY KEY REFERENCES pos_saas.empresa(id_empresa) ON DELETE CASCADE,
    health_status VARCHAR(16) NOT NULL,
    checked_at TIMESTAMPTZ NOT NULL,
    connection_ms INTEGER NULL,
    check_ms INTEGER NOT NULL,
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT tenant_health_snapshot_status_ck
        CHECK (health_status IN ('OK', 'WARNING', 'ERROR'))
);

CREATE INDEX IF NOT EXISTS tenant_health_snapshot_checked_idx
    ON admin.tenant_health_snapshot (checked_at ASC, id_empresa ASC);

CREATE INDEX IF NOT EXISTS tenant_health_snapshot_status_checked_idx
    ON admin.tenant_health_snapshot (health_status, checked_at DESC, id_empresa DESC);

ANALYZE admin.tenant_health_snapshot;
