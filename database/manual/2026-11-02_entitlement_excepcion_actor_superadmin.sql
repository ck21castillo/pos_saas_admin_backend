-- Ejecutar SOLO en bersano_control despues de
-- 2026-11-02_saas_matriz_comercial_y_excepciones.sql.
-- La excepcion es administrativa: su actor es un superadmin, no un usuario
-- del tenant. No crea una tabla nueva ni modifica suscripciones o matrices.

BEGIN;

-- Una fila historica con actor que no corresponde a un superadmin requiere
-- correccion explicita; no se borra ni se atribuye a otra persona en silencio.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM admin.empresa_entitlement_excepcion e
        LEFT JOIN admin.superadmin_user sa ON sa.id_superadmin = e.actor_id
        WHERE e.actor_id IS NOT NULL
          AND sa.id_superadmin IS NULL
    ) THEN
        RAISE EXCEPTION 'ENTITLEMENT_EXCEPTION_ACTOR_NOT_SUPERADMIN';
    END IF;
END;
$$;

DO $$
DECLARE
    constraint_name text;
BEGIN
    FOR constraint_name IN
        SELECT c.conname
        FROM pg_constraint c
        JOIN pg_attribute a
          ON a.attrelid = c.conrelid
         AND a.attnum = ANY(c.conkey)
        WHERE c.conrelid = 'admin.empresa_entitlement_excepcion'::regclass
          AND c.contype = 'f'
          AND a.attname = 'actor_id'
    LOOP
        EXECUTE format(
            'ALTER TABLE admin.empresa_entitlement_excepcion DROP CONSTRAINT %I',
            constraint_name
        );
    END LOOP;
END;
$$;

ALTER TABLE admin.empresa_entitlement_excepcion
    ADD CONSTRAINT empresa_entitlement_excepcion_actor_superadmin_fkey
    FOREIGN KEY (actor_id)
    REFERENCES admin.superadmin_user(id_superadmin)
    ON UPDATE CASCADE
    ON DELETE SET NULL;

CREATE OR REPLACE FUNCTION admin.audit_empresa_entitlement_excepcion()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    actor bigint;
    email varchar(255);
BEGIN
    actor := CASE WHEN TG_OP = 'DELETE' THEN OLD.actor_id ELSE NEW.actor_id END;

    SELECT u.email INTO email
      FROM admin.superadmin_user u
     WHERE u.id_superadmin = actor
     LIMIT 1;

    INSERT INTO admin.audit_log (
        actor_id, actor_email, action, target_type, target_id,
        before, after, ip, user_agent
    ) VALUES (
        actor,
        COALESCE(email, 'system'),
        'EMPRESA_ENTITLEMENT_EXCEPCION_' || TG_OP,
        'empresa',
        CASE WHEN TG_OP = 'DELETE' THEN OLD.id_empresa ELSE NEW.id_empresa END,
        CASE WHEN TG_OP IN ('UPDATE', 'DELETE') THEN to_jsonb(OLD) ELSE NULL END,
        CASE WHEN TG_OP IN ('INSERT', 'UPDATE') THEN to_jsonb(NEW) ELSE NULL END,
        NULL,
        'database-trigger'
    );

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;

COMMIT;
