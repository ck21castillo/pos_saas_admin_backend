-- T-B-01 control-plane catalog. Run once in bersano_control after deploying
-- the admin backend. It does not touch financial facts or tenant operations.

BEGIN;

WITH permisos(codigo, descripcion) AS (
    VALUES
        ('PRECONTABILIDAD__VER', 'Consultar configuracion y checklist precontable'),
        ('PRECONTABILIDAD__CONFIGURAR', 'Configurar PUC, centros de costo y reglas precontables')
)
INSERT INTO pos_saas.permiso (codigo, descripcion)
SELECT codigo, descripcion FROM permisos
ON CONFLICT (codigo) DO UPDATE SET descripcion = EXCLUDED.descripcion;

WITH gate AS (
    SELECT id_permiso FROM pos_saas.permiso WHERE codigo = 'PRECONTABILIDAD__VER'
)
INSERT INTO pos_saas.modulo (nombre, ruta, icono, orden, parent_id, estado, created_at, updated_at, id_permiso_gate)
SELECT 'Precontabilidad', '/precontabilidad', 'account_tree', 38, NULL, 1, now(), now(), gate.id_permiso
FROM gate
WHERE NOT EXISTS (SELECT 1 FROM pos_saas.modulo WHERE ruta = '/precontabilidad');

WITH gate AS (
    SELECT id_permiso FROM pos_saas.permiso WHERE codigo = 'PRECONTABILIDAD__VER'
)
UPDATE pos_saas.modulo m
SET nombre = 'Precontabilidad', icono = 'account_tree', orden = 38, estado = 1,
    id_permiso_gate = gate.id_permiso, updated_at = now()
FROM gate
WHERE m.ruta = '/precontabilidad';

-- Cortes is the operational financial reading. Keep old endpoints only as a
-- backend compatibility bridge; do not expose a competing admin module.
UPDATE pos_saas.modulo
SET estado = 0, updated_at = now()
WHERE ruta = '/reportes-contables' AND estado = 1;

UPDATE admin.empresa_modulo em
SET enabled = false, updated_at = now()
FROM pos_saas.modulo m
WHERE m.id_modulo = em.id_modulo
  AND m.ruta = '/reportes-contables';

COMMIT;
