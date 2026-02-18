<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

function create_session(int $user_id): string {
    $pdo   = get_db_connection();
    $token = bin2hex(random_bytes(64));
    $expires_at = date('Y-m-d H:i:s', time() + SESSION_LIFETIME_SECONDS);

    $stmt = $pdo->prepare('
        INSERT INTO sessions (user_id, token, ip_address, user_agent, expires_at, created_at)
        VALUES (:user_id, :token, :ip, :ua, :expires, NOW())
    ');
    $stmt->execute([
        ':user_id' => $user_id,
        ':token'   => hash('sha256', $token),
        ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
        ':ua'      => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ':expires' => $expires_at,
    ]);

    setcookie(SESSION_COOKIE_NAME, $token, [
        'expires'  => time() + SESSION_LIFETIME_SECONDS,
        'path'     => '/',
        'secure'   => true,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
    return $token;
}

function get_authenticated_user(): ?array {
    if (empty($_COOKIE[SESSION_COOKIE_NAME])) return null;
    $pdo = get_db_connection();
    $token_hash = hash('sha256', $_COOKIE[SESSION_COOKIE_NAME]);

    $stmt = $pdo->prepare('
        SELECT u.* FROM users u
        INNER JOIN sessions s ON s.user_id = u.id
        WHERE s.token = :token AND s.expires_at > NOW()
        LIMIT 1
    ');
    $stmt->execute([':token' => $token_hash]);
    return $stmt->fetch() ?: null;
}

function require_auth(): array {
    $user = get_authenticated_user();
    if (!$user) {
        header('Location: /auth/login.php');
        exit;
    }
    return $user;
}

function destroy_session(): void {
    if (!empty($_COOKIE[SESSION_COOKIE_NAME])) {
        $pdo = get_db_connection();
        $token_hash = hash('sha256', $_COOKIE[SESSION_COOKIE_NAME]);
        $stmt = $pdo->prepare('DELETE FROM sessions WHERE token = :token');
        $stmt->execute([':token' => $token_hash]);
    }
    setcookie(SESSION_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
}
