-- Opcional pero recomendado para busqueda libre de auditoria a escala.
-- Ejecutar una vez en bersano_control, fuera de una transaccion explicita.
-- El rol debe poder instalar la extension pg_trgm.

CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_audit_log_actor_email_trgm
    ON admin.audit_log USING gin (actor_email gin_trgm_ops);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_audit_log_action_trgm
    ON admin.audit_log USING gin (action gin_trgm_ops);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_audit_log_target_type_trgm
    ON admin.audit_log USING gin (target_type gin_trgm_ops);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_audit_log_ip_text_trgm
    ON admin.audit_log USING gin ((ip::text) gin_trgm_ops);

ANALYZE admin.audit_log;
