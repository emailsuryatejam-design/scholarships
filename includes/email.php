<?php
require_once __DIR__ . '/../config/app.php';

function generate_email_verification_token(int $user_id): string {
    $payload = $user_id . '|' . (time() + 86400); // 24 hour expiry
    $signature = hash_hmac('sha256', $payload, EMAIL_VERIFY_SECRET);
    return base64_encode($payload . '|' . $signature);
}

function validate_email_verification_token(string $token): ?int {
    $decoded = base64_decode($token, true);
    if (!$decoded) return null;

    $parts = explode('|', $decoded);
    if (count($parts) !== 3) return null;

    [$user_id, $expires, $signature] = $parts;
    $expected = hash_hmac('sha256', $user_id . '|' . $expires, EMAIL_VERIFY_SECRET);

    if (!hash_equals($expected, $signature)) return null;
    if (time() > (int)$expires) return null;

    return (int)$user_id;
}

function send_verification_email(string $to_email, string $first_name, string $token): bool {
    $verify_url = APP_URL . '/auth/verify-email.php?token=' . urlencode($token);
    $subject = 'Verify your email - ' . APP_NAME;

    $body = "Hi {$first_name},\n\n";
    $body .= "Welcome to " . APP_NAME . "! Please verify your email address by clicking the link below:\n\n";
    $body .= $verify_url . "\n\n";
    $body .= "This link will expire in 24 hours.\n\n";
    $body .= "If you didn't create an account, you can safely ignore this email.\n\n";
    $body .= "Best regards,\n";
    $body .= APP_NAME . " Team";

    $headers = [
        'From: noreply@' . parse_url(APP_URL, PHP_URL_HOST),
        'Reply-To: noreply@' . parse_url(APP_URL, PHP_URL_HOST),
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion(),
    ];

    return mail($to_email, $subject, $body, implode("\r\n", $headers));
}
