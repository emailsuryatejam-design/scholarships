<?php
/**
 * Google OAuth callback for SPA
 * Exchanges code for token, creates/finds user, creates session
 * Redirects to React app with Bearer token in URL
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/google.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/http.php';
require_once __DIR__ . '/../includes/auth.php';

$frontend_url = 'http://localhost:5173';

// Check for errors from Google
if (!empty($_GET['error'])) {
    $error = urlencode('Google sign-in was cancelled or denied.');
    header("Location: {$frontend_url}/auth/google/callback?error={$error}");
    exit;
}

// Validate state (CSRF check)
$state = $_GET['state'] ?? '';
if (!validate_oauth_state($state)) {
    $error = urlencode('Security validation failed. Please try again.');
    header("Location: {$frontend_url}/auth/google/callback?error={$error}");
    exit;
}

$code = $_GET['code'] ?? '';
if (empty($code)) {
    $error = urlencode('No authorization code received from Google.');
    header("Location: {$frontend_url}/auth/google/callback?error={$error}");
    exit;
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
                header("Location: {$frontend_url}/auth/google/callback?error={$error}");
                exit;
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
        header("Location: {$frontend_url}/auth/google/callback?error={$error}");
        exit;
    }

    // Create session and get raw token
    $session_token = create_api_session((int)$user['id']);

    // Redirect to React app with token
    header("Location: {$frontend_url}/auth/google/callback?token=" . urlencode($session_token));
    exit;

} catch (Exception $e) {
    $error = urlencode('Google sign-in failed: ' . $e->getMessage());
    header("Location: {$frontend_url}/auth/google/callback?error={$error}");
    exit;
}
