-- Campos operativos para mesa de ayuda del panel administrativo.
-- Ejecutar en la base de control (bersano_control). Seguro para ejecutar multiples veces.

ALTER TABLE admin.help_ticket
    ADD COLUMN IF NOT EXISTS assigned_to bigint NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'help_ticket_assigned_to_fkey'
    ) THEN
        ALTER TABLE admin.help_ticket
            ADD CONSTRAINT help_ticket_assigned_to_fkey
            FOREIGN KEY (assigned_to)
            REFERENCES admin.superadmin_user(id_superadmin)
            ON DELETE SET NULL;
    END IF;
END$$;

CREATE INDEX IF NOT EXISTS idx_help_ticket_prioridad_created
    ON admin.help_ticket (prioridad, created_at DESC, id_ticket DESC);

CREATE INDEX IF NOT EXISTS idx_help_ticket_assigned_created
    ON admin.help_ticket (assigned_to, created_at DESC, id_ticket DESC);

CREATE INDEX IF NOT EXISTS idx_help_ticket_created_id
    ON admin.help_ticket (created_at DESC, id_ticket DESC);

ANALYZE admin.help_ticket;
