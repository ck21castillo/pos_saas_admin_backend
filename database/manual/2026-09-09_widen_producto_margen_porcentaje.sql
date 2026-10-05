-- Amplia el rango de margen_porcentaje para importaciones administrativas.
--
-- Motivo:
-- Algunos productos pueden tener una utilidad calculada mayor a 999.99%.
-- En tenants donde pos_saas.producto.margen_porcentaje esta como numeric(5,2),
-- PostgreSQL rechaza la confirmacion con "numeric field overflow".
--
-- Ejecutar en cada tenant afectado.

BEGIN;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'pos_saas'
          AND table_name = 'producto'
          AND column_name = 'margen_porcentaje'
          AND data_type = 'numeric'
          AND numeric_precision = 5
          AND numeric_scale = 2
    ) THEN
        ALTER TABLE pos_saas.producto
            ALTER COLUMN margen_porcentaje TYPE numeric(10,2)
            USING margen_porcentaje::numeric(10,2);
    END IF;
END $$;

COMMIT;
