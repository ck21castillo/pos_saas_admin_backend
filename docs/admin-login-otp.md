# OTP para login del panel admin

Este flujo agrega verificacion OTP por correo para el panel administrativo.

## Archivos principales

- Backend:
  - `src/Controller/AdminAuthController.php`
  - `src/Service/AdminOtpService.php`
  - `public/index.php`
- Frontend:
  - `src/pages/login/LoginPage.tsx`
  - `src/store/adminAuthSlice.ts`
  - `src/api/adminClient.ts`
- SQL manual:
  - `database/manual/2026-06-19_admin_login_otp.sql`

## Activacion

1. Ejecutar el SQL manual en la base `bersano_control`.
2. Configurar el `.env` del backend admin:

```env
ADMIN_OTP_ENABLE=true
ADMIN_OTP_TTL=300
ADMIN_OTP_MAX_ATTEMPTS=5
ADMIN_OTP_RESEND_MAX=3
ADMIN_OTP_COOKIE_NAME=admin_otp
```

3. Confirmar que SMTP esta configurado:

```env
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=...
SMTP_PASS=...
MAIL_FROM=...
MAIL_FROM_NAME="Bersano POS"
```

## Flujo

1. El superadmin ingresa email y password.
2. Si las credenciales son correctas y `ADMIN_OTP_ENABLE=true`, el backend no crea la sesion final todavia.
3. El backend crea un codigo OTP de 6 digitos, guarda solo su hash en `admin.superadmin_otp`, envia el codigo por correo y guarda un `admin_otp` cookie HttpOnly temporal.
4. El frontend muestra el campo de codigo.
5. `POST /admin/auth/otp/verify` valida el codigo y emite la cookie final `admin_access`.
6. `POST /admin/auth/otp/resend` permite reenviar el codigo dentro del limite configurado.

## Seguridad

- El codigo OTP no se guarda en texto plano, solo `sha256`.
- El intent se guarda en cookie HttpOnly temporal.
- Hay limite de intentos por codigo.
- Hay limite de reenvios por intent.
- Si el superadmin se desactiva, el OTP deja de ser valido.

## Nota de despliegue

Por seguridad operativa, el codigo no activa OTP por defecto. Si el SQL no se ha ejecutado y `ADMIN_OTP_ENABLE=true`, el login fallara al intentar crear el OTP. Primero aplicar SQL, luego activar la variable.