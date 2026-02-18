<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /auth/login.php');
    exit;
}

$email    = trim(strtolower($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';

if (empty($email) || empty($password)) {
    header('Location: /auth/login.php?error=missing_fields');
    exit;
}

$pdo = get_db_connection();
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email AND auth_provider = :p LIMIT 1');
$stmt->execute([':email' => $email, ':p' => 'email']);
$user = $stmt->fetch();

if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
    header('Location: /auth/login.php?error=invalid_credentials');
    exit;
}

if (!$user['is_active']) {
    header('Location: /auth/login.php?error=account_inactive');
    exit;
}

// Update last login
$stmt = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
$stmt->execute([':id' => $user['id']]);

create_session((int)$user['id']);
header('Location: /dashboard.php');
exit;
