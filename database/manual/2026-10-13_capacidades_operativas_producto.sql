-- Separa las formas operativas de registrar/vender productos del catalogo SaaS.
-- Se ejecuta una vez en bersano_control, despues de los catalogos SaaS y de
-- categorias de negocio. No modifica tablas ni datos operativos de tenants.

BEGIN;

UPDATE pos_saas.capacidad
SET descripcion = 'Permite registrar un serial o IMEI por cada unidad vendida cuando la empresa lo habilite.',
    updated_at = now()
WHERE codigo_capacidad = 'SERIALES_EN_VENTA';

-- Cada empresa conserva exactamente el estado efectivo que ya tenia. Las que
-- nunca recibieron una fila quedan deshabilitadas hasta que un administrador
-- la configure de forma explicita.
INSERT INTO pos_saas.empresa_capacidad (id_empresa, codigo_capacidad, enabled)
SELECT e.id_empresa, 'SERIALES_EN_VENTA', false
FROM pos_saas.empresa e
WHERE NOT EXISTS (
    SELECT 1
    FROM pos_saas.empresa_capacidad ec
    WHERE ec.id_empresa = e.id_empresa
      AND ec.codigo_capacidad = 'SERIALES_EN_VENTA'
)
ON CONFLICT (id_empresa, codigo_capacidad) DO NOTHING;

-- Las categorias solo sugieren formas operativas de producto. Soporte sigue
-- siendo una capacidad comercial definida por plan/suscripcion.
DELETE FROM admin.saas_categoria_negocio_capacidad
WHERE codigo_capacidad = 'SOPORTE_TECNICO';

INSERT INTO admin.saas_categoria_negocio_capacidad
    (id_categoria_negocio, codigo_capacidad, habilitada_por_defecto)
SELECT c.id_categoria_negocio, defaults.codigo_capacidad, true
FROM (
    VALUES
        ('DROGUERIA', 'LOTES_VENCIMIENTOS'),
        ('DROGUERIA', 'PRODUCTOS_PRESENTACION'),
        ('TIENDA_MINIMARKET', 'LOTES_VENCIMIENTOS'),
        ('TIENDA_MINIMARKET', 'PRODUCTOS_PESO'),
        ('TIENDA_TECNOLOGIA', 'SERIALES_EN_VENTA'),
        ('TECNOLOGIA_SERVICIO_TECNICO', 'SERIALES_EN_VENTA')
) AS defaults(codigo_categoria, codigo_capacidad)
JOIN admin.saas_categoria_negocio c
  ON c.codigo = defaults.codigo_categoria
JOIN pos_saas.capacidad capacidad
  ON capacidad.codigo_capacidad = defaults.codigo_capacidad
WHERE capacidad.estado = 1
ON CONFLICT (id_categoria_negocio, codigo_capacidad) DO UPDATE
SET habilitada_por_defecto = EXCLUDED.habilitada_por_defecto;

-- IMEI deja de ser una promesa, excepcion o techo comercial. Se conserva la
-- fila historica de la excepcion, pero queda inactiva y no vuelve a gobernar
-- empresa_capacidad.
DELETE FROM admin.saas_plan_capacidad
WHERE codigo_capacidad = 'SERIALES_EN_VENTA';

UPDATE admin.saas_empresa_capacidad_excepcion
SET activa = false,
    updated_at = now()
WHERE codigo_capacidad = 'SERIALES_EN_VENTA'
  AND activa = true;

DO $$
BEGIN
    IF to_regclass('admin.saas_plan_beneficio') IS NOT NULL THEN
        DELETE FROM admin.saas_plan_beneficio
        WHERE codigo_capacidad = 'SERIALES_EN_VENTA';
    END IF;
END $$;

COMMIT;

ANALYZE pos_saas.empresa_capacidad;
ANALYZE admin.saas_categoria_negocio_capacidad;
