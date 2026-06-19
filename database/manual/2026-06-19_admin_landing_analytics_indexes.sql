-- Indices para metricas de visitantes landing del panel administrativo.
-- Seguro para ejecutar multiples veces.

CREATE INDEX IF NOT EXISTS idx_landing_visit_created_visitor
    ON admin.landing_visit (created_at DESC, visitor_id);

CREATE INDEX IF NOT EXISTS idx_landing_visit_path_created
    ON admin.landing_visit (landing_path, created_at DESC);

ANALYZE admin.landing_visit;