<?php
namespace RoutesPro\Forms;

use WP_Error;

if (!defined('ABSPATH')) exit;

class RecordService {
    public static function table_records(): string { global $wpdb; return $wpdb->prefix . 'routespro_form_records'; }
    public static function table_versions(): string { global $wpdb; return $wpdb->prefix . 'routespro_form_record_versions'; }
    public static function table_values(): string { global $wpdb; return $wpdb->prefix . 'routespro_form_record_values'; }
    public static function table_analytics_bindings(): string { global $wpdb; return $wpdb->prefix . 'routespro_form_analytics_bindings'; }

    public static function build_record_key(int $form_id, int $client_id, int $project_id, int $location_id): string {
        return implode(':', [$form_id, $client_id, $project_id, $location_id]);
    }

    public static function get_record_by_context(int $form_id, int $client_id, int $project_id, int $location_id): ?array {
        global $wpdb;
        $key = self::build_record_key($form_id, $client_id, $project_id, $location_id);
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_records() . ' WHERE record_key = %s LIMIT 1',
            $key
        ), ARRAY_A);
        return $row ?: null;
    }

    public static function get_current_values(int $record_id): array {
        global $wpdb;
        if ($record_id <= 0) return [];
        $record = $wpdb->get_row($wpdb->prepare(
            'SELECT current_version_id FROM ' . self::table_records() . ' WHERE id = %d LIMIT 1',
            $record_id
        ), ARRAY_A);
        $version_id = (int)($record['current_version_id'] ?? 0);
        if ($version_id <= 0) return [];
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table_values() . ' WHERE version_id = %d ORDER BY sort_order ASC, id ASC',
            $version_id
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $key = sanitize_key($row['question_key'] ?? '');
            if (!$key) continue;
            $out[$key] = [
                'type' => (string)($row['value_type'] ?? 'text'),
                'text' => isset($row['value_text']) ? (string)$row['value_text'] : '',
                'number' => $row['value_number'] !== null ? (float)$row['value_number'] : null,
                'json' => isset($row['value_json']) ? (string)$row['value_json'] : '',
            ];
        }
        return $out;
    }


    public static function get_record_state(int $form_id, int $client_id, int $project_id, int $location_id): array {
        $record = self::get_record_by_context($form_id, $client_id, $project_id, $location_id);
        if (!$record) {
            return [
                'record' => null,
                'version' => null,
                'prefill' => [],
            ];
        }
        $version = self::get_current_version((int)($record['id'] ?? 0));
        return [
            'record' => $record,
            'version' => $version,
            'prefill' => self::prepare_prefill_from_values((int)($record['id'] ?? 0)),
        ];
    }

    public static function get_current_version(int $record_id): ?array {
        global $wpdb;
        if ($record_id <= 0) return null;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_versions() . ' WHERE record_id = %d ORDER BY version_no DESC, id DESC LIMIT 1',
            $record_id
        ), ARRAY_A);
        return $row ?: null;
    }

    public static function prepare_prefill_from_values(int $record_id): array {
        $values = self::get_current_values($record_id);
        $prefill = [];
        foreach ($values as $key => $item) {
            $prefill[$key] = self::value_for_form_input($item);
        }
        return $prefill;
    }

    public static function value_for_form_input(array $stored) {
        $type = sanitize_key($stored['type'] ?? 'text');
        $text = isset($stored['text']) ? (string)$stored['text'] : '';
        $json = isset($stored['json']) ? (string)$stored['json'] : '';
        $number = array_key_exists('number', $stored) ? $stored['number'] : null;

        if ($type === 'checkbox') {
            return $text === '1' || $number === 1 || $number === 1.0;
        }
        if ($json !== '') {
            $decoded = json_decode($json, true);
            return $decoded !== null ? $decoded : $text;
        }
        if (in_array($type, ['number', 'currency', 'percent'], true) && $number !== null) {
            return (string)$number;
        }
        return $text;
    }



    public static function get_version_values(int $version_id): array {
        global $wpdb;
        if ($version_id <= 0) return [];
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table_values() . ' WHERE version_id = %d ORDER BY sort_order ASC, id ASC',
            $version_id
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $key = sanitize_key($row['question_key'] ?? '');
            if (!$key) continue;
            $out[$key] = [
                'label' => (string)($row['question_label'] ?? $key),
                'type' => (string)($row['value_type'] ?? 'text'),
                'text' => isset($row['value_text']) ? (string)$row['value_text'] : '',
                'number' => $row['value_number'] !== null ? (float)$row['value_number'] : null,
                'json' => isset($row['value_json']) ? (string)$row['value_json'] : '',
            ];
        }
        return $out;
    }

    public static function diff_versions(int $version_id, int $compare_to_version_id = 0): array {
        global $wpdb;
        $current = self::get_version_values($version_id);
        if ($compare_to_version_id <= 0) {
            $compare_to_version_id = (int)$wpdb->get_var($wpdb->prepare(
                'SELECT parent_version_id FROM ' . self::table_versions() . ' WHERE id = %d LIMIT 1',
                $version_id
            ));
        }
        $previous = self::get_version_values($compare_to_version_id);
        $keys = array_unique(array_merge(array_keys($previous), array_keys($current)));
        $diff = [];
        foreach ($keys as $key) {
            $before = self::value_for_form_input($previous[$key] ?? []);
            $after = self::value_for_form_input($current[$key] ?? []);
            if (is_array($before)) $before = wp_json_encode($before, JSON_UNESCAPED_UNICODE);
            if (is_array($after)) $after = wp_json_encode($after, JSON_UNESCAPED_UNICODE);
            if ((string)$before === (string)$after) continue;
            $diff[$key] = [
                'label' => (string)(($current[$key]['label'] ?? '') ?: ($previous[$key]['label'] ?? $key)),
                'before' => (string)$before,
                'after' => (string)$after,
            ];
        }
        return $diff;
    }

    public static function sync_submission(int $submission_id, array $submission_row, array $answers) {
        global $wpdb;
        if ($submission_id <= 0 || empty($submission_row['form_id'])) {
            return new WP_Error('invalid_submission', 'Submissão inválida para sincronização.', ['status' => 400]);
        }

        $form_id = (int)($submission_row['form_id'] ?? 0);
        $client_id = (int)($submission_row['client_id'] ?? 0);
        $project_id = (int)($submission_row['project_id'] ?? 0);
        $location_id = (int)($submission_row['location_id'] ?? 0);
        $route_id = (int)($submission_row['route_id'] ?? 0);
        $route_stop_id = (int)($submission_row['route_stop_id'] ?? 0);
        $binding_id = (int)($submission_row['binding_id'] ?? 0);
        $user_id = (int)($submission_row['user_id'] ?? 0);
        $owner_user_id = (int)($submission_row['owner_user_id'] ?? 0);
        $submitted_at = (string)($submission_row['submitted_at'] ?? current_time('mysql'));
        $meta_json = (string)($submission_row['meta_json'] ?? '');

        $record_key = self::build_record_key($form_id, $client_id, $project_id, $location_id);
        $record = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_records() . ' WHERE record_key = %s LIMIT 1',
            $record_key
        ), ARRAY_A);

        $previous_values = [];
        $parent_version_id = 0;
        $version_no = 1;
        $record_id = 0;
        if ($record) {
            $record_id = (int)$record['id'];
            $version_no = max(1, (int)($record['current_version_no'] ?? 0) + 1);
            $parent_version_id = (int)($record['current_version_id'] ?? 0);
            $previous_values = self::get_current_values($record_id);
        } else {
            $wpdb->insert(self::table_records(), [
                'record_key' => $record_key,
                'form_id' => $form_id,
                'client_id' => $client_id,
                'project_id' => $project_id,
                'location_id' => $location_id,
                'first_submission_id' => $submission_id,
                'latest_submission_id' => $submission_id,
                'latest_binding_id' => $binding_id,
                'latest_route_id' => $route_id,
                'latest_route_stop_id' => $route_stop_id,
                'last_user_id' => $user_id,
                'first_submitted_at' => $submitted_at,
                'last_submitted_at' => $submitted_at,
                'status' => 'active',
                'meta_json' => $meta_json,
            ], ['%s','%d','%d','%d','%d','%d','%d','%d','%d','%d','%d','%s','%s','%s','%s']);
            $record_id = (int)$wpdb->insert_id;
            if ($record_id <= 0) {
                $record = $wpdb->get_row($wpdb->prepare(
                    'SELECT * FROM ' . self::table_records() . ' WHERE record_key = %s LIMIT 1',
                    $record_key
                ), ARRAY_A);
                if ($record) {
                    $record_id = (int)$record['id'];
                    $version_no = max(1, (int)($record['current_version_no'] ?? 0) + 1);
                    $parent_version_id = (int)($record['current_version_id'] ?? 0);
                    $previous_values = self::get_current_values($record_id);
                }
            }
            if ($record_id <= 0) {
                return new WP_Error('record_create_failed', $wpdb->last_error ?: 'Falha ao criar registo do formulário.', ['status' => 500]);
            }
        }

        $change_summary = self::build_change_summary($previous_values, $answers);
        $wpdb->insert(self::table_versions(), [
            'record_id' => $record_id,
            'version_no' => $version_no,
            'submission_id' => $submission_id,
            'binding_id' => $binding_id,
            'route_id' => $route_id,
            'route_stop_id' => $route_stop_id,
            'client_id' => $client_id,
            'project_id' => $project_id,
            'location_id' => $location_id,
            'user_id' => $user_id,
            'owner_user_id' => $owner_user_id,
            'submitted_at' => $submitted_at,
            'parent_version_id' => $parent_version_id ?: null,
            'change_summary_json' => wp_json_encode($change_summary, JSON_UNESCAPED_UNICODE),
            'meta_json' => $meta_json,
        ], ['%d','%d','%d','%d','%d','%d','%d','%d','%d','%d','%d','%s','%d','%s','%s']);
        $version_id = (int)$wpdb->insert_id;
        if ($version_id <= 0) {
            return new WP_Error('version_create_failed', $wpdb->last_error ?: 'Falha ao criar versão do registo.', ['status' => 500]);
        }

        $sort_order = 0;
        foreach ($answers as $question_key => $item) {
            $sort_order++;
            $stored = self::normalize_value_for_storage($item['value'] ?? null, (string)($item['type'] ?? 'text'));
            $wpdb->insert(self::table_values(), [
                'record_id' => $record_id,
                'version_id' => $version_id,
                'question_key' => sanitize_key($question_key),
                'question_label' => sanitize_text_field($item['label'] ?? $question_key),
                'value_type' => sanitize_key($item['type'] ?? 'text'),
                'value_text' => $stored['text'],
                'value_number' => $stored['number'],
                'value_json' => $stored['json'],
                'sort_order' => $sort_order,
            ], ['%d','%d','%s','%s','%s','%s','%f','%s','%d']);
        }

        $wpdb->update(self::table_records(), [
            'current_version_id' => $version_id,
            'current_version_no' => $version_no,
            'latest_submission_id' => $submission_id,
            'latest_binding_id' => $binding_id,
            'latest_route_id' => $route_id,
            'latest_route_stop_id' => $route_stop_id,
            'last_user_id' => $user_id,
            'last_submitted_at' => $submitted_at,
            'version_count' => $version_no,
            'meta_json' => $meta_json,
        ], ['id' => $record_id], ['%d','%d','%d','%d','%d','%d','%d','%s','%d','%s'], ['%d']);

        return [
            'record_id' => $record_id,
            'version_id' => $version_id,
            'version_no' => $version_no,
            'change_summary' => $change_summary,
        ];
    }

    public static function backfill_existing_submissions(int $limit = 10000): int {
        global $wpdb;
        $limit = max(1, (int)$limit);
        $sql = "SELECT s.*, r.owner_user_id
                FROM " . Forms::table_submissions() . " s
                LEFT JOIN {$wpdb->prefix}routespro_routes r ON r.id = s.route_id
                WHERE COALESCE(s.record_id, 0) = 0 OR COALESCE(s.record_version_id, 0) = 0
                ORDER BY s.submitted_at ASC, s.id ASC
                LIMIT {$limit}";
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        $count = 0;
        foreach ($rows as $row) {
            $answersRows = $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM ' . Forms::table_answers() . ' WHERE submission_id = %d ORDER BY id ASC',
                (int)$row['id']
            ), ARRAY_A) ?: [];
            $answers = [];
            foreach ($answersRows as $a) {
                $key = sanitize_key($a['question_key'] ?? '');
                if (!$key) continue;
                $answers[$key] = [
                    'type' => self::guess_value_type($a),
                    'label' => (string)($a['question_label'] ?? $key),
                    'value' => self::value_from_answer_row($a),
                ];
            }
            $synced = self::sync_submission((int)$row['id'], $row, $answers);
            if (is_wp_error($synced)) {
                continue;
            }
            $wpdb->update(Forms::table_submissions(), [
                'record_id' => (int)$synced['record_id'],
                'record_version_id' => (int)$synced['version_id'],
            ], ['id' => (int)$row['id']], ['%d','%d'], ['%d']);
            $count++;
        }
        return $count;
    }

    private static function build_change_summary(array $previous_values, array $answers): array {
        $current_values = [];
        foreach ($answers as $key => $item) {
            $current_values[sanitize_key($key)] = self::normalize_value_for_storage($item['value'] ?? null, (string)($item['type'] ?? 'text'));
        }
        $summary = [
            'added' => 0,
            'changed' => 0,
            'removed' => 0,
            'unchanged' => 0,
            'fields' => [],
        ];
        $all_keys = array_unique(array_merge(array_keys($previous_values), array_keys($current_values)));
        sort($all_keys);
        foreach ($all_keys as $key) {
            $prev = $previous_values[$key] ?? null;
            $curr = $current_values[$key] ?? null;
            $prevSig = self::value_signature($prev);
            $currSig = self::value_signature($curr);
            if ($prev === null && $curr !== null) {
                $summary['added']++;
                $summary['fields'][$key] = 'added';
                continue;
            }
            if ($prev !== null && $curr === null) {
                $summary['removed']++;
                $summary['fields'][$key] = 'removed';
                continue;
            }
            if ($prevSig !== $currSig) {
                $summary['changed']++;
                $summary['fields'][$key] = 'changed';
                continue;
            }
            $summary['unchanged']++;
            $summary['fields'][$key] = 'unchanged';
        }
        return $summary;
    }

    private static function normalize_value_for_storage($value, string $type): array {
        $text = null;
        $number = null;
        $json = null;
        if (is_array($value)) {
            $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
            $text = $json;
        } elseif (in_array($type, ['number','currency','percent'], true) && $value !== '' && $value !== null) {
            $number = (float)$value;
            $text = (string)$value;
        } elseif ($type === 'checkbox') {
            $number = $value ? 1.0 : 0.0;
            $text = $value ? '1' : '0';
        } else {
            $text = is_scalar($value) ? (string)$value : wp_json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return ['text' => $text, 'number' => $number, 'json' => $json];
    }

    private static function value_signature($stored): string {
        if (!is_array($stored)) return 'null';
        return wp_json_encode([
            'text' => array_key_exists('text', $stored) ? $stored['text'] : null,
            'number' => array_key_exists('number', $stored) ? $stored['number'] : null,
            'json' => array_key_exists('json', $stored) ? $stored['json'] : null,
        ], JSON_UNESCAPED_UNICODE);
    }

    private static function guess_value_type(array $row): string {
        if (!empty($row['value_json'])) return 'json';
        if ($row['value_number'] !== null && ($row['value_text'] === '1' || $row['value_text'] === '0')) return 'checkbox';
        if ($row['value_number'] !== null) return 'number';
        return 'text';
    }

    private static function value_from_answer_row(array $row) {
        if (!empty($row['value_json'])) {
            $decoded = json_decode((string)$row['value_json'], true);
            return is_array($decoded) ? $decoded : (string)$row['value_json'];
        }
        if ($row['value_number'] !== null) {
            if ((string)$row['value_text'] === '1' || (string)$row['value_text'] === '0') {
                return (string)$row['value_text'] === '1' ? 1 : 0;
            }
            return (float)$row['value_number'];
        }
        return (string)($row['value_text'] ?? '');
    }
}
