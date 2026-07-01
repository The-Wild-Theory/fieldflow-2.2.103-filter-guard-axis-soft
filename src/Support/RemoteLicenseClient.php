<?php
namespace RoutesPro\Support;

if (!defined('ABSPATH')) exit;

class RemoteLicenseClient {
    public static function isReady(): bool {
        return self::baseUrl() !== '' && self::sharedSecret() !== '';
    }

    public static function baseUrl(): string {
        return rtrim((string) Config::get('license_remote_api_base', ''), '/');
    }

    public static function sharedSecret(): string {
        return (string) Config::get('license_remote_shared_secret', '');
    }

    public static function adminSecret(): string {
        return (string) Config::get('license_remote_admin_secret', '');
    }

    public static function timeout(): int {
        return max(5, (int) Config::get('license_remote_timeout', 15));
    }

    public static function productId(): string {
        return (string) Config::get('license_remote_product_id', 'fieldflow');
    }

    public static function siteContext(): array {
        return [
            'product_id' => self::productId(),
            'license_key' => (string) LicenseManager::get('key', ''),
            'domain' => LicenseManager::currentDomain(),
            'site_url' => home_url('/'),
            'admin_email' => get_option('admin_email'),
            'fingerprint' => LicenseManager::currentFingerprint(),
            'plugin_version' => defined('ROUTESPRO_VERSION') ? ROUTESPRO_VERSION : '',
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
        ];
    }

    public static function post(string $path, array $payload, bool $useAdminSecret = false): array {
        if (!self::isReady()) {
            return ['success' => false, 'message' => 'API remota de licenciamento incompleta.'];
        }

        $payload = array_merge([
            'product_id' => self::productId(),
        ], $payload);

        $adminSecret = trim(self::adminSecret());
        if ($useAdminSecret && $adminSecret !== '') {
            // Diferentes versões da Function usam nomes diferentes.
            // Enviamos nos formatos mais comuns e a API ignora o que não precisar.
            $payload['admin_secret'] = $adminSecret;
            $payload['license_admin_secret'] = $adminSecret;
        }

        $json = wp_json_encode($payload);
        if (!is_string($json) || $json === '') {
            return ['success' => false, 'message' => 'Falha ao serializar pedido remoto.'];
        }

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $json, self::sharedSecret());

        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept' => 'application/json',
            'X-RoutesPro-Time' => $timestamp,
            'X-RoutesPro-Signature' => $signature,
            'X-RoutesPro-Product' => self::productId(),
        ];
        if ($useAdminSecret && $adminSecret !== '') {
            $headers['X-RoutesPro-Admin-Secret'] = $adminSecret;
            $headers['X-FieldFlow-Admin-Secret'] = $adminSecret;
            $headers['X-License-Admin-Secret'] = $adminSecret;
        }

        $response = wp_remote_post(self::baseUrl() . '/' . ltrim($path, '/'), [
            'timeout' => self::timeout(),
            'headers' => $headers,
            'body' => $json,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $data = ['success' => false, 'message' => 'Resposta inválida da API.', 'raw' => $body];
        }
        $data['http_code'] = $code;
        return $data;
    }
}
