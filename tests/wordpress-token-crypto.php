<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);

function add_action(...$args): void {}
function add_filter(...$args): void {}
function wp_salt(string $scheme): string { return 'test-wordpress-auth-salt-' . $scheme; }
function wp_json_encode($value): string { return (string) json_encode($value, JSON_THROW_ON_ERROR); }
function get_option(string $name, $default = false) { return $default; }
function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', $value)); }
function esc_url_raw(string $value): string { return filter_var($value, FILTER_VALIDATE_URL) ? $value : ''; }
function wp_parse_url(string $value, int $component = -1) { return parse_url($value, $component); }
function add_settings_error(...$args): void {}

require __DIR__ . '/../wordpress/ccf-google-ads-site-connector.php';

$reflection = new ReflectionClass(CCF_Google_Ads_Site_Connector::class);
$encrypt = $reflection->getMethod('encrypt_token');
$decrypt = $reflection->getMethod('decrypt_token');

$token = str_repeat('test-token-', 5);
$encrypted = $encrypt->invoke(null, $token);

if (!is_string($encrypted) || $encrypted === '' || str_contains($encrypted, $token)) {
    throw new RuntimeException('Token encryption failed.');
}

$decrypted = $decrypt->invoke(null, $encrypted);
if ($decrypted !== $token) {
    throw new RuntimeException('Token decryption failed.');
}

$firstSanitized = CCF_Google_Ads_Site_Connector::sanitize_settings([
    'endpoint' => 'https://control.example.test',
    'client_id' => 'example-customer',
    'site_id' => 'example-site',
    'environment' => 'production',
    'site_token' => $token,
]);
$secondSanitized = CCF_Google_Ads_Site_Connector::sanitize_settings($firstSanitized);

if (($secondSanitized['encrypted_token'] ?? '') !== ($firstSanitized['encrypted_token'] ?? '')) {
    throw new RuntimeException('A repeated WordPress sanitize pass discarded the encrypted token.');
}

if ($decrypt->invoke(null, $secondSanitized['encrypted_token']) !== $token) {
    throw new RuntimeException('Sanitized token decryption failed.');
}

echo "WordPress token encryption round-trip passed.\n";
