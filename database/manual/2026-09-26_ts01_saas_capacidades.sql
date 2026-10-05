-- T-S-01: ejecutar una vez en bersano_control.
--
-- Define las capacidades SaaS financieras/fiscales, la matriz plan ->
-- capacidad y las excepciones auditables por empresa. No debe ejecutarse en
-- tenants: el panel sincroniza las capacidades efectivas hacia cada tenant.

BEGIN;

INSERT INTO pos_saas.capacidad (codigo_capacidad, nombre, descripcion, estado)
VALUES
    ('PRECONTABILIDAD', 'Precontabilidad', 'Permite configurar y consultar reglas y comprobantes precontables por empresa.', 1),
    ('EXPORTACION_CONTABLE', 'Exportacion contable', 'Permite generar exportables precontables cuando el mapeo este completo.', 1),
    ('FACTURACION_ELECTRONICA', 'Facturacion electronica', 'Permite habilitar y emitir documentos fiscales despues del checklist y la habilitacion externa.', 1),
    ('NOTAS_FISCALES', 'Notas fiscales', 'Permite solicitar notas credito o debito fiscales para documentos electronicos habilitados.', 1)
ON CONFLICT (codigo_capacidad) DO UPDATE
SET nombre = EXCLUDED.nombre,
    descripcion = EXCLUDED.descripcion,
    estado = EXCLUDED.estado,
    updated_at = now();

CREATE TABLE IF NOT EXISTS admin.saas_plan_capacidad (
    id_plan bigint NOT NULL REFERENCES admin.saas_plan(id_plan) ON DELETE CASCADE,
    codigo_capacidad text NOT NULL REFERENCES pos_saas.capacidad(codigo_capacidad) ON DELETE RESTRICT,
    incluida boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (id_plan, codigo_capacidad)
);

CREATE INDEX IF NOT EXISTS saas_plan_capacidad_codigo_idx
    ON admin.saas_plan_capacidad (codigo_capacidad);

CREATE TABLE IF NOT EXISTS admin.saas_empresa_capacidad_excepcion (
    id_empresa bigint NOT NULL REFERENCES pos_saas.empresa(id_empresa) ON DELETE CASCADE,
    codigo_capacidad text NOT NULL REFERENCES pos_saas.capacidad(codigo_capacidad) ON DELETE RESTRICT,
    enabled boolean NOT NULL,
    motivo text NOT NULL CHECK (btrim(motivo) <> ''),
    activa boolean NOT NULL DEFAULT true,
    creado_por bigint NULL,
    creado_por_email text NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (id_empresa, codigo_capacidad)
);

CREATE INDEX IF NOT EXISTS saas_empresa_capacidad_excepcion_empresa_activa_idx
    ON admin.saas_empresa_capacidad_excepcion (id_empresa, activa);

COMMIT;
