<?php
require_once __DIR__ . '/vendor/autoload.php';

// Load .env
function env_get($key, $default = null) {
    $val = getenv($key);
    if ($val !== false) return $val;
    $dotEnvPath = __DIR__ . '/.env';
    if (file_exists($dotEnvPath)) {
        foreach (file($dotEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if ($k === $key) return trim($v, "\"'");
        }
    }
    return $default;
}

$to       = ('dandolewski@gmail.com');
$from     = env_get('SMTP_FROM', 'wine@winecellarhub.com');
$fromName = env_get('SMTP_FROM_NAME', 'WineCellarHub');

$mail = new PHPMailer\PHPMailer\PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = env_get('SMTP_HOST');
    $mail->Port       = (int)env_get('SMTP_PORT', 587);
    $mail->SMTPAuth   = true;
    $mail->Username   = env_get('SMTP_USER');
    $mail->Password   = env_get('SMTP_PASS');
    $secure           = env_get('SMTP_SECURE', 'tls');
    if ($secure === 'ssl') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($secure === 'tls' || $secure === 'STARTTLS') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->setFrom($from, $fromName);
    $mail->addAddress($to);
    $mail->Subject = 'PHPMailer Test from WineCellarHub';
    $mail->Body    = '<p>This is a <strong>PHPMailer</strong> SMTP test email from WineCellarHub.</p>';
    $mail->AltBody = 'This is a PHPMailer SMTP test email from WineCellarHub.';

    $mail->send();
    echo "✅ Test email sent to {$to}";
} catch (Exception $e) {
    echo "❌ Email sending failed: {$mail->ErrorInfo}";
}
