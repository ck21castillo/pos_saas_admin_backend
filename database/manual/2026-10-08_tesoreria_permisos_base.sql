-- Permisos operativos base: no son modulo ni capacidad SaaS.
-- COMPRAS__CORREGIR_CONFIRMADA solo se registra en catalogo; su techo
-- comercial depende de admin.saas_plan_permiso y no de este rollout base.
-- Ejecutar una vez en bersano_control antes de desplegar el runtime asociado.

BEGIN;

INSERT INTO pos_saas.permiso (codigo, descripcion)
VALUES
    ('TESORERIA__VER', 'Consultar fuentes y movimientos de tesoreria.'),
    ('TESORERIA__CONFIGURAR', 'Crear, editar y activar fuentes de tesoreria.'),
    ('TESORERIA__TRASLADAR', 'Registrar traslados entre fuentes de tesoreria.'),
    ('TESORERIA__AJUSTAR', 'Autorizar ajustes excepcionales de tesoreria.'),
    ('COMPRAS__CORREGIR_CONFIRMADA', 'Corregir compras confirmadas.')
ON CONFLICT (codigo) DO UPDATE
SET descripcion = EXCLUDED.descripcion;

-- Todo plan comercial actual y todo plan que use la matriz recibe esta base.
INSERT INTO admin.saas_plan_permiso (id_plan, id_permiso, enabled, created_at, updated_at)
SELECT plan.id_plan, permiso.id_permiso, true, now(), now()
FROM admin.saas_plan plan
CROSS JOIN pos_saas.permiso permiso
WHERE permiso.codigo IN (
    'TESORERIA__VER',
    'TESORERIA__CONFIGURAR',
    'TESORERIA__TRASLADAR',
    'TESORERIA__AJUSTAR'
)
ON CONFLICT (id_plan, id_permiso) DO UPDATE
SET enabled = true,
    updated_at = now();

-- La empresa no puede quedar bajo un techo comercial inferior a esta base.
INSERT INTO admin.empresa_permiso (id_empresa, id_permiso, enabled, created_at, updated_at)
SELECT empresa.id_empresa, permiso.id_permiso, true, now(), now()
FROM pos_saas.empresa empresa
CROSS JOIN pos_saas.permiso permiso
WHERE permiso.codigo IN (
    'TESORERIA__VER',
    'TESORERIA__CONFIGURAR',
    'TESORERIA__TRASLADAR',
    'TESORERIA__AJUSTAR'
)
ON CONFLICT (id_empresa, id_permiso) DO UPDATE
SET enabled = true,
    updated_at = now();

-- Solo los roles administrativos reciben administracion completa por defecto.
WITH permisos AS (
    SELECT id_permiso
    FROM pos_saas.permiso
    WHERE codigo IN (
        'TESORERIA__VER',
        'TESORERIA__CONFIGURAR',
        'TESORERIA__TRASLADAR',
        'TESORERIA__AJUSTAR',
        'COMPRAS__CORREGIR_CONFIRMADA'
    )
), roles_administrativos AS (
    SELECT id_rol
    FROM pos_saas.rol
    WHERE upper(nombre) IN ('ADMIN', 'ADMINISTRADOR', 'ADMINISTRATIVO', 'SUPERADMIN', 'PROPIETARIO', 'OWNER')
       OR upper(nombre) LIKE '%ADMIN%'
)
INSERT INTO pos_saas.rol_permiso (id_rol, id_permiso)
SELECT rol.id_rol, permiso.id_permiso
FROM roles_administrativos rol
CROSS JOIN permisos permiso
ON CONFLICT DO NOTHING;

INSERT INTO admin.audit_log (
    actor_id, actor_email, action, target_type, target_id, before, after, ip, user_agent
)
VALUES (
    NULL,
    'system',
    'TESORERIA_PERMISOS_BASE_ROLLOUT',
    'saas',
    NULL,
    '{}'::jsonb,
    jsonb_build_object(
        'permisos', jsonb_build_array(
            'TESORERIA__VER',
            'TESORERIA__CONFIGURAR',
            'TESORERIA__TRASLADAR',
            'TESORERIA__AJUSTAR',
            'COMPRAS__CORREGIR_CONFIRMADA'
        ),
        'alcance', 'tesoreria en planes y empresas; compra solo en catalogo y roles administrativos'
    ),
    NULL,
    NULL
);

COMMIT;

ANALYZE pos_saas.permiso;
ANALYZE admin.saas_plan_permiso;
ANALYZE admin.empresa_permiso;
ANALYZE pos_saas.rol_permiso;
