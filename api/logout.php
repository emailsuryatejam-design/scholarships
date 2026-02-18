<?php
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!empty($header) && str_starts_with($header, 'Bearer ')) {
    $token = substr($header, 7);
    if (!empty($token)) {
        $pdo = get_db_connection();
        $token_hash = hash('sha256', $token);
        $stmt = $pdo->prepare('DELETE FROM sessions WHERE token = :token');
        $stmt->execute([':token' => $token_hash]);
    }
}

json_response(['message' => 'Signed out successfully.']);
