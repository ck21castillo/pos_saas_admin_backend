# Entitlements comerciales

La fuente comercial es la matriz del plan: `admin.saas_plan_modulo` y
`admin.saas_plan_permiso`. Al guardar una suscripcion, el backend materializa
todos los modulos activos y todos los permisos en `admin.empresa_modulo` y
`admin.empresa_permiso`; por eso un cambio de Pro a Esencial tambien deja en
`false` los recursos que el nuevo plan no incluye.

`COMPRAS__CORREGIR_CONFIRMADA` no es permiso base. Puede existir en el
catalogo y en roles administrativos, pero solo queda efectivo cuando el plan
lo incluya en `admin.saas_plan_permiso`.

La prioridad de lectura es:

1. `admin.empresa_entitlement_excepcion` para una excepcion interna puntual.
2. Complementos comerciales activos, vigentes y compatibles con el plan.
3. La matriz del plan.

`admin.empresa_modulo` y `admin.empresa_permiso` son una materializacion
tecnica del resultado, no una fuente comercial. El modo inicial es
`ENTITLEMENTS_MODE=legacy`; solo `ENTITLEMENTS_MODE=plans` activa el modelo
comercial como frontera de escritura. En ese modo, sus rutas antiguas de
escritura responden
`EDICION_DIRECTA_ENTITLEMENTS_OBSOLETA`.

## Permisos efectivos y no disponibles

`GET /admin/empresas/{idEmpresa}/accesos-efectivos` entrega ambos conjuntos
sin delegar reglas comerciales al frontend:

- `accesos.permisos`: permisos comerciales efectivos.
- `accesos.permisos_no_disponibles`: permisos visibles que no son efectivos.

Los dos conjuntos incluyen `id_permiso`, `codigo`, `nombre` (derivado de la
descripción del catálogo), `descripcion`, `grupo_ui`, `grupo_ui_nombre`,
`orden_ui`, `id_modulo_asociado` y `ruta_modulo_asociado`. La asociación a
módulo solo se informa cuando el permiso es el *gate* declarado de un módulo;
un valor `null` permite mostrarlo como “Otros permisos disponibles” sin
inferir asociaciones mediante prefijos de código.

Un permiso con `visible_ui=false` no aparece en ninguno de los conjuntos. Si
la metadata editorial aún no existe, el contrato conserva compatibilidad con
los valores `OTROS`, `Otros permisos`, orden `1000` y visible por defecto.

Cada elemento no disponible incluye uno de estos motivos, resuelto por backend
en este orden:

1. `RESTRINGIDO_EXCEPCION`: una excepción interna explícita lo apagó.
2. `REQUIERE_COMPLEMENTO`: un complemento comercial activo en catálogo y
   compatible con el plan podría habilitarlo, pero no está activo para la
   empresa.
3. `NO_INCLUIDO_PLAN`: el plan vigente no lo incluye.
4. `NO_DISPONIBLE`: no hay plan comercial vigente, el complemento que lo
   comercializa no es compatible con el plan actual, u ocurre el fallback
   seguro.

`accesos.modo_entitlements` informa si la instancia está en `legacy` o
`plans`; no cambia los cálculos de este contrato comercial ni autoriza al
frontend a modificar materializaciones.

Las excepciones de acceso no alteran la suscripcion ni reemplazan
`admin.saas_empresa_capacidad_excepcion`, que sigue siendo exclusivamente el
mecanismo para capacidades SaaS. Una funcion que dependa de una capacidad
comercial requiere que esa capacidad tambien sea efectiva; por tanto, el
endpoint rechaza habilitar un modulo o permiso mapeado a una capacidad apagada.

Al guardar suscripcion, registrar pago o cambiar una excepcion de capacidad,
el cambio se confirma en control, se encola `BUSINESS_CONFIG` en la misma
transaccion y solo despues del `commit` se procesa la copia tenant de
`pos_saas.empresa_capacidad`. La respuesta incluye `sync.estado` como
`SINCRONIZADO` o `PENDIENTE`. Las excepciones de modulo y permiso no encolan
tenant: el POS las consulta desde control.

