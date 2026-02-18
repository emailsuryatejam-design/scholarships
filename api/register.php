<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/email.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = get_json_body();
$first_name = trim($body['first_name'] ?? '');
$last_name  = trim($body['last_name'] ?? '');
$email      = trim(strtolower($body['email'] ?? ''));
$password   = $body['password'] ?? '';

// Validate
if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
    json_response(['error' => 'All fields are required.'], 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'Please enter a valid email address.'], 422);
}

if (strlen($password) < 8) {
    json_response(['error' => 'Password must be at least 8 characters.'], 422);
}

$pdo = get_db_connection();

// Check for existing email
$stmt = $pdo->prepare('SELECT id, auth_provider FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $email]);
$existing = $stmt->fetch();

if ($existing) {
    if ($existing['auth_provider'] === 'google') {
        json_response(['error' => 'This email is registered with Google. Please sign in with Google.'], 409);
    }
    json_response(['error' => 'An account with this email already exists.'], 409);
}

// Create user
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$stmt = $pdo->prepare('
    INSERT INTO users (email, password_hash, first_name, last_name, role, auth_provider, is_active, created_at, updated_at)
    VALUES (:email, :hash, :fn, :ln, :role, :ap, 1, NOW(), NOW())
');
$stmt->execute([
    ':email' => $email,
    ':hash'  => $hash,
    ':fn'    => $first_name,
    ':ln'    => $last_name,
    ':role'  => 'student',
    ':ap'    => 'email',
]);

$user_id = (int)$pdo->lastInsertId();

// Send verification email
$verify_token = generate_email_verification_token($user_id);
send_verification_email($email, $first_name, $verify_token);

// Create session
$token = create_api_session($user_id);

// Fetch full user
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch();

json_response([
    'token' => $token,
    'user'  => format_user($user),
    'message' => 'Account created! Please check your email to verify your address.',
], 201);
