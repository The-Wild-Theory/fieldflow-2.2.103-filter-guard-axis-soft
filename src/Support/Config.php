<?php
namespace RoutesPro\Support;

if (!defined('ABSPATH')) exit;

class Config {
    public const SETTINGS_OPTION = 'routespro_settings';

    public static function defaults(): array {
        return [
            'optimizer_url' => '',
            'optimizer_api_key' => '',
            'maps_provider' => 'leaflet',
            'google_maps_key' => '',
            'azure_maps_key' => '',
            'ai_provider' => 'none',
            'google_ai_key' => '',
            'azure_openai_endpoint' => '',
            'azure_openai_deployment' => '',
            'azure_openai_key' => '',
            'openai_api_key' => '',
            'openai_base_url' => '',
            'openai_model' => 'gpt-4o-mini',
            'copilot_webhook_url' => '',
            'copilot_auth_header' => '',
            'maps_test_address' => 'Praça do Comércio, Lisboa',
            'ai_test_task' => 'route_notes',
            'license_mode' => 'remote',
            'license_remote_api_base' => 'https://func-fieldflow-licensing-a7dkgyfsfmg9dvgt.westeurope-01.azurewebsites.net/api',
            'license_remote_shared_secret' => '',
            'license_remote_admin_secret' => '',
            'license_remote_product_id' => 'fieldflow',
            'license_remote_timeout' => 15,
            'license_remote_validate_interval' => 12,
            'routing_provider' => 'internal',
            'google_routes_api_key' => '',
            'google_routes_preference' => 'TRAFFIC_AWARE',
            'google_routes_route_mode' => 'fastest_tolls',
            'google_routes_vehicle_profile' => 'car_class1',
            'routing_fallback_internal' => 1,
            'routing_cache_days' => 30,
            'google_routes_test_origin' => 'Lisboa, Portugal',
            'google_routes_test_destination' => 'Porto, Portugal',
        ];
    }

    public static function secretConstants(): array {
        return [
            'google_maps_key' => 'ROUTESPRO_GOOGLE_MAPS_KEY',
            'azure_maps_key' => 'ROUTESPRO_AZURE_MAPS_KEY',
            'google_ai_key' => 'ROUTESPRO_GOOGLE_AI_KEY',
            'azure_openai_endpoint' => 'ROUTESPRO_AZURE_OPENAI_ENDPOINT',
            'azure_openai_deployment' => 'ROUTESPRO_AZURE_OPENAI_DEPLOYMENT',
            'azure_openai_key' => 'ROUTESPRO_AZURE_OPENAI_KEY',
            'openai_api_key' => 'ROUTESPRO_OPENAI_API_KEY',
            'openai_base_url' => 'ROUTESPRO_OPENAI_BASE_URL',
            'openai_model' => 'ROUTESPRO_OPENAI_MODEL',
            'copilot_webhook_url' => 'ROUTESPRO_COPILOT_WEBHOOK_URL',
            'copilot_auth_header' => 'ROUTESPRO_COPILOT_AUTH_HEADER',
            'license_remote_api_base' => 'ROUTESPRO_LICENSE_API_BASE',
            'license_remote_shared_secret' => 'ROUTESPRO_LICENSE_API_SECRET',
            'license_remote_admin_secret' => 'ROUTESPRO_LICENSE_ADMIN_SECRET',
            'license_remote_product_id' => 'ROUTESPRO_LICENSE_PRODUCT_ID',
            'license_remote_timeout' => 'ROUTESPRO_LICENSE_API_TIMEOUT',
            'license_remote_validate_interval' => 'ROUTESPRO_LICENSE_VALIDATE_INTERVAL',
            'google_routes_api_key' => 'ROUTESPRO_GOOGLE_ROUTES_API_KEY',
        ];
    }

    public static function all(): array {
        $saved = get_option(self::SETTINGS_OPTION, []);
        $opts = wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
        foreach (self::secretConstants() as $key => $constant) {
            if (defined($constant)) {
                $opts[$key] = constant($constant);
            }
        }
        return $opts;
    }

    public static function get(?string $key = null, $default = null) {
        $opts = self::all();
        if ($key === null) {
            return $opts;
        }
        return array_key_exists($key, $opts) ? $opts[$key] : $default;
    }

    public static function mergeAndSave(array $input): array {
        $merged = wp_parse_args($input, self::all());
        update_option(self::SETTINGS_OPTION, $merged);
        return $merged;
    }

    public static function providerReady(string $provider, ?array $opts = null): bool {
        $opts = $opts ?? self::all();
        switch ($provider) {
            case 'google_maps': return !empty($opts['google_maps_key']);
            case 'azure_maps': return !empty($opts['azure_maps_key']);
            case 'google_ai': return !empty($opts['google_ai_key']);
            case 'azure_ai': return !empty($opts['azure_openai_endpoint']) && !empty($opts['azure_openai_deployment']) && !empty($opts['azure_openai_key']);
            case 'openai': return !empty($opts['openai_api_key']);
            case 'copilot': return !empty($opts['copilot_webhook_url']);
            case 'license_remote': return !empty($opts['license_remote_api_base']) && !empty($opts['license_remote_shared_secret']) && !empty($opts['license_remote_product_id']);
            case 'google_routes': return !empty($opts['google_routes_api_key']);
        }
        return false;
    }

    public static function clearRoutingCache(): int {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || empty($wpdb->options)) {
            return 0;
        }
        $likeTransient = $wpdb->esc_like('_transient_ff_google_routes_') . '%';
        $likeTimeout = $wpdb->esc_like('_transient_timeout_ff_google_routes_') . '%';
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $likeTransient,
            $likeTimeout
        ));
        return is_numeric($deleted) ? (int) $deleted : 0;
    }

}
