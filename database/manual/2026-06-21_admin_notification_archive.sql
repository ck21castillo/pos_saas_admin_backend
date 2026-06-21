-- Campos operativos para archivado y limpieza de notificaciones.
-- Ejecutar en la base de control (bersano_control). Seguro para ejecutar multiples veces.

ALTER TABLE admin.notification
    ADD COLUMN IF NOT EXISTS archived_at timestamptz NULL,
    ADD COLUMN IF NOT EXISTS archived_by bigint NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'notification_archived_by_fkey'
    ) THEN
        ALTER TABLE admin.notification
            ADD CONSTRAINT notification_archived_by_fkey
            FOREIGN KEY (archived_by)
            REFERENCES admin.superadmin_user(id_superadmin)
            ON DELETE SET NULL;
    END IF;
END$$;

CREATE INDEX IF NOT EXISTS idx_admin_notification_archived_created
    ON admin.notification (archived_at, created_at DESC, id_notification DESC);

CREATE INDEX IF NOT EXISTS idx_admin_notification_expires
    ON admin.notification (expires_at)
    WHERE expires_at IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_admin_notification_starts
    ON admin.notification (starts_at)
    WHERE starts_at IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_admin_notification_read_notification
    ON admin.notification_read (id_notification, read_at DESC);

ANALYZE admin.notification;
ANALYZE admin.notification_read;
