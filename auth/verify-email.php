<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/email.php';

$token = $_GET['token'] ?? '';
$status = 'invalid';

if (!empty($token)) {
    $user_id = validate_email_verification_token($token);
    if ($user_id) {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare('SELECT id, email_verified_at FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $user_id]);
        $user = $stmt->fetch();

        if ($user) {
            if ($user['email_verified_at']) {
                $status = 'already_verified';
            } else {
                $stmt = $pdo->prepare('UPDATE users SET email_verified_at = NOW(), updated_at = NOW() WHERE id = :id');
                $stmt->execute([':id' => $user_id]);
                $status = 'success';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email Verification - <?= htmlspecialchars(APP_NAME) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;min-height:100vh;display:flex;align-items:center;justify-content:center}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 16px rgba(0,0,0,.08);padding:40px;width:100%;max-width:420px;text-align:center}
.icon{font-size:48px;margin-bottom:16px}
h1{font-size:22px;color:#202124;margin-bottom:8px}
p{color:#666;font-size:15px;line-height:1.5;margin-bottom:20px}
.btn{display:inline-block;padding:12px 32px;border-radius:8px;font-size:15px;font-weight:600;text-decoration:none;transition:background .2s}
.btn-primary{background:#1a73e8;color:#fff}
.btn-primary:hover{background:#1557b0}
.success{color:#137333}
.error{color:#c5221f}
</style>
</head>
<body>
<div class="card">
    <?php if ($status === 'success'): ?>
        <div class="icon">&#9989;</div>
        <h1 class="success">Email Verified!</h1>
        <p>Your email address has been verified successfully. You can now enjoy all features of ProjectScholar.</p>
        <a href="/dashboard.php" class="btn btn-primary">Go to Dashboard</a>
    <?php elseif ($status === 'already_verified'): ?>
        <div class="icon">&#9989;</div>
        <h1>Already Verified</h1>
        <p>Your email address was already verified. No action needed.</p>
        <a href="/dashboard.php" class="btn btn-primary">Go to Dashboard</a>
    <?php else: ?>
        <div class="icon">&#10060;</div>
        <h1 class="error">Invalid or Expired Link</h1>
        <p>This verification link is invalid or has expired. Please request a new verification email from your dashboard.</p>
        <a href="/auth/login.php" class="btn btn-primary">Go to Sign In</a>
    <?php endif; ?>
</div>
</body>
</html>
