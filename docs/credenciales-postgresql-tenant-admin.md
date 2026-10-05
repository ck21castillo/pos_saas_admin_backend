# Credenciales PostgreSQL de tenants en admin

El panel `pos_saas_admin` usa dos grupos explicitos de variables para sus
conexiones PostgreSQL:

```text
DB_USER / DB_PASSWORD = bersano_admin_ejecucion
DB_TENANT_USER / DB_TENANT_PASSWORD = bersano_admin_ejecucion
```

Aunque en la topologia actual ambos pares usan el mismo rol tecnico, deben
mantenerse separados. `DB_USER` y `DB_PASSWORD` abren `bersano_control`;
`DB_TENANT_USER` y `DB_TENANT_PASSWORD` abren las bases tenant desde los flujos
de sincronizacion, importacion de inventario y salud de tenants.

## Produccion

Con `APP_ENV=production` o `APP_ENV=prod`, toda conexion tenant exige que las
dos variables `DB_TENANT_*` existan y no esten vacias. El panel rechaza una
configuracion incompleta con `DB_TENANT_CREDENTIALS_REQUIRED`; no usa como
fallback `DB_USER`, `DB_PASSWORD` ni `admin.tenant_database.db_user`.

En `local`, `development` y `test` se conserva temporalmente el fallback
existente para facilitar desarrollo: usuario desde `db_user` o `DB_USER`, y
password desde `DB_PASSWORD` o `DB_PASS`. No depender de ese fallback en un
servidor productivo.

No definir `DB_PROVISION_*` en este proyecto. El aprovisionamiento de tenants
pertenece exclusivamente a `pos_saas`; este panel solo se conecta a tenants ya
registrados para sincronizacion y operaciones administrativas autorizadas.

## Despliegue

Antes de desplegar el runtime, configurar las cuatro variables en el gestor de
secretos del servidor y mantener `COOKIE_SECURE=1` fuera de desarrollo. No
guardar passwords reales en `.env.example`, commits, logs ni documentos.

Archivos runtime que aplican esta politica:

- `src/Core/Database.php`
- `src/Service/AdminTenantSyncService.php`
- `src/Service/AdminInventoryImportPreviewService.php`
- `src/Service/AdminInventoryImportConfirmService.php`
- `src/Service/TenantHealthService.php`
