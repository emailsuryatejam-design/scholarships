<?php
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = get_json_body();
$email    = trim(strtolower($body['email'] ?? ''));
$password = $body['password'] ?? '';

if (empty($email) || empty($password)) {
    json_response(['error' => 'Please enter both email and password.'], 422);
}

$pdo = get_db_connection();
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email AND auth_provider = :p LIMIT 1');
$stmt->execute([':email' => $email, ':p' => 'email']);
$user = $stmt->fetch();

if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
    json_response(['error' => 'Invalid email or password.'], 401);
}

if (!$user['is_active']) {
    json_response(['error' => 'Your account has been deactivated. Contact support.'], 403);
}

// Update last login
$stmt = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
$stmt->execute([':id' => $user['id']]);

// Create session
$token = create_api_session((int)$user['id']);

json_response([
    'token' => $token,
    'user'  => format_user($user),
]);
