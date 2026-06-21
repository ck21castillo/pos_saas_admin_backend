-- Indices para la vista de auditoria administrativa.
-- Seguro para ejecutar multiples veces.

DO $$
BEGIN
    IF to_regclass('admin.audit_log') IS NOT NULL
       AND EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'admin'
              AND table_name = 'audit_log'
              AND column_name = 'created_at'
       ) THEN
        IF NOT EXISTS (
            SELECT 1 FROM pg_indexes
            WHERE schemaname = 'admin' AND indexname = 'idx_audit_log_created'
        ) THEN
            CREATE INDEX idx_audit_log_created
                ON admin.audit_log (created_at DESC);
        END IF;

        IF EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'admin'
              AND table_name = 'audit_log'
              AND column_name = 'action'
        ) AND NOT EXISTS (
            SELECT 1 FROM pg_indexes
            WHERE schemaname = 'admin' AND indexname = 'idx_audit_log_action_created'
        ) THEN
            CREATE INDEX idx_audit_log_action_created
                ON admin.audit_log (action, created_at DESC);
        END IF;

        IF EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'admin'
              AND table_name = 'audit_log'
              AND column_name IN ('target_type', 'target_id')
            GROUP BY table_schema, table_name
            HAVING COUNT(*) = 2
        ) AND NOT EXISTS (
            SELECT 1 FROM pg_indexes
            WHERE schemaname = 'admin' AND indexname = 'idx_audit_log_target_created'
        ) THEN
            CREATE INDEX idx_audit_log_target_created
                ON admin.audit_log (target_type, target_id, created_at DESC);
        END IF;

        ANALYZE admin.audit_log;
    END IF;
END $$;
