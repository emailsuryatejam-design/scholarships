<?php
/**
 * Google OAuth initialization for SPA
 * Returns the Google OAuth URL or redirects to it
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/google.php';
require_once __DIR__ . '/../includes/csrf.php';

// Generate state for CSRF protection
$state = generate_oauth_state();

// Build Google OAuth URL
$params = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => APP_URL . '/api/google-callback.php',
    'response_type' => 'code',
    'scope'         => GOOGLE_SCOPES,
    'state'         => $state,
    'access_type'   => 'offline',
    'prompt'        => 'select_account',
]);

$auth_url = GOOGLE_AUTH_URL . '?' . $params;

// Redirect to Google
header("Location: $auth_url");
exit;
