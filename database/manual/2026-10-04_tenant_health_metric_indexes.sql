-- Ejecutar una vez en CADA base tenant, fuera de una transaccion explicita.
-- CREATE INDEX CONCURRENTLY evita bloquear escrituras en tablas con volumen.
-- Requiere PostgreSQL con el schema pos_saas y las tablas actuales del POS.

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_health_venta_empresa_estado_normalizado_fecha
    ON pos_saas.venta (id_empresa, UPPER(COALESCE(estado, '')), fecha DESC)
    INCLUDE (total);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_health_compra_empresa_fecha_doc
    ON pos_saas.compra (id_empresa, fecha_doc DESC);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_health_caja_movimiento_empresa_created
    ON pos_saas.caja_movimiento (id_empresa, created_at DESC);

ANALYZE pos_saas.venta;
ANALYZE pos_saas.compra;
ANALYZE pos_saas.caja_movimiento;
