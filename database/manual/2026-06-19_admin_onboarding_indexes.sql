-- Indices para solicitudes e invitaciones del onboarding administrativo.
-- Seguro para ejecutar multiples veces.

CREATE INDEX IF NOT EXISTS idx_invitation_request_estado_created
    ON admin.invitation_request (estado, created_at DESC, id_request DESC);

CREATE INDEX IF NOT EXISTS idx_invitation_email_created
    ON admin.invitation (email, created_at DESC, id_invitation DESC);

CREATE INDEX IF NOT EXISTS idx_invitation_created
    ON admin.invitation (created_at DESC, id_invitation DESC);

ANALYZE admin.invitation_request;
ANALYZE admin.invitation;