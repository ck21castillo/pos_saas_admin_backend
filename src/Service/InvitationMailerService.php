<?php

namespace PosAdmin\Service;

use PHPMailer\PHPMailer\PHPMailer;

final class InvitationMailerService
{
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

    public static function sendInvitation(
        string $toEmail,
        string $inviteCode,
        string $expiresAt,
        int $days,
        string $template
    ): void {
        $template = self::normalizeTemplate($template);
        $mail = self::baseMailer();
        $mail->addAddress($toEmail, $toEmail);
        $mail->Subject = self::subject($template);
        $mail->Body = self::htmlBody($toEmail, $inviteCode, $expiresAt, $days, $template);
        $mail->AltBody = self::textBody($toEmail, $inviteCode, $expiresAt, $days, $template);
        $mail->send();
    }

    public static function normalizeTemplate(string $template): string
    {
        $value = strtolower(trim($template));
        return in_array($value, ['meta', 'cliente'], true) ? $value : 'cliente';
    }

    private static function subject(string $template): string
    {
        $app = self::appName();
        if ($template === 'meta') {
            return "Invitacion de prueba para revision de {$app}";
        }

        return "Tu invitacion para activar {$app}";
    }

    private static function htmlBody(
        string $toEmail,
        string $inviteCode,
        string $expiresAt,
        int $days,
        string $template
    ): string {
        $app = htmlspecialchars(self::appName(), ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8');
        $code = htmlspecialchars($inviteCode, ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars(self::registerUrl(), ENT_QUOTES, 'UTF-8');
        $expires = htmlspecialchars(self::formatExpiresAt($expiresAt), ENT_QUOTES, 'UTF-8');
        $intro = self::introHtml($template);
        $note = self::noteHtml($template);

        return <<<HTML
<!doctype html>
<html lang="es">
  <body style="margin:0;padding:0;background:#f5f7fb;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7fb;padding:24px 0;">
      <tr>
        <td align="center" style="padding:0 12px;">
          <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(15,23,42,.08);">
            <tr>
              <td style="padding:22px 24px;border-bottom:1px solid #eef2f7;">
                <div style="font-family:Arial,Helvetica,sans-serif;font-size:16px;color:#0f172a;font-weight:700;">{$app}</div>
                <div style="font-family:Arial,Helvetica,sans-serif;margin-top:6px;font-size:13px;color:#64748b;">Invitacion de registro</div>
              </td>
            </tr>
            <tr>
              <td style="padding:22px 24px;font-family:Arial,Helvetica,sans-serif;color:#334155;line-height:1.5;font-size:14px;">
                {$intro}
                <p style="margin:0 0 14px 0;">El codigo esta ligado a este correo:</p>
                <p style="margin:0 0 18px 0;"><b>{$email}</b></p>

                <div style="text-align:center;margin:20px 0;">
                  <div style="display:inline-block;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;">
                    <div style="font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;font-size:24px;font-weight:800;letter-spacing:2px;color:#0f172a;">
                      {$code}
                    </div>
                  </div>
                </div>

                <p style="margin:0 0 18px 0;">Para continuar, abre el registro y pega el codigo cuando se solicite.</p>
                <p style="text-align:center;margin:24px 0;">
                  <a href="{$url}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:10px;padding:12px 18px;font-weight:700;">
                    Abrir registro
                  </a>
                </p>
                <p style="margin:0 0 10px 0;color:#64748b;font-size:12px;">Si el boton no abre, copia este enlace en el navegador:</p>
                <p style="margin:0 0 16px 0;color:#2563eb;font-size:12px;word-break:break-all;">{$url}</p>
                <p style="margin:0;color:#64748b;font-size:12px;">Vence en {$days} dia(s). Fecha de vencimiento: {$expires}.</p>
                {$note}
              </td>
            </tr>
            <tr>
              <td style="padding:14px 24px;background:#fafafa;border-top:1px solid #eef2f7;font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#94a3b8;line-height:1.4;">
                Si no solicitaste esta invitacion, puedes ignorar este correo.
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

    private static function textBody(
        string $toEmail,
        string $inviteCode,
        string $expiresAt,
        int $days,
        string $template
    ): string {
        $app = self::appName();
        $url = self::registerUrl();
        $expires = self::formatExpiresAt($expiresAt);

        if ($template === 'meta') {
            return "Hola equipo Meta.\n\nCompartimos una invitacion de prueba para revisar el flujo de registro de {$app}.\n\nCorreo ligado: {$toEmail}\nCodigo: {$inviteCode}\nRegistro: {$url}\nVence en {$days} dia(s). Fecha: {$expires}\n\nUsen el mismo correo destinatario al completar el registro.";
        }

        return "Hola.\n\nTu invitacion para activar {$app} esta lista.\n\nCorreo ligado: {$toEmail}\nCodigo: {$inviteCode}\nRegistro: {$url}\nVence en {$days} dia(s). Fecha: {$expires}\n\nUsa el mismo correo destinatario al completar el registro.";
    }

    private static function introHtml(string $template): string
    {
        if ($template === 'meta') {
            return '<p style="margin:0 0 14px 0;">Hola equipo Meta,</p><p style="margin:0 0 14px 0;">Compartimos una invitacion de prueba para revisar el flujo de registro de Bersano POS.</p>';
        }

        return '<p style="margin:0 0 14px 0;">Hola,</p><p style="margin:0 0 14px 0;">Tu invitacion para activar Bersano POS esta lista.</p>';
    }

    private static function noteHtml(string $template): string
    {
        if ($template === 'meta') {
            return '<p style="margin:16px 0 0 0;color:#64748b;font-size:12px;">Nota para revision: usa exactamente el correo destinatario de este mensaje al crear la cuenta.</p>';
        }

        return '<p style="margin:16px 0 0 0;color:#64748b;font-size:12px;">Recuerda usar exactamente este correo al crear la cuenta de tu empresa.</p>';
    }

    private static function appName(): string
    {
        return (string)($_ENV['APP_NAME'] ?? 'Bersano POS');
    }

    private static function registerUrl(): string
    {
        $explicit = trim((string)($_ENV['ONBOARDING_REGISTER_URL'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $base = trim((string)($_ENV['APP_PUBLIC_URL'] ?? $_ENV['APP_URL'] ?? ''));
        if ($base === '') {
            return 'https://bersanopos.com/#/register-company';
        }

        return rtrim($base, '/') . '/#/register-company';
    }

    private static function formatExpiresAt(string $expiresAt): string
    {
        $ts = strtotime($expiresAt);
        if ($ts === false) {
            return $expiresAt;
        }

        return date('Y-m-d H:i', $ts);
    }
}
