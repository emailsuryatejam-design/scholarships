<?php
/**
 * Google OAuth initialization for SPA
 * Sets CSRF cookie then redirects to Google via HTML meta refresh
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
$escaped  = htmlspecialchars($auth_url, ENT_QUOTES, 'UTF-8');

// Use HTML redirect instead of header() to avoid LiteSpeed issues
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Content-Type: text/html; charset=UTF-8');
echo "<!DOCTYPE html><html><head><meta http-equiv=\"refresh\" content=\"0;url={$escaped}\"><title>Redirecting...</title></head><body><p>Redirecting to Google... <a href=\"{$escaped}\">Click here</a> if not redirected.</p></body></html>";
exit;
