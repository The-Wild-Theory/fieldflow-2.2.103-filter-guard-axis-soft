<?php
namespace RoutesPro\Services;

use RoutesPro\Admin\Integrations;

class IntegrationPlatform {
    public static function options(): array {
        return Integrations::get();
    }

    public static function log(string $connector, string $action, string $status, array $payload = [], string $message = ''): void {
        global $wpdb;
        $table = $wpdb->prefix . 'routespro_integration_logs';
        $wpdb->insert($table, [
            'connector' => sanitize_text_field($connector),
            'action' => sanitize_text_field($action),
            'status' => sanitize_text_field($status),
            'message' => sanitize_text_field($message),
            'payload_json' => wp_json_encode($payload),
            'created_at' => current_time('mysql'),
        ]);
    }

    public static function parse_auth_header(string $header): array {
        $out = [];
        $header = trim($header);
        if ($header === '' || strpos($header, ':') === false) {
            return $out;
        }
        [$name, $value] = explode(':', $header, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name !== '' && $value !== '') {
            $out[$name] = $value;
        }
        return $out;
    }

    public static function connector_meta(string $connector, array $opts): array {
        switch ($connector) {
            case 'powerbi':
                return ['table' => (string)($opts['powerbi_table'] ?? '')];
            case 'gcloud':
                return [
                    'project' => (string)($opts['gcloud_project'] ?? ''),
                    'dataset' => (string)($opts['gcloud_dataset'] ?? ''),
                    'table' => (string)($opts['gcloud_table'] ?? ''),
                ];
            case 'azure':
                return [
                    'workspace' => (string)($opts['azure_workspace'] ?? ''),
                    'dataset' => (string)($opts['azure_dataset'] ?? ''),
                ];
        }
        return [];
    }

    public static function build_push_envelope(string $connector, string $resource, array $exportPayload, array $opts): array {
        return [
            'ok' => true,
            'connector' => $connector,
            'resource' => $resource,
            'meta' => self::connector_meta($connector, $opts),
            'generated_at' => current_time('mysql'),
            'export' => $exportPayload,
        ];
    }
}
