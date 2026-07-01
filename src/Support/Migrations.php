<?php
namespace RoutesPro\Support;

use wpdb;

if (!defined('ABSPATH')) exit;

class Migrations {
    public static function run(wpdb $wpdb, string $prefix): void {
        $applied = (string) get_option('routespro_schema_version', '0.0.0');
        $steps = [
            '1.3.0' => [self::class, 'migrate130'],
            '1.4.0' => [self::class, 'migrate140'],
        ];

        foreach ($steps as $version => $callback) {
            if (version_compare($applied, $version, '>=')) {
                continue;
            }
            call_user_func($callback, $wpdb, $prefix);
            update_option('routespro_schema_version', $version);
            $applied = $version;
            if (class_exists('\\RoutesPro\\Support\\Logger')) {
                Logger::info('Migração versionada aplicada.', ['context_key' => 'migration'], ['schema_version' => $version]);
            }
        }
    }

    private static function migrate130(wpdb $wpdb, string $prefix): void {
        self::maybeAddIndex($wpdb, $prefix . 'routes', 'client_project_date_idx', 'client_id, project_id, date');
        self::maybeAddIndex($wpdb, $prefix . 'routes', 'owner_date_idx', 'owner_user_id, date');
        self::maybeAddIndex($wpdb, $prefix . 'route_stops', 'route_location_idx', 'route_id, location_id');
        self::maybeAddIndex($wpdb, $prefix . 'assignments', 'user_active_idx', 'user_id, is_active');
        self::maybeAddIndex($wpdb, $prefix . 'campaign_locations', 'project_owner_active_idx', 'project_id, assigned_to, is_active');
        self::maybeAddIndex($wpdb, $prefix . 'campaign_locations', 'project_status_priority_idx', 'project_id, status, priority');
        self::maybeAddIndex($wpdb, $prefix . 'locations', 'project_city_idx', 'project_id, city');
        self::maybeAddIndex($wpdb, $prefix . 'system_logs', 'context_created_idx', 'context_key, created_at');
    }


    private static function migrate140(wpdb $wpdb, string $prefix): void {
        $charset = $wpdb->get_charset_collate();
        $table = $prefix . 'report_context_questions';
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            project_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            campaign_location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            form_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            context_type VARCHAR(64) NOT NULL DEFAULT 'custom',
            question_key VARCHAR(191) NOT NULL,
            question_label VARCHAR(255) NOT NULL,
            question_type VARCHAR(32) NOT NULL DEFAULT 'select',
            question_options_json LONGTEXT NULL,
            help_text TEXT NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 100,
            visibility_rules_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY scope_idx (client_id, project_id, location_id),
            KEY campaign_location_idx (campaign_location_id),
            KEY form_idx (form_id),
            KEY active_idx (is_active),
            KEY question_key_idx (question_key)
        ) {$charset}");
        $hasFormId = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'form_id'));
        if (!$hasFormId) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN form_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER campaign_location_id");
            $wpdb->query("ALTER TABLE {$table} ADD INDEX form_idx (form_id)");
        }
    }

    private static function maybeAddIndex(wpdb $wpdb, string $table, string $indexName, string $definition): void {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $indexName));
        if ($exists) return;
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($tableExists !== $table) return;
        $wpdb->query("ALTER TABLE {$table} ADD INDEX {$indexName} ({$definition})");
    }
}
