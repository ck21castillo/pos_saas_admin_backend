# Landing Analytics

La analitica de visitas de la landing usa `admin.landing_visit` en
`bersano_control`. El runtime no crea esquemas, tablas ni indices: el rol
`bersano_admin_ejecucion` conserva solo acceso de lectura y escritura.

## Despliegue

Antes de desplegar el backend que elimina el DDL de la ruta HTTP, ejecutar en
`bersano_control` con el rol propietario o de despliegue:

```text
database/manual/2026-10-05_landing_analytics.sql
```

Ejemplo de ejecucion con una sesion autenticada como rol propietario:

```powershell
psql -v ON_ERROR_STOP=1 -d bersano_control -f database/manual/2026-10-05_landing_analytics.sql
```

El SQL es idempotente y crea `admin.landing_visit`, sus cuatro indices y los
permisos minimos para `bersano_admin_ejecucion` (`USAGE` del esquema y
secuencia; `SELECT, INSERT` sobre la tabla). No conceder `CREATE` a ese rol.

Orden de despliegue:

1. Ejecutar el SQL manual en `bersano_control`.
2. Desplegar `pos_saas_admin`.
3. Verificar `GET /admin/analytics/landing-visits` autenticado.

Si la migracion no existe, las rutas de ingesta y resumen responden JSON con
HTTP `503` y codigo `LANDING_ANALYTICS_SCHEMA_MISSING`; no exponen SQLSTATE,
rutas locales ni trazas.

## Proteccion de ingesta publica

`POST /analytics/landing-visit` valida tamanos de campos, acepta solo las rutas
de landing conocidas y limita la tasa por visitante e IP. Los valores por
defecto son 6 eventos por visitante y 30 por IP cada 60 segundos. Los limites
se configuran con `LANDING_ANALYTICS_*` en el entorno.

Si el sitio usa Nginx o Apache delante de PHP, declarar su IP en
`TRUSTED_PROXY_IPS`; solo en ese caso se utiliza `X-Forwarded-For`. Nunca
confiar en ese encabezado desde Internet sin un proxy controlado.
