<?php
namespace RoutesPro\Forms;
if (!defined('ABSPATH')) exit;
class Forms {
    const ACTION_SUBMIT = 'routespro_submit_form';
    const NONCE_FIELD = 'routespro_form_nonce';
    const NONCE_ACTION = 'routespro_form_submit';
    public static function boot() {
        add_shortcode('fieldflow_form', [self::class, 'shortcode_form']);
        add_shortcode('fieldflow_route_form', [self::class, 'shortcode_route_form']);
        // aliases legados
        add_shortcode('routespro_form', [self::class, 'shortcode_form']);
        add_shortcode('routespro_route_form', [self::class, 'shortcode_route_form']);
        add_action('admin_post_' . self::ACTION_SUBMIT, [self::class, 'handle_submit']);
        add_action('admin_post_nopriv_' . self::ACTION_SUBMIT, [self::class, 'handle_submit']);
        add_action('wp_ajax_routespro_context_form', [self::class, 'ajax_context_form']);
        add_action('wp_ajax_nopriv_routespro_context_form', [self::class, 'ajax_context_form']);
        add_action('wp_enqueue_scripts', [FormRenderer::class, 'enqueue_assets']);
    }
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'routespro_forms'; }
    public static function table_submissions(): string { global $wpdb; return $wpdb->prefix . 'routespro_form_submissions'; }
    public static function table_answers(): string { global $wpdb; return $wpdb->prefix . 'routespro_form_submission_answers'; }
    public static function default_schema(): array {
        return ['meta'=>['title'=>'','subtitle'=>''],'layout'=>['mode'=>'single','show_progress'=>false,'steps'=>[],'field_layout'=>[]],'questions'=>[]];
    }
    public static function get_form(int $id): ?array { global $wpdb; $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id=%d', $id), ARRAY_A); return $row ?: null; }
    public static function list_forms(): array { global $wpdb; return $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY updated_at DESC, id DESC', ARRAY_A) ?: []; }

    public static function shortcode_route_form($atts = []) {
        $atts = shortcode_atts(['client_id'=>0,'project_id'=>0,'route_id'=>0,'stop_id'=>0,'location_id'=>0,'fallback'=>'','show_title'=>1], $atts);

        $context = [
            'client_id' => absint($atts['client_id']),
            'project_id' => absint($atts['project_id']),
            'route_id' => absint($atts['route_id']),
            'stop_id' => absint($atts['stop_id']),
            'location_id' => absint($atts['location_id']),
        ];

        foreach (array_keys($context) as $field) {
            if (empty($context[$field]) && isset($_GET[$field])) {
                $context[$field] = absint(wp_unslash($_GET[$field]));
            }
        }

        $context = BindingResolver::get_context($context);
        $binding = BindingResolver::resolve($context);
        if (!$binding || empty($binding['form_id'])) {
            $fallback = trim((string) ($atts['fallback'] ?? ''));
            if ($fallback !== '') return do_shortcode($fallback);
            return '<p>Nenhum formulário activo para este contexto.</p>';
        }
        return self::render_form_with_context((int) $binding['form_id'], $context, $binding, [
            'show_title' => !empty($atts['show_title']),
        ]);
    }

    public static function shortcode_form($atts = []) {
        $atts = shortcode_atts(['id'=>0,'client_id'=>0,'project_id'=>0,'route_id'=>0,'stop_id'=>0,'location_id'=>0,'binding_id'=>0,'show_title'=>1], $atts);
        $context = [
            'client_id' => absint($atts['client_id']),
            'project_id' => absint($atts['project_id']),
            'route_id' => absint($atts['route_id']),
            'stop_id' => absint($atts['stop_id']),
            'location_id' => absint($atts['location_id']),
        ];
        $binding = !empty($atts['binding_id']) ? ['id' => absint($atts['binding_id'])] : null;
        return self::render_form_with_context(absint($atts['id']), $context, $binding, [
            'show_title' => !empty($atts['show_title']),
        ]);
    }

    public static function render_form_with_context(int $form_id, array $context = [], ?array $binding = null, array $opts = []) {
        if (!$form_id) return '<p>Formulário inválido.</p>';
        if (!is_user_logged_in()) return '<p>Precisas de login para submeter.</p>';
        $form = self::get_form($form_id);
        if (!$form || ($form['status'] ?? '') !== 'active') return '<p>Formulário não encontrado ou inativo.</p>';
        $schema = self::decode_schema($form['schema_json'] ?? '');
        if (class_exists('\\RoutesPro\\Forms\\ContextQuestions')) {
            $context_for_questions = $context;
            $context_for_questions['form_id'] = $form_id;
            $schema = ContextQuestions::inject_into_schema($schema, ContextQuestions::for_context($context_for_questions));
        }
        if (empty($schema['questions'])) return '<p>O formulário não tem perguntas configuradas.</p>';
        $needs_multipart = FormRenderer::schema_needs_multipart($schema);
        $record_state = RecordService::get_record_state(
            $form_id,
            (int) ($context['client_id'] ?? 0),
            (int) ($context['project_id'] ?? 0),
            (int) ($context['location_id'] ?? 0)
        );
        $prefill = is_array($record_state['prefill'] ?? null) ? $record_state['prefill'] : [];
        $record = is_array($record_state['record'] ?? null) ? $record_state['record'] : null;
        $version = is_array($record_state['version'] ?? null) ? $record_state['version'] : null;
        $theme = self::decode_json_array($form['theme_json'] ?? '');
        $style_attr = FormRenderer::theme_style_attr($theme);
        $show_title = array_key_exists('show_title', $opts) ? !empty($opts['show_title']) : true;
        $hide_actions = !empty($opts['hide_actions']);
        $binding_id = (int) ($binding['id'] ?? 0);
        $return_url = isset($opts['return_url']) ? esc_url_raw((string) $opts['return_url']) : self::current_request_url();
        ob_start(); ?>
        <div class="routespro-form-wrap rp-form-ui"<?php echo $style_attr ? ' style="' . esc_attr($style_attr) . '"' : ''; ?>>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="routespro-dyn-form" data-routespro-form-id="<?php echo esc_attr($form_id); ?>"<?php echo $needs_multipart ? ' enctype="multipart/form-data"' : ''; ?>>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SUBMIT); ?>">
                <input type="hidden" name="routespro_form_id" value="<?php echo esc_attr($form_id); ?>">
                <input type="hidden" name="routespro_binding_id" value="<?php echo esc_attr($binding_id); ?>">
                <input type="hidden" name="routespro_client_id" value="<?php echo esc_attr((int) ($context['client_id'] ?? 0)); ?>">
                <input type="hidden" name="routespro_project_id" value="<?php echo esc_attr((int) ($context['project_id'] ?? 0)); ?>">
                <input type="hidden" name="routespro_route_id" value="<?php echo esc_attr((int) ($context['route_id'] ?? 0)); ?>">
                <input type="hidden" name="routespro_stop_id" value="<?php echo esc_attr((int) ($context['stop_id'] ?? 0)); ?>">
                <input type="hidden" name="routespro_location_id" value="<?php echo esc_attr((int) ($context['location_id'] ?? 0)); ?>">
                <input type="hidden" name="routespro_record_id" value="<?php echo esc_attr((int) ($record['id'] ?? 0)); ?>">
                <input type="hidden" name="routespro_record_version_id" value="<?php echo esc_attr((int) ($version['id'] ?? 0)); ?>">
                <input type="hidden" name="routespro_return_url" value="<?php echo esc_attr($return_url); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="routespro_ajax" value="0">
                <?php if (isset($_GET['routespro_form_ok']) && wp_unslash($_GET['routespro_form_ok']) === '1'): ?><div class="routespro-form-notice success">Submissão gravada com sucesso. O histórico desta visita foi atualizado.</div><?php endif; ?>
                <?php if (isset($_GET['routespro_form_err'])): ?><div class="routespro-form-notice error">Falha ao submeter: <?php echo esc_html(sanitize_text_field(wp_unslash($_GET['routespro_form_err']))); ?>.</div><?php endif; ?>
                <?php if ($record): ?>
                    <div class="routespro-form-notice info">
                        <strong>Histórico carregado.</strong> Este local já tem registo anterior.
                        <?php if ($version): ?>
                            <span>Versão atual #<?php echo esc_html((string) ($version['version_no'] ?? '1')); ?>, atualizada em <?php echo esc_html(mysql2date('d/m/Y H:i', (string) ($version['submitted_at'] ?? ''))); ?>.</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="routespro-form-notice info">Primeira visita para este contexto. O sistema vai criar o registo base e a primeira versão.</div>
                <?php endif; ?>
                <?php if ($show_title && (!empty($schema['meta']['title']) || !empty($schema['meta']['subtitle']))): ?>
                    <div class="routespro-form-head">
                        <?php if (!empty($schema['meta']['title'])): ?><h3><?php echo esc_html($schema['meta']['title']); ?></h3><?php endif; ?>
                        <?php if (!empty($schema['meta']['subtitle'])): ?><p><?php echo esc_html($schema['meta']['subtitle']); ?></p><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php echo FormRenderer::render_questions($schema, $prefill, $context); ?>
                <?php if (!$hide_actions): ?><div class="routespro-form-actions"><button type="submit" class="routespro-form-btn">Submeter</button></div><?php endif; ?>
            </form>
        </div>
        <?php return ob_get_clean();
    }


    public static function ajax_context_form() {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Sem sessão ativa.'], 401);
        }
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            wp_send_json_error(['message' => 'Nonce inválido.'], 403);
        }

        $context = BindingResolver::get_context([
            'client_id' => isset($_POST['client_id']) ? absint($_POST['client_id']) : 0,
            'project_id' => isset($_POST['project_id']) ? absint($_POST['project_id']) : 0,
            'route_id' => isset($_POST['route_id']) ? absint($_POST['route_id']) : 0,
            'stop_id' => isset($_POST['stop_id']) ? absint($_POST['stop_id']) : 0,
            'location_id' => isset($_POST['location_id']) ? absint($_POST['location_id']) : 0,
        ]);

        $binding = BindingResolver::resolve($context);
        if (!$binding || empty($binding['form_id'])) {
            wp_send_json_success([
                'has_form' => false,
                'binding_mode' => '',
                'html' => '',
                'context' => $context,
            ]);
        }

        $binding_mode = sanitize_key($binding['mode'] ?? '');
        if ($binding_mode !== 'route_and_form' && $binding_mode !== 'form_only') {
            wp_send_json_success([
                'has_form' => false,
                'binding_mode' => $binding_mode,
                'html' => '',
                'context' => $context,
            ]);
        }

        $return_url = isset($_POST['return_url']) ? esc_url_raw((string) wp_unslash($_POST['return_url'])) : '';
        $html = self::render_form_with_context((int) $binding['form_id'], $context, $binding, [
            'show_title' => true,
            'hide_actions' => true,
            'return_url' => $return_url,
        ]);

        wp_send_json_success([
            'has_form' => true,
            'binding_mode' => $binding_mode,
            'html' => $html,
            'context' => $context,
            'form_id' => (int) ($binding['form_id'] ?? 0),
            'binding_id' => (int) ($binding['id'] ?? 0),
        ]);
    }

    public static function handle_submit() {
        $is_ajax = !empty($_POST['routespro_ajax']);
        if (!is_user_logged_in()) { self::finish_submit_error('sem_login', $is_ajax); }
        $nonce = isset($_POST[self::NONCE_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, self::NONCE_ACTION)) { self::finish_submit_error('nonce', $is_ajax); }
        $form_id = isset($_POST['routespro_form_id']) ? absint($_POST['routespro_form_id']) : 0;
        $form = self::get_form($form_id);
        if (!$form || ($form['status'] ?? '') !== 'active') { self::finish_submit_error('form', $is_ajax); }
        $schema = self::decode_schema($form['schema_json'] ?? '');
        $submit_context = [
            'client_id' => isset($_POST['routespro_client_id']) ? absint($_POST['routespro_client_id']) : 0,
            'project_id' => isset($_POST['routespro_project_id']) ? absint($_POST['routespro_project_id']) : 0,
            'route_id' => isset($_POST['routespro_route_id']) ? absint($_POST['routespro_route_id']) : 0,
            'stop_id' => isset($_POST['routespro_stop_id']) ? absint($_POST['routespro_stop_id']) : 0,
            'location_id' => isset($_POST['routespro_location_id']) ? absint($_POST['routespro_location_id']) : 0,
        ];
        if (class_exists('\\RoutesPro\\Forms\\ContextQuestions')) {
            $submit_context_for_questions = $submit_context;
            $submit_context_for_questions['form_id'] = $form_id;
            $schema = ContextQuestions::inject_into_schema($schema, ContextQuestions::for_context($submit_context_for_questions));
        }
        if (empty($schema['questions'])) { self::finish_submit_error('schema', $is_ajax); }
        $record_state = RecordService::get_record_state(
            $form_id,
            (int) ($submit_context['client_id'] ?? 0),
            (int) ($submit_context['project_id'] ?? 0),
            (int) ($submit_context['location_id'] ?? 0)
        );
        $existing_prefill = is_array($record_state['prefill'] ?? null) ? $record_state['prefill'] : [];
        $answers = [];
        foreach ($schema['questions'] as $question) {
            if (!is_array($question)) continue; $key = sanitize_key($question['key'] ?? ''); if (!$key) continue;
            $type = sanitize_key($question['type'] ?? 'text'); $required = !empty($question['required']); $value = self::extract_value_from_request($key, $type);
            if (self::is_empty_value($value, $type) && in_array($type, ['image_upload','file_upload'], true) && !empty($existing_prefill[$key])) {
                $value = $existing_prefill[$key];
            }
            if ($required && self::is_empty_value($value, $type)) { self::finish_submit_error('campo_' . $key, $is_ajax); }
            if (self::is_empty_value($value, $type)) continue;
            $answers[$key] = ['type'=>$type,'value'=>$value,'label'=>sanitize_text_field($question['label'] ?? $key)];
        }
        $binding_id = isset($_POST['routespro_binding_id']) ? absint($_POST['routespro_binding_id']) : 0;
        $normalized = SubmissionContext::normalize_for_submission([
            'client_id' => isset($_POST['routespro_client_id']) ? absint($_POST['routespro_client_id']) : 0,
            'project_id' => isset($_POST['routespro_project_id']) ? absint($_POST['routespro_project_id']) : 0,
            'route_id' => isset($_POST['routespro_route_id']) ? absint($_POST['routespro_route_id']) : 0,
            'route_stop_id' => isset($_POST['routespro_stop_id']) ? absint($_POST['routespro_stop_id']) : 0,
            'location_id' => isset($_POST['routespro_location_id']) ? absint($_POST['routespro_location_id']) : 0,
        ], $form_id, $binding_id, get_current_user_id());
        if (is_wp_error($normalized)) { self::finish_submit_error((string) $normalized->get_error_code(), $is_ajax); }
        $context = (array) ($normalized['context'] ?? []);
        $binding = is_array($normalized['binding'] ?? null) ? $normalized['binding'] : null;
        $binding_id = (int) ($binding['id'] ?? $binding_id);
        $owner_user_id = (int) ($normalized['owner_user_id'] ?? 0);
        $submitted_at = current_time('mysql');
        $meta_json = wp_json_encode([
            'source_url' => esc_url_raw(wp_get_referer() ?: ''),
            'context_model' => 'record_version_v1',
            'previous_record_id' => isset($_POST['routespro_record_id']) ? absint($_POST['routespro_record_id']) : 0,
            'previous_version_id' => isset($_POST['routespro_record_version_id']) ? absint($_POST['routespro_record_version_id']) : 0,
        ], JSON_UNESCAPED_UNICODE);
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        $inserted = $wpdb->insert(self::table_submissions(), [
            'form_id' => $form_id,
            'binding_id' => $binding_id,
            'client_id' => (int) ($context['client_id'] ?? 0),
            'project_id' => (int) ($context['project_id'] ?? 0),
            'route_id' => (int) ($context['route_id'] ?? 0),
            'route_stop_id' => (int) ($context['route_stop_id'] ?? 0),
            'location_id' => (int) ($context['location_id'] ?? 0),
            'user_id' => get_current_user_id(),
            'submitted_at' => $submitted_at,
            'status' => 'submitted',
            'meta_json' => $meta_json,
        ], ['%d','%d','%d','%d','%d','%d','%d','%d','%s','%s','%s']);
        if ($inserted === false) {
            $wpdb->query('ROLLBACK');
            self::finish_submit_error('submission_insert', $is_ajax);
        }
        $submission_id = (int) $wpdb->insert_id;
        foreach ($answers as $key => $item) {
            $stored = self::prepare_answer_storage($item['value'], $item['type']);
            $ok = $wpdb->insert(self::table_answers(), [
                'submission_id' => $submission_id,
                'question_key' => $key,
                'question_label' => $item['label'],
                'value_text' => $stored['text'],
                'value_number' => $stored['number'],
                'value_json' => $stored['json'],
                'created_at' => $submitted_at,
            ], ['%d','%s','%s','%s','%f','%s','%s']);
            if ($ok === false) {
                $wpdb->query('ROLLBACK');
                self::finish_submit_error('answer_insert', $is_ajax);
            }
        }
        $synced = RecordService::sync_submission($submission_id, [
            'form_id' => $form_id,
            'binding_id' => $binding_id,
            'client_id' => (int) ($context['client_id'] ?? 0),
            'project_id' => (int) ($context['project_id'] ?? 0),
            'route_id' => (int) ($context['route_id'] ?? 0),
            'route_stop_id' => (int) ($context['route_stop_id'] ?? 0),
            'location_id' => (int) ($context['location_id'] ?? 0),
            'user_id' => get_current_user_id(),
            'owner_user_id' => $owner_user_id,
            'submitted_at' => $submitted_at,
            'meta_json' => $meta_json,
        ], $answers);
        if (is_wp_error($synced)) {
            $wpdb->query('ROLLBACK');
            self::finish_submit_error((string) $synced->get_error_code(), $is_ajax);
        }
        $updated = $wpdb->update(self::table_submissions(), [
            'record_id' => (int) ($synced['record_id'] ?? 0),
            'record_version_id' => (int) ($synced['version_id'] ?? 0),
        ], ['id' => $submission_id], ['%d','%d'], ['%d']);
        if ($updated === false) {
            $wpdb->query('ROLLBACK');
            self::finish_submit_error('submission_link', $is_ajax);
        }
        $wpdb->query('COMMIT');
        /**
         * Fired after a FieldFlow form submission is safely stored and synced.
         * Performance automations listen here without changing the normal reporting flow.
         */
        do_action('fieldflow_form_submitted', $submission_id, [
            'id' => $submission_id,
            'form_id' => $form_id,
            'binding_id' => $binding_id,
            'client_id' => (int) ($context['client_id'] ?? 0),
            'project_id' => (int) ($context['project_id'] ?? 0),
            'route_id' => (int) ($context['route_id'] ?? 0),
            'route_stop_id' => (int) ($context['route_stop_id'] ?? 0),
            'location_id' => (int) ($context['location_id'] ?? 0),
            'user_id' => get_current_user_id(),
            'submitted_at' => $submitted_at,
        ], $answers);
        if ($is_ajax) {
            wp_send_json_success([
                'submission_id' => $submission_id,
                'record_id' => (int) ($synced['record_id'] ?? 0),
                'record_version_id' => (int) ($synced['version_id'] ?? 0),
                'message' => 'Submissão gravada com sucesso.',
            ]);
        }
        wp_safe_redirect(self::redirect_back_with_success()); exit;
    }
    private static function finish_submit_error(string $code, bool $is_ajax): void {
        if ($is_ajax) {
            wp_send_json_error(['code' => $code, 'message' => $code], 400);
        }
        wp_safe_redirect(self::redirect_back_with_error($code));
        exit;
    }
    private static function extract_value_from_request(string $key, string $type) {
        if ($type === 'checkbox') return isset($_POST[$key]) ? 1 : 0;
        if ($type === 'product_matrix') return self::extract_product_matrix_from_request($key);
        if ($type === 'image_upload' || $type === 'file_upload') return self::handle_upload($key);
        $raw = $_POST[$key] ?? null; if (is_array($raw)) return array_map('sanitize_text_field', wp_unslash($raw)); $raw = is_string($raw) ? wp_unslash($raw) : $raw;
        switch ($type) {
            case 'number': case 'currency': case 'percent': return ($raw === '' || $raw === null) ? '' : (float) str_replace(',', '.', (string) $raw);
            case 'textarea': return sanitize_textarea_field((string) $raw);
            default: return sanitize_text_field((string) $raw);
        }
    }
    private static function extract_product_matrix_from_request(string $key): array {
        $raw = $_POST[$key] ?? [];
        if (!is_array($raw)) return [];
        $rows = [];
        foreach (wp_unslash($raw) as $row) {
            if (!is_array($row)) continue;
            $ref = sanitize_text_field((string)($row['ref'] ?? $row['reference'] ?? ''));
            $name = sanitize_text_field((string)($row['name'] ?? $row['product'] ?? ''));
            $before_raw = isset($row['before']) ? (string)$row['before'] : '';
            $after_raw = isset($row['after']) ? (string)$row['after'] : '';
            $qty_raw = $after_raw !== '' ? $after_raw : (isset($row['qty']) ? (string)$row['qty'] : (isset($row['quantity']) ? (string)$row['quantity'] : ''));
            $qty_raw = str_replace(',', '.', trim($qty_raw));
            $before_raw = str_replace(',', '.', trim($before_raw));
            $after_raw = str_replace(',', '.', trim($after_raw));
            $has_qty = ($qty_raw !== '' && is_numeric($qty_raw));
            $has_before = ($before_raw !== '' && is_numeric($before_raw));
            $has_after = ($after_raw !== '' && is_numeric($after_raw));
            if ($ref === '' && $name === '' && !$has_qty && !$has_before && !$has_after) continue;
            $stored = [
                'ref' => $ref,
                'name' => $name,
                'qty' => $has_qty ? (float)$qty_raw : '',
            ];
            if ($has_before || $has_after) {
                $stored['before'] = $has_before ? (float)$before_raw : ($has_qty ? (float)$qty_raw : '');
                $stored['after'] = $has_after ? (float)$after_raw : ($has_qty ? (float)$qty_raw : '');
            }
            $rows[] = $stored;
        }
        return $rows;
    }
    private static function handle_upload(string $field_key) {
        if (empty($_FILES[$field_key])) return '';
        $file = $_FILES[$field_key];
        $has_multiple = is_array($file['name'] ?? null);
        if (!$has_multiple && empty($file['name'])) return '';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if (!$has_multiple) {
            $uploaded = wp_handle_upload($file, ['test_form'=>false]);
            return !empty($uploaded['url']) ? esc_url_raw($uploaded['url']) : '';
        }
        $urls = [];
        $total = count($file['name']);
        for ($i = 0; $i < $total; $i++) {
            if (empty($file['name'][$i])) continue;
            $single = [
                'name' => $file['name'][$i],
                'type' => $file['type'][$i] ?? '',
                'tmp_name' => $file['tmp_name'][$i] ?? '',
                'error' => $file['error'][$i] ?? 0,
                'size' => $file['size'][$i] ?? 0,
            ];
            $uploaded = wp_handle_upload($single, ['test_form'=>false]);
            if (!empty($uploaded['url'])) $urls[] = esc_url_raw($uploaded['url']);
        }
        return $urls;
    }
    private static function prepare_answer_storage($value, string $type): array {
        $text = null; $number = null; $json = null; if (is_array($value)) { $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE); $text = $json; } elseif (in_array($type, ['number','currency','percent'], true) && $value !== '') { $number = (float) $value; $text = (string) $value; } elseif ($type === 'checkbox') { $number = $value ? 1 : 0; $text = $value ? '1' : '0'; } else { $text = is_scalar($value) ? (string) $value : wp_json_encode($value, JSON_UNESCAPED_UNICODE); } return ['text'=>$text,'number'=>$number,'json'=>$json];
    }
    private static function is_empty_value($value, string $type): bool { if ($type === 'checkbox') return false; if ($type === 'product_matrix') return empty($value); if (is_array($value)) return empty($value); return $value === '' || $value === null; }
    public static function decode_schema(string $json): array { $schema = self::decode_json_array($json); if (!$schema) $schema = self::default_schema(); if (empty($schema['meta']) || !is_array($schema['meta'])) $schema['meta'] = ['title'=>'','subtitle'=>'']; if (empty($schema['layout']) || !is_array($schema['layout'])) $schema['layout'] = self::default_schema()['layout']; if (empty($schema['questions']) || !is_array($schema['questions'])) $schema['questions'] = []; return $schema; }
    public static function decode_json_array(string $json): array { $decoded = json_decode($json, true); return is_array($decoded) ? $decoded : []; }
    private static function get_return_url(): string {
        $posted = isset($_POST['routespro_return_url']) ? esc_url_raw((string) wp_unslash($_POST['routespro_return_url'])) : '';
        if ($posted !== '' && self::is_safe_return_url($posted)) return $posted;
        $referer = wp_get_referer() ?: '';
        if ($referer !== '' && self::is_safe_return_url($referer) && strpos($referer, 'admin-ajax.php') === false && strpos($referer, 'admin-post.php') === false) return $referer;
        return home_url('/');
    }
    private static function is_safe_return_url(string $url): bool {
        if ($url === '') return false;
        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $url_host = wp_parse_url($url, PHP_URL_HOST);
        return !$url_host || !$home_host || strtolower((string) $url_host) === strtolower((string) $home_host);
    }
    private static function current_request_url(): string {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field((string) wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if ($host && $uri) return esc_url_raw($scheme . $host . $uri);
        return home_url('/');
    }
    public static function redirect_back_with_error(string $code): string { $url = remove_query_arg(['routespro_form_ok','routespro_form_err'], self::get_return_url()); return add_query_arg('routespro_form_err', rawurlencode($code), $url); }
    public static function redirect_back_with_success(): string { $url = remove_query_arg(['routespro_form_ok','routespro_form_err'], self::get_return_url()); return add_query_arg('routespro_form_ok', '1', $url); }
}
