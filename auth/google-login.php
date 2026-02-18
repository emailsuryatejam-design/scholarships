<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/google.php';
require_once __DIR__ . '/../includes/csrf.php';

$state = generate_oauth_state();

$params = [
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => GOOGLE_SCOPES,
    'state'         => $state,
    'access_type'   => 'online',
    'prompt'        => 'select_account',
];

header('Location: ' . GOOGLE_AUTH_URL . '?' . http_build_query($params));
exit;
