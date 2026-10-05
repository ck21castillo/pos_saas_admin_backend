# SOPORTE_TECNICO en SaaS

`SOPORTE_TECNICO` es una capacidad comercial de `bersano_control`. La define
un plan o una excepcion administrativa activa. La capacidad es el techo: los
editores locales pueden apagar modulos o permisos, pero no encender un recurso
que requiera una capacidad SaaS inactiva.

El catalogo declarativo `admin.saas_capacidad_modulo` y
`admin.saas_capacidad_permiso` valida los requisitos de cada recurso. Una fila
puede tener varios requisitos y todos deben estar efectivos. No materializa ni
reescribe `admin.empresa_modulo` o `admin.empresa_permiso`.

Antes de retirar soporte, el panel consulta la base tenant. Las ordenes en
`RECIBIDO`, `EN_PROCESO` o `LISTO` bloquean la operacion con `409`; no se
borran ordenes, pagos, eventos ni comprobantes. El diagnostico incluye total,
agrupacion y `muestra_ordenes` (tambien conserva `muestra` por compatibilidad).
Si el tenant no se puede consultar, tambien se bloquea por seguridad.

Cada cambio de capacidad, suscripcion, modulo o permiso encola una tarea
`BUSINESS_CONFIG` en `admin.tenant_sync_outbox` dentro de la transaccion de
control. Despues del commit se intenta sincronizar el tenant. Un error no
revierte el cambio comercial: queda `PENDIENTE` con intentos, error y proximo
reintento. El endpoint protegido `POST /admin/saas/sincronizaciones/reintentar`
procesa tareas pendientes.

El worker programable `bin/process_tenant_sync_outbox.php` procesa las tareas
`BUSINESS_CONFIG` vencidas sin intervencion del panel. Conserva el reclamo por
tarea con `FOR UPDATE SKIP LOCKED`; varias ejecuciones pueden coincidir sin
duplicar una sincronizacion. Solo replica `pos_saas.empresa` y
`pos_saas.empresa_capacidad`; modulos y permisos siguen consultandose desde
`bersano_control`.

Ejecucion manual:

```text
php bin/process_tenant_sync_outbox.php --limit=50
```

Cron Linux cada minuto:

```text
* * * * * cd /ruta/pos_saas_admin && /usr/bin/php bin/process_tenant_sync_outbox.php --limit=50 >> /var/log/pos_saas_tenant_sync.log 2>&1
```

En Windows Task Scheduler, crear una tarea que se repita cada minuto, con
"Iniciar en" configurado como la carpeta de `pos_saas_admin` y programa
`php.exe`; argumentos: `bin/process_tenant_sync_outbox.php --limit=50`.
El worker debe desplegarse junto con el cambio SaaS para que los reintentos no
dependan de intervencion manual.

Los cambios de matriz plan-capacidad se registran en `admin.audit_log` con la
accion `SAAS_PLAN_CAPACIDADES_SAVE`. Para volver al plan, el contrato es
`{ "restablecer_plan": true, "motivo": "..." }`; deja la excepcion inactiva.

## Despliegue

1. Desplegar estos archivos runtime de `pos_saas_admin`:
   - `src/Service/SaasCapabilityService.php`
   - `src/Service/SupportCapabilityDiagnosticService.php`
   - `src/Service/SaasCapabilityDisableBlockedException.php`
   - `src/Service/AdminTenantSyncService.php`
   - `src/Service/TenantSyncOutboxWorker.php`
   - `src/Controller/AdminSaasController.php`
   - `src/Controller/AdminEmpresaModuloController.php`
   - `src/Controller/AdminEmpresaPermisoController.php`
   - `src/Controller/AdminEmpresaConfiguracionController.php`
   - `public/index.php`
   - `bin/process_tenant_sync_outbox.php`
2. Ejecutar una sola vez en `bersano_control`:
   `database/manual/2026-10-04_tb05_soporte_tecnico_capacidad.sql`.
3. Confirmar que el tenant ya tiene la migracion POS
   `database/migrations/2026-09-23_soporte_tecnico_capacidad.sql` antes de
   habilitar la capacidad en un plan o excepcion.
4. Ejecutar `php tests/saas_support_capability_test.php` y los `php -l`
   indicados por el cambio.

## Rollback

No borrar la outbox ni las capacidades ya sincronizadas. Para detener nuevos
reintentos, dejar el runtime anterior fuera de despliegue y conservar las filas
pendientes para diagnostico. Revertir una capacidad debe hacerse desde plan o
excepcion, respetando el bloqueo de ordenes abiertas.

## Planes publicos de landing

`admin.saas_plan` es la fuente unica para los planes visibles en la landing.
La ficha editorial publica y sus beneficios estructurados se guardan en el
mismo plan; no crean una segunda catalogacion comercial. El panel permite
editar titulo, resumen, icono, etiqueta, CTA, destacado y beneficios ordenados.
La API publica solo devuelve planes `activo` y `visible_publico`.

Para desplegar esta parte, ejecutar una vez y en este orden en
`bersano_control`:

```text
database/manual/2026-10-04_saas_plan_public_profile.sql
database/manual/2026-10-04_saas_plan_public_catalogo_editorial.sql
```

La segunda reconciliacion carga la matriz completa de funciones historicas
para los cuatro planes existentes, conserva las ayudas de cada funcion y reemplaza
`Reportes contables` por `Precontabilidad`. Solo restablece los beneficios de
`ESENCIAL`, `PRO`, `SOPORTE_ESENCIAL` y `SOPORTE_PRO`; se debe ejecutar antes
de hacer ediciones editoriales manuales en esos planes. Como proteccion, la
reconciliacion aborta sin escribir si detecta mas beneficios, titulos distintos
o una estructura diferente a la semilla resumida inicial.

Desplegar junto con el SQL:

- `src/Service/AdminSaasService.php`
- `src/Controller/AdminSaasController.php`
- `public/index.php`

La ficha publica no afecta capacidades efectivas, modulos, permisos ni bases
tenant. Los beneficios pueden asociarse opcionalmente a una capacidad solo
para fines informativos de la landing.
