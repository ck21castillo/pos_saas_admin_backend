-- Ejecutar SOLO en bersano_control despues de la matriz comercial y la
-- correccion de actor de empresa_entitlement_excepcion.
-- No ejecutar en tenants. No crea activaciones para empresas existentes.

BEGIN;

CREATE TABLE IF NOT EXISTS admin.saas_complemento (
    id_complemento bigserial PRIMARY KEY,
    codigo varchar(80) NOT NULL UNIQUE,
    nombre varchar(160) NOT NULL,
    descripcion text NULL,
    activo boolean NOT NULL DEFAULT true,
    tipo_comercial varchar(20) NOT NULL,
    valor_mensual numeric(14,2) NOT NULL DEFAULT 0,
    valor_anual numeric(14,2) NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT saas_complemento_tipo_chk CHECK (tipo_comercial IN ('COMPLEMENTO', 'SOLO_INTERNO')),
    CONSTRAINT saas_complemento_valor_chk CHECK (valor_mensual >= 0 AND valor_anual >= 0)
);

CREATE TABLE IF NOT EXISTS admin.saas_complemento_plan (
    id_complemento bigint NOT NULL REFERENCES admin.saas_complemento(id_complemento) ON DELETE CASCADE,
    id_plan bigint NOT NULL REFERENCES admin.saas_plan(id_plan) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (id_complemento, id_plan)
);

CREATE TABLE IF NOT EXISTS admin.saas_complemento_modulo (
    id_complemento bigint NOT NULL REFERENCES admin.saas_complemento(id_complemento) ON DELETE CASCADE,
    id_modulo bigint NOT NULL REFERENCES pos_saas.modulo(id_modulo) ON DELETE CASCADE,
    PRIMARY KEY (id_complemento, id_modulo)
);

CREATE TABLE IF NOT EXISTS admin.saas_complemento_permiso (
    id_complemento bigint NOT NULL REFERENCES admin.saas_complemento(id_complemento) ON DELETE CASCADE,
    id_permiso bigint NOT NULL REFERENCES pos_saas.permiso(id_permiso) ON DELETE CASCADE,
    PRIMARY KEY (id_complemento, id_permiso)
);

CREATE TABLE IF NOT EXISTS admin.saas_complemento_capacidad (
    id_complemento bigint NOT NULL REFERENCES admin.saas_complemento(id_complemento) ON DELETE CASCADE,
    codigo_capacidad varchar(100) NOT NULL REFERENCES pos_saas.capacidad(codigo_capacidad) ON DELETE CASCADE,
    PRIMARY KEY (id_complemento, codigo_capacidad)
);

CREATE TABLE IF NOT EXISTS admin.saas_complemento_dependencia (
    id_complemento bigint NOT NULL REFERENCES admin.saas_complemento(id_complemento) ON DELETE CASCADE,
    id_complemento_requerido bigint NOT NULL REFERENCES admin.saas_complemento(id_complemento) ON DELETE RESTRICT,
    PRIMARY KEY (id_complemento, id_complemento_requerido),
    CONSTRAINT saas_complemento_dependencia_distinta_chk CHECK (id_complemento <> id_complemento_requerido)
);

CREATE TABLE IF NOT EXISTS admin.empresa_saas_complemento (
    id_empresa_saas_complemento bigserial PRIMARY KEY,
    id_empresa bigint NOT NULL REFERENCES pos_saas.empresa(id_empresa) ON DELETE CASCADE,
    id_complemento bigint NOT NULL REFERENCES admin.saas_complemento(id_complemento) ON DELETE RESTRICT,
    estado varchar(16) NOT NULL DEFAULT 'ACTIVO',
    valor_mensual_acordado numeric(14,2) NOT NULL DEFAULT 0,
    valor_anual_acordado numeric(14,2) NOT NULL DEFAULT 0,
    ciclo_cobro varchar(10) NOT NULL DEFAULT 'MENSUAL',
    fecha_inicio date NOT NULL DEFAULT current_date,
    fecha_fin date NULL,
    medio_pago varchar(80) NULL,
    referencia_pago varchar(160) NULL,
    observacion text NULL,
    actor_id bigint NULL REFERENCES admin.superadmin_user(id_superadmin) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT empresa_saas_complemento_unq UNIQUE (id_empresa, id_complemento),
    CONSTRAINT empresa_saas_complemento_estado_chk CHECK (estado IN ('ACTIVO', 'INACTIVO')),
    CONSTRAINT empresa_saas_complemento_ciclo_chk CHECK (ciclo_cobro IN ('MENSUAL', 'ANUAL')),
    CONSTRAINT empresa_saas_complemento_fechas_chk CHECK (fecha_fin IS NULL OR fecha_fin >= fecha_inicio),
    CONSTRAINT empresa_saas_complemento_valores_chk CHECK (
        valor_mensual_acordado >= 0 AND valor_anual_acordado >= 0
    )
);

CREATE INDEX IF NOT EXISTS idx_empresa_saas_complemento_effective
    ON admin.empresa_saas_complemento (id_empresa, estado, fecha_inicio, fecha_fin);
CREATE INDEX IF NOT EXISTS idx_saas_complemento_plan_plan
    ON admin.saas_complemento_plan (id_plan, id_complemento);
CREATE INDEX IF NOT EXISTS idx_saas_complemento_modulo_modulo
    ON admin.saas_complemento_modulo (id_modulo, id_complemento);
CREATE INDEX IF NOT EXISTS idx_saas_complemento_permiso_permiso
    ON admin.saas_complemento_permiso (id_permiso, id_complemento);

