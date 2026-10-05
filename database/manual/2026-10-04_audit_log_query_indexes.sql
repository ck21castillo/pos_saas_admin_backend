-- Ejecutar una vez en bersano_control, fuera de una transaccion explicita.
-- El esquema actual usa admin.audit_log.id_audit y created_at.

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_audit_log_created_id
    ON admin.audit_log (created_at DESC NULLS LAST, id_audit DESC);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_audit_log_action_created_id
    ON admin.audit_log (action, created_at DESC NULLS LAST, id_audit DESC);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_audit_log_target_created_id
    ON admin.audit_log (target_type, target_id, created_at DESC NULLS LAST, id_audit DESC);

ANALYZE admin.audit_log;
