<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

if (!function_exists('app_config')) {
    require_once __DIR__ . '/config.php';
}

require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/SMTP.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';

function smtp_config_from_env(): ?array
{
    $host = env_value('MAIL_HOST', '') ?? '';
    if (trim($host) === '') {
        return null;
    }

    $auth = env_bool('MAIL_SMTP_AUTH', true);
    $username = env_value('MAIL_USERNAME', '') ?? '';
    $password = env_value('MAIL_PASSWORD', '') ?? '';
    $fromEmail = env_value('MAIL_FROM_EMAIL', '') ?? '';
    $encryption = strtolower(env_value('MAIL_ENCRYPTION', 'tls') ?? 'tls');

    if (!in_array($encryption, ['', 'tls', 'ssl'], true)) {
        safe_log('Invalid MAIL_ENCRYPTION value');
        return null;
    }

    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        safe_log('MAIL_FROM_EMAIL is missing or invalid');
        return null;
    }

    if ($auth && ($username === '' || $password === '')) {
        safe_log('SMTP auth is enabled but MAIL_USERNAME or MAIL_PASSWORD is missing');
        return null;
    }

    return [
        'host' => $host,
        'port' => env_int('MAIL_PORT', $encryption === 'ssl' ? 465 : 587, 1),
        'username' => $username,
        'password' => $password,
        'encryption' => $encryption,
        'from_email' => $fromEmail,
        'from_name' => env_value('MAIL_FROM_NAME', (string) app_config('app_name')) ?: (string) app_config('app_name'),
        'auth' => $auth,
    ];
}

function send_admin_mail(string $to, string $subject, string $html): bool
{
    if (!class_exists(PHPMailer::class)) {
        safe_log('PHPMailer runtime is missing');
        return false;
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        safe_log('Refused to send email to invalid address', ['recipient_hash' => keyed_hash($to)]);
        return false;
    }

    $cfg = smtp_config_from_env();
    if ($cfg === null) {
        safe_log('SMTP is not configured');
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->Port = (int) $cfg['port'];
        $mail->SMTPAuth = (bool) $cfg['auth'];
        $mail->Username = $cfg['username'];
        $mail->Password = $cfg['password'];
        $mail->SMTPSecure = $cfg['encryption'];
        $mail->SMTPDebug = 0;
        $mail->Timeout = env_int('MAIL_TIMEOUT_SECONDS', 10, 1);
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));

        return $mail->send();
    } catch (Throwable $e) {
        safe_log('SMTP delivery failed', [
            'recipient_hash' => keyed_hash($to),
            'message' => $e->getMessage(),
        ]);

        return false;
    }
}
