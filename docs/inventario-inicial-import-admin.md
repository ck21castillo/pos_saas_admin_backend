# Importacion de inventario inicial desde panel admin

Esta funcion permite que el administrador de Bersano cargue productos iniciales para una empresa usando una plantilla Excel. La carga se hace en el tenant de la empresa, no en `bersano_control`.

## Flujo recomendado

1. Entrar al panel admin.
2. Abrir el detalle de la empresa.
3. Ir a la seccion Configuracion.
4. En Implementacion inicial, descargar la plantilla Excel.
5. Diligenciar la hoja `Inventario`.
6. Subir el archivo y usar `Previsualizar archivo`.
7. Corregir errores si los hay.
8. Si la previsualizacion queda valida, usar `Confirmar importacion`.

## Campos obligatorios

- `nombre`
- `costo_unitario`
- `utilidad_porcentaje`
- `precio`
- `stock`

Los demas campos son opcionales. Si se informan `categoria`, `proveedor`, `unidad_medida` o `impuesto_nombre`, el importador reutiliza el registro existente o lo crea si no existe.

## Capacidades especiales

- `LOTES_VENCIMIENTOS`: permite importar lote y fecha de vencimiento. Si no esta activa, esos datos se ignoran.
- `PRODUCTOS_PESO`: permite productos con `tipo_cantidad = PESO` y stock decimal hasta 3 decimales.
- `PRODUCTOS_PRESENTACION`: permite crear presentaciones de venta. Si `usa_presentaciones = SI`, se crea automaticamente `Unidad x 1` y luego las presentaciones extra indicadas en las columnas `presentacion_1_*`, `presentacion_2_*`, etc.

## Que crea la confirmacion

- Producto.
- Registro de inventario.
- Documento confirmado de inventario inicial.
- Detalle del documento.
- Movimiento de inventario tipo `IN`.
- Lote, si aplica.
- Presentaciones, si aplica.

La operacion se ejecuta en una transaccion. Si una fila falla al confirmar, se revierte toda la importacion.

## Endpoints admin

- `GET /admin/empresas/{id}/inventario-import/plantilla`
- `POST /admin/empresas/{id}/inventario-import/preview`
- `POST /admin/empresas/{id}/inventario-import/confirm`

Los endpoints requieren sesion de administrador.

## Notas operativas

- No importar directamente por base de datos salvo casos excepcionales.
- Para cargas grandes, siempre previsualizar primero.
- El archivo debe ser `.xlsx`.
- Los SKU y codigos de barras no se pueden repetir entre productos y presentaciones.
