<?php
/**
 * API Bootstrap - Shared setup for all API endpoints
 * Handles CORS, JSON responses, Bearer token auth
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// CORS headers
$allowed_origins = [
    'http://localhost:5173',
    'http://localhost:3000',
    'https://plum-armadillo-323374.hostingersite.com',
    APP_URL,
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins, true)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: " . APP_URL);
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Send JSON response and exit
 */
function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Get JSON request body
 */
function get_json_body(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Get user from Bearer token
 */
function get_bearer_user(): ?array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if (empty($header) || !str_starts_with($header, 'Bearer ')) {
        return null;
    }

    $token = substr($header, 7);
    if (empty($token)) return null;

    $pdo = get_db_connection();
    $token_hash = hash('sha256', $token);
    $stmt = $pdo->prepare('
        SELECT u.id, u.email, u.first_name, u.last_name, u.auth_provider, u.avatar_url,
               u.email_verified_at, u.is_active, u.role, u.created_at
        FROM users u
        INNER JOIN sessions s ON s.user_id = u.id
        WHERE s.token = :token AND s.expires_at > NOW()
        LIMIT 1
    ');
    $stmt->execute([':token' => $token_hash]);
    return $stmt->fetch() ?: null;
}

/**
 * Require Bearer auth - returns user or sends 401
 */
function require_bearer_auth(): array {
    $user = get_bearer_user();
    if (!$user) {
        json_response(['error' => 'Unauthorized. Please sign in.'], 401);
    }
    return $user;
}

/**
 * Create session and return raw token (not hashed)
 */
function create_api_session(int $user_id): string {
    $pdo = get_db_connection();
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

    return $token;
}

/**
 * Format user data for API response (strips sensitive fields)
 */
function format_user(array $user): array {
    return [
        'id'                => (int)$user['id'],
        'email'             => $user['email'],
        'first_name'        => $user['first_name'],
        'last_name'         => $user['last_name'],
        'auth_provider'     => $user['auth_provider'],
        'avatar_url'        => $user['avatar_url'],
        'email_verified_at' => $user['email_verified_at'],
        'role'              => $user['role'] ?? 'student',
        'created_at'        => $user['created_at'],
    ];
}
