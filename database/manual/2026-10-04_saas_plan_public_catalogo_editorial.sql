-- Reconciliacion editorial para instalaciones que ya ejecutaron
-- 2026-10-04_saas_plan_public_profile.sql con la semilla resumida.
-- Ejecutar una vez en bersano_control antes de editar beneficios desde el panel.
-- No aplica en tenants ni modifica capacidades, modulos o permisos.

BEGIN;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM admin.saas_plan_beneficio b
        JOIN admin.saas_plan p ON p.id_plan = b.id_plan
        WHERE p.codigo IN ('ESENCIAL', 'PRO', 'SOPORTE_ESENCIAL', 'SOPORTE_PRO')
          AND NOT (
              (p.codigo = 'ESENCIAL' AND b.titulo IN ('Ventas, inventario y compras', 'Caja, clientes y creditos', 'Comprobantes por WhatsApp'))
              OR (p.codigo = 'PRO' AND b.titulo IN ('Todo lo del plan Esencial', 'Comprobantes por WhatsApp', 'Preparado para crecer'))
              OR (p.codigo = 'SOPORTE_ESENCIAL' AND b.titulo IN ('Ventas e inventario', 'Servicio tecnico', 'Comprobantes por WhatsApp'))
              OR (p.codigo = 'SOPORTE_PRO' AND b.titulo IN ('Todo lo del servicio tecnico', 'Comprobantes por WhatsApp', 'Atencion postventa'))
          )
    ) OR (
        SELECT COUNT(*)
        FROM admin.saas_plan_beneficio b
        JOIN admin.saas_plan p ON p.id_plan = b.id_plan
        WHERE p.codigo IN ('ESENCIAL', 'PRO', 'SOPORTE_ESENCIAL', 'SOPORTE_PRO')
    ) <> 12 THEN
        RAISE EXCEPTION
            'CATALOGO_EDITORIAL_MODIFICADO: no se reemplazaron beneficios con ediciones manuales o estructura inesperada.';
    END IF;
END $$;

DELETE FROM admin.saas_plan_beneficio b
USING admin.saas_plan p
WHERE p.id_plan = b.id_plan
  AND p.codigo IN ('ESENCIAL', 'PRO', 'SOPORTE_ESENCIAL', 'SOPORTE_PRO');