CREATE OR REPLACE FUNCTION admin.audit_empresa_saas_complemento()
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
        'EMPRESA_SAAS_COMPLEMENTO_' || TG_OP,
        'empresa',
        CASE WHEN TG_OP = 'DELETE' THEN OLD.id_empresa ELSE NEW.id_empresa END,
        CASE WHEN TG_OP IN ('UPDATE', 'DELETE') THEN to_jsonb(OLD) ELSE NULL END,
        CASE WHEN TG_OP IN ('INSERT', 'UPDATE') THEN to_jsonb(NEW) ELSE NULL END,
        NULL,
        'database-trigger'
    );

    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_audit_empresa_saas_complemento ON admin.empresa_saas_complemento;
CREATE TRIGGER trg_audit_empresa_saas_complemento
AFTER INSERT OR UPDATE OR DELETE ON admin.empresa_saas_complemento
FOR EACH ROW EXECUTE FUNCTION admin.audit_empresa_saas_complemento();

-- Catalogo inicial. Los precios quedan en cero para configurarlos desde el
-- panel; no cambia ninguna inclusion por defecto de los planes actuales.
INSERT INTO admin.saas_complemento (
    codigo, nombre, descripcion, activo, tipo_comercial, valor_mensual, valor_anual
) VALUES
    ('WHATSAPP_POS', 'WhatsApp para comprobantes POS', 'Envio de comprobantes de venta por WhatsApp.', true, 'COMPLEMENTO', 0, 0),
    ('WHATSAPP_SOPORTE', 'WhatsApp para comprobantes de soporte', 'Envio de comprobantes de ingreso y entrega de soporte.', true, 'COMPLEMENTO', 0, 0),
    ('PRECONTABILIDAD_INTERNA', 'Precontabilidad interna', 'Habilitacion exclusiva para desarrollo interno; no es vendible.', true, 'SOLO_INTERNO', 0, 0)
ON CONFLICT (codigo) DO UPDATE SET
    nombre = EXCLUDED.nombre,
    descripcion = EXCLUDED.descripcion,
    tipo_comercial = EXCLUDED.tipo_comercial,
    updated_at = now();

INSERT INTO admin.saas_complemento_plan (id_complemento, id_plan)
SELECT c.id_complemento, p.id_plan
FROM admin.saas_complemento c
JOIN admin.saas_plan p ON p.codigo IN ('ESENCIAL', 'PRO', 'SOPORTE_ESENCIAL', 'SOPORTE_PRO')
WHERE c.codigo = 'WHATSAPP_POS'
ON CONFLICT DO NOTHING;

INSERT INTO admin.saas_complemento_plan (id_complemento, id_plan)
SELECT c.id_complemento, p.id_plan
FROM admin.saas_complemento c
JOIN admin.saas_plan p ON p.codigo IN ('SOPORTE_ESENCIAL', 'SOPORTE_PRO')
WHERE c.codigo = 'WHATSAPP_SOPORTE'
ON CONFLICT DO NOTHING;

INSERT INTO admin.saas_complemento_permiso (id_complemento, id_permiso)
SELECT c.id_complemento, p.id_permiso
FROM admin.saas_complemento c
JOIN pos_saas.permiso p ON p.codigo = 'WHATSAPP__POS_ENVIAR_COMPROBANTE'
WHERE c.codigo = 'WHATSAPP_POS'
ON CONFLICT DO NOTHING;

INSERT INTO admin.saas_complemento_permiso (id_complemento, id_permiso)
SELECT c.id_complemento, p.id_permiso
FROM admin.saas_complemento c
JOIN pos_saas.permiso p ON p.codigo = 'WHATSAPP__SOPORTE_ENVIAR_COMPROBANTE'
WHERE c.codigo = 'WHATSAPP_SOPORTE'
ON CONFLICT DO NOTHING;

INSERT INTO admin.saas_complemento_modulo (id_complemento, id_modulo)
SELECT c.id_complemento, m.id_modulo
FROM admin.saas_complemento c
JOIN pos_saas.modulo m ON m.ruta = '/precontabilidad'
WHERE c.codigo = 'PRECONTABILIDAD_INTERNA'
ON CONFLICT DO NOTHING;

INSERT INTO admin.saas_complemento_permiso (id_complemento, id_permiso)
SELECT c.id_complemento, p.id_permiso
FROM admin.saas_complemento c
JOIN pos_saas.permiso p ON p.codigo LIKE 'PRECONTABILIDAD__%'
WHERE c.codigo = 'PRECONTABILIDAD_INTERNA'
ON CONFLICT DO NOTHING;

INSERT INTO admin.saas_complemento_capacidad (id_complemento, codigo_capacidad)
SELECT c.id_complemento, cap.codigo_capacidad
FROM admin.saas_complemento c
JOIN pos_saas.capacidad cap ON cap.codigo_capacidad = 'PRECONTABILIDAD'
WHERE c.codigo = 'PRECONTABILIDAD_INTERNA'
ON CONFLICT DO NOTHING;

-- Transicion no destructiva: instalar el catalogo no modifica ninguna fila de
-- admin.empresa_modulo ni admin.empresa_permiso. Asi, las asignaciones
-- manuales existentes (incluida Precontabilidad del tenant interno) siguen
-- disponibles mientras el sistema continua en ENTITLEMENTS_MODE=legacy.
--
-- Cuando se active ENTITLEMENTS_MODE=plans, esas asignaciones dejan de ser una
-- fuente comercial efectiva, pero tampoco se borran. La materializacion se
-- normaliza despues, de forma acotada, al guardar una suscripcion, operar un
-- complemento, aplicar una excepcion interna o mediante una accion explicita.
-- No se infiere ni crea automaticamente PRECONTABILIDAD_INTERNA.

ANALYZE admin.saas_complemento;
ANALYZE admin.empresa_saas_complemento;

COMMIT;
