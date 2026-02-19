<?php
/**
 * CSRF protection for OAuth flow using HMAC-signed state
 * No cookies needed — the state parameter itself is self-verifying
 */

function generate_oauth_state(): string {
    $nonce = bin2hex(random_bytes(16));
    $timestamp = time();
    $payload = "{$nonce}.{$timestamp}";
    $signature = hash_hmac('sha256', $payload, EMAIL_VERIFY_SECRET);
    return "{$payload}.{$signature}";
}

function validate_oauth_state(string $received_state): bool {
    $parts = explode('.', $received_state);
    if (count($parts) !== 3) return false;

    [$nonce, $timestamp, $signature] = $parts;

    // Check timestamp (valid for 10 minutes)
    if (abs(time() - (int)$timestamp) > CSRF_STATE_LIFETIME_SECONDS) return false;

    // Verify signature
    $payload = "{$nonce}.{$timestamp}";
    $expected_signature = hash_hmac('sha256', $payload, EMAIL_VERIFY_SECRET);
    return hash_equals($expected_signature, $signature);
}
