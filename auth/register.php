<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email.php';

$user = get_authenticated_user();
if ($user) { header('Location: /dashboard.php'); exit; }

$errors = [];
$old = ['first_name' => '', 'last_name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $email      = trim(strtolower($_POST['email'] ?? ''));
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['password_confirm'] ?? '';

    $old = compact('first_name', 'last_name', 'email');

    if (strlen($first_name) < 1) $errors[] = 'First name is required.';
    if (strlen($last_name) < 1) $errors[] = 'Last name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with this email already exists. <a href="/auth/login.php">Sign in instead</a>.';
        }
    }

    if (empty($errors)) {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare('
            INSERT INTO users (email, password_hash, first_name, last_name, auth_provider, is_active, created_at, updated_at)
            VALUES (:email, :pw, :first, :last, :provider, 1, NOW(), NOW())
        ');
        $stmt->execute([
            ':email'    => $email,
            ':pw'       => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            ':first'    => $first_name,
            ':last'     => $last_name,
            ':provider' => 'email',
        ]);
        $user_id = (int)$pdo->lastInsertId();

        $token = generate_email_verification_token($user_id);
        send_verification_email($email, $first_name, $token);

        create_session($user_id);
        header('Location: /dashboard.php?verify=pending');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign Up - <?= htmlspecialchars(APP_NAME) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 16px rgba(0,0,0,.08);padding:40px;width:100%;max-width:420px}
.logo{text-align:center;margin-bottom:24px}
.logo h1{font-size:24px;color:#1a73e8}
.logo p{color:#666;font-size:14px;margin-top:4px}
.error-list{background:#fce8e6;color:#c5221f;padding:12px 16px;border-radius:8px;font-size:14px;margin-bottom:20px}
.error-list li{margin-left:16px}
.error-list a{color:#1a73e8}
.form-row{display:flex;gap:12px}
.form-group{margin-bottom:16px;flex:1}
.form-group label{display:block;font-size:13px;color:#555;margin-bottom:4px;font-weight:500}
.form-group input{width:100%;padding:12px 16px;border:1px solid #ddd;border-radius:8px;font-size:15px;transition:border-color .2s}
.form-group input:focus{outline:none;border-color:#1a73e8}
.btn{width:100%;padding:12px;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;transition:background .2s}
.btn-primary{background:#1a73e8;color:#fff}
.btn-primary:hover{background:#1557b0}
.btn-google{display:flex;align-items:center;justify-content:center;gap:10px;background:#fff;color:#333;border:1px solid #ddd;margin-bottom:8px;text-decoration:none;padding:12px;border-radius:8px;font-size:15px;font-weight:600}
.btn-google:hover{background:#f7f8f8;border-color:#bbb}
.btn-google svg{width:20px;height:20px}
.divider{display:flex;align-items:center;margin:20px 0;color:#999;font-size:13px}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:#ddd}
.divider span{padding:0 12px}
.footer{text-align:center;margin-top:20px;font-size:13px;color:#888}
.footer a{color:#1a73e8;text-decoration:none}
</style>
</head>
<body>
<div class="card">
    <div class="logo">
        <h1>ProjectScholar</h1>
        <p>Create your account</p>
    </div>

    <?php if ($errors): ?>
        <div class="error-list"><ul>
            <?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>

    <a href="/auth/google-login.php" class="btn btn-google">
        <svg viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
        Sign up with Google
    </a>

    <div class="divider"><span>or sign up with email</span></div>

    <form method="POST">
        <div class="form-row">
            <div class="form-group">
                <label>First Name</label>
                <input type="text" name="first_name" required value="<?= htmlspecialchars($old['first_name']) ?>">
            </div>
            <div class="form-group">
                <label>Last Name</label>
                <input type="text" name="last_name" required value="<?= htmlspecialchars($old['last_name']) ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" required value="<?= htmlspecialchars($old['email']) ?>">
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required minlength="8" placeholder="At least 8 characters">
        </div>
        <div class="form-group">
            <label>Confirm Password</label>
            <input type="password" name="password_confirm" required minlength="8">
        </div>
        <button type="submit" class="btn btn-primary">Create Account</button>
    </form>

    <div class="footer">
        <p>Already have an account? <a href="/auth/login.php">Sign in</a></p>
    </div>
</div>
</body>
</html>
