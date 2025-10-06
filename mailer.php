<?php
// mailer.php - PHPMailer wrapper using .env values
// Requires composer autoload OR falls back to native mail() if PHPMailer isn't available.

require_once __DIR__ . '/auth.php';

// Lazy .env loader
// In mailer.php (top-level helper)
// Avoid redeclare + search getenv, $_ENV, $_SERVER, then .env cache.
if (!function_exists('env_get')) {
    function env_get($key, $default = null) {
        $val = getenv($key);
        if ($val !== false && $val !== '') return $val;

        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];

        static $ENV_CACHE = null;
        if ($ENV_CACHE === null) {
            $ENV_CACHE = [];
            // Look for .env at project root (adjust if needed)
            $candidates = [
                __DIR__ . '/.env',            // if mailer.php is in project root
                dirname(__DIR__) . '/.env',   // if mailer/ lives under root subdir
            ];
            foreach ($candidates as $dotEnvPath) {
                if (is_readable($dotEnvPath)) {
                    foreach (file($dotEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                        $t = ltrim($line);
                        if ($t === '' || $t[0] === '#') continue;
                        $parts = explode('=', $line, 2);
                        if (count($parts) === 2) {
                            $k = trim($parts[0]);
                            $v = trim($parts[1], " \t\n\r\0\x0B\"'");
                            if ($k !== '') $ENV_CACHE[$k] = $v;
                        }
                    }
                    break;
                }
            }
        }
        return $ENV_CACHE[$key] ?? $default;
    }
}


function send_mail($to, $subject, $html, $text = '', $bcc = null, $fromEmail = null, $fromName = null) {
    $fromEmail = $fromEmail ?: env_get('SMTP_FROM', env_get('MAIL_FROM_ADDRESS', 'noreply@winecellarhub.com'));
    $fromName  = $fromName  ?: env_get('SMTP_FROM_NAME', env_get('MAIL_FROM_NAME', 'WineCellarHub'));
    $host      = env_get('SMTP_HOST');
    $port      = (int)(env_get('SMTP_PORT') ?: 587);
    $user      = env_get('SMTP_USER');
    $pass      = env_get('SMTP_PASS');
    $secure    = env_get('SMTP_SECURE', 'tls'); // tls|ssl|STARTTLS depending on server

    // Try PHPMailer first
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->Port       = $port;
            $mail->SMTPAuth   = true;
            $mail->Username   = $user;
            $mail->Password   = $pass;
            // Handle security
            if ($secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls' || $secure === 'STARTTLS') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = false;
            }
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            if (!empty($bcc)) {
                if (is_array($bcc)) {
                    foreach ($bcc as $b) $mail->addBCC($b);
                } else {
                    $mail->addBCC($bcc);
                }
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $text ?: strip_tags($html);

            $mail->send();
            return true;
        } catch (Throwable $e) {
            error_log('[mailer] PHPMailer failed: ' . $e->getMessage());
            // fall through to native mail
        }
    }

    // Fallback: native mail()
    $headers = [];
    $headers[] = "From: {$fromName} <{$fromEmail}>";
    if (!empty($bcc)) {
        $bccLine = is_array($bcc) ? implode(',', $bcc) : $bcc;
        $headers[] = "Bcc: {$bccLine}";
    }
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/html; charset=UTF-8";
    $headersStr = implode("\r\n", $headers);
    return @mail($to, $subject, $html, $headersStr);
}


/**
 * send_mail_with_overrides allows specifying alternate SMTP creds/from for special cases
 * e.g., password resets from admin account using SMTP_USER_ADMIN/SMTP_PASS_ADMIN.
 */
function send_mail_with_overrides($to, $subject, $html, $text = '', $bcc = null, $overrides = []) {
    $fromEmail = $overrides['from']      ?? env_get('SMTP_FROM', env_get('MAIL_FROM_ADDRESS', 'noreply@winecellarhub.com'));
    $fromName  = $overrides['from_name'] ?? env_get('SMTP_FROM_NAME', env_get('MAIL_FROM_NAME', 'WineCellarHub'));
    $host      = $overrides['host']      ?? env_get('SMTP_HOST');
    $port      = (int)($overrides['port'] ?? (env_get('SMTP_PORT') ?: 587));
    $user      = $overrides['user']      ?? env_get('SMTP_USER');
    $pass      = $overrides['pass']      ?? env_get('SMTP_PASS');
    $secure    = $overrides['secure']    ?? env_get('SMTP_SECURE', 'tls');

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->Port       = $port;
            $mail->SMTPAuth   = true;
            $mail->Username   = $user;
            $mail->Password   = $pass;
            if ($secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls' || $secure === 'STARTTLS') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = false;
            }
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            if (!empty($bcc)) {
                if (is_array($bcc)) {
                    foreach ($bcc as $b) $mail->addBCC($b);
                } else {
                    $mail->addBCC($bcc);
                }
            }
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $text ?: strip_tags($html);

            $mail->send();
            return true;
        } catch (Throwable $e) {
            error_log('[mailer] PHPMailer(override) failed: ' . $e->getMessage());
        }
    }

    // Fallback mail()
    $headers = [];
    $headers[] = "From: {$fromName} <{$fromEmail}>";
    if (!empty($bcc)) {
        $bccLine = is_array($bcc) ? implode(',', $bcc) : $bcc;
        $headers[] = "Bcc: {$bccLine}";
    }
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/html; charset=UTF-8";
    $headersStr = implode("\r\n", $headers);
    return @mail($to, $subject, $html, $headersStr);
}
