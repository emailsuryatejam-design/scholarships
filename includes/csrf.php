<?php
function generate_oauth_state(): string {
    $state = bin2hex(random_bytes(32));
    setcookie(CSRF_STATE_COOKIE_NAME, $state, [
        'expires'  => time() + CSRF_STATE_LIFETIME_SECONDS,
        'path'     => '/auth/',
        'secure'   => true,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
    return $state;
}

function validate_oauth_state(string $received_state): bool {
    if (empty($_COOKIE[CSRF_STATE_COOKIE_NAME])) return false;
    $expected = $_COOKIE[CSRF_STATE_COOKIE_NAME];
    setcookie(CSRF_STATE_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/auth/',
        'secure'   => true,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
    return hash_equals($expected, $received_state);
}
