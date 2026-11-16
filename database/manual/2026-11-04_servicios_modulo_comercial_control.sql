-- Ejecutar SOLO en bersano_control, como propietario del esquema.
--
-- Crea/alinea el módulo comercial Servicios y lo incluye en los cuatro planes
-- públicos. No materializa empresa_modulo ni empresa_permiso: esas tablas son
-- una proyección técnica que se actualiza al guardar suscripción o mediante
-- la materialización explícita. El catálogo de módulos se consulta desde
-- bersano_control; no se crea ninguna copia tenant.

BEGIN;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pos_saas.permiso
        WHERE codigo = 'PRODUCTOS__VER'
    ) THEN
        RAISE EXCEPTION 'SERVICIOS_REQUIERE_PRODUCTOS_VER';
    END IF;
END $$;

WITH gate AS (
    SELECT id_permiso
    FROM pos_saas.permiso
    WHERE codigo = 'PRODUCTOS__VER'
    LIMIT 1
)
INSERT INTO pos_saas.modulo (
    nombre, ruta, icono, orden, parent_id, estado,
    created_at, updated_at, id_permiso_gate
)
SELECT
    'Servicios', '/servicios', 'sell', 0, NULL, 1,
    now(), now(), gate.id_permiso
FROM gate
WHERE NOT EXISTS (
    SELECT 1 FROM pos_saas.modulo WHERE ruta = '/servicios'
);

WITH gate AS (
    SELECT id_permiso
    FROM pos_saas.permiso
    WHERE codigo = 'PRODUCTOS__VER'
    LIMIT 1
)
UPDATE pos_saas.modulo m
SET nombre = 'Servicios',
    icono = 'sell',
    parent_id = NULL,
    estado = 1,
    id_permiso_gate = gate.id_permiso,
    updated_at = now()
FROM gate
WHERE m.ruta = '/servicios'
  AND (
      m.nombre,
      m.icono,
      m.parent_id,
      m.estado,
      m.id_permiso_gate
  ) IS DISTINCT FROM (
      'Servicios',
      'sell',
      NULL::bigint,
      1,
      gate.id_permiso
  );

-- Inserta Servicios inmediatamente después de Deudores y antes de Productos.
-- El desplazamiento ocurre una única vez: si Servicios ya ocupa esa posición,
-- una nueva ejecución no cambia el orden de ningún módulo.
DO $$
DECLARE
    deudores_orden integer;
    productos_orden integer;
    servicios_orden integer;
BEGIN
    SELECT orden INTO deudores_orden
    FROM pos_saas.modulo
    WHERE ruta = '/deudores';

    SELECT orden INTO productos_orden
    FROM pos_saas.modulo
    WHERE ruta = '/productos';

    SELECT orden INTO servicios_orden
    FROM pos_saas.modulo
    WHERE ruta = '/servicios';

    IF deudores_orden IS NULL OR productos_orden IS NULL OR servicios_orden IS NULL THEN
        RAISE EXCEPTION 'SERVICIOS_ORDEN_REFERENCIA_FALTANTE';
    END IF;

    IF deudores_orden >= productos_orden THEN
        RAISE EXCEPTION 'SERVICIOS_ORDEN_REFERENCIA_INVALIDO';
    END IF;

    IF servicios_orden <> deudores_orden + 1 THEN
        UPDATE pos_saas.modulo
        SET orden = orden + 1,
            updated_at = now()
        WHERE orden > deudores_orden
          AND ruta <> '/servicios';

        UPDATE pos_saas.modulo
        SET orden = deudores_orden + 1,
            updated_at = now()
        WHERE ruta = '/servicios';
    END IF;
END $$;

INSERT INTO admin.saas_plan_modulo (
    id_plan, id_modulo, enabled, created_at, updated_at
)
SELECT p.id_plan, m.id_modulo, true, now(), now()
FROM admin.saas_plan p
JOIN pos_saas.modulo m ON m.ruta = '/servicios'
WHERE p.codigo IN ('ESENCIAL', 'PRO', 'SOPORTE_ESENCIAL', 'SOPORTE_PRO')
ON CONFLICT (id_plan, id_modulo) DO UPDATE
SET enabled = EXCLUDED.enabled,
    updated_at = now()
WHERE admin.saas_plan_modulo.enabled IS DISTINCT FROM EXCLUDED.enabled;

DO $$
BEGIN
    IF (
        SELECT count(*)
        FROM admin.saas_plan_modulo spm
        JOIN admin.saas_plan p ON p.id_plan = spm.id_plan
        JOIN pos_saas.modulo m ON m.id_modulo = spm.id_modulo
        WHERE p.codigo IN ('ESENCIAL', 'PRO', 'SOPORTE_ESENCIAL', 'SOPORTE_PRO')
          AND m.ruta = '/servicios'
          AND spm.enabled = true
    ) <> 4 THEN
        RAISE EXCEPTION 'SERVICIOS_PLANES_INCOMPLETOS';
    END IF;
END $$;

COMMIT;
