# Salud multibase y auditoria

## Modelo de salud

`GET /admin/tenant-health` no abre conexiones a las bases tenant. Lee la
ultima instantanea disponible en `admin.tenant_health_snapshot` y devuelve:

- `inspection.state = PENDING`: aun no existe sondeo.
- `inspection.state = FRESH`: la instantanea tiene menos de 15 minutos.
- `inspection.state = STALE`: la instantanea existe, pero ya vencio.

`GET /admin/tenant-health/{id}` ejecuta un sondeo puntual y actualiza la
instantanea. Por defecto es rapido (`deep=0`). El diagnostico de aislamiento
entre empresas solo corre con `deep=1`; revisa tablas completas y no debe
pedirse automaticamente desde una lista.

Programar cada cinco minutos, con el usuario y variables de entorno del admin:

```text
php /ruta/pos_saas_admin/bin/refresh_tenant_health.php --limit=20
```

El proceso usa un advisory lock de PostgreSQL para que dos ejecuciones no
sondeen los mismos tenants a la vez. `--deep` queda reservado para una
verificacion operativa excepcional, no para el cron.

## Espejo de tipo de negocio

`empresa.tipo_negocio` es un contrato operativo compartido con `pos_saas`.
El panel admin acepta los mismos once codigos canónicos y los replica mediante
la outbox `BUSINESS_CONFIG`; no debe convertir tipos validos a `GENERAL`.

Para corregir un tenant ya creado antes de esta paridad, reenviar su empresa
por outbox desde `bersano_control` y dejar que el worker haga la copia:

```sql
INSERT INTO admin.tenant_sync_outbox
    (id_empresa, tipo, estado, intentos, ultimo_error, proximo_intento_at)
VALUES
    (8, 'BUSINESS_CONFIG', 'PENDIENTE', 0, NULL, now())
ON CONFLICT (id_empresa, tipo) DO UPDATE SET
    estado = 'PENDIENTE',
    ultimo_error = NULL,
    proximo_intento_at = now(),
    updated_at = now();
```

Luego ejecutar `php bin/process_tenant_sync_outbox.php --limit=50` o esperar
el worker programado. Cambiar `8` por el ID de la empresa afectada. No editar
la fila del tenant manualmente.

## Indices

En `bersano_control` ejecutar, fuera de una transaccion explicita:

1. `database/manual/2026-10-04_tenant_health_snapshots.sql`
2. `database/manual/2026-10-04_audit_log_query_indexes.sql`
3. `database/manual/2026-10-04_audit_log_search_trgm.sql` cuando el rol pueda
   instalar `pg_trgm`.

En cada base tenant ejecutar, tambien fuera de una transaccion explicita:

`database/manual/2026-10-04_tenant_health_metric_indexes.sql`.

Los indices de auditoria anteriores se mantienen durante la primera ventana de
despliegue. Tras verificar con `EXPLAIN (ANALYZE, BUFFERS)` que los nuevos son
usados en produccion, se pueden retirar los tres indices sin `id_audit` en una
migracion posterior y separada.

## Operacion

Revisar periodicamente `pg_stat_statements`, latencia de sondeos y crecimiento
de `audit_log`. No ejecutar la busqueda libre de auditoria como sustituto de
filtros por fecha, accion o empresa; los filtros estructurados siguen siendo
el recorrido de menor costo.
