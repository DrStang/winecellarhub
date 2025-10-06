<?php
require 'db.php';
require_once 'mailer.php';
require __DIR__.'/analytics_track.php'; // <-- add this


// Create password_resets table if needed
$pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    UNIQUE KEY token_hash_unique (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email.";
    } else {
        // Find user (do not reveal existence later)
        $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Always behave similarly, but only create a token if user exists
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $hash  = hash('sha256', $token);
            $expiresMinutes = 60;
            $expiresAt = (new DateTime("+{$expiresMinutes} minutes"))->format('Y-m-d H:i:s');

            // Optional: invalidate previous unused tokens for this user
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0")->execute([$user['id']]);

            $ins = $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, used) VALUES (?, ?, ?, 0)");
            $ins->execute([$user['id'], $hash, $expiresAt]);

            // Build reset link
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
            $resetUrl = $baseUrl . dirname($_SERVER['REQUEST_URI']) . "/reset_password.php?token=" . urlencode($token);

            // Use ADMIN SMTP creds for reset emails
            $over = [
                'user'   => env_get('SMTP_USER_ADMIN', env_get('SMTP_USER')),
                'pass'   => env_get('SMTP_PASS_ADMIN', env_get('SMTP_PASS')),
                'from'   => env_get('SMTP_FROM_ADMIN', 'admin@winecellarhub.com'),
                'from_name' => env_get('SMTP_FROM_NAME_ADMIN', 'WineCellarHub Admin'),
                'host'   => env_get('SMTP_HOST'),
                'port'   => env_get('SMTP_PORT', 587),
                'secure' => env_get('SMTP_SECURE', 'tls')
            ];

            $subject = "Reset your WineCellarHub password";
            $html = '
            <div style="font-family:Arial,Helvetica,sans-serif; line-height:1.6; max-width:640px; margin:auto;">
                <p>Hi ' . htmlspecialchars($user['username']) . ',</p>
                <p>We received a request to reset your password. Click the button below to set a new one. This link expires in 60 minutes.</p>
                <p style="margin:24px 0;"><a href="' . htmlspecialchars($resetUrl) . '" style="background:#7A1E1E;color:#fff;padding:12px 18px;border-radius:8px;text-decoration:none;display:inline-block;">Reset password</a></p>
                <p>If you didn\'t request this, you can safely ignore this email.</p>
                <p style="margin-top:24px;">– WineCellarHub</p>
            </div>';
            $text = "Hi {$user['username']},\n\nUse this link to reset your password (expires in 60 minutes):\n{$resetUrl}\n\nIf you didn't request this, ignore this email.\n\n– WineCellarHub";

            // BCC admin is OK per requirements
            @send_mail_with_overrides($user['email'], $subject, $html, $text, 'admin@winecellarhub.com', $over);
        }

        $sent = true; // always show success to avoid account enumeration
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot password — WineCellarHub</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#faf7f5; color:#222; }
    .card { max-width: 520px; margin: 48px auto; background:#fff; border-radius:16px; padding:28px; box-shadow:0 10px 30px rgba(0,0,0,.06); }
    h2 { margin:0 0 12px; }
    label { display:block; font-weight:600; margin-top:14px; }
    input[type="email"] { width:100%; padding:12px 14px; border:1px solid #ddd; border-radius:10px; margin-top:6px; }
    button { margin-top:18px; width:100%; padding:12px 14px; border:0; border-radius:12px; background:#7A1E1E; color:#fff; font-weight:700; cursor:pointer; }
    .alert { background:#f0fdf4; color:#166534; border:1px solid #86efac; padding:12px; border-radius:10px; margin-bottom:12px; }
    .error { background:#fdf2f2; color:#7a1e1e; border:1px solid #f5c2c7; }
    a { color:#7A1E1E; text-decoration:none; }
  </style>
</head>
<body>
  <div class="card">
    <h2>Forgot your password?</h2>
    <p>Enter your email and we’ll send a reset link if there’s an account for it.</p>

    <?php if ($error): ?>
      <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($sent): ?>
      <div class="alert">If that email exists, a reset link is on its way.</div>
    <?php else: ?>
      <form method="post" autocomplete="on">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" required>
        <button type="submit">Send reset link</button>
      </form>
      <p style="margin-top:12px;"><a href="login.php">Back to login</a></p>
    <?php endif; ?>
  </div>
</body>
</html>
