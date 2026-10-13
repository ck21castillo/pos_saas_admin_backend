# Catalogo de Categorias de Negocio

El catalogo central vive exclusivamente en `bersano_control` y se administra
desde el panel mediante `/admin/categorias-negocio`.

## Conceptos

- `categoria_negocio`: clasificacion comercial editable. Decide que opciones
  aparecen en el registro publico cuando POS publique el catalogo.
- `perfil_operativo`: comportamiento tecnico estable de la empresa. Las
  categorias solo pueden apuntar a `GENERAL`, `DROGUERIA`,
  `TIENDA_MINIMARKET` o `TECNOLOGIA_SERVICIO_TECNICO`.
- `capacidad inicial`: sugerencia de configuracion para una categoria. No
  habilita por si sola una funcion ni modifica una empresa existente.
- capacidad operativa efectiva: se guarda por empresa y se replica al tenant.
  Incluye lotes, peso, presentaciones y seriales/IMEI.
- capacidad SaaS efectiva: se define por plan, suscripcion y excepciones. Incluye
  soporte tecnico, precontabilidad, exportacion contable y funciones fiscales.

Por ejemplo, `TIENDA_TECNOLOGIA` usa el perfil `GENERAL` y sugiere
`SERIALES_EN_VENTA`. POS aplica esa sugerencia al crear la empresa; despues un
administrador autorizado puede mantenerla, activarla o desactivarla sin que un
plan comercial la bloquee. La categoria no modifica empresas existentes.

## Base de datos y despliegue

Ejecutar una vez, solo en `bersano_control`, como propietario:

```powershell
& 'C:\Program Files\PostgreSQL\17\bin\psql.exe' -v ON_ERROR_STOP=1 -U cristian -d bersano_control -f database/manual/2026-10-12_catalogo_categorias_negocio.sql
```

El SQL es idempotente. Crea el catalogo y su relacion con capacidades, agrega
`pos_saas.empresa.categoria_negocio`, siembra las categorias historicas y hace
backfill unicamente cuando la columna esta vacia. No toca tenants, planes,
suscripciones, modulos, permisos, outbox ni capacidades efectivas.

El orden de despliegue es obligatorio: primero ejecutar
`2026-10-12_catalogo_categorias_negocio.sql`, despues aplicar desde `pos_saas`
la migracion tenant `2026-10-12_categoria_negocio_empresa.sql` al template y a
cada tenant, y por ultimo desplegar el runtime administrativo que sincroniza la
categoria. Antes de reenviar tareas `BUSINESS_CONFIG`, todos los tenants deben
tener la columna `pos_saas.empresa.categoria_negocio`; de lo contrario, la
sincronizacion falla con `TENANT_SCHEMA_CATEGORIA_NEGOCIO_REQUIRED` y queda
pendiente para reintento. Asi una edicion posterior no puede normalizar un
valor historico a `GENERAL` antes de preservar su categoria comercial. La
conversion fisica coordinada entre control y tenants se implementara despues
desde `pos_saas`.

La separacion posterior de IMEI se ejecuta tambien una vez en control con
`2026-10-13_capacidades_operativas_producto.sql`. Conserva el valor actual de
cada empresa, retira `SERIALES_EN_VENTA` de planes y excepciones SaaS, y deja
las sugerencias operativas de categoria listas para altas nuevas.

## API administrativa

- `GET /admin/categorias-negocio`
- `POST /admin/categorias-negocio`
- `PUT /admin/categorias-negocio/{id}`

El codigo se normaliza a mayusculas al crear y no puede editarse despues. Las
altas y cambios quedan en `admin.audit_log`. Desactivar u ocultar una categoria
solo afecta su disponibilidad futura; no cambia empresas existentes.
