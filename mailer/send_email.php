<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/../vendor/autoload.php';

function send_email(string $to, string $subject, string $html, bool $isHtml=true, ?string $bcc=null): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host     = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['SMTP_USER_ADMIN'] ?? $_ENV['SMTP_USER'];
        $mail->Password = $_ENV['SMTP_PASS'];
        $mail->Port     = intval($_ENV['SMTP_PORT'] ?? 587);
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        $from = $_ENV['SMTP_FROM'] ?? 'noreply@winecellarhub.com';
        $mail->setFrom($from, 'WineCellarHub');
        $mail->addAddress($to);
        if ($bcc ?? $_ENV['SMTP_BCC'] ?? null) $mail->addBCC($bcc ?? $_ENV['SMTP_BCC']);

        $mail->isHTML($isHtml);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = strip_tags($html);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("[mail] send failed: ".$e->getMessage());
        return false;
    }
}

