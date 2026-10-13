-- Ejecutar una vez en bersano_control como propietario de la base.
-- No ejecutar en tenants ni en bersano_tenant_template.
-- Catalogo comercial: no cambia planes, suscripciones, modulos, permisos ni outbox.

BEGIN;

CREATE TABLE IF NOT EXISTS admin.saas_categoria_negocio (
    id_categoria_negocio bigserial PRIMARY KEY,
    codigo varchar(80) NOT NULL,
    nombre varchar(120) NOT NULL,
    descripcion text NULL,
    perfil_operativo varchar(80) NOT NULL,
    activo boolean NOT NULL DEFAULT true,
    visible_publico boolean NOT NULL DEFAULT true,
    orden integer NOT NULL DEFAULT 100,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT saas_categoria_negocio_codigo_ux UNIQUE (codigo),
    CONSTRAINT saas_categoria_negocio_codigo_chk CHECK (codigo ~ '^[A-Z][A-Z0-9_]*$'),
    CONSTRAINT saas_categoria_negocio_perfil_chk CHECK (
        perfil_operativo IN (
            'GENERAL',
            'DROGUERIA',
            'TIENDA_MINIMARKET',
            'TECNOLOGIA_SERVICIO_TECNICO'
        )
    ),
    CONSTRAINT saas_categoria_negocio_orden_chk CHECK (orden >= 0)
);

CREATE INDEX IF NOT EXISTS idx_saas_categoria_negocio_publica_orden
    ON admin.saas_categoria_negocio (visible_publico, activo, orden, codigo);

CREATE TABLE IF NOT EXISTS admin.saas_categoria_negocio_capacidad (
    id_categoria_negocio bigint NOT NULL
        REFERENCES admin.saas_categoria_negocio(id_categoria_negocio)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    codigo_capacidad text NOT NULL
        REFERENCES pos_saas.capacidad(codigo_capacidad)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    habilitada_por_defecto boolean NOT NULL DEFAULT true,
    PRIMARY KEY (id_categoria_negocio, codigo_capacidad)
);

CREATE INDEX IF NOT EXISTS idx_saas_categoria_negocio_capacidad_codigo
    ON admin.saas_categoria_negocio_capacidad (codigo_capacidad);

ALTER TABLE pos_saas.empresa
    ADD COLUMN IF NOT EXISTS categoria_negocio varchar(80) NULL;

INSERT INTO admin.saas_categoria_negocio
    (codigo, nombre, descripcion, perfil_operativo, activo, visible_publico, orden)
VALUES
    ('GENERAL', 'Otro negocio', NULL, 'GENERAL', true, true, 10),
    ('DROGUERIA', 'Droguería', NULL, 'DROGUERIA', true, true, 20),
    ('TIENDA_MINIMARKET', 'Tienda / minimarket', NULL, 'TIENDA_MINIMARKET', true, true, 30),
    ('TECNOLOGIA_SERVICIO_TECNICO', 'Tecnología y servicio técnico', NULL, 'TECNOLOGIA_SERVICIO_TECNICO', true, true, 40),
    ('TIENDA_TECNOLOGIA', 'Tienda de tecnología', NULL, 'GENERAL', true, true, 50),
    ('FERRETERIA', 'Ferretería', NULL, 'GENERAL', true, true, 60),
    ('MISCELANEA_PAPELERIA', 'Miscelánea y papelería', NULL, 'GENERAL', true, true, 70),
    ('COLMENA', 'Colmena', NULL, 'GENERAL', true, true, 80),
    ('ROPA_CALZADO', 'Tienda de ropa y calzado', NULL, 'GENERAL', true, true, 90),
    ('COSMETICA_BELLEZA', 'Cosmética / belleza', NULL, 'GENERAL', true, true, 100),
    ('REPUESTOS_ACCESORIOS', 'Repuestos y accesorios', NULL, 'GENERAL', true, true, 110),
    ('TIENDA_MASCOTAS', 'Tienda de mascotas', NULL, 'GENERAL', true, true, 120)
ON CONFLICT (codigo) DO NOTHING;

INSERT INTO pos_saas.capacidad (codigo_capacidad, nombre, descripcion, estado)
VALUES (
    'SERIALES_EN_VENTA',
    'Seriales e IMEI en ventas',
    'Permite registrar seriales o IMEI durante operaciones de venta cuando el plan los habilite.',
    1
)
ON CONFLICT (codigo_capacidad) DO UPDATE
SET nombre = EXCLUDED.nombre,
    descripcion = EXCLUDED.descripcion,
    estado = EXCLUDED.estado,
    updated_at = now();

INSERT INTO admin.saas_categoria_negocio_capacidad
    (id_categoria_negocio, codigo_capacidad, habilitada_por_defecto)
SELECT c.id_categoria_negocio, v.codigo_capacidad, true
FROM (
    VALUES
        ('TIENDA_TECNOLOGIA', 'SERIALES_EN_VENTA'),
        ('TECNOLOGIA_SERVICIO_TECNICO', 'SOPORTE_TECNICO'),
        ('TECNOLOGIA_SERVICIO_TECNICO', 'SERIALES_EN_VENTA')
) AS v(codigo_categoria, codigo_capacidad)
JOIN admin.saas_categoria_negocio c ON c.codigo = v.codigo_categoria
JOIN pos_saas.capacidad capacidad ON capacidad.codigo_capacidad = v.codigo_capacidad
ON CONFLICT (id_categoria_negocio, codigo_capacidad) DO UPDATE
SET habilitada_por_defecto = EXCLUDED.habilitada_por_defecto;

UPDATE pos_saas.empresa empresa
SET categoria_negocio = CASE
    WHEN upper(trim(COALESCE(empresa.tipo_negocio, ''))) IN (
        'GENERAL',
        'DROGUERIA',
        'TIENDA_MINIMARKET',
        'TECNOLOGIA_SERVICIO_TECNICO',
        'FERRETERIA',
        'MISCELANEA_PAPELERIA',
        'COLMENA',
        'ROPA_CALZADO',
        'COSMETICA_BELLEZA',
        'REPUESTOS_ACCESORIOS',
        'TIENDA_MASCOTAS'
    ) THEN upper(trim(empresa.tipo_negocio))
    ELSE 'GENERAL'
END
WHERE empresa.categoria_negocio IS NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'empresa_categoria_negocio_fk'
          AND conrelid = 'pos_saas.empresa'::regclass
    ) THEN
        ALTER TABLE pos_saas.empresa
            ADD CONSTRAINT empresa_categoria_negocio_fk
            FOREIGN KEY (categoria_negocio)
            REFERENCES admin.saas_categoria_negocio(codigo)
            ON UPDATE CASCADE
            ON DELETE RESTRICT;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_empresa_categoria_negocio
    ON pos_saas.empresa (categoria_negocio)
    WHERE categoria_negocio IS NOT NULL;

COMMIT;

ANALYZE admin.saas_categoria_negocio;
ANALYZE admin.saas_categoria_negocio_capacidad;
ANALYZE pos_saas.empresa;
