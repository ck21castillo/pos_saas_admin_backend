<?php

namespace PosAdmin\Service;

use PDO;
use PosAdmin\Core\Database;
use PHPMailer\PHPMailer\PHPMailer;

final class AdminOtpService
{
    public static function ttl(): int
    {
        return max(60, (int)($_ENV['ADMIN_OTP_TTL'] ?? 300));
    }

    public static function maxAttempts(): int
    {
        return max(1, (int)($_ENV['ADMIN_OTP_MAX_ATTEMPTS'] ?? 5));
    }

    public static function createLoginOtp(int $adminId, string $email): array
    {
        $pdo = Database::getConnection();
        $ttl = self::ttl();
        $code = self::newCode();
        $intent = self::newIntent();
        $expiresAt = (new \DateTimeImmutable('now'))->modify('+' . $ttl . ' seconds')->format('Y-m-d H:i:sP');

        $st = $pdo->prepare('
            INSERT INTO admin.superadmin_otp
                (id_superadmin, email, proposito, code_hash, intent_token, expires_at)
            VALUES
                (:admin, :email, \'login\', :hash, :intent, :expires)
        ');
        $st->execute([
            ':admin' => $adminId,
            ':email' => $email,
            ':hash' => self::hashCode($code),
            ':intent' => $intent,
            ':expires' => $expiresAt,
        ]);

        return [$code, $intent, $ttl];
    }

    public static function verifyLoginOtp(string $intentToken, string $code): array
    {
        $intentToken = trim($intentToken);
        $code = trim($code);
        if ($intentToken === '' || !preg_match('/^\d{6}$/', $code)) {
            throw new \RuntimeException('OTP_INVALID');
        }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $st = $pdo->prepare('
                SELECT
                    o.id_otp, o.id_superadmin, o.code_hash, o.expires_at,
                    o.attempts, o.consumed_at,
                    u.email, u.estado
                FROM admin.superadmin_otp o
                JOIN admin.superadmin_user u ON u.id_superadmin = o.id_superadmin
                WHERE o.intent_token = :intent
                LIMIT 1
                FOR UPDATE
            ');
            $st->execute([':intent' => $intentToken]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new \RuntimeException('OTP_INTENT_NOT_FOUND');
            }
            if ((int)$row['estado'] !== 1) {
                throw new \RuntimeException('ADMIN_DISABLED');
            }
            if (!empty($row['consumed_at'])) {
                throw new \RuntimeException('OTP_ALREADY_USED');
            }
            if (new \DateTimeImmutable((string)$row['expires_at']) < new \DateTimeImmutable('now')) {
                throw new \RuntimeException('OTP_EXPIRED');
            }
            if ((int)$row['attempts'] >= self::maxAttempts()) {
                throw new \RuntimeException('OTP_TOO_MANY_ATTEMPTS');
            }

            $ok = hash_equals((string)$row['code_hash'], self::hashCode($code));
            if (!$ok) {
                $upd = $pdo->prepare('UPDATE admin.superadmin_otp SET attempts = attempts + 1, updated_at = now() WHERE id_otp = :id');
                $upd->execute([':id' => (int)$row['id_otp']]);
                throw new \RuntimeException('OTP_INVALID');
            }

            $upd = $pdo->prepare('UPDATE admin.superadmin_otp SET consumed_at = now(), updated_at = now() WHERE id_otp = :id');
            $upd->execute([':id' => (int)$row['id_otp']]);
            $pdo->commit();

            return [
                'id_superadmin' => (int)$row['id_superadmin'],
                'email' => (string)$row['email'],
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function resendLoginOtp(string $intentToken): array
    {
        $intentToken = trim($intentToken);
        if ($intentToken === '') {
            throw new \RuntimeException('OTP_INTENT_NOT_FOUND');
        }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $st = $pdo->prepare('
                SELECT
                    o.id_otp, o.id_superadmin, o.email, o.expires_at,
                    o.consumed_at, o.resend_count,
                    u.estado
                FROM admin.superadmin_otp o
                JOIN admin.superadmin_user u ON u.id_superadmin = o.id_superadmin
                WHERE o.intent_token = :intent
                LIMIT 1
                FOR UPDATE
            ');
            $st->execute([':intent' => $intentToken]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new \RuntimeException('OTP_INTENT_NOT_FOUND');
            }
            if ((int)$row['estado'] !== 1) {
                throw new \RuntimeException('ADMIN_DISABLED');
            }
            if (!empty($row['consumed_at'])) {
                throw new \RuntimeException('OTP_ALREADY_USED');
            }
            if (new \DateTimeImmutable((string)$row['expires_at']) < new \DateTimeImmutable('now')) {
                throw new \RuntimeException('OTP_EXPIRED');
            }

            $maxResend = max(0, (int)($_ENV['ADMIN_OTP_RESEND_MAX'] ?? 3));
            if ((int)$row['resend_count'] >= $maxResend) {
                throw new \RuntimeException('OTP_RESEND_LIMIT');
            }

            $ttl = self::ttl();
            $code = self::newCode();
            $newIntent = self::newIntent();
            $expiresAt = (new \DateTimeImmutable('now'))->modify('+' . $ttl . ' seconds')->format('Y-m-d H:i:sP');

            $upd = $pdo->prepare('
                UPDATE admin.superadmin_otp
                   SET code_hash = :hash,
                       intent_token = :intent,
                       attempts = 0,
                       resend_count = resend_count + 1,
                       expires_at = :expires,
                       updated_at = now()
                 WHERE id_otp = :id
            ');
            $upd->execute([
                ':hash' => self::hashCode($code),
                ':intent' => $newIntent,
                ':expires' => $expiresAt,
                ':id' => (int)$row['id_otp'],
            ]);

            $pdo->commit();

            return [
                'code' => $code,
                'intent' => $newIntent,
                'ttl' => $ttl,
                'email' => (string)$row['email'],
                'id_superadmin' => (int)$row['id_superadmin'],
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function sendLoginOtp(string $toEmail, string $code, int $ttl): void
    {
        $mail = self::baseMailer();
        $mail->addAddress($toEmail, $toEmail);
        $mail->Subject = 'Codigo de acceso - ' . self::appName();
        $mail->Body = self::htmlBody($toEmail, $code, $ttl);
        $mail->AltBody = self::textBody($toEmail, $code, $ttl);
        $mail->send();
    }

    private static function newCode(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function newIntent(): string
    {
        return bin2hex(random_bytes(32));
    }

    private static function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }

    private static function baseMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string)($_ENV['SMTP_HOST'] ?? 'smtp.gmail.com');
        $mail->Port = (int)($_ENV['SMTP_PORT'] ?? 587);
        $mail->SMTPAuth = true;
        $mail->SMTPSecure = (string)($_ENV['SMTP_SECURE'] ?? 'tls');
        $mail->Username = (string)($_ENV['SMTP_USER'] ?? '');
        $mail->Password = (string)($_ENV['SMTP_PASS'] ?? '');
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isHTML(true);

        $from = (string)($_ENV['MAIL_FROM'] ?? $mail->Username);
        $fromName = (string)($_ENV['MAIL_FROM_NAME'] ?? self::appName());
        $mail->setFrom($from, $fromName);

        return $mail;
    }

    private static function htmlBody(string $toEmail, string $code, int $ttl): string
    {
        $app = htmlspecialchars(self::appName(), ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8');
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $minutes = (int)ceil($ttl / 60);
        $ttlText = $ttl <= 60 ? $ttl . ' segundos' : $minutes . ' minutos';

        return <<<HTML
<!doctype html>
<html lang="es">
  <body style="margin:0;padding:0;background:#f5f7fb;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7fb;padding:24px 0;">
      <tr>
        <td align="center" style="padding:0 12px;">
          <table role="presentation" width="520" cellspacing="0" cellpadding="0" style="max-width:520px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(15,23,42,.08);">
            <tr>
              <td style="padding:22px 24px;border-bottom:1px solid #eef2f7;font-family:Arial,Helvetica,sans-serif;">
                <div style="font-size:16px;color:#0f172a;font-weight:700;">{$app}</div>
                <div style="margin-top:6px;font-size:13px;color:#64748b;">Codigo de acceso al panel administrativo</div>
              </td>
            </tr>
            <tr>
              <td style="padding:22px 24px;font-family:Arial,Helvetica,sans-serif;color:#334155;line-height:1.5;font-size:14px;">
                <p style="margin:0 0 12px 0;">Se solicito acceso al panel administrativo para:</p>
                <p style="margin:0 0 18px 0;"><b>{$email}</b></p>
                <p style="margin:0 0 16px 0;">Usa este codigo para completar el inicio de sesion:</p>
                <div style="text-align:center;margin:20px 0;">
                  <div style="display:inline-block;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;">
                    <div style="font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;font-size:30px;font-weight:800;letter-spacing:6px;color:#0f172a;">
                      {$safeCode}
                    </div>
                  </div>
                </div>
                <p style="margin:0;color:#64748b;font-size:12px;">Este codigo vence en <b>{$ttlText}</b>. Si no fuiste tu, ignora este correo.</p>
              </td>
            </tr>
            <tr>
              <td style="padding:14px 24px;background:#fafafa;border-top:1px solid #eef2f7;font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#94a3b8;line-height:1.4;">
                No compartas este codigo con nadie.
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </body>
</html>
HTML;
    }

    private static function textBody(string $toEmail, string $code, int $ttl): string
    {
        return "Codigo de acceso para " . self::appName() . "\n\nCorreo: {$toEmail}\nCodigo: {$code}\nValido por {$ttl} segundos.\n\nSi no fuiste tu, ignora este mensaje.";
    }

    private static function appName(): string
    {
        return (string)($_ENV['APP_NAME'] ?? 'Bersano POS');
    }
}