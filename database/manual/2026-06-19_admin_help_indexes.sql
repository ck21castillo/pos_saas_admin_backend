-- Indices para el modulo de ayuda del panel administrativo.
-- Seguro para ejecutar multiples veces.

CREATE INDEX IF NOT EXISTS idx_help_ticket_created
    ON admin.help_ticket (created_at DESC, id_ticket DESC);

CREATE INDEX IF NOT EXISTS idx_help_ticket_estado_created
    ON admin.help_ticket (estado, created_at DESC, id_ticket DESC);

CREATE INDEX IF NOT EXISTS idx_help_ticket_empresa_created
    ON admin.help_ticket (id_empresa, created_at DESC, id_ticket DESC);

CREATE INDEX IF NOT EXISTS idx_help_ticket_message_ticket_created
    ON admin.help_ticket_message (id_ticket, created_at ASC, id_message ASC);

ANALYZE admin.help_ticket;
ANALYZE admin.help_ticket_message;

-- Opcional si el servidor permite extensiones y la busqueda textual crece mucho:
-- CREATE EXTENSION IF NOT EXISTS pg_trgm;
-- CREATE INDEX IF NOT EXISTS idx_help_ticket_asunto_trgm
--     ON admin.help_ticket USING gin (asunto gin_trgm_ops);
-- CREATE INDEX IF NOT EXISTS idx_help_ticket_contacto_nombre_trgm
--     ON admin.help_ticket USING gin (contacto_nombre gin_trgm_ops);
-- CREATE INDEX IF NOT EXISTS idx_help_ticket_contacto_email_trgm
--     ON admin.help_ticket USING gin (contacto_email gin_trgm_ops);
