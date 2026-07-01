<?php
namespace RoutesPro\Repositories;

if (!defined('ABSPATH')) exit;

class SchemaRepository {
    public static function requiredTables(): array {
        return [
            'routespro_clients',
            'routespro_projects',
            'routespro_routes',
            'routespro_route_stops',
            'routespro_assignments',
            'routespro_categories',
            'routespro_locations',
            'routespro_campaign_locations',
            'routespro_forms',
            'routespro_form_submissions',
            'routespro_form_bindings',
            'routespro_email_logs',
            'routespro_system_logs',
        ];
    }

    public static function existingTables(): array {
        global $wpdb;
        $rows = $wpdb->get_col('SHOW TABLES');
        return is_array($rows) ? array_values($rows) : [];
    }

    public static function missingTables(): array {
        global $wpdb;
        $existing = array_flip(self::existingTables());
        $missing = [];
        foreach (self::requiredTables() as $table) {
            $full = $wpdb->prefix . $table;
            if (!isset($existing[$full])) {
                $missing[] = $full;
            }
        }
        return $missing;
    }

    public static function tableRowCount(string $suffix): int {
        global $wpdb;
        $table = $wpdb->prefix . $suffix;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) return 0;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }
}