## API interna

Todas las rutas exigen sesion de superadmin:

- `GET /admin/empresas/{idEmpresa}/accesos-efectivos`
- `GET /admin/empresas/{idEmpresa}/entitlement-excepciones`
- `PUT /admin/empresas/{idEmpresa}/entitlement-excepciones`
- `DELETE /admin/empresas/{idEmpresa}/entitlement-excepciones/{idExcepcion}`

El `PUT` recibe exactamente uno de `id_modulo` o `id_permiso`, junto con
`enabled` y un `motivo` de minimo diez caracteres. Las escrituras dejan
auditoria de aplicacion y la auditoria de base existente.

Complementos comerciales:

- `GET /admin/saas/complementos`
- `PUT /admin/saas/complementos/{codigo}`
- `GET /admin/empresas/{idEmpresa}/complementos`
- `GET /admin/empresas/{idEmpresa}/complementos/elegibles`
- `PUT /admin/empresas/{idEmpresa}/complementos/{codigo}`
- `PATCH /admin/empresas/{idEmpresa}/complementos/{codigo}/desactivar`
- `PATCH /admin/empresas/{idEmpresa}/complementos/{codigo}/restaurar`
- `PUT /admin/empresas/{idEmpresa}/excepciones-internas/{codigo}`

El ultimo endpoint es para catalogos `SOLO_INTERNO`. La semilla inicial usa
`PRECONTABILIDAD_INTERNA`; activa en bloque sus recursos y capacidad con motivo
obligatorio, para una empresa concreta, sin precio ni exposicion publica.

`WHATSAPP_POS` y `WHATSAPP_SOPORTE` son complementos separados. La migracion
no crea activaciones existentes ni cambia lo que los planes ya incluyen.

El catálogo de complementos elegibles no usa `whatsapp_incluido` ni las tablas
materializadas de empresa para decidir. Compara cada módulo, permiso y
capacidad mapeados contra la matriz canónica del plan. Por ello devuelve:

- `ACTIVABLE`: aporta al menos un recurso comercial adicional y no tiene
  bloqueos.
- `INCLUIDO_EN_PLAN`: todos sus recursos ya los aporta el plan; no se puede
  activar ni cobrar de nuevo.
- `BLOQUEADO_CAPACIDAD`: requiere una capacidad efectiva apagada.
- `INCOMPATIBLE_PLAN`, `REQUIERE_DEPENDENCIA`, `CATALOGO_INACTIVO` o
  `SIN_RECURSOS`: no se puede activar por la razón indicada.

Cada elemento incluye `elegible`, `estado_comercial`, `incluido_en_plan`, el
resumen `recursos` y `capacidades_requeridas_faltantes`. El frontend solo debe
renderizar esos valores: puede mostrar los incluidos en una sección sin botón
de activación y los bloqueados con una explicación. El endpoint de activación
repite la validación y rechaza `COMPLEMENTO_YA_INCLUIDO_EN_PLAN`, por lo que no
puede duplicarse mediante un payload manual.

## Reconciliación de capacidades materializadas

Cuando una matriz comercial cambia después de que una capacidad fue
materializada históricamente, usar la CLI administrativa. No modifica
suscripciones, módulos, permisos, complementos ni excepciones internas:

```powershell
php bin/reconcile_saas_capabilities.php --dry-run
php bin/reconcile_saas_capabilities.php --apply
```

`--dry-run` no escribe y lista por empresa la capacidad, su valor actual y el
valor esperado por el servicio canónico. `--apply` llama
`SaasCapabilityService::applyEffectiveCapabilities()` únicamente para empresas
con diferencias, registra `SAAS_CAPACIDADES_RECONCILIADAS_CLI`, encola
`BUSINESS_CONFIG` dentro de la transacción y procesa esa tarea después del
commit. Si el tenant no está disponible, la tarea queda `PENDIENTE` para el
worker de outbox.

