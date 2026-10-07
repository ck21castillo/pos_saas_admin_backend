# Seguridad del panel admin

Este documento cubre los controles de aplicacion del repositorio
`pos_saas_admin` y los requisitos que deben configurarse al desplegarlo.

## Controles en runtime

- No existe un creador de superadmin en `public/`. Para una recuperacion
  excepcional se usa solo por consola:

  En Linux, una sesion operativa puede ejecutar:

  ```bash
  read -rs ADMIN_PASSWORD
  printf '%s' "$ADMIN_PASSWORD" | php bin/create_superadmin.php --email=admin@dominio.com --password-stdin
  unset ADMIN_PASSWORD
  ```

  En Windows, solicitar la clave de forma interactiva y pasarla por `stdin`;
  no incluirla en la URL, argumentos, historiales ni archivos `.env`.

- Con `APP_ENV=production` o `prod`, las peticiones HTTP no arrancan si
  `APP_DEBUG=1`, `COOKIE_SECURE!=1`, el rate limit de login esta apagado o
  `JWT_SECRET` es corto/inseguro. La respuesta es el codigo seguro
  `SERVER_SECURITY_MISCONFIGURED`.
- Las mutaciones autenticadas rechazan un encabezado `Origin` que no pertenezca
  a `CORS_ALLOWED_ORIGINS` o a los origenes oficiales. Mantener el frontend
  admin en esa lista.
- Los cuerpos JSON tienen un maximo de 1 MB por defecto, configurable con
  `ADMIN_JSON_MAX_BYTES` entre 16 KB y 5 MB.
- Las cargas de inventario se limitan a 30 MB comprimidos, 20.000 filas y
  limites conservadores de ZIP/XML para impedir archivos de expansion masiva.
- La inspeccion profunda de salud tenant por HTTP queda apagada por defecto;
  el worker programado es el mecanismo normal de refresco.

## Infraestructura obligatoria en produccion

Aplicar limites antes de PHP. Ejemplo Nginx, ajustando el bloque y las zonas a
la configuracion real:

```nginx
limit_req_zone $binary_remote_addr zone=admin_login:10m rate=10r/m;
limit_req_zone $binary_remote_addr zone=landing_analytics:10m rate=30r/m;

location = /api/admin/auth/login {
    limit_req zone=admin_login burst=10 nodelay;
    client_max_body_size 64k;
}

location = /api/analytics/landing-visit {
    limit_req zone=landing_analytics burst=15 nodelay;
    client_max_body_size 16k;
}

location /api/ {
    client_max_body_size 31m;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Real-IP $remote_addr;
}
```

Configurar tambien PHP con `post_max_size=31M`, `upload_max_filesize=30M` y un
`memory_limit` suficiente para cargas autorizadas, sin elevarlos para aceptar
archivos arbitrarios. El WAF o CDN debe limitar solicitudes por IP antes del
origen y bloquear patrones automatizados.

## Base de datos y SQL

El runtime usa sentencias preparadas y `PDO::ATTR_EMULATE_PREPARES=false`. Los
identificadores dinamicos existentes deben seguir siendo listas internas
cerradas, nunca valores de rutas, query strings o cuerpos HTTP. El rol
`bersano_admin_ejecucion` conserva minimo privilegio y no recibe `CREATE`.

Antes de activar limits de login/analitica, confirmar que existe
`admin.admin_auth_rate_limit_bucket` mediante:

```text
database/manual/2026-06-20_admin_login_rate_limit.sql
```

El SQL se ejecuta en `bersano_control` con un rol propietario/de despliegue.
Incluye los permisos de lectura/escritura estrictamente necesarios para
`bersano_admin_ejecucion`. No se requieren cambios en bases tenant para este
bloque.
