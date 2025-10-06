<?php
require 'db.php';
require __DIR__.'/analytics_track.php'; // <-- add this



$message = '';
$error = '';
$valid = false;
$token = $_GET['token'] ?? '';
$token = is_string($token) ? trim($token) : '';

if ($token !== '') {
    $hash = hash('sha256', $token);
    // Load reset record
    $stmt = $pdo->prepare("SELECT pr.id, pr.user_id, pr.expires_at, pr.used, u.username FROM password_resets pr JOIN users u ON pr.user_id = u.id WHERE pr.token_hash = ? LIMIT 1");
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $now = new DateTime();
        $exp = new DateTime($row['expires_at']);
        if ((int)$row['used'] === 0 && $exp >= $now) {
            $valid = true; // token OK
        } else {
            $error = "This reset link is expired or already used. Please request a new one.";
        }
    } else {
        $error = "Invalid reset link. Please request a new one.";
    }
} else {
    $error = "Missing reset token.";
}

// Handle password update
if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = $_POST['password'] ?? '';
    $p2 = $_POST['confirm_password'] ?? '';

    if (strlen($p1) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($p1 !== $p2) {
        $error = "Passwords do not match.";
    } else {
        $hashPass = password_hash($p1, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            // Update user password
            $up = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $up->execute([$hashPass, $row['user_id']]);

            // Mark token as used
            $up2 = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
            $up2->execute([$row['id']]);

            $pdo->commit();

            // Auto-login
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['username'] = $row['username'];

            header("Location: index.php");
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = "Could not reset password. Please try again.";
            error_log('[reset_password] ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset password — WineCellarHub</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#faf7f5; color:#222; }
    .card { max-width: 520px; margin: 48px auto; background:#fff; border-radius:16px; padding:28px; box-shadow:0 10px 30px rgba(0,0,0,.06); }
    h2 { margin:0 0 12px; }
    label { display:block; font-weight:600; margin-top:14px; }
    input[type="password"] { width:100%; padding:12px 14px; border:1px solid #ddd; border-radius:10px; margin-top:6px; }
    button { margin-top:18px; width:100%; padding:12px 14px; border:0; border-radius:12px; background:#7A1E1E; color:#fff; font-weight:700; cursor:pointer; }
    .alert { background:#fdf2f2; color:#7a1e1e; border:1px solid #f5c2c7; padding:12px; border-radius:10px; margin-bottom:12px; }
    a { color:#7A1E1E; text-decoration:none; }
  </style>
</head>
<body>
  <div class="card">
    <h2>Set a new password</h2>

    <?php if ($error): ?>
      <div class="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($valid): ?>
      <form method="post" autocomplete="new-password">
        <label for="password">New password</label>
        <input id="password" name="password" type="password" minlength="8" required>

        <label for="confirm_password">Confirm new password</label>
        <input id="confirm_password" name="confirm_password" type="password" minlength="8" required>

        <button type="submit">Update password</button>
      </form>
    <?php else: ?>
      <p><a href="forgot_password.php">Request a new reset link</a></p>
    <?php endif; ?>
  </div>
</body>
</html>
