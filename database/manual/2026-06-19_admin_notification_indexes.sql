-- Indices para notificaciones del panel administrativo.
-- Seguro para ejecutar multiples veces.

CREATE INDEX IF NOT EXISTS idx_admin_notification_created
    ON admin.notification (created_at DESC, id_notification DESC);

CREATE INDEX IF NOT EXISTS idx_admin_notification_scope_created
    ON admin.notification (scope, created_at DESC, id_notification DESC);

CREATE INDEX IF NOT EXISTS idx_admin_notification_estado_created
    ON admin.notification (estado, created_at DESC, id_notification DESC);

CREATE INDEX IF NOT EXISTS idx_admin_notification_empresa_created
    ON admin.notification (id_empresa, created_at DESC, id_notification DESC);

CREATE INDEX IF NOT EXISTS idx_admin_notification_user_created
    ON admin.notification (id_usuario, created_at DESC, id_notification DESC);

ANALYZE admin.notification;
ANALYZE admin.notification_read;

-- Opcional si el servidor permite extensiones y la busqueda textual crece mucho:
-- CREATE EXTENSION IF NOT EXISTS pg_trgm;
-- CREATE INDEX IF NOT EXISTS idx_admin_notification_titulo_trgm
--     ON admin.notification USING gin (titulo gin_trgm_ops);
-- CREATE INDEX IF NOT EXISTS idx_admin_notification_mensaje_trgm
--     ON admin.notification USING gin (mensaje gin_trgm_ops);