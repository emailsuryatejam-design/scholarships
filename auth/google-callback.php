<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/google.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/http.php';
require_once __DIR__ . '/../includes/auth.php';

// 1. Validate state (CSRF)
$state = $_GET['state'] ?? '';
if (!validate_oauth_state($state)) {
    header('Location: /auth/login.php?error=invalid_state');
    exit;
}

// 2. Check for errors from Google
if (!empty($_GET['error'])) {
    error_log('Google OAuth error: ' . $_GET['error']);
    header('Location: /auth/login.php?error=google_failed');
    exit;
}

$code = $_GET['code'] ?? '';
if (empty($code)) {
    header('Location: /auth/login.php?error=google_failed');
    exit;
}

// 3. Exchange code for access token
try {
    $token_data = http_post(GOOGLE_TOKEN_URL, [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]);
} catch (RuntimeException $e) {
    error_log('Google token exchange failed: ' . $e->getMessage());
    header('Location: /auth/login.php?error=google_failed');
    exit;
}

if (empty($token_data['access_token'])) {
    error_log('No access_token: ' . json_encode($token_data));
    header('Location: /auth/login.php?error=google_failed');
    exit;
}

// 4. Fetch user profile
try {
    $guser = http_get(GOOGLE_USERINFO_URL, [
        'Authorization: Bearer ' . $token_data['access_token'],
    ]);
} catch (RuntimeException $e) {
    error_log('Google userinfo failed: ' . $e->getMessage());
    header('Location: /auth/login.php?error=google_failed');
    exit;
}

$google_id       = $guser['sub'] ?? null;
$google_email    = $guser['email'] ?? null;
$email_verified  = $guser['email_verified'] ?? false;

if (!$google_id || !$google_email) {
    header('Location: /auth/login.php?error=google_failed');
    exit;
}

// 5. Find or create user
$pdo = get_db_connection();

try {
    $pdo->beginTransaction();

    // Check by Google ID first
    $stmt = $pdo->prepare('SELECT * FROM users WHERE auth_provider = :p AND auth_provider_id = :pid LIMIT 1');
    $stmt->execute([':p' => 'google', ':pid' => $google_id]);
    $user = $stmt->fetch();

    if (!$user) {
        // Check by email
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $google_email]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($existing['auth_provider'] === 'email' && !empty($existing['password_hash']) && $email_verified) {
                // Auto-link: email user + verified Google email
                $stmt = $pdo->prepare('
                    UPDATE users SET auth_provider = :p, auth_provider_id = :pid,
                    email_verified_at = COALESCE(email_verified_at, NOW()),
                    avatar_url = COALESCE(avatar_url, :avatar), last_login_at = NOW()
                    WHERE id = :id
                ');
                $stmt->execute([
                    ':p'      => 'google',
                    ':pid'    => $google_id,
                    ':avatar' => $guser['picture'] ?? null,
                    ':id'     => $existing['id'],
                ]);
                $user = $existing;
            } else {
                $pdo->rollBack();
                header('Location: /auth/login.php?error=email_exists');
                exit;
            }
        } else {
            // New user
            $stmt = $pdo->prepare('
                INSERT INTO users (email, first_name, last_name, avatar_url,
                    auth_provider, auth_provider_id, email_verified_at,
                    is_active, created_at, updated_at)
                VALUES (:email, :first, :last, :avatar,
                    :p, :pid, :verified, 1, NOW(), NOW())
            ');
            $stmt->execute([
                ':email'    => $google_email,
                ':first'    => $guser['given_name'] ?? '',
                ':last'     => $guser['family_name'] ?? '',
                ':avatar'   => $guser['picture'] ?? null,
                ':p'        => 'google',
                ':pid'      => $google_id,
                ':verified' => $email_verified ? date('Y-m-d H:i:s') : null,
            ]);
            $user = ['id' => (int)$pdo->lastInsertId()];
        }
    } else {
        // Returning Google user — refresh profile
        $stmt = $pdo->prepare('
            UPDATE users SET last_login_at = NOW(), avatar_url = :avatar,
            first_name = COALESCE(NULLIF(:first, ""), first_name),
            last_name = COALESCE(NULLIF(:last, ""), last_name)
            WHERE id = :id
        ');
        $stmt->execute([
            ':avatar' => $guser['picture'] ?? null,
            ':first'  => $guser['given_name'] ?? '',
            ':last'   => $guser['family_name'] ?? '',
            ':id'     => $user['id'],
        ]);
    }

    if (isset($user['is_active']) && !$user['is_active']) {
        $pdo->rollBack();
        header('Location: /auth/login.php?error=account_inactive');
        exit;
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    error_log('Google OAuth DB error: ' . $e->getMessage());
    header('Location: /auth/login.php?error=google_failed');
    exit;
}

// 6. Create session & redirect
create_session((int)$user['id']);
header('Location: /dashboard.php');
exit;
