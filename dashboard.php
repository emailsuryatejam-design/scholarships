<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/email.php';

$user = require_auth();
$show_verify_banner = ($user['auth_provider'] === 'email' && !$user['email_verified_at']);

// Handle resend verification
if (isset($_GET['resend_verify']) && $show_verify_banner) {
    $token = generate_email_verification_token((int)$user['id']);
    send_verification_email($user['email'], $user['first_name'], $token);
    header('Location: /dashboard.php?verify=resent');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard - <?= htmlspecialchars(APP_NAME) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;min-height:100vh}
.navbar{background:#1a73e8;color:#fff;padding:16px 24px;display:flex;align-items:center;justify-content:space-between}
.navbar h2{font-size:18px}
.navbar a{color:#fff;text-decoration:none;background:rgba(255,255,255,.15);padding:8px 16px;border-radius:6px;font-size:14px}
.navbar a:hover{background:rgba(255,255,255,.25)}
.container{max-width:800px;margin:40px auto;padding:0 20px}
.profile-card{background:#fff;border-radius:12px;box-shadow:0 2px 16px rgba(0,0,0,.08);padding:32px;display:flex;gap:24px;align-items:center}
.avatar{width:80px;height:80px;border-radius:50%;background:#e8eaed;display:flex;align-items:center;justify-content:center;font-size:32px;color:#666;overflow:hidden}
.avatar img{width:100%;height:100%;object-fit:cover}
.info h1{font-size:22px;color:#202124;margin-bottom:4px}
.info p{color:#666;font-size:14px;margin-top:4px}
.badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;margin-top:8px}
.badge-google{background:#e8f0fe;color:#1a73e8}
.badge-email{background:#fce8e6;color:#c5221f}
.badge-verified{background:#e6f4ea;color:#137333}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-top:24px}
.stat-card{background:#fff;border-radius:12px;box-shadow:0 2px 16px rgba(0,0,0,.08);padding:24px;text-align:center}
.stat-card h3{font-size:28px;color:#1a73e8}
.stat-card p{color:#666;font-size:14px;margin-top:4px}
.verify-banner{background:#fff3cd;border:1px solid #ffc107;border-radius:12px;padding:16px 24px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.verify-banner p{color:#856404;font-size:14px;margin:0}
.verify-banner a{color:#1a73e8;font-weight:600;font-size:14px;text-decoration:none}
.verify-banner a:hover{text-decoration:underline}
.verify-success{background:#d4edda;border-color:#28a745;color:#155724}
</style>
</head>
<body>
<div class="navbar">
    <h2><?= htmlspecialchars(APP_NAME) ?></h2>
    <a href="/auth/logout.php">Sign Out</a>
</div>
<div class="container">
    <?php if (isset($_GET['verify']) && $_GET['verify'] === 'resent'): ?>
        <div class="verify-banner verify-success"><p>Verification email sent! Check your inbox.</p></div>
    <?php elseif ($show_verify_banner): ?>
        <div class="verify-banner">
            <p>Please verify your email address to access all features.</p>
            <a href="/dashboard.php?resend_verify=1">Resend verification email</a>
        </div>
    <?php endif; ?>

    <div class="profile-card">
        <div class="avatar">
            <?php if ($user['avatar_url']): ?>
                <img src="<?= htmlspecialchars($user['avatar_url']) ?>" alt="Avatar" referrerpolicy="no-referrer">
            <?php else: ?>
                <?= strtoupper(substr($user['first_name'] ?: 'U', 0, 1)) ?>
            <?php endif; ?>
        </div>
        <div class="info">
            <h1><?= htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name'])) ?: 'User' ?></h1>
            <p><?= htmlspecialchars($user['email']) ?></p>
            <span class="badge badge-<?= $user['auth_provider'] === 'google' ? 'google' : 'email' ?>">
                <?= ucfirst(htmlspecialchars($user['auth_provider'])) ?> Account
            </span>
            <?php if ($user['email_verified_at']): ?>
                <span class="badge badge-verified">Verified</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="stats">
        <div class="stat-card">
            <h3>0</h3>
            <p>Scholarship Matches</p>
        </div>
        <div class="stat-card">
            <h3>0</h3>
            <p>Saved Scholarships</p>
        </div>
        <div class="stat-card">
            <h3>0</h3>
            <p>Applications</p>
        </div>
    </div>
</div>
</body>
</html>