INSERT INTO admin.saas_plan_beneficio (id_plan, titulo, descripcion, icono, incluido, orden)
SELECT p.id_plan, b.titulo, b.descripcion, b.icono, b.incluido, b.orden
FROM admin.saas_plan p
JOIN (
    VALUES
        ('ESENCIAL', 'Ventas POS rapidas', 'Registra ventas de contado, transferencia o credito desde una pantalla pensada para atender rapido.', 'check_circle', true, 10),
        ('ESENCIAL', 'Productos, stock e inventario inicial', 'Controla existencias, carga inventario inicial y consulta disponibilidad antes de vender.', 'check_circle', true, 20),
        ('ESENCIAL', 'Compras y proveedores', 'Registra compras, actualiza costos y alimenta el inventario sin volver a crear el producto.', 'check_circle', true, 30),
        ('ESENCIAL', 'Clientes y deudores', 'Guarda clientes, ventas a credito, abonos, pagos totales y saldos pendientes por cobrar.', 'check_circle', true, 40),
        ('ESENCIAL', 'Caja, cierres y cortes', 'Controla apertura, ingresos, egresos, cierre diario y resumen comercial del periodo.', 'check_circle', true, 50),
        ('ESENCIAL', 'Productos por peso y presentaciones', 'Vende por unidades, kilos o presentaciones como blister, paquete o caja segun tu negocio.', 'check_circle', true, 60),
        ('ESENCIAL', 'Bancos y transferencias', 'Relaciona pagos por transferencia con cuentas bancarias creadas dentro del sistema.', 'check_circle', true, 70),
        ('ESENCIAL', 'Comprobantes por correo', 'Entrega comprobantes informativos por email cuando el cliente lo solicite.', 'check_circle', true, 80),
        ('ESENCIAL', 'Creditos simples y por cuotas', 'Maneja ventas a credito, planes de cuotas, abonos, pagos totales y saldos pendientes.', 'check_circle', true, 90),
        ('ESENCIAL', 'Devoluciones y trazabilidad', 'Conserva rastro de movimientos, devoluciones, pagos y cambios que afectan la operacion.', 'check_circle', true, 100),
        ('ESENCIAL', 'Gastos operativos', 'Registra arriendo, servicios, transporte, papeleria u otros gastos para revisar resultado del negocio.', 'check_circle', false, 150),
        ('ESENCIAL', 'Precontabilidad', 'Configura mapeos de cuentas, centros de costo y comprobantes de partida doble para apoyar el trabajo del contador.', 'check_circle', false, 160),
        ('ESENCIAL', 'Comprobantes por WhatsApp', 'Emision de comprobantes por WhatsApp ilimitada, usando plantillas aprobadas y el numero configurado.', 'check_circle', false, 170),

        ('PRO', 'Ventas POS rapidas', 'Registra ventas de contado, transferencia o credito desde una pantalla pensada para atender rapido.', 'check_circle', true, 10),
        ('PRO', 'Productos, stock e inventario inicial', 'Controla existencias, carga inventario inicial y consulta disponibilidad antes de vender.', 'check_circle', true, 20),
        ('PRO', 'Compras y proveedores', 'Registra compras, actualiza costos y alimenta el inventario sin volver a crear el producto.', 'check_circle', true, 30),
        ('PRO', 'Clientes y deudores', 'Guarda clientes, ventas a credito, abonos, pagos totales y saldos pendientes por cobrar.', 'check_circle', true, 40),
        ('PRO', 'Caja, cierres y cortes', 'Controla apertura, ingresos, egresos, cierre diario y resumen comercial del periodo.', 'check_circle', true, 50),
        ('PRO', 'Productos por peso y presentaciones', 'Vende por unidades, kilos o presentaciones como blister, paquete o caja segun tu negocio.', 'check_circle', true, 60),
        ('PRO', 'Bancos y transferencias', 'Relaciona pagos por transferencia con cuentas bancarias creadas dentro del sistema.', 'check_circle', true, 70),
        ('PRO', 'Comprobantes por correo', 'Entrega comprobantes informativos por email cuando el cliente lo solicite.', 'check_circle', true, 80),
        ('PRO', 'Creditos simples y por cuotas', 'Maneja ventas a credito, planes de cuotas, abonos, pagos totales y saldos pendientes.', 'check_circle', true, 90),
        ('PRO', 'Devoluciones y trazabilidad', 'Conserva rastro de movimientos, devoluciones, pagos y cambios que afectan la operacion.', 'check_circle', true, 100),
        ('PRO', 'Gastos operativos', 'Registra arriendo, servicios, transporte, papeleria u otros gastos para revisar resultado del negocio.', 'check_circle', true, 150),
        ('PRO', 'Precontabilidad', 'Configura mapeos de cuentas, centros de costo y comprobantes de partida doble para apoyar el trabajo del contador.', 'check_circle', true, 160),
        ('PRO', 'Comprobantes por WhatsApp', 'Emision de comprobantes por WhatsApp ilimitada, usando plantillas aprobadas y el numero configurado.', 'check_circle', true, 170),

        ('SOPORTE_ESENCIAL', 'Ventas POS rapidas', 'Registra ventas de contado, transferencia o credito desde una pantalla pensada para atender rapido.', 'check_circle', true, 10),
        ('SOPORTE_ESENCIAL', 'Productos, stock e inventario inicial', 'Controla existencias, carga inventario inicial y consulta disponibilidad antes de vender.', 'check_circle', true, 20),
        ('SOPORTE_ESENCIAL', 'Compras y proveedores', 'Registra compras, actualiza costos y alimenta el inventario sin volver a crear el producto.', 'check_circle', true, 30),
        ('SOPORTE_ESENCIAL', 'Clientes y deudores', 'Guarda clientes, ventas a credito, abonos, pagos totales y saldos pendientes por cobrar.', 'check_circle', true, 40),
        ('SOPORTE_ESENCIAL', 'Caja, cierres y cortes', 'Controla apertura, ingresos, egresos, cierre diario y resumen comercial del periodo.', 'check_circle', true, 50),
        ('SOPORTE_ESENCIAL', 'Productos por peso y presentaciones', 'Vende por unidades, kilos o presentaciones como blister, paquete o caja segun tu negocio.', 'check_circle', true, 60),
        ('SOPORTE_ESENCIAL', 'Bancos y transferencias', 'Relaciona pagos por transferencia con cuentas bancarias creadas dentro del sistema.', 'check_circle', true, 70),
        ('SOPORTE_ESENCIAL', 'Comprobantes por correo', 'Entrega comprobantes informativos por email cuando el cliente lo solicite.', 'check_circle', true, 80),
        ('SOPORTE_ESENCIAL', 'Creditos simples y por cuotas', 'Maneja ventas a credito, planes de cuotas, abonos, pagos totales y saldos pendientes.', 'check_circle', true, 90),
        ('SOPORTE_ESENCIAL', 'Devoluciones y trazabilidad', 'Conserva rastro de movimientos, devoluciones, pagos y cambios que afectan la operacion.', 'check_circle', true, 100),
        ('SOPORTE_ESENCIAL', 'Ingreso, diagnostico y entrega', 'Registra el equipo recibido, falla reportada, diagnostico, trabajo realizado y estado de entrega.', 'check_circle', true, 110),
        ('SOPORTE_ESENCIAL', 'Repuestos y mano de obra', 'Separa valores por repuestos, mano de obra, anticipos, saldos y pagos del servicio.', 'check_circle', true, 120),
        ('SOPORTE_ESENCIAL', 'Comprobantes de ingreso y salida', 'Genera comprobantes para dejar evidencia del ingreso del equipo y de la entrega final.', 'check_circle', true, 130),
        ('SOPORTE_ESENCIAL', 'Ideal para postventa', 'Pensado para negocios que necesitan vender, reparar, entregar y mantener historial del cliente.', 'check_circle', true, 140),
        ('SOPORTE_ESENCIAL', 'Gastos operativos', 'Registra arriendo, servicios, transporte, papeleria u otros gastos para revisar resultado del negocio.', 'check_circle', false, 150),
        ('SOPORTE_ESENCIAL', 'Precontabilidad', 'Configura mapeos de cuentas, centros de costo y comprobantes de partida doble para apoyar el trabajo del contador.', 'check_circle', false, 160),
        ('SOPORTE_ESENCIAL', 'Comprobantes por WhatsApp', 'Emision de comprobantes por WhatsApp ilimitada, usando plantillas aprobadas y el numero configurado.', 'check_circle', false, 170),

        ('SOPORTE_PRO', 'Ventas POS rapidas', 'Registra ventas de contado, transferencia o credito desde una pantalla pensada para atender rapido.', 'check_circle', true, 10),
        ('SOPORTE_PRO', 'Productos, stock e inventario inicial', 'Controla existencias, carga inventario inicial y consulta disponibilidad antes de vender.', 'check_circle', true, 20),
        ('SOPORTE_PRO', 'Compras y proveedores', 'Registra compras, actualiza costos y alimenta el inventario sin volver a crear el producto.', 'check_circle', true, 30),
        ('SOPORTE_PRO', 'Clientes y deudores', 'Guarda clientes, ventas a credito, abonos, pagos totales y saldos pendientes por cobrar.', 'check_circle', true, 40),
        ('SOPORTE_PRO', 'Caja, cierres y cortes', 'Controla apertura, ingresos, egresos, cierre diario y resumen comercial del periodo.', 'check_circle', true, 50),
        ('SOPORTE_PRO', 'Productos por peso y presentaciones', 'Vende por unidades, kilos o presentaciones como blister, paquete o caja segun tu negocio.', 'check_circle', true, 60),
        ('SOPORTE_PRO', 'Bancos y transferencias', 'Relaciona pagos por transferencia con cuentas bancarias creadas dentro del sistema.', 'check_circle', true, 70),
        ('SOPORTE_PRO', 'Comprobantes por correo', 'Entrega comprobantes informativos por email cuando el cliente lo solicite.', 'check_circle', true, 80),
        ('SOPORTE_PRO', 'Creditos simples y por cuotas', 'Maneja ventas a credito, planes de cuotas, abonos, pagos totales y saldos pendientes.', 'check_circle', true, 90),
        ('SOPORTE_PRO', 'Devoluciones y trazabilidad', 'Conserva rastro de movimientos, devoluciones, pagos y cambios que afectan la operacion.', 'check_circle', true, 100),
        ('SOPORTE_PRO', 'Ingreso, diagnostico y entrega', 'Registra el equipo recibido, falla reportada, diagnostico, trabajo realizado y estado de entrega.', 'check_circle', true, 110),
        ('SOPORTE_PRO', 'Repuestos y mano de obra', 'Separa valores por repuestos, mano de obra, anticipos, saldos y pagos del servicio.', 'check_circle', true, 120),
        ('SOPORTE_PRO', 'Comprobantes de ingreso y salida', 'Genera comprobantes para dejar evidencia del ingreso del equipo y de la entrega final.', 'check_circle', true, 130),
        ('SOPORTE_PRO', 'Ideal para postventa', 'Pensado para negocios que necesitan vender, reparar, entregar y mantener historial del cliente.', 'check_circle', true, 140),
        ('SOPORTE_PRO', 'Gastos operativos', 'Registra arriendo, servicios, transporte, papeleria u otros gastos para revisar resultado del negocio.', 'check_circle', true, 150),
        ('SOPORTE_PRO', 'Precontabilidad', 'Configura mapeos de cuentas, centros de costo y comprobantes de partida doble para apoyar el trabajo del contador.', 'check_circle', true, 160),
        ('SOPORTE_PRO', 'Comprobantes por WhatsApp', 'Emision de comprobantes por WhatsApp ilimitada, usando plantillas aprobadas y el numero configurado.', 'check_circle', true, 170)
) AS b(codigo, titulo, descripcion, icono, incluido, orden)
  ON p.codigo = b.codigo;

COMMIT;

ANALYZE admin.saas_plan_beneficio;