La opción opcional `--actor=nombre` identifica el origen de auditoría; su
valor por defecto es `system`. Una segunda ejecución sin diferencias no crea
auditoría ni sincronizaciones nuevas.

## Despliegue

### Módulo comercial Servicios

`/servicios` reutiliza el recurso Producto y conserva `PRODUCTOS__VER` como
permiso *gate*; no existen permisos paralelos `SERVICIOS__*` mientras el API
del catálogo siga protegido por Productos. La matriz comercial lo habilita en
Esencial, Pro, Soporte Esencial y Soporte Pro. La clave canónica de icono es
`sell` (Material Symbols) y se ubica inmediatamente debajo de Deudores y
encima de Productos.

Primero ejecutar en `bersano_control`, como propietario:

```powershell
& "C:\Program Files\PostgreSQL\17\bin\psql.exe" -v ON_ERROR_STOP=1 -U cristian -d bersano_control -f database/manual/2026-11-04_servicios_modulo_comercial_control.sql
```

Esta es la única migración requerida para publicar Servicios. `GET
/modulo?visible=1` consulta `bersano_control.pos_saas.modulo`; en modo
`plans`, su visibilidad se calcula contra la matriz comercial central. No se
crea una copia del módulo en tenants ni se replica `empresa_modulo` o
`empresa_permiso` mediante `BUSINESS_CONFIG`, que conserva exclusivamente la
sincronización de empresa y capacidades operativas.

La matriz base `2026-11-02_saas_matriz_comercial_y_excepciones.sql` ya fue
aplicada en `bersano_control`. Antes del runtime nuevo aplicar, una unica vez
en esa misma base y como propietario:

```powershell
& "C:\Program Files\PostgreSQL\17\bin\psql.exe" -v ON_ERROR_STOP=1 -U cristian -d bersano_control -f database/manual/2026-11-02_entitlement_excepcion_actor_superadmin.sql
```

El SQL no crea otra tabla de excepciones: corrige la referencia de `actor_id`
para que apunte a `admin.superadmin_user`, que es quien opera el panel.
Si detecta una excepcion historica cuyo actor no existe como superadmin, aborta
sin modificarla con `ENTITLEMENT_EXCEPTION_ACTOR_NOT_SUPERADMIN`; ese caso se
debe revisar antes de volver a ejecutarlo.

La correccion al archivo historico
`2026-10-08_tesoreria_permisos_base.sql` es preventiva para futuras
instalaciones. No se debe volver a ejecutar en una base ya desplegada: la
matriz comercial vigente es quien define Compras.

## Complementos: despliegue

Despues de las dos migraciones previas de entitlements, ejecutar una vez en
`bersano_control`:

```powershell
& "C:\Program Files\PostgreSQL\17\bin\psql.exe" -v ON_ERROR_STOP=1 -U cristian -d bersano_control -f database/manual/2026-11-02_complementos_comerciales.sql
```

La migracion crea solo catalogo y activaciones vacias. No reescribe,
deshabilita ni elimina la materializacion existente, ni convierte permisos
historicos desconocidos en complementos. Esto protege, entre otros, el acceso
manual de Precontabilidad del tenant interno durante la transicion.

La secuencia segura de activacion es:

1. Aplicar esta migracion con `ENTITLEMENTS_MODE=legacy` (o sin la variable).
2. Registrar `PRECONTABILIDAD_INTERNA` para la empresa 2 mediante la API
   interna, con motivo y actor superadministrador.
3. Consultar accesos efectivos y confirmar que Precontabilidad tiene origen
   `EXCEPCION_INTERNA`.
4. Configurar explicitamente `ENTITLEMENTS_MODE=plans` y desplegar el runtime.
5. Normalizar la materializacion solo al guardar una suscripcion, operar un
   complemento, aplicar una excepcion interna o mediante una accion explicita
   posterior. No se debe hacer una normalizacion masiva al instalar el SQL.
