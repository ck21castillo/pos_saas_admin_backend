-- Ejecutar una vez en bersano_control. No aplica en tenants.

ALTER TABLE admin.saas_plan
    ADD COLUMN IF NOT EXISTS landing_titulo varchar(120) NULL,
    ADD COLUMN IF NOT EXISTS landing_resumen varchar(360) NULL,
    ADD COLUMN IF NOT EXISTS landing_icono varchar(64) NOT NULL DEFAULT 'storefront',
    ADD COLUMN IF NOT EXISTS landing_destacado boolean NOT NULL DEFAULT false,
    ADD COLUMN IF NOT EXISTS landing_etiqueta varchar(80) NULL,
    ADD COLUMN IF NOT EXISTS landing_cta_texto varchar(80) NOT NULL DEFAULT 'Solicitar invitacion';

CREATE TABLE IF NOT EXISTS admin.saas_plan_beneficio (
    id_beneficio bigserial PRIMARY KEY,
    id_plan bigint NOT NULL REFERENCES admin.saas_plan(id_plan) ON DELETE CASCADE,
    codigo_capacidad text NULL REFERENCES pos_saas.capacidad(codigo_capacidad) ON DELETE SET NULL,
    titulo varchar(120) NOT NULL CHECK (btrim(titulo) <> ''),
    descripcion varchar(280) NULL,
    icono varchar(64) NOT NULL DEFAULT 'check_circle',
    incluido boolean NOT NULL DEFAULT true,
    orden integer NOT NULL DEFAULT 100,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS saas_plan_beneficio_plan_orden_idx
    ON admin.saas_plan_beneficio (id_plan, orden, id_beneficio);

UPDATE admin.saas_plan
SET
    landing_titulo = COALESCE(landing_titulo, nombre),
    landing_resumen = COALESCE(landing_resumen, descripcion),
    landing_icono = CASE codigo
        WHEN 'SOPORTE_ESENCIAL' THEN 'handyman'
        WHEN 'SOPORTE_PRO' THEN 'workspace_premium'
        WHEN 'PRO' THEN 'workspace_premium'
        ELSE COALESCE(NULLIF(landing_icono, ''), 'storefront')
    END,
    landing_destacado = CASE WHEN codigo IN ('PRO', 'SOPORTE_PRO') THEN true ELSE landing_destacado END,
    landing_etiqueta = CASE WHEN codigo IN ('PRO', 'SOPORTE_PRO') THEN COALESCE(landing_etiqueta, 'Recomendado') ELSE landing_etiqueta END,
    landing_cta_texto = COALESCE(NULLIF(landing_cta_texto, ''), 'Solicitar invitacion')
WHERE codigo IN ('ESENCIAL', 'PRO', 'SOPORTE_ESENCIAL', 'SOPORTE_PRO');

INSERT INTO admin.saas_plan_beneficio (id_plan, titulo, descripcion, icono, incluido, orden)
SELECT p.id_plan, b.titulo, b.descripcion, b.icono, b.incluido, b.orden
FROM admin.saas_plan p
JOIN (
    VALUES
        ('ESENCIAL', 'Ventas, inventario y compras', 'Operacion POS, existencias y proveedores desde un mismo sistema.', 'inventory_2', true, 10),
        ('ESENCIAL', 'Caja, clientes y creditos', 'Control diario de caja, clientes, saldos y pagos.', 'point_of_sale', true, 20),
        ('ESENCIAL', 'Comprobantes por WhatsApp', 'Disponible como adicional para tu negocio.', 'chat', false, 30),
        ('PRO', 'Todo lo del plan Esencial', 'Ventas, inventario, compras, caja, clientes y creditos.', 'check_circle', true, 10),
        ('PRO', 'Comprobantes por WhatsApp', 'Comprobantes incluidos para una atencion mas agil.', 'chat', true, 20),
        ('PRO', 'Preparado para crecer', 'Usuarios y servicios adicionales segun la necesidad del negocio.', 'trending_up', true, 30),
        ('SOPORTE_ESENCIAL', 'Ventas e inventario', 'Operacion comercial completa para el negocio.', 'inventory_2', true, 10),
        ('SOPORTE_ESENCIAL', 'Servicio tecnico', 'Ingreso, diagnostico, reparacion y entrega de equipos.', 'handyman', true, 20),
        ('SOPORTE_ESENCIAL', 'Comprobantes por WhatsApp', 'Disponible como adicional para el servicio.', 'chat', false, 30),
        ('SOPORTE_PRO', 'Todo lo del servicio tecnico', 'Operacion comercial y trazabilidad de cada equipo.', 'handyman', true, 10),
        ('SOPORTE_PRO', 'Comprobantes por WhatsApp', 'Incluidos para ingreso y entrega de equipos.', 'chat', true, 20),
        ('SOPORTE_PRO', 'Atencion postventa', 'Historial organizado para clientes y equipos atendidos.', 'support_agent', true, 30)
) AS b(codigo, titulo, descripcion, icono, incluido, orden)
  ON p.codigo = b.codigo
WHERE NOT EXISTS (
    SELECT 1
    FROM admin.saas_plan_beneficio existing
    WHERE existing.id_plan = p.id_plan
      AND existing.titulo = b.titulo
);

ANALYZE admin.saas_plan;
ANALYZE admin.saas_plan_beneficio;
