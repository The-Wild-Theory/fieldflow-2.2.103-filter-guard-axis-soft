<?php
namespace RoutesPro\Repositories;

if (!defined('ABSPATH')) exit;

class SystemLogRepository {
    private static function tableName(): string {
        global $wpdb;
        return $wpdb->prefix . 'routespro_system_logs';
    }

    public static function exists(): bool {
        global $wpdb;
        $table = self::tableName();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $exists === $table;
    }

    public static function insert(array $data): bool {
        global $wpdb;
        if (!self::exists()) {
            return false;
        }
        $table = self::tableName();
        $result = $wpdb->insert($table, [
            'log_level' => (string) ($data['level'] ?? 'info'),
            'context_key' => (string) ($data['context_key'] ?? 'system'),
            'message' => (string) ($data['message'] ?? ''),
            'user_id' => !empty($data['user_id']) ? absint($data['user_id']) : null,
            'route_id' => !empty($data['route_id']) ? absint($data['route_id']) : null,
            'client_id' => !empty($data['client_id']) ? absint($data['client_id']) : null,
            'project_id' => !empty($data['project_id']) ? absint($data['project_id']) : null,
            'meta_json' => $data['meta_json'] ?? null,
        ], ['%s','%s','%s','%d','%d','%d','%d','%s']);
        return (bool) $result;
    }

    public static function latest(int $limit = 100): array {
        global $wpdb;
        if (!self::exists()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $table = self::tableName();
        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT {$limit}", ARRAY_A) ?: [];
    }

    public static function count(): int {
        global $wpdb;
        if (!self::exists()) {
            return 0;
        }
        $table = self::tableName();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    public static function pruneOlderThanDays(int $days): int {
        global $wpdb;
        if (!self::exists()) {
            return 0;
        }
        $days = max(1, $days);
        $table = self::tableName();
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < (NOW() - INTERVAL %d DAY)", $days));
        return (int) $wpdb->rows_affected;
    }
}
