<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

$user = get_authenticated_user();
if ($user) { header('Location: /dashboard.php'); exit; }

$error = $_GET['error'] ?? null;
$errors = [
    'google_failed'       => 'Google sign-in failed. Please try again.',
    'invalid_state'       => 'Security validation failed. Please try again.',
    'email_exists'        => 'An account with this email already exists. Try signing in with your password.',
    'account_inactive'    => 'Your account has been deactivated. Contact support.',
    'invalid_credentials' => 'Invalid email or password. Please try again.',
    'missing_fields'      => 'Please enter both email and password.',
];
$msg = $errors[$error] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In - <?= htmlspecialchars(APP_NAME) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;min-height:100vh;display:flex;align-items:center;justify-content:center}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 16px rgba(0,0,0,.08);padding:40px;width:100%;max-width:420px}
.logo{text-align:center;margin-bottom:24px}
.logo h1{font-size:24px;color:#1a73e8}
.logo p{color:#666;font-size:14px;margin-top:4px}
.error{background:#fce8e6;color:#c5221f;padding:12px 16px;border-radius:8px;font-size:14px;margin-bottom:20px}
.divider{display:flex;align-items:center;margin:24px 0;color:#999;font-size:13px}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:#ddd}
.divider span{padding:0 12px}
.form-group{margin-bottom:16px}
.form-group input{width:100%;padding:12px 16px;border:1px solid #ddd;border-radius:8px;font-size:15px;transition:border-color .2s}
.form-group input:focus{outline:none;border-color:#1a73e8}
.btn{width:100%;padding:12px;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;transition:background .2s}
.btn-primary{background:#1a73e8;color:#fff}
.btn-primary:hover{background:#1557b0}
.btn-google{display:flex;align-items:center;justify-content:center;gap:10px;background:#fff;color:#333;border:1px solid #ddd;margin-top:8px}
.btn-google:hover{background:#f7f8f8;border-color:#bbb}
.btn-google svg{width:20px;height:20px}
.footer{text-align:center;margin-top:20px;font-size:13px;color:#888}
.footer a{color:#1a73e8;text-decoration:none}
</style>
</head>
<body>
<div class="card">
    <div class="logo">
        <h1>ProjectScholar</h1>
        <p>Scholarship Matching for African Students</p>
    </div>

    <?php if ($msg): ?>
        <div class="error"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <a href="/auth/google-login.php" class="btn btn-google">
        <svg viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
        Sign in with Google
    </a>

    <div class="divider"><span>or sign in with email</span></div>

    <form method="POST" action="/auth/login-email.php">
        <div class="form-group">
            <input type="email" name="email" required placeholder="Email address">
        </div>
        <div class="form-group">
            <input type="password" name="password" required placeholder="Password">
        </div>
        <button type="submit" class="btn btn-primary">Sign In</button>
    </form>

    <div class="footer">
        <p>Don't have an account? <a href="/auth/register.php">Sign up</a></p>
    </div>
</div>
</body>
</html>
