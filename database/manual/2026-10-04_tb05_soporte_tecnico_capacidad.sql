-- T-B-05: ejecutar una vez en bersano_control antes de desplegar el runtime.
-- Declara la capacidad comercial SOPORTE_TECNICO y el catalogo capacidad ->
-- recurso. No se ejecuta en tenants ni habilita soporte por defecto.

BEGIN;

INSERT INTO pos_saas.capacidad (codigo_capacidad, nombre, descripcion, estado)
VALUES (
    'SOPORTE_TECNICO',
    'Soporte tecnico',
    'Permite operar ordenes de servicio tecnico y sus pagos, entregas, correcciones y exportables.',
    1
)
ON CONFLICT (codigo_capacidad) DO UPDATE
SET nombre = EXCLUDED.nombre,
    descripcion = EXCLUDED.descripcion,
    estado = EXCLUDED.estado,
    updated_at = now();

CREATE TABLE IF NOT EXISTS admin.saas_capacidad_modulo (
    id_modulo bigint NOT NULL REFERENCES pos_saas.modulo(id_modulo) ON DELETE CASCADE,
    codigo_capacidad text NOT NULL REFERENCES pos_saas.capacidad(codigo_capacidad) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (id_modulo, codigo_capacidad)
);

CREATE TABLE IF NOT EXISTS admin.saas_capacidad_permiso (
    id_permiso bigint NOT NULL REFERENCES pos_saas.permiso(id_permiso) ON DELETE CASCADE,
    codigo_capacidad text NOT NULL REFERENCES pos_saas.capacidad(codigo_capacidad) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (id_permiso, codigo_capacidad)
);

CREATE INDEX IF NOT EXISTS saas_capacidad_modulo_capacidad_idx
    ON admin.saas_capacidad_modulo (codigo_capacidad);
CREATE INDEX IF NOT EXISTS saas_capacidad_permiso_capacidad_idx
    ON admin.saas_capacidad_permiso (codigo_capacidad);

CREATE TABLE IF NOT EXISTS admin.tenant_sync_outbox (
    id_tenant_sync_outbox bigserial PRIMARY KEY,
    id_empresa bigint NOT NULL REFERENCES pos_saas.empresa(id_empresa) ON DELETE CASCADE,
    tipo varchar(40) NOT NULL,
    estado varchar(24) NOT NULL DEFAULT 'PENDIENTE',
    intentos integer NOT NULL DEFAULT 0,
    ultimo_error text NULL,
    proximo_intento_at timestamptz NULL DEFAULT now(),
    sincronizado_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT tenant_sync_outbox_tipo_chk CHECK (tipo IN ('BUSINESS_CONFIG')),
    CONSTRAINT tenant_sync_outbox_estado_chk CHECK (estado IN ('PENDIENTE', 'SINCRONIZADO')),
    CONSTRAINT tenant_sync_outbox_intentos_chk CHECK (intentos >= 0),
    CONSTRAINT tenant_sync_outbox_empresa_tipo_uk UNIQUE (id_empresa, tipo)
);

CREATE INDEX IF NOT EXISTS tenant_sync_outbox_pending_idx
    ON admin.tenant_sync_outbox (tipo, estado, proximo_intento_at, id_tenant_sync_outbox);

-- Cada fila es un requisito. Si un recurso tiene varias filas, requiere todas
-- sus capacidades. PRECONTABILIDAD__EXPORTAR necesita Precontabilidad y
-- Exportacion contable.
DELETE FROM admin.saas_capacidad_modulo
WHERE codigo_capacidad IN ('PRECONTABILIDAD', 'SOPORTE_TECNICO')
  AND id_modulo IN (
      SELECT id_modulo FROM pos_saas.modulo WHERE ruta IN ('/precontabilidad', '/soporte')
  );

INSERT INTO admin.saas_capacidad_modulo (id_modulo, codigo_capacidad)
SELECT id_modulo, 'PRECONTABILIDAD'
FROM pos_saas.modulo
WHERE ruta = '/precontabilidad'
UNION ALL
SELECT id_modulo, 'SOPORTE_TECNICO'
FROM pos_saas.modulo
WHERE ruta = '/soporte'
ON CONFLICT DO NOTHING;

DELETE FROM admin.saas_capacidad_permiso
WHERE codigo_capacidad IN ('PRECONTABILIDAD', 'EXPORTACION_CONTABLE', 'SOPORTE_TECNICO')
  AND id_permiso IN (
      SELECT id_permiso
      FROM pos_saas.permiso
      WHERE codigo IN ('PRECONTABILIDAD__VER', 'PRECONTABILIDAD__CONFIGURAR', 'PRECONTABILIDAD__EXPORTAR', 'WHATSAPP__SOPORTE_ENVIAR_COMPROBANTE')
         OR codigo LIKE 'SOPORTE__%'
  );

INSERT INTO admin.saas_capacidad_permiso (id_permiso, codigo_capacidad)
SELECT id_permiso, 'PRECONTABILIDAD'
FROM pos_saas.permiso
WHERE codigo IN ('PRECONTABILIDAD__VER', 'PRECONTABILIDAD__CONFIGURAR', 'PRECONTABILIDAD__EXPORTAR')
UNION ALL
SELECT id_permiso, 'EXPORTACION_CONTABLE'
FROM pos_saas.permiso
WHERE codigo = 'PRECONTABILIDAD__EXPORTAR'
UNION ALL
SELECT id_permiso, 'SOPORTE_TECNICO'
FROM pos_saas.permiso
WHERE codigo LIKE 'SOPORTE__%'
UNION ALL
SELECT id_permiso, 'SOPORTE_TECNICO'
FROM pos_saas.permiso
WHERE codigo = 'WHATSAPP__SOPORTE_ENVIAR_COMPROBANTE'
ON CONFLICT DO NOTHING;

COMMIT;
