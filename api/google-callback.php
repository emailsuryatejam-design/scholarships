<?php
/**
 * Google OAuth callback for SPA
 * Exchanges code for token, creates/finds user, creates session
 * Redirects to React app with Bearer token in URL
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/google.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/http.php';

// Inline session creator (can't use _bootstrap.php — it sends JSON headers)
function create_google_session(int $user_id): string {
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

$frontend_url = APP_URL;  // Same domain — frontend is served from the backend domain now

// Helper: HTML redirect (avoids LiteSpeed header issues)
function html_redirect(string $url): void {
    $escaped = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Content-Type: text/html; charset=UTF-8');
    echo "<!DOCTYPE html><html><head><meta http-equiv=\"refresh\" content=\"0;url={$escaped}\"></head>";
    echo "<body><p>Redirecting... <a href=\"{$escaped}\">Click here</a></p></body></html>";
    exit;
}

// Check for errors from Google
if (!empty($_GET['error'])) {
    $error = urlencode('Google sign-in was cancelled or denied.');
    html_redirect("{$frontend_url}/auth/google/callback?error={$error}");
}

// Validate state (CSRF check)
$state = $_GET['state'] ?? '';
if (!validate_oauth_state($state)) {
    $error = urlencode('Security validation failed. Please try again.');
    html_redirect("{$frontend_url}/auth/google/callback?error={$error}");
}

$code = $_GET['code'] ?? '';
if (empty($code)) {
    $error = urlencode('No authorization code received from Google.');
    html_redirect("{$frontend_url}/auth/google/callback?error={$error}");
}

try {
    // Exchange code for access token
    $token_response = http_post(GOOGLE_TOKEN_URL, [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => APP_URL . '/api/google-callback.php',
        'grant_type'    => 'authorization_code',
    ]);

    if (empty($token_response['access_token'])) {
        throw new RuntimeException('No access token received from Google.');
    }

    // Fetch user profile
    $profile = http_get(GOOGLE_USERINFO_URL . '?access_token=' . urlencode($token_response['access_token']));

    if (empty($profile['email'])) {
        throw new RuntimeException('Could not retrieve email from Google.');
    }

    $google_id    = $profile['sub'] ?? '';
    $google_email = strtolower($profile['email']);
    $first_name   = $profile['given_name'] ?? '';
    $last_name    = $profile['family_name'] ?? '';
    $avatar       = $profile['picture'] ?? '';
    $verified     = $profile['email_verified'] ?? false;

    $pdo = get_db_connection();

    // Find by Google ID
    $stmt = $pdo->prepare('SELECT * FROM users WHERE auth_provider = :p AND auth_provider_id = :gid LIMIT 1');
    $stmt->execute([':p' => 'google', ':gid' => $google_id]);
    $user = $stmt->fetch();

    if ($user) {
        // Returning Google user - update profile
        $stmt = $pdo->prepare('
            UPDATE users SET first_name = :fn, last_name = :ln, avatar_url = :av, last_login_at = NOW(), updated_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute([':fn' => $first_name, ':ln' => $last_name, ':av' => $avatar, ':id' => $user['id']]);
    } else {
        // Check if email exists with different provider
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $google_email]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($verified) {
                // Auto-link: merge Google into existing email account
                $stmt = $pdo->prepare('
                    UPDATE users SET auth_provider = :p, auth_provider_id = :gid, avatar_url = :av,
                    email_verified_at = COALESCE(email_verified_at, NOW()), last_login_at = NOW(), updated_at = NOW()
                    WHERE id = :id
                ');
                $stmt->execute([':p' => 'google', ':gid' => $google_id, ':av' => $avatar, ':id' => $existing['id']]);
                $user = $existing;
            } else {
                $error = urlencode('An account with this email already exists. Please sign in with your password.');
                html_redirect("{$frontend_url}/auth/google/callback?error={$error}");
            }
        } else {
            // New user
            $stmt = $pdo->prepare('
                INSERT INTO users (email, first_name, last_name, auth_provider, auth_provider_id, avatar_url,
                email_verified_at, role, is_active, created_at, updated_at)
                VALUES (:email, :fn, :ln, :p, :gid, :av, :ev, :role, 1, NOW(), NOW())
            ');
            $stmt->execute([
                ':email' => $google_email,
                ':fn'    => $first_name,
                ':ln'    => $last_name,
                ':p'     => 'google',
                ':gid'   => $google_id,
                ':av'    => $avatar,
                ':ev'    => $verified ? date('Y-m-d H:i:s') : null,
                ':role'  => 'student',
            ]);
            $user = ['id' => $pdo->lastInsertId()];
        }
    }

    // Check active
    if (isset($user['is_active']) && !$user['is_active']) {
        $error = urlencode('Your account has been deactivated. Contact support.');
        html_redirect("{$frontend_url}/auth/google/callback?error={$error}");
    }

    // Create session and get raw token
    $session_token = create_google_session((int)$user['id']);

    // Redirect to React app with token
    html_redirect("{$frontend_url}/auth/google/callback?token=" . urlencode($session_token));

} catch (Exception $e) {
    $error = urlencode('Google sign-in failed: ' . $e->getMessage());
    html_redirect("{$frontend_url}/auth/google/callback?error={$error}");
}
