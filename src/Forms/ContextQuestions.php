<?php
namespace RoutesPro\Forms;

if (!defined('ABSPATH')) exit;

class ContextQuestions {
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'routespro_report_context_questions';
    }

    public static function for_context(array $context): array {
        global $wpdb;
        $table = self::table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return [];

        $clientId = absint($context['client_id'] ?? 0);
        $projectId = absint($context['project_id'] ?? 0);
        $locationId = absint($context['location_id'] ?? 0);
        $formId = absint($context['form_id'] ?? 0);
        $campaignLocationId = absint($context['campaign_location_id'] ?? 0);
        if (!$campaignLocationId && $projectId && $locationId) {
            $px = $wpdb->prefix . 'routespro_';
            $campaignLocationId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$px}campaign_locations WHERE project_id=%d AND location_id=%d LIMIT 1", $projectId, $locationId));
        }

        $hasFormId = (bool) $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'form_id'));
        $where = ['is_active = 1'];
        $args = [];
        foreach ([
            'client_id' => $clientId,
            'project_id' => $projectId,
            'location_id' => $locationId,
            'campaign_location_id' => $campaignLocationId,
        ] as $field => $value) {
            if ($value > 0) {
                $where[] = "({$field}=0 OR {$field}=%d)";
                $args[] = $value;
            } else {
                $where[] = "{$field}=0";
            }
        }
        if ($hasFormId) {
            if ($formId > 0) {
                $where[] = '(form_id=0 OR form_id=%d)';
                $args[] = $formId;
            } else {
                $where[] = 'form_id=0';
            }
        }

        $specificity = $hasFormId
            ? '((client_id>0) + (project_id>0) + (location_id>0) + (campaign_location_id>0) + (form_id>0))'
            : '((client_id>0) + (project_id>0) + (location_id>0) + (campaign_location_id>0))';
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY
            {$specificity} DESC,
            priority ASC,
            id ASC";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $key = sanitize_key($row['question_key'] ?? '');
            if (!$key) $key = 'ctxq_' . (int)($row['id'] ?? 0);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $row['question_key'] = $key;
            $out[] = $row;
        }
        return $out;
    }

    public static function inject_into_schema(array $schema, array $questions): array {
        if (!$questions) return $schema;
        if (empty($schema['questions']) || !is_array($schema['questions'])) $schema['questions'] = [];
        $existing = [];
        foreach ($schema['questions'] as $q) {
            if (is_array($q) && !empty($q['key'])) $existing[sanitize_key($q['key'])] = true;
        }
        $contextKeys = [];
        foreach ($questions as $row) {
            $q = self::row_to_schema_question((array)$row);
            if (!$q || isset($existing[$q['key']])) continue;
            $schema['questions'][] = $q;
            $existing[$q['key']] = true;
            $contextKeys[] = $q['key'];
        }
        if ($contextKeys && !empty($schema['layout']) && is_array($schema['layout']) && ($schema['layout']['mode'] ?? '') === 'steps') {
            if (empty($schema['layout']['steps']) || !is_array($schema['layout']['steps'])) $schema['layout']['steps'] = [];
            $schema['layout']['steps'][] = [
                'title' => 'Perguntas da loja',
                'description' => 'Campos específicos configurados para este cliente, campanha, formulário ou local.',
                'fields' => $contextKeys,
            ];
        }
        return $schema;
    }

    public static function row_to_schema_question(array $row): array {
        $key = sanitize_key($row['question_key'] ?? ('ctxq_' . (int)($row['id'] ?? 0)));
        if (!$key) return [];
        $type = sanitize_key($row['question_type'] ?? 'select');
        $allowed = ['text','textarea','number','currency','percent','date','time','select','radio','checkbox','image_upload','file_upload'];
        if (!in_array($type, $allowed, true)) $type = 'select';
        $options = [];
        $raw = (string)($row['question_options_json'] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $options = array_values(array_filter(array_map('strval', $decoded), static fn($v) => trim($v) !== ''));
        }
        if (($type === 'select' || $type === 'radio') && !$options) $options = ['Sim', 'Não', 'Não aplicável'];
        return [
            'key' => $key,
            'label' => sanitize_text_field((string)($row['question_label'] ?? $key)),
            'type' => $type,
            'required' => !empty($row['is_required']),
            'options' => $options,
            'help_text' => sanitize_text_field((string)($row['help_text'] ?? '')),
            'source' => 'context_question',
            'context_question_id' => (int)($row['id'] ?? 0),
            'context_type' => sanitize_key((string)($row['context_type'] ?? 'custom')),
            'form_id' => (int)($row['form_id'] ?? 0),
        ];
    }

    public static function make_question_key(string $contextType, string $label, int $locationId = 0): string {
        $base = sanitize_key($contextType ?: 'custom');
        $slug = sanitize_key(substr(remove_accents($label), 0, 70));
        if (!$slug) $slug = 'pergunta';
        return sanitize_key('ctx_' . $base . '_' . ($locationId ? $locationId . '_' : '') . $slug);
    }
}
