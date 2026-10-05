-- T-B-03 control-plane catalog. Run once in bersano_control after deploying
-- the admin backend. The entitlement still requires both SaaS capabilities:
-- PRECONTABILIDAD and EXPORTACION_CONTABLE.

BEGIN;

INSERT INTO pos_saas.permiso (codigo, descripcion)
VALUES ('PRECONTABILIDAD__EXPORTAR', 'Validar conciliacion y exportar comprobantes precontables')
ON CONFLICT (codigo) DO UPDATE SET descripcion = EXCLUDED.descripcion;

COMMIT;
