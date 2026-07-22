-- Normaliza importaciones administrativas antiguas de inventario inicial.
--
-- Ejecutar SOLO en tenants donde se haya importado un archivo masivo desde panel admin y el listado
-- de inventario inicial haya quedado con un solo registro que contiene muchos productos.
--
-- Objetivo:
-- - Mantener el primer producto en el registro original.
-- - Crear un registro confirmado de inventario inicial por cada producto restante.
-- - Mover cada detalle a su nuevo registro.
-- - Actualizar los movimientos IN de alta_inicial para que referencia_id apunte
--   al registro individual correspondiente.
--
-- No modifica stock, productos, lotes ni costos. Solo corrige la trazabilidad visual
-- y documental de inventario inicial.

BEGIN;

CREATE TEMP TABLE tmp_admin_import_split (
    id_detalle bigint PRIMARY KEY,
    id_empresa bigint NOT NULL,
    old_id_inicial bigint NOT NULL,
    new_id_inicial bigint NOT NULL,
    id_producto bigint NOT NULL
) ON COMMIT DROP;

WITH multi AS (
    SELECT iid.id_empresa, iid.id_inicial
    FROM pos_saas.inventario_inicial_detalle iid
    JOIN pos_saas.inventario_inicial ii
      ON ii.id_empresa = iid.id_empresa
     AND ii.id_inicial = iid.id_inicial
    GROUP BY iid.id_empresa, iid.id_inicial
    HAVING COUNT(*) > 1
), ranked AS (
    SELECT
        iid.id_detalle,
        iid.id_empresa,
        iid.id_inicial AS old_id_inicial,
        iid.id_producto,
        ROW_NUMBER() OVER (
            PARTITION BY iid.id_empresa, iid.id_inicial
            ORDER BY iid.id_detalle ASC
        ) AS rn
    FROM pos_saas.inventario_inicial_detalle iid
    JOIN multi m
      ON m.id_empresa = iid.id_empresa
     AND m.id_inicial = iid.id_inicial
)
INSERT INTO tmp_admin_import_split (
    id_detalle,
    id_empresa,
    old_id_inicial,
    new_id_inicial,
    id_producto
)
SELECT
    id_detalle,
    id_empresa,
    old_id_inicial,
    nextval('pos_saas.inventario_inicial_id_inicial_seq')::bigint,
    id_producto
FROM ranked
WHERE rn > 1;

INSERT INTO pos_saas.inventario_inicial (
    id_inicial,
    id_empresa,
    estado,
    modo,
    actualizar_costo_producto,
    id_usuario_creador,
    id_usuario_confirmador,
    confirmado_at,
    created_at,
    updated_at
)
SELECT
    s.new_id_inicial,
    ii.id_empresa,
    ii.estado,
    ii.modo,
    ii.actualizar_costo_producto,
    ii.id_usuario_creador,
    ii.id_usuario_confirmador,
    ii.confirmado_at,
    ii.created_at,
    now()
FROM tmp_admin_import_split s
JOIN pos_saas.inventario_inicial ii
  ON ii.id_empresa = s.id_empresa
 AND ii.id_inicial = s.old_id_inicial
ORDER BY s.id_detalle;

UPDATE pos_saas.inventario_inicial_detalle iid
SET id_inicial = s.new_id_inicial,
    updated_at = now()
FROM tmp_admin_import_split s
WHERE iid.id_empresa = s.id_empresa
  AND iid.id_detalle = s.id_detalle;

UPDATE pos_saas.movimientos_inventario mi
SET referencia_id = s.new_id_inicial,
    observaciones = TRIM(BOTH ' ' FROM COALESCE(mi.observaciones, '') || ' Registro individualizado desde importacion administrativa.')
FROM tmp_admin_import_split s
WHERE mi.id_empresa = s.id_empresa
  AND mi.id_producto = s.id_producto
  AND mi.referencia_tipo = 'inventario.inicial.registro'
  AND mi.referencia_id = s.old_id_inicial
  AND mi.tipo = 'IN'
  AND mi.motivo = 'alta_inicial';

ANALYZE pos_saas.inventario_inicial;
ANALYZE pos_saas.inventario_inicial_detalle;
ANALYZE pos_saas.movimientos_inventario;

COMMIT;

SELECT
    'registros_multi_detalle_restantes' AS item,
    COUNT(*) AS total
FROM (
    SELECT iid.id_empresa, iid.id_inicial
    FROM pos_saas.inventario_inicial_detalle iid
    GROUP BY iid.id_empresa, iid.id_inicial
    HAVING COUNT(*) > 1
) x;