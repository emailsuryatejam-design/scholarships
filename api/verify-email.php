<?php
/**
 * Email verification endpoint
 * Called when user clicks the verification link in their email
 * Verifies the token and redirects to the React app with status
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/email.php';

$token = $_GET['token'] ?? '';
$frontend_url = 'http://localhost:5173'; // React dev server

if (empty($token)) {
    header("Location: {$frontend_url}/auth/verify-email?status=error");
    exit;
}

$user_id = validate_email_verification_token($token);
if (!$user_id) {
    header("Location: {$frontend_url}/auth/verify-email?status=error");
    exit;
}

$pdo = get_db_connection();
$stmt = $pdo->prepare('SELECT id, email_verified_at FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: {$frontend_url}/auth/verify-email?status=error");
    exit;
}

if ($user['email_verified_at']) {
    header("Location: {$frontend_url}/auth/verify-email?status=already_verified");
    exit;
}

$stmt = $pdo->prepare('UPDATE users SET email_verified_at = NOW(), updated_at = NOW() WHERE id = :id');
$stmt->execute([':id' => $user_id]);

header("Location: {$frontend_url}/auth/verify-email?status=success");
exit;
