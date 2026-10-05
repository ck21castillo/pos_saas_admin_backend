-- bersano_control. Ejecutar con el rol propietario/de despliegue, no con
-- bersano_admin_ejecucion.
CREATE SCHEMA IF NOT EXISTS admin;

CREATE TABLE IF NOT EXISTS admin.landing_visit (
    id_visit BIGSERIAL PRIMARY KEY,
    visitor_id VARCHAR(120) NOT NULL,
    landing_path TEXT NOT NULL,
    page_location TEXT NULL,
    referrer TEXT NULL,
    user_agent TEXT NULL,
    ip INET NULL,
    meta JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_landing_visit_created_at
    ON admin.landing_visit (created_at DESC);

CREATE INDEX IF NOT EXISTS idx_landing_visit_path_created
    ON admin.landing_visit (landing_path, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_landing_visit_visitor_created
    ON admin.landing_visit (visitor_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_landing_visit_created_visitor
    ON admin.landing_visit (created_at DESC, visitor_id);

GRANT USAGE ON SCHEMA admin TO bersano_admin_ejecucion;
GRANT SELECT, INSERT ON TABLE admin.landing_visit TO bersano_admin_ejecucion;
GRANT USAGE, SELECT ON SEQUENCE admin.landing_visit_id_visit_seq TO bersano_admin_ejecucion;
