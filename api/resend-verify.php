<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/email.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = require_bearer_auth();

if ($user['auth_provider'] !== 'email') {
    json_response(['error' => 'Email verification is not required for Google accounts.'], 400);
}

if ($user['email_verified_at']) {
    json_response(['error' => 'Your email is already verified.'], 400);
}

$token = generate_email_verification_token((int)$user['id']);
send_verification_email($user['email'], $user['first_name'], $token);

json_response(['message' => 'Verification email sent! Check your inbox.']);
