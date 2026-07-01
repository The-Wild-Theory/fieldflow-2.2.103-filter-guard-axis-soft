<?php
namespace RoutesPro\Support;

if (!defined('ABSPATH')) exit;

class Request {
    public static function postString(string $key, string $default = ''): string {
        return sanitize_text_field((string)($_POST[$key] ?? $default));
    }

    public static function postKey(string $key, string $default = ''): string {
        return sanitize_key((string)($_POST[$key] ?? $default));
    }

    public static function postUrl(string $key, string $default = ''): string {
        return esc_url_raw((string)($_POST[$key] ?? $default));
    }

    public static function postInt(string $key, int $default = 0): int {
        return absint($_POST[$key] ?? $default);
    }

    public static function verifyNonce(string $field, string $action): bool {
        return !empty($_POST[$field]) && wp_verify_nonce((string)$_POST[$field], $action);
    }

    public static function userCan(string $capability): bool {
        return current_user_can($capability);
    }
}
