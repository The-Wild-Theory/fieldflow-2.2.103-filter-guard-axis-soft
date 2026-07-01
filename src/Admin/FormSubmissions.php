<?php
namespace RoutesPro\Admin;
use RoutesPro\Forms\Forms as FormsModule;
use RoutesPro\Forms\ContextQuestions as ContextQuestionService;
if (!defined('ABSPATH')) exit;
class FormSubmissions {
    public static function register_hooks() {
        add_action('admin_post_routespro_save_submission', [self::class, 'handle_save']);
        add_action('admin_post_routespro_delete_submission', [self::class, 'handle_delete']);
        add_action('admin_post_routespro_export_submissions', [self::class, 'handle_export']);
        add_action('admin_post_routespro_export_dashboard', [self::class, 'handle_dashboard_export']);
    }

    public static function render() {
        if (!current_user_can('routespro_manage')) wp_die('Sem permissões.');
        global $wpdb;

        $filters = [
            'client_id' => absint($_GET['client_id'] ?? 0),
            'project_id' => absint($_GET['project_id'] ?? 0),
            'owner_user_id' => absint($_GET['owner_user_id'] ?? 0),
            'route_id' => absint($_GET['route_id'] ?? 0),
            'location_id' => absint($_GET['location_id'] ?? 0),
            'date_from' => sanitize_text_field($_GET['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_GET['date_to'] ?? ''),
        ];

        $dataset = self::get_submission_dataset($filters + ['limit' => 300]);
        $rows = $dataset['rows'];
        $dynamic_columns = $dataset['columns'];

        $clients = $wpdb->get_results('SELECT id, name FROM ' . $wpdb->prefix . 'routespro_clients ORDER BY name ASC', ARRAY_A) ?: [];
        $projects = $wpdb->get_results('SELECT id, name FROM ' . $wpdb->prefix . 'routespro_projects ORDER BY name ASC', ARRAY_A) ?: [];
        $owners = $wpdb->get_results('SELECT DISTINCT u.ID AS id, u.display_name AS name FROM ' . $wpdb->prefix . 'routespro_routes r INNER JOIN ' . $wpdb->users . ' u ON u.ID = r.owner_user_id WHERE r.owner_user_id IS NOT NULL ORDER BY u.display_name ASC', ARRAY_A) ?: [];

        echo '<div class="wrap">';
        Branding::render_header('Submissões', 'Agora já com contexto operacional na grelha, filtros por cliente, campanha e owner, e sem menus duplicados no backoffice.');
        if (isset($_GET['saved'])) echo '<div class="notice notice-success"><p>Submissão atualizada.</p></div>';
        if (isset($_GET['deleted'])) echo '<div class="notice notice-success"><p>Submissão apagada.</p></div>';

        self::render_filters($filters, $clients, $projects, $owners);
        self::render_export_actions($filters);

        echo '<div class="routespro-table-scroll" style="overflow:auto;-webkit-overflow-scrolling:touch;margin-top:14px">';
        echo '<table class="widefat striped routespro-wide-table" style="min-width:1200px"><thead><tr><th>ID</th><th>Formulário</th><th>Cliente</th><th>Campanha / Projeto</th><th>Rota</th><th>Paragem</th><th>Owner</th><th>Submetido por</th><th>Data</th><th>Status</th>';
        foreach ($dynamic_columns as $col) echo '<th>' . esc_html($col['label']) . '</th>';
        echo '<th>Ações</th></tr></thead><tbody>';
        if(!$rows){
            echo '<tr><td colspan="' . (11 + count($dynamic_columns)) . '">Ainda não existem submissões para estes filtros.</td></tr>';
        } else {
            foreach($rows as $row){
                $answers = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . FormsModule::table_answers() . ' WHERE submission_id=%d ORDER BY id ASC', (int)$row['id']), ARRAY_A) ?: [];
                $edit = admin_url('admin.php?page=routespro-form-submission-edit&id=' . (int)$row['id']);
                $del = wp_nonce_url(admin_url('admin-post.php?action=routespro_delete_submission&id=' . (int)$row['id']), 'routespro_delete_submission_' . (int)$row['id']);
                echo '<tr>';
                echo '<td>'.(int)$row['id'].'</td>';
                echo '<td>'.esc_html($row['form_title'] ?: ('#'.(int)$row['form_id'])).'</td>';
                echo '<td>'.esc_html($row['client_name'] ?: 'Sem cliente').'</td>';
                echo '<td>'.esc_html($row['project_name'] ?: 'Sem campanha').'</td>';
                echo '<td>' . wp_kses_post(self::render_route_cell($row)) . '</td>';
                echo '<td>' . wp_kses_post(self::render_stop_cell($row)) . '</td>';
                echo '<td>'.esc_html($row['owner_name'] ?: 'Sem owner').'</td>';
                echo '<td>'.esc_html($row['user_name'] ?: ('User #'.(int)$row['user_id'])).'</td>';
                echo '<td>'.esc_html($row['submitted_at']).'</td>';
                echo '<td>'.esc_html($row['status']).'</td>';
                foreach ($dynamic_columns as $col) {
                    $cell = $row['answers'][$col['key']] ?? '';
                    echo '<td style="white-space:nowrap">' . esc_html($cell !== '' ? $cell : '—') . '</td>';
                }
                echo '<td><a class="button button-small" href="'.esc_url($edit).'">Editar</a> <a class="button button-small" href="'.esc_url($del).'" onclick="return confirm(\'Apagar submissão?\')">Apagar</a></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div></div>';
    }


    public static function get_submission_dataset(array $filters = []) : array {
        global $wpdb;
        $filters = wp_parse_args($filters, [
            'client_id' => 0,
            'project_id' => 0,
            'owner_user_id' => 0,
            'route_id' => 0,
            'location_id' => 0,
            'date_from' => '',
            'date_to' => '',
            'limit' => 300,
        ]);

        $sql = 'SELECT s.*, 
            f.title AS form_title,
            submitter.display_name AS user_name,
            c.name AS client_name,
            p.name AS project_name,
            r.id AS joined_route_id,
            r.date AS route_date,
            r.status AS route_status,
            r.owner_user_id AS joined_owner_user_id,
            owner.display_name AS owner_name,
            rs.id AS stop_row_id,
            rs.seq AS stop_seq,
            rs.status AS stop_status,
            rs.planned_arrival,
            rs.planned_departure,
            rs.arrived_at,
            rs.departed_at,
            rs.duration_s,
            rs.note AS stop_note,
            rs.fail_reason,
            rs.real_lat,
            rs.real_lng,
            rs.qty,
            rs.weight,
            rs.volume,
            loc.name AS location_name
        FROM ' . FormsModule::table_submissions() . ' s
        LEFT JOIN ' . FormsModule::table() . ' f ON f.id = s.form_id
        LEFT JOIN ' . $wpdb->users . ' submitter ON submitter.ID = s.user_id
        LEFT JOIN ' . $wpdb->prefix . 'routespro_clients c ON c.id = s.client_id
        LEFT JOIN ' . $wpdb->prefix . 'routespro_projects p ON p.id = s.project_id
        LEFT JOIN ' . $wpdb->prefix . 'routespro_routes r ON r.id = s.route_id
        LEFT JOIN ' . $wpdb->users . ' owner ON owner.ID = r.owner_user_id
        LEFT JOIN ' . $wpdb->prefix . 'routespro_route_stops rs ON rs.id = s.route_stop_id
        LEFT JOIN ' . $wpdb->prefix . 'routespro_locations loc ON loc.id = rs.location_id
        WHERE 1=1';
        $params = [];
        if (!empty($filters['client_id'])) { $sql .= ' AND s.client_id = %d'; $params[] = (int) $filters['client_id']; }
        if (!empty($filters['project_id'])) { $sql .= ' AND s.project_id = %d'; $params[] = (int) $filters['project_id']; }
        if (!empty($filters['owner_user_id'])) { $sql .= ' AND r.owner_user_id = %d'; $params[] = (int) $filters['owner_user_id']; }
        if (!empty($filters['route_id'])) { $sql .= ' AND s.route_id = %d'; $params[] = (int) $filters['route_id']; }
        if (!empty($filters['location_id'])) { $sql .= ' AND COALESCE(s.location_id, rs.location_id) = %d'; $params[] = (int) $filters['location_id']; }
        if (!empty($filters['date_from'])) { $sql .= ' AND DATE(s.submitted_at) >= %s'; $params[] = $filters['date_from']; }
        if (!empty($filters['date_to'])) { $sql .= ' AND DATE(s.submitted_at) <= %s'; $params[] = $filters['date_to']; }
        $sql .= ' ORDER BY s.submitted_at DESC, s.id DESC';
        if (!empty($filters['limit'])) { $sql .= ' LIMIT ' . max(1, (int) $filters['limit']); }
        if ($params) $sql = $wpdb->prepare($sql, $params);
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        $columns = [];
        $seen = [];
        foreach ($rows as &$row) {
            $answers = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . FormsModule::table_answers() . ' WHERE submission_id=%d ORDER BY id ASC', (int)$row['id']), ARRAY_A) ?: [];
            $row['answers'] = [];
            foreach ($answers as $a) {
                $key = sanitize_key($a['question_key'] ?: ('q_' . $a['id']));
                $label = (string) ($a['question_label'] ?: $a['question_key']);
                $matrix_rows = self::product_matrix_rows_from_answer($a);
                if ($matrix_rows !== null) {
                    $summary_parts = [];
                    foreach ($matrix_rows as $mrow) {
                        $ref = trim((string)($mrow['ref'] ?? ''));
                        $name = trim((string)($mrow['name'] ?? ''));
                        $qty = $mrow['qty'] ?? '';
                        $before = $mrow['before'] ?? null;
                        $after = $mrow['after'] ?? null;
                        $prod_label = trim(($ref ? $ref . ' · ' : '') . ($name ?: 'Produto'));
                        if ($before !== null || $after !== null) {
                            $before_key = sanitize_key($key . '_' . md5($prod_label . '_before'));
                            $after_key = sanitize_key($key . '_' . md5($prod_label . '_after'));
                            if (!isset($seen[$before_key])) { $seen[$before_key] = true; $columns[] = ['key' => $before_key, 'label' => $label . ' | ' . $prod_label . ' | Antes', 'parent_key' => $key, 'product' => $prod_label, 'period' => 'before']; }
                            if (!isset($seen[$after_key])) { $seen[$after_key] = true; $columns[] = ['key' => $after_key, 'label' => $label . ' | ' . $prod_label . ' | Depois', 'parent_key' => $key, 'product' => $prod_label, 'period' => 'after']; }
                            $row['answers'][$before_key] = (string)($before ?? '');
                            $row['answers'][$after_key] = (string)($after ?? $qty);
                        } else {
                            $col_key = sanitize_key($key . '_' . md5($prod_label));
                            if (!isset($seen[$col_key])) {
                                $seen[$col_key] = true;
                                $columns[] = ['key' => $col_key, 'label' => $label . ' | ' . $prod_label, 'parent_key' => $key, 'product' => $prod_label];
                            }
                            $row['answers'][$col_key] = (string)$qty;
                        }
                    }
                    // Product matrix answers are expanded into one column per product.
                    // Do not add the old aggregated "Resumo" column to the tabular dataset,
                    // because it makes BO, client portal and exports unnecessarily wide.
                    continue;
                }
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $columns[] = ['key' => $key, 'label' => $label];
                }
                $row['answers'][$key] = self::answer_to_string($a);
            }
        }
        unset($row);
        $columns = self::append_context_question_columns($columns, $seen, $filters, $rows);
        return ['rows' => $rows, 'columns' => $columns];
    }

    private static function append_context_question_columns(array $columns, array $seen, array $filters, array $rows): array {
        global $wpdb;
        $table = ContextQuestionService::table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return $columns;

        $clientIds = [];
        $projectIds = [];
        $locationIds = [];
        $formIds = [];
        foreach ($rows as $row) {
            foreach ([['client_id', &$clientIds], ['project_id', &$projectIds], ['location_id', &$locationIds], ['form_id', &$formIds]] as $pair) {
                $value = absint($row[$pair[0]] ?? 0);
                if ($value > 0) $pair[1][$value] = $value;
            }
            $joinedLocation = absint($row['location_id'] ?? $row['stop_row_id'] ?? 0);
            if ($joinedLocation > 0) $locationIds[$joinedLocation] = $joinedLocation;
        }
        foreach (['client_id' => &$clientIds, 'project_id' => &$projectIds, 'location_id' => &$locationIds] as $field => &$bucket) {
            $value = absint($filters[$field] ?? 0);
            if ($value > 0) $bucket[$value] = $value;
        }
        unset($bucket);

        $where = ['is_active = 1'];
        $args = [];
        foreach (['client_id' => $clientIds, 'project_id' => $projectIds, 'location_id' => $locationIds, 'form_id' => $formIds] as $field => $ids) {
            $ids = array_values(array_filter(array_map('absint', (array)$ids)));
            if ($ids) {
                $where[] = '(' . $field . '=0 OR ' . $field . ' IN (' . implode(',', array_fill(0, count($ids), '%d')) . '))';
                $args = array_merge($args, $ids);
            } else {
                if (in_array($field, ['client_id', 'project_id', 'location_id'], true) && !empty($filters[$field])) continue;
            }
        }

        $sql = 'SELECT question_key, question_label, context_type FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY priority ASC, id ASC LIMIT 500';
        $questions = $args ? ($wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: []) : ($wpdb->get_results($sql, ARRAY_A) ?: []);
        foreach ($questions as $q) {
            $key = sanitize_key($q['question_key'] ?? '');
            if (!$key || isset($seen[$key])) continue;
            $seen[$key] = true;
            $label = (string)($q['question_label'] ?? $key);
            $columns[] = ['key' => $key, 'label' => $label];
        }
        return $columns;
    }

    private static function render_export_actions(array $filters) {
        $base = admin_url('admin-post.php?action=routespro_export_submissions');
        $query = [];
        foreach (['client_id','project_id','owner_user_id','route_id','location_id','date_from','date_to'] as $k) {
            if (!empty($filters[$k])) $query[$k] = $filters[$k];
        }
        $csv = wp_nonce_url(add_query_arg($query + ['format' => 'csv'], $base), 'routespro_export_submissions');
        $xls = wp_nonce_url(add_query_arg($query + ['format' => 'xls'], $base), 'routespro_export_submissions');
        $pdf = wp_nonce_url(add_query_arg($query + ['format' => 'pdf'], $base), 'routespro_export_submissions');
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px">';
        echo '<a class="button button-primary" href="' . esc_url($csv) . '">Exportar CSV</a>';
        echo '<a class="button" href="' . esc_url($xls) . '">Exportar Excel</a>';
        echo '<a class="button" href="' . esc_url($pdf) . '">Exportar PDF</a>';
        echo '<span style="color:#64748b">Exporta o mesmo dataset visível na grelha, incluindo contexto da rota, colunas dinâmicas das perguntas e, no PDF, um relatório com cabeçalho TWT e filtros aplicados.</span>';
        echo '</div>';
    }


    public static function handle_dashboard_export() {
        if (!is_user_logged_in()) wp_die('Sem permissões.');
        if (!current_user_can('routespro_manage') && !\RoutesPro\Support\Permissions::can_access_front()) wp_die('Sem permissões.');
        $nonce_ok = false;
        if (isset($_REQUEST['_wpnonce'])) {
            $nonce_ok = wp_verify_nonce((string) $_REQUEST['_wpnonce'], 'routespro_export_dashboard');
        }
        if (!$nonce_ok) wp_die('Pedido inválido.');

        $format = sanitize_key($_GET['format'] ?? 'csv');
        if ($format === 'excel') $format = 'xls';
        $from = sanitize_text_field($_GET['from'] ?? date('Y-m-d', strtotime('-6 days')));
        $to = sanitize_text_field($_GET['to'] ?? date('Y-m-d'));
        $client_id = absint($_GET['client_id'] ?? 0);
        $project_id = absint($_GET['project_id'] ?? 0);
        $user_id = absint($_GET['user_id'] ?? 0);
        $role = sanitize_key($_GET['role'] ?? '');

        $req = new \WP_REST_Request('GET', '/routespro/v1/stats');
        foreach ([
            'from' => $from,
            'to' => $to,
            'client_id' => $client_id,
            'project_id' => $project_id,
            'user_id' => $user_id,
            'role' => $role,
        ] as $key => $value) {
            if ($value !== '' && $value !== 0) $req->set_param($key, $value);
        }
        $controller = new \RoutesPro\Rest\StatsController();
        $response = $controller->stats($req);
        if (is_wp_error($response)) wp_die(esc_html($response->get_error_message()));
        $data = $response instanceof \WP_REST_Response ? $response->get_data() : [];
        if (!is_array($data)) $data = [];

        $headers = ['Data','Cliente','Projeto','Funcionário','Rota','Status','Paragens','Concluídas','% Done'];
        $rows = [];
        foreach ((array)($data['by_day'] ?? []) as $row) {
            $rows[] = [
                (string)($row['date'] ?? ''),
                (string)($row['client_name'] ?? ''),
                (string)($row['project_name'] ?? ''),
                (string)($row['user_name'] ?? ''),
                '#' . (string)($row['route_id'] ?? ''),
                (string)($row['route_status'] ?? ''),
                (string)($row['stops'] ?? '0'),
                (string)($row['stops_done'] ?? '0'),
                isset($row['done_rate']) ? ((string)$row['done_rate'] . '%') : '',
            ];
        }
        $summary = [
            ['Métrica','Valor'],
            ['Rotas', (string)($data['total_routes'] ?? 0)],
            ['Concluídas', (string)($data['completed_routes'] ?? 0)],
            ['% Rotas concluídas', (string)($data['completion_rate'] ?? 0) . '%'],
            ['Paragens', (string)($data['total_stops'] ?? 0)],
            ['Paragens concluídas', (string)($data['done_stops'] ?? 0)],
            ['% Paragens concluídas', (string)($data['done_rate'] ?? 0) . '%'],
            ['Média paragens/rota', (string)($data['avg_stops_per_route'] ?? 0)],
            ['Período', $from . ' até ' . $to],
        ];
        $analytics_export_rows = [];
        $fa = is_array($data['form_analytics'] ?? null) ? $data['form_analytics'] : [];
        $analytics_export_rows[] = [];
        $analytics_export_rows[] = ['Dashboard','Métrica','Grupo lojas','Valor','Registos','Estado'];
        foreach ((array)($fa['metrics'] ?? []) as $metric) {
            $analytics_export_rows[] = [
                (string)($metric['dashboard_title'] ?? 'Analytics operacional'),
                (string)($metric['metric_label'] ?? $metric['metric_key'] ?? ''),
                (string)($metric['store_group_name'] ?? ''),
                $metric['value'] === null ? 'Sem dados' : (string)$metric['value'],
                (string)($metric['record_count'] ?? 0),
                (string)($metric['note'] ?? ''),
            ];
            foreach (array_slice((array)($metric['rows'] ?? []), 0, 200) as $mrow) {
                $analytics_export_rows[] = [
                    '',
                    'Loja: ' . (string)($mrow['location'] ?? ''),
                    '',
                    (string)($mrow['value'] ?? ''),
                    (string)($mrow['date'] ?? ''),
                    (string)($mrow['status'] ?? ''),
                ];
            }
        }
        if (count($analytics_export_rows) === 2) {
            $analytics_export_rows[] = ['Sem dashboards analíticos configurados para este âmbito.','','','','',''];
        }
        $export_rows = array_merge($summary, $analytics_export_rows, [[]], [$headers], $rows);
        $filename = 'fieldflow-analytics-' . gmdate('Ymd-His');
        if ($format === 'xls') {
            self::output_excel_xml($filename . '.xls', [], $export_rows);
        }
        if ($format === 'pdf') {
            self::output_dashboard_pdf($filename . '.pdf', $data, [
                'from' => $from,
                'to' => $to,
                'client_id' => $client_id,
                'project_id' => $project_id,
                'user_id' => $user_id,
                'role' => $role,
            ]);
        }
        self::output_csv($filename . '.csv', [], $export_rows);
    }

    private static function output_dashboard_pdf(string $filename, array $data, array $filters): void {
        global $wpdb;
        $client_id = (int)($filters['client_id'] ?? 0);
        $project_id = (int)($filters['project_id'] ?? 0);
        $client_name = 'Global';
        $project_name = 'Global';
        if ($client_id) $client_name = (string)($wpdb->get_var($wpdb->prepare('SELECT name FROM ' . $wpdb->prefix . 'routespro_clients WHERE id=%d', $client_id)) ?: ('#' . $client_id));
        if ($project_id) $project_name = (string)($wpdb->get_var($wpdb->prepare('SELECT name FROM ' . $wpdb->prefix . 'routespro_projects WHERE id=%d', $project_id)) ?: ('#' . $project_id));

        $blocks = [];
        $blocks[] = ['type' => 'summary', 'lines' => [
            'Resumo executivo de analytics',
            'Período: ' . (($filters['from'] ?? '') ?: '...') . ' até ' . (($filters['to'] ?? '') ?: '...'),
            'Cliente: ' . $client_name,
            'Campanha / Projeto: ' . $project_name,
            'Função: ' . (($filters['role'] ?? '') ?: 'Todas'),
            'Funcionário: ' . (!empty($filters['user_id']) ? ('#' . (int)$filters['user_id']) : 'Todos'),
        ]];
        $blocks[] = ['type' => 'summary', 'lines' => [
            'KPIs',
            'Rotas: ' . (string)($data['total_routes'] ?? 0),
            'Concluídas: ' . (string)($data['completed_routes'] ?? 0),
            '% Rotas concluídas: ' . (string)($data['completion_rate'] ?? 0) . '%',
            'Paragens: ' . (string)($data['total_stops'] ?? 0),
            'Paragens concluídas: ' . (string)($data['done_stops'] ?? 0),
            '% Paragens concluídas: ' . (string)($data['done_rate'] ?? 0) . '%',
            'Média paragens/rota: ' . (string)($data['avg_stops_per_route'] ?? 0),
        ]];
        $fa = is_array($data['form_analytics'] ?? null) ? $data['form_analytics'] : [];
        if (!empty($fa['dashboards']) || !empty($fa['metrics'])) {
            $blocks[] = ['type' => 'summary', 'lines' => ['Dashboards analíticos configurados']];
            foreach (array_slice((array)($fa['metrics'] ?? []), 0, 80) as $metric) {
                $value = ($metric['value'] ?? null) === null ? 'Sem dados' : (string)$metric['value'];
                $blocks[] = ['type' => 'route', 'lines' => [
                    (string)($metric['dashboard_title'] ?? 'Analytics operacional') . ' | ' . (string)($metric['metric_label'] ?? ''),
                    'Valor: ' . $value . ' | Registos: ' . (string)($metric['record_count'] ?? 0),
                    'Agregação: ' . (string)($metric['aggregation'] ?? '') . ' | Dimensão: ' . (string)($metric['dimension'] ?? ''),
                    'Grupo lojas: ' . ((string)($metric['store_group_name'] ?? '') ?: 'Todas as lojas do âmbito'),
                    'Estado: ' . (string)($metric['note'] ?? ''),
                ]];
            }
        }

        foreach (array_slice((array)($data['by_day'] ?? []), 0, 120) as $row) {
            $blocks[] = ['type' => 'route', 'lines' => [
                'Rota #' . (string)($row['route_id'] ?? '') . ' | ' . (string)($row['date'] ?? ''),
                'Cliente: ' . (string)($row['client_name'] ?? ''),
                'Projeto: ' . (string)($row['project_name'] ?? ''),
                'Funcionário: ' . (string)($row['user_name'] ?? ''),
                'Status: ' . (string)($row['route_status'] ?? ''),
                'Paragens: ' . (string)($row['stops'] ?? 0) . ' | Concluídas: ' . (string)($row['stops_done'] ?? 0) . ' | Done: ' . (string)($row['done_rate'] ?? '') . '%',
            ]];
        }

        $logo_paths = [];
        $tmp_logo_files = [];
        foreach (Branding::get_header_logos(['client_id' => $client_id, 'project_id' => $project_id]) as $logo) {
            $prepared = Branding::maybe_prepare_pdf_logo_file((string)($logo['url'] ?? ''));
            if ($prepared) {
                $logo_paths[] = $prepared;
                if (strpos($prepared, sys_get_temp_dir()) === 0) $tmp_logo_files[] = $prepared;
            }
        }
        if (!$logo_paths) $logo_paths[] = ROUTESPRO_PATH . 'assets/logo-twt.jpg';
        $pdf = self::build_simple_pdf([], [
            'title' => 'Analytics operacional',
            'subtitle' => 'FieldFlow Pro',
            'logo_paths' => $logo_paths,
            'summary' => [
                'Cliente' => $client_name,
                'Campanha' => $project_name,
                'Rotas' => (string)($data['total_routes'] ?? 0),
                'Exportado' => wp_date('d/m/Y H:i'),
            ],
            'blocks' => $blocks,
        ]);
        foreach ($tmp_logo_files as $tmp_file) if (is_string($tmp_file) && file_exists($tmp_file)) @unlink($tmp_file);
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    public static function handle_export() {
        if (!(current_user_can('routespro_manage') || \RoutesPro\Support\Permissions::can_access_front())) wp_die('Sem permissões.');
        $nonce_ok = false;
        if (!empty($_REQUEST['_wpnonce'])) {
            $nonce_ok = wp_verify_nonce((string) $_REQUEST['_wpnonce'], 'routespro_export_submissions');
        }
        if (!$nonce_ok && is_admin()) {
            check_admin_referer('routespro_export_submissions');
        }
        $filters = [
            'client_id' => absint($_GET['client_id'] ?? 0),
            'project_id' => absint($_GET['project_id'] ?? 0),
            'owner_user_id' => absint($_GET['owner_user_id'] ?? 0),
            'route_id' => absint($_GET['route_id'] ?? 0),
            'location_id' => absint($_GET['location_id'] ?? 0),
            'date_from' => sanitize_text_field($_GET['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_GET['date_to'] ?? ''),
            'limit' => 2000,
        ];
        $format = sanitize_key($_GET['format'] ?? 'csv');
        $dataset = self::get_submission_dataset($filters);
        $headers = ['ID','Formulário','Cliente','Campanha / Projeto','Rota','Paragem','Owner ID','Owner','Submetido por','Data','Status','Check-in','Check-out','Duração visita (min)','Estado paragem','Nota operacional','Motivo falha','GPS real','Qtd','Peso','Volume'];
        foreach ($dataset['columns'] as $col) $headers[] = $col['label'];
        $table_rows = [];
        foreach ($dataset['rows'] as $row) {
            $line = [
                (int) $row['id'],
                (string) ($row['form_title'] ?: ('#' . (int) $row['form_id'])),
                (string) ($row['client_name'] ?: ''),
                (string) ($row['project_name'] ?: ''),
                wp_strip_all_tags(self::render_route_cell($row)),
                wp_strip_all_tags(self::render_stop_cell($row)),
                (string) ((int)($row['joined_owner_user_id'] ?? $row['owner_user_id'] ?? 0)),
                (string) ($row['owner_name'] ?: ''),
                (string) ($row['user_name'] ?: ('User #' . (int) $row['user_id'])),
                (string) ($row['submitted_at'] ?: ''),
                (string) ($row['status'] ?: ''),
                (string) ($row['arrived_at'] ?: ''),
                (string) ($row['departed_at'] ?: ''),
                (string) self::format_duration_minutes($row['duration_s'] ?? null),
                (string) ($row['stop_status'] ?: ''),
                (string) ($row['stop_note'] ?: ''),
                (string) ($row['fail_reason'] ?: ''),
                (string) self::format_real_gps($row),
                (string) ($row['qty'] ?? ''),
                (string) ($row['weight'] ?? ''),
                (string) ($row['volume'] ?? ''),
            ];
            foreach ($dataset['columns'] as $col) $line[] = (string) ($row['answers'][$col['key']] ?? '');
            $table_rows[] = $line;
        }
        $filename = 'routespro-submissoes-' . gmdate('Ymd-His');
        if ($format === 'xls') {
            self::output_excel_xml($filename . '.xls', $headers, $table_rows);
        }
        if ($format === 'pdf') {
            self::output_pdf_report($filename . '.pdf', $filters, $dataset);
        }
        self::output_csv($filename . '.csv', $headers, $table_rows);
    }

    private static function output_csv(string $filename, array $headers, array $rows) {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, array_map([self::class, 'normalize_export_text'], $headers));
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'normalize_export_text'], $row));
        }
        fclose($out);
        exit;
    }

    private static function output_excel_xml(string $filename, array $headers, array $rows) {
        nocache_headers();
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Submissoes"><Table>';
        echo '<Row>';
        foreach ($headers as $cell) echo '<Cell><Data ss:Type="String">' . self::xml_escape($cell) . '</Data></Cell>';
        echo '</Row>';
        foreach ($rows as $row) {
            echo '<Row>';
            foreach ($row as $cell) echo '<Cell><Data ss:Type="String">' . self::xml_escape((string) $cell) . '</Data></Cell>';
            echo '</Row>';
        }
        echo '</Table></Worksheet></Workbook>';
        exit;
    }


    private static function output_pdf_report(string $filename, array $filters, array $dataset) {
        $filter_lines = self::build_filter_lines($filters);
        $project_id = (int)($filters['project_id'] ?? 0);
        $client_id = (int)($filters['client_id'] ?? 0);
        if (!$client_id && !empty($dataset['rows'][0]['client_id'])) $client_id = (int)$dataset['rows'][0]['client_id'];
        if (!$project_id && !empty($dataset['rows'][0]['project_id'])) $project_id = (int)$dataset['rows'][0]['project_id'];

        $content_blocks = [];
        $content_blocks[] = [
            'type' => 'summary',
            'lines' => [
                'Resumo executivo',
                'Gerado em: ' . wp_date('d/m/Y H:i'),
                'Total de submissões: ' . count($dataset['rows']),
                'Total de colunas dinâmicas: ' . count((array)$dataset['columns']),
            ],
        ];
        $filter_block = ['Filtros aplicados:'];
        foreach ($filter_lines as $line) $filter_block[] = '- ' . $line;
        $content_blocks[] = ['type' => 'filters', 'lines' => $filter_block];

        foreach ($dataset['rows'] as $row) {
            $submission_lines = [];
            $submission_lines[] = 'Submissão #' . (int) $row['id'] . ' | ' . ($row['submitted_at'] ?: 'Sem data');
            $submission_lines[] = 'Formulário: ' . ($row['form_title'] ?: ('#' . (int) $row['form_id']));
            $submission_lines[] = 'Cliente: ' . ($row['client_name'] ?: 'Sem cliente');
            $submission_lines[] = 'Campanha / Projeto: ' . ($row['project_name'] ?: 'Sem campanha');
            $submission_lines[] = 'Rota: ' . trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags(self::render_route_cell($row))));
            $submission_lines[] = 'Paragem: ' . trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags(self::render_stop_cell($row))));
            $submission_lines[] = 'Owner ID: ' . ((int)($row['joined_owner_user_id'] ?? $row['owner_user_id'] ?? 0));
            $submission_lines[] = 'Owner: ' . ($row['owner_name'] ?: 'Sem owner');
            $submission_lines[] = 'Submetido por: ' . ($row['user_name'] ?: ('User #' . (int) $row['user_id']));
            $submission_lines[] = 'Status: ' . ($row['status'] ?: '');
            $submission_lines[] = 'Check-in: ' . ($row['arrived_at'] ?: '--');
            $submission_lines[] = 'Check-out: ' . ($row['departed_at'] ?: '--');
            $submission_lines[] = 'Duração visita: ' . self::format_duration_minutes($row['duration_s'] ?? null);
            $submission_lines[] = 'Estado paragem: ' . ($row['stop_status'] ?: '--');
            $submission_lines[] = 'Nota operacional: ' . ($row['stop_note'] ?: '--');
            $submission_lines[] = 'Motivo falha: ' . ($row['fail_reason'] ?: '--');
            $submission_lines[] = 'GPS real: ' . self::format_real_gps($row);
            if (!empty($dataset['columns'])) {
                $submission_lines[] = 'Respostas:';
                foreach ($dataset['columns'] as $col) {
                    $value = trim((string) ($row['answers'][$col['key']] ?? ''));
                    if ($value === '') $value = '--';
                    $submission_lines[] = '- ' . $col['label'] . ': ' . $value;
                }
            }
            $content_blocks[] = ['type' => 'submission', 'lines' => $submission_lines];
        }

        $logo_paths = [];
        $tmp_logo_files = [];
        foreach (Branding::get_header_logos(['client_id' => $client_id, 'project_id' => $project_id]) as $logo) {
            $prepared = Branding::maybe_prepare_pdf_logo_file((string)($logo['url'] ?? ''));
            if ($prepared) {
                $logo_paths[] = $prepared;
                if (strpos($prepared, sys_get_temp_dir()) === 0) $tmp_logo_files[] = $prepared;
            }
        }
        if (!$logo_paths) $logo_paths[] = ROUTESPRO_PATH . 'assets/logo-twt.jpg';

        $pdf = self::build_simple_pdf([], [
            'title' => 'Relatório premium de submissões',
            'subtitle' => 'FieldFlow Pro',
            'logo_paths' => $logo_paths,
            'summary' => [
                'Cliente' => $dataset['rows'][0]['client_name'] ?? ($client_id ? ('#' . $client_id) : 'Global'),
                'Campanha' => $dataset['rows'][0]['project_name'] ?? ($project_id ? ('#' . $project_id) : 'Global'),
                'Registos' => (string)count($dataset['rows']),
                'Exportado' => wp_date('d/m/Y H:i'),
            ],
            'blocks' => $content_blocks,
        ]);

        foreach ($tmp_logo_files as $tmp_file) if (is_string($tmp_file) && file_exists($tmp_file)) @unlink($tmp_file);

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }


    private static function format_duration_minutes($seconds): string {
        if ($seconds === null || $seconds === '' || !is_numeric($seconds)) return '--';
        $minutes = round(((float)$seconds) / 60, 1);
        if (abs($minutes - round($minutes)) < 0.01) return (string)((int)round($minutes));
        return number_format($minutes, 1, '.', '');
    }

    private static function format_real_gps(array $row): string {
        $lat = isset($row['real_lat']) && $row['real_lat'] !== '' ? (string)$row['real_lat'] : '';
        $lng = isset($row['real_lng']) && $row['real_lng'] !== '' ? (string)$row['real_lng'] : '';
        if ($lat === '' && $lng === '') return '--';
        return trim($lat . ', ' . $lng, ', ');
    }

    private static function build_filter_lines(array $filters): array {
        global $wpdb;
        $lines = [];
        if (!empty($filters['client_id'])) {
            $name = $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . $wpdb->prefix . 'routespro_clients WHERE id=%d', (int) $filters['client_id']));
            $lines[] = 'Cliente: ' . ($name ?: ('#' . (int) $filters['client_id']));
        }
        if (!empty($filters['project_id'])) {
            $name = $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . $wpdb->prefix . 'routespro_projects WHERE id=%d', (int) $filters['project_id']));
            $lines[] = 'Campanha / Projeto: ' . ($name ?: ('#' . (int) $filters['project_id']));
        }
        if (!empty($filters['owner_user_id'])) {
            $user = get_userdata((int) $filters['owner_user_id']);
            $lines[] = 'Owner: ' . ($user ? $user->display_name : ('#' . (int) $filters['owner_user_id']));
        }
        if (!empty($filters['route_id'])) {
            $route = $wpdb->get_row($wpdb->prepare('SELECT id, date, status FROM ' . $wpdb->prefix . 'routespro_routes WHERE id=%d', (int) $filters['route_id']), ARRAY_A);
            $lines[] = 'Rota: ' . ($route ? ('#' . (int) $route['id'] . ' | ' . ($route['date'] ?: 'Sem data') . ' | ' . ($route['status'] ?: '')) : ('#' . (int) $filters['route_id']));
        }
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $lines[] = 'Periodo: ' . (!empty($filters['date_from']) ? $filters['date_from'] : '...') . ' ate ' . (!empty($filters['date_to']) ? $filters['date_to'] : '...');
        }
        if (empty($lines)) $lines[] = 'Sem filtros específicos, relatório global dentro do âmbito visível.';
        return $lines;
    }

    private static function build_simple_pdf(array $lines, array $options = []): string {
        $title = (string) ($options['title'] ?? 'Relatório');
        $subtitle = (string) ($options['subtitle'] ?? 'FieldFlow Pro');
        $summary = is_array($options['summary'] ?? null) ? $options['summary'] : [];
        $logo_paths = [];
        if (!empty($options['logo_paths']) && is_array($options['logo_paths'])) $logo_paths = array_values(array_filter(array_map('strval', $options['logo_paths'])));
        elseif (!empty($options['logo_path'])) $logo_paths = [(string)$options['logo_path']];

        $max_chars = 102;
        $blocks = is_array($options['blocks'] ?? null) ? $options['blocks'] : [];
        if (!$blocks) {
            foreach ($lines as $line) {
                $blocks[] = ['type' => 'text', 'lines' => [$line]];
            }
        }

        $page_w = 841.89;
        $page_h = 595.28;
        $line_h = 12.0;
        $first_page_start = 372;
        $other_page_start = 438;
        $bottom_margin = 40;
        $pages = [];
        $current = [];
        $y = $first_page_start;

        foreach ($blocks as $block) {
            $block_lines = [];
            foreach ((array)($block['lines'] ?? []) as $line) {
                $line = self::normalize_export_text($line, ['for_pdf' => false]);
                if ($line === '') {
                    $block_lines[] = '';
                    continue;
                }
                foreach (preg_split('/\n/', wordwrap($line, $max_chars, "\n", true)) as $chunk) {
                    $block_lines[] = $chunk;
                }
            }
            if (!$block_lines) continue;

            $block_height = count($block_lines) * $line_h + 8;
            if (($y - $block_height) < $bottom_margin && !empty($current)) {
                $pages[] = $current;
                $current = [];
                $y = $other_page_start;
            }

            foreach ($block_lines as $line) {
                if ($y < $bottom_margin) {
                    $pages[] = $current;
                    $current = [];
                    $y = $other_page_start;
                }
                $current[] = [$line, $y, (string)($block['type'] ?? 'text')];
                $y -= $line_h;
            }
            $y -= 8;
            if ($y < $bottom_margin && !empty($current)) {
                $pages[] = $current;
                $current = [];
                $y = $other_page_start;
            }
        }
        if ($current || empty($pages)) $pages[] = $current;

        $objects = [];
        $add = function ($content) use (&$objects) {
            $objects[] = $content;
            return count($objects);
        };

        $font_regular = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $font_bold = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');

        $image_objects = [];
        foreach ($logo_paths as $logo_path) {
            if (!$logo_path || !is_readable($logo_path)) continue;
            $img_data = @file_get_contents($logo_path);
            $img_info = @getimagesize($logo_path);
            if ($img_data === false || empty($img_info[0]) || empty($img_info[1])) continue;
            $filter = '/DCTDecode';
            if (($img_info['mime'] ?? '') === 'image/png') $filter = '/FlateDecode';
            $image_objects[] = [
                'id' => $add("<< /Type /XObject /Subtype /Image /Width {$img_info[0]} /Height {$img_info[1]} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter {$filter} /Length " . strlen($img_data) . " >>
stream
" . $img_data . "
endstream"),
                'w' => (int)$img_info[0],
                'h' => (int)$img_info[1],
            ];
        }

        $content_ids = [];
        $page_ids = [];
        foreach ($pages as $page_index => $page_lines) {
            $stream = '';
            $stream .= "0.949 0.957 0.976 rg 0 0 {$page_w} {$page_h} re f
";
            $stream .= "0.973 0.976 0.988 rg 24 454 794 116 re f
";
            $stream .= "0.85 0.88 0.93 RG 24 454 794 116 re S
";
            $stream .= "0.92 0.38 0.16 rg 24 454 6 116 re f
";

            $logo_x = 40;
            foreach ($image_objects as $idx => $img) {
                $draw_h = 30;
                $ratio = $img['w'] / max(1, $img['h']);
                $draw_w = min(110, max(58, $ratio * $draw_h));
                $box_w = $draw_w + 14;
                $stream .= sprintf("1 1 1 rg %.2f 478 %.2f 44 re f
", $logo_x, $box_w);
                $stream .= sprintf("0.85 0.88 0.93 RG %.2f 478 %.2f 44 re S
", $logo_x, $box_w);
                $stream .= 'q ' . number_format($draw_w, 2, '.', '') . ' 0 0 ' . number_format($draw_h, 2, '.', '') . ' ' . number_format($logo_x + 7, 2, '.', '') . ' 485 cm /Im' . ($idx + 1) . ' Do Q' . "
";
                $logo_x += $box_w + 12;
            }

            $stream .= "0.16 0.20 0.29 rg
";
            $stream .= 'BT /F2 21 Tf 40 554 Td (' . self::pdf_escape($title) . ') Tj ET' . "
";
            if ($subtitle !== '') {
                $stream .= "0.36 0.42 0.52 rg
";
                $stream .= 'BT /F1 10 Tf 40 537 Td (' . self::pdf_escape($subtitle) . ') Tj ET' . "
";
            }
            $stream .= "0.92 0.38 0.16 rg
";
            $stream .= 'BT /F2 11 Tf 690 548 Td (' . self::pdf_escape('FieldFlow Pro') . ') Tj ET' . "
";
            $stream .= "0.36 0.42 0.52 rg
";
            $stream .= 'BT /F1 9 Tf 690 534 Td (' . self::pdf_escape('Exportação PDF') . ') Tj ET' . "
";

            if ($page_index === 0 && $summary) {
                $card_x = [40, 236, 432, 628];
                $i = 0;
                foreach ($summary as $label => $value) {
                    if ($i >= 4) break;
                    $x = $card_x[$i];
                    $stream .= sprintf("1 1 1 rg %.2f 405 170 46 re f
", $x);
                    $stream .= sprintf("0.85 0.88 0.93 RG %.2f 405 170 46 re S
", $x);
                    $stream .= "0.36 0.42 0.52 rg
";
                    $stream .= 'BT /F1 8 Tf ' . ($x + 10) . ' 435 Td (' . self::pdf_escape((string)$label) . ') Tj ET' . "
";
                    $stream .= "0.16 0.20 0.29 rg
";
                    $stream .= 'BT /F2 10 Tf ' . ($x + 10) . ' 418 Td (' . self::pdf_escape((string)$value) . ') Tj ET' . "
";
                    $i++;
                }
            }

            foreach ($page_lines as $line_data) {
                [$line, $yy, $block_type] = array_pad($line_data, 3, 'text');
                $font = '/F1 9 Tf';
                $text_rgb = '0.23 0.27 0.34 rg';
                if (strpos($line, 'Submissão #') === 0 || $line === 'Filtros aplicados:' || $line === 'Respostas:' || $line === 'Resumo executivo') {
                    $font = '/F2 10 Tf';
                    $text_rgb = '0.16 0.20 0.29 rg';
                } elseif (preg_match('/^(Cliente|Campanha \/ Projeto|Rota|Paragem|Owner|Owner ID|Submetido por|Status|Check\-in|Check\-out|Duração visita|Estado paragem|Nota operacional|Motivo falha|GPS real|Formulário):/u', $line)) {
                    $text_rgb = '0.30 0.35 0.43 rg';
                } elseif (strpos($line, '- ') === 0) {
                    $text_rgb = '0.36 0.42 0.52 rg';
                }
                $stream .= $text_rgb . "
";
                $stream .= 'BT ' . $font . ' 40 ' . number_format($yy, 2, '.', '') . ' Td (' . self::pdf_escape($line) . ') Tj ET' . "
";
            }

            $stream .= "0.85 0.88 0.93 RG 24 22 794 0.7 re f
";
            $stream .= "0.36 0.42 0.52 rg
";
            $stream .= 'BT /F1 8 Tf 40 12 Td (' . self::pdf_escape('FieldFlow Pro | Relatório operacional') . ') Tj ET' . "
";
            $stream .= 'BT /F1 8 Tf 740 12 Td (' . self::pdf_escape('Página ' . ($page_index + 1)) . ') Tj ET' . "
";

            $content_ids[] = $add("<< /Length " . strlen($stream) . " >>
stream
" . $stream . "endstream");
            $page_ids[] = 0;
        }

        $pages_root_hint = count($objects) + count($pages) + 1;
        foreach ($pages as $i => $page_lines) {
            $resources = '<< /Font << /F1 ' . $font_regular . ' 0 R /F2 ' . $font_bold . ' 0 R >>';
            if ($image_objects) {
                $resources .= ' /XObject << ';
                foreach ($image_objects as $idx => $img) $resources .= '/Im' . ($idx + 1) . ' ' . $img['id'] . ' 0 R ';
                $resources .= '>>';
            }
            $resources .= ' >>';
            $page_ids[$i] = $add('<< /Type /Page /Parent ' . $pages_root_hint . ' 0 R /MediaBox [0 0 ' . $page_w . ' ' . $page_h . '] /Resources ' . $resources . ' /Contents ' . $content_ids[$i] . ' 0 R >>');
        }
        $kids = implode(' ', array_map(static function ($id) { return $id . ' 0 R'; }, $page_ids));
        $pages_id = $add('<< /Type /Pages /Kids [ ' . $kids . ' ] /Count ' . count($page_ids) . ' >>');
        $catalog_id = $add('<< /Type /Catalog /Pages ' . $pages_id . ' 0 R >>');

        $pdf = "%PDF-1.4
%âãÏÓ
";
        $offsets = [0];
        foreach ($objects as $idx => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($idx + 1) . " 0 obj
" . $obj . "
endobj
";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "
" . '0 ' . (count($objects) + 1) . "
";
        $pdf .= "0000000000 65535 f 
";
        for ($i = 1; $i <= count($objects); $i++) $pdf .= sprintf("%010d 00000 n 
", $offsets[$i]);
        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . ' /Root ' . $catalog_id . ' 0 R >>' . "
";
        $pdf .= 'startxref' . "
" . $xref . "
%%EOF";
        return $pdf;
    }

    private static function normalize_export_text($value, array $options = []): string {
        $text = is_scalar($value) || (is_object($value) && method_exists($value, '__toString')) ? (string) $value : '';
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x{00A0}\x{2007}\x{202F}]/u', ' ', $text) ?: $text;
        $text = preg_replace('/[\r\n\t]+/u', ' ', $text) ?: $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
        $text = trim($text);

        $map = [
            '–' => '-',
            '—' => '-',
            '−' => '-',
            '•' => '-',
            '·' => '-',
            '“' => '"',
            '”' => '"',
            '„' => '"',
            '’' => "'",
            '‘' => "'",
            '´' => "'",
            '…' => '...',
        ];
        $text = strtr($text, $map);

        if (!empty($options['for_pdf'])) {
            if (function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
                if ($converted !== false) return $converted;
            } elseif (function_exists('iconv')) {
                $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
                if ($converted !== false) return $converted;
            }
        }

        return $text;
    }

    private static function xml_escape($value): string {
        return htmlspecialchars(self::normalize_export_text($value), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function pdf_escape(string $text): string {
        $text = self::normalize_export_text($text, ['for_pdf' => true]);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    public static function render_edit() {
        if (!current_user_can('routespro_manage')) wp_die('Sem permissões.');
        global $wpdb;
        $id = absint($_GET['id'] ?? 0);
        $submission = $id ? $wpdb->get_row($wpdb->prepare('SELECT s.*, c.name AS client_name, p.name AS project_name, r.owner_user_id, r.date AS route_date, r.status AS route_status, rs.status AS stop_status, loc.name AS location_name, owner.display_name AS owner_name FROM ' . FormsModule::table_submissions() . ' s LEFT JOIN ' . $wpdb->prefix . 'routespro_clients c ON c.id=s.client_id LEFT JOIN ' . $wpdb->prefix . 'routespro_projects p ON p.id=s.project_id LEFT JOIN ' . $wpdb->prefix . 'routespro_routes r ON r.id=s.route_id LEFT JOIN ' . $wpdb->users . ' owner ON owner.ID=r.owner_user_id LEFT JOIN ' . $wpdb->prefix . 'routespro_route_stops rs ON rs.id=s.route_stop_id LEFT JOIN ' . $wpdb->prefix . 'routespro_locations loc ON loc.id=rs.location_id WHERE s.id=%d', $id), ARRAY_A) : null;
        if (!$submission) wp_die('Submissão não encontrada.');
        $form = FormsModule::get_form((int)$submission['form_id']);
        $schema = FormsModule::decode_schema($form['schema_json'] ?? '');
        $answers = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . FormsModule::table_answers() . ' WHERE submission_id=%d ORDER BY id ASC', $id), ARRAY_A) ?: [];
        $answers_by_key = [];
        foreach ($answers as $a) $answers_by_key[$a['question_key']] = $a;
        echo '<div class="wrap">';
        Branding::render_header('Editar submissão', 'Podes corrigir respostas, ajustar o estado e rever também o contexto operacional da rota.');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="routespro_save_submission">';
        echo '<input type="hidden" name="id" value="' . (int)$id . '">';
        wp_nonce_field('routespro_save_submission_' . $id, 'routespro_save_submission_nonce');
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">ID</th><td>#' . (int)$submission['id'] . '</td></tr>';
        echo '<tr><th scope="row">Formulário</th><td>' . esc_html($form['title'] ?? ('#' . (int)$submission['form_id'])) . '</td></tr>';
        echo '<tr><th scope="row">Cliente</th><td>' . esc_html($submission['client_name'] ?: 'Sem cliente') . '</td></tr>';
        echo '<tr><th scope="row">Campanha / Projeto</th><td>' . esc_html($submission['project_name'] ?: 'Sem campanha') . '</td></tr>';
        echo '<tr><th scope="row">Rota</th><td>' . wp_kses_post(self::render_route_cell($submission)) . '</td></tr>';
        echo '<tr><th scope="row">Paragem</th><td>' . wp_kses_post(self::render_stop_cell($submission)) . '</td></tr>';
        echo '<tr><th scope="row">Owner</th><td>' . esc_html($submission['owner_name'] ?: 'Sem owner') . '</td></tr>';
        echo '<tr><th scope="row"><label for="routespro_submission_status">Status</label></th><td><select id="routespro_submission_status" name="status">';
        foreach (['submitted'=>'Submetida','reviewed'=>'Revista','approved'=>'Aprovada','rejected'=>'Rejeitada','draft'=>'Rascunho'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($submission['status'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        echo '</tbody></table>';
        echo '<h2 style="margin-top:24px">Respostas</h2>';
        if (!empty($schema['questions'])) {
            echo '<table class="form-table" role="presentation"><tbody>';
            foreach ($schema['questions'] as $question) {
                if (!is_array($question)) continue;
                $key = sanitize_key($question['key'] ?? '');
                if (!$key) continue;
                $answer = $answers_by_key[$key] ?? null;
                $type = sanitize_key($question['type'] ?? 'text');
                $label = sanitize_text_field($question['label'] ?? $key);
                echo '<tr><th scope="row"><label for="routespro_answer_' . esc_attr($key) . '">' . esc_html($label) . '</label><div style="font-weight:400;color:#64748b;margin-top:4px">' . esc_html($key) . '</div></th><td>';
                self::render_answer_input($key, $type, $answer, $question);
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>O formulário já não tem schema disponível. Vais ver apenas as respostas atuais.</p>';
            echo '<table class="form-table" role="presentation"><tbody>';
            foreach ($answers as $answer) {
                $key = sanitize_key($answer['question_key'] ?? '');
                $label = sanitize_text_field($answer['question_label'] ?: $key);
                echo '<tr><th scope="row"><label for="routespro_answer_' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
                self::render_answer_input($key, 'text', $answer, []);
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        submit_button('Guardar submissão');
        echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=routespro-form-submissions')) . '">Voltar</a>';
        echo '</form></div>';
    }

    public static function handle_save() {
        if (!current_user_can('routespro_manage')) wp_die('Sem permissões.');
        $id = absint($_POST['id'] ?? 0);
        check_admin_referer('routespro_save_submission_' . $id, 'routespro_save_submission_nonce');
        global $wpdb;
        $submission = $id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . FormsModule::table_submissions() . ' WHERE id=%d', $id), ARRAY_A) : null;
        if (!$submission) wp_die('Submissão não encontrada.');
        $status = sanitize_key($_POST['status'] ?? 'submitted');
        $allowed_status = ['submitted','reviewed','approved','rejected','draft'];
        if (!in_array($status, $allowed_status, true)) $status = 'submitted';
        $wpdb->update(FormsModule::table_submissions(), ['status' => $status], ['id' => $id], ['%s'], ['%d']);
        $form = FormsModule::get_form((int)$submission['form_id']);
        $schema = FormsModule::decode_schema($form['schema_json'] ?? '');
        $questions = !empty($schema['questions']) && is_array($schema['questions']) ? $schema['questions'] : [];
        $known_keys = [];
        foreach ($questions as $question) {
            if (!is_array($question)) continue;
            $key = sanitize_key($question['key'] ?? '');
            if (!$key) continue;
            $known_keys[] = $key;
            $type = sanitize_key($question['type'] ?? 'text');
            $label = sanitize_text_field($question['label'] ?? $key);
            $value = self::extract_posted_answer($key, $type);
            self::upsert_answer($id, $key, $label, $type, $value);
        }
        if (!$known_keys) {
            foreach ((array)($_POST['answers'] ?? []) as $key => $raw) {
                $key = sanitize_key($key);
                self::upsert_answer($id, $key, $key, 'text', is_array($raw) ? $raw : wp_unslash($raw));
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=routespro-form-submissions&saved=1'));
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('routespro_manage')) wp_die('Sem permissões.');
        $id = absint($_GET['id'] ?? 0);
        check_admin_referer('routespro_delete_submission_' . $id);
        global $wpdb;
        if ($id) {
            $wpdb->delete(FormsModule::table_answers(), ['submission_id' => $id], ['%d']);
            $wpdb->delete(FormsModule::table_submissions(), ['id' => $id], ['%d']);
        }
        wp_safe_redirect(admin_url('admin.php?page=routespro-form-submissions&deleted=1'));
        exit;
    }

    private static function render_filters(array $filters, array $clients, array $projects, array $owners) {
        $url = admin_url('admin.php');
        echo '<form method="get" action="' . esc_url($url) . '" style="margin:16px 0 18px;padding:14px 16px;background:#fff;border:1px solid #e2e8f0;border-radius:12px">';
        echo '<input type="hidden" name="page" value="routespro-form-submissions">';
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">';
        echo '<p style="margin:0"><label for="routespro_filter_client"><strong>Cliente</strong></label><br><select id="routespro_filter_client" name="client_id"><option value="0">Todos</option>';
        foreach ($clients as $client) echo '<option value="' . (int)$client['id'] . '"' . selected($filters['client_id'], (int)$client['id'], false) . '>' . esc_html($client['name']) . '</option>';
        echo '</select></p>';
        echo '<p style="margin:0"><label for="routespro_filter_project"><strong>Campanha / Projeto</strong></label><br><select id="routespro_filter_project" name="project_id"><option value="0">Todas</option>';
        foreach ($projects as $project) echo '<option value="' . (int)$project['id'] . '"' . selected($filters['project_id'], (int)$project['id'], false) . '>' . esc_html($project['name']) . '</option>';
        echo '</select></p>';
        echo '<p style="margin:0"><label for="routespro_filter_owner"><strong>Owner</strong></label><br><select id="routespro_filter_owner" name="owner_user_id"><option value="0">Todos</option>';
        foreach ($owners as $owner) echo '<option value="' . (int)$owner['id'] . '"' . selected($filters['owner_user_id'], (int)$owner['id'], false) . '>' . esc_html($owner['name']) . '</option>';
        echo '</select></p>';
        echo '<p style="margin:0"><label for="routespro_filter_date_from"><strong>De</strong></label><br><input type="date" id="routespro_filter_date_from" name="date_from" value="' . esc_attr($filters['date_from'] ?? '') . '"></p>';
        echo '<p style="margin:0"><label for="routespro_filter_date_to"><strong>Até</strong></label><br><input type="date" id="routespro_filter_date_to" name="date_to" value="' . esc_attr($filters['date_to'] ?? '') . '"></p>';
        echo '<p style="margin:0"><button class="button button-primary">Filtrar</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=routespro-form-submissions')) . '">Limpar</a></p>';
        echo '</div>';
        echo '</form>';
    }

    private static function render_route_cell(array $row): string {
        $route_id = (int)($row['route_id'] ?? $row['joined_route_id'] ?? 0);
        if (!$route_id) return 'Sem rota';
        $parts = ['#' . $route_id];
        if (!empty($row['route_date'])) $parts[] = esc_html($row['route_date']);
        if (!empty($row['route_status'])) $parts[] = esc_html($row['route_status']);
        return implode('<br>', $parts);
    }

    private static function render_stop_cell(array $row): string {
        $stop_id = (int)($row['route_stop_id'] ?? $row['stop_row_id'] ?? 0);
        if (!$stop_id) return 'Sem paragem';
        $parts = ['#' . $stop_id];
        if (!empty($row['location_name'])) $parts[] = esc_html($row['location_name']);
        if (!empty($row['stop_status'])) $parts[] = esc_html($row['stop_status']);
        return implode('<br>', $parts);
    }

    private static function render_answer_input(string $key, string $type, ?array $answer, array $question) {
        $field_name = 'answers[' . $key . ']';
        $id = 'routespro_answer_' . $key;
        $value = self::answer_raw_value($answer, $type);
        $options = [];
        if (!empty($question['options']) && is_array($question['options'])) $options = array_values(array_filter(array_map('sanitize_text_field', $question['options'])));
        if (in_array($type, ['textarea'], true)) {
            echo '<textarea class="large-text" rows="5" id="' . esc_attr($id) . '" name="' . esc_attr($field_name) . '">' . esc_textarea(is_array($value) ? wp_json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value) . '</textarea>';
            return;
        }
        if (in_array($type, ['select','radio'], true) && $options) {
            echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($field_name) . '"><option value="">Selecione</option>';
            foreach ($options as $option) echo '<option value="' . esc_attr($option) . '"' . selected((string)$value, (string)$option, false) . '>' . esc_html($option) . '</option>';
            echo '</select>';
            return;
        }
        if ($type === 'checkbox') {
            echo '<label><input type="hidden" name="' . esc_attr($field_name) . '" value="0"><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($field_name) . '" value="1"' . checked(!empty($value), true, false) . '> Marcado</label>';
            return;
        }
        if (in_array($type, ['number','currency','percent'], true)) {
            echo '<input type="number" step="0.01" class="regular-text" id="' . esc_attr($id) . '" name="' . esc_attr($field_name) . '" value="' . esc_attr((string)$value) . '">';
            return;
        }
        if (in_array($type, ['date','time'], true)) {
            echo '<input type="' . esc_attr($type) . '" class="regular-text" id="' . esc_attr($id) . '" name="' . esc_attr($field_name) . '" value="' . esc_attr((string)$value) . '">';
            return;
        }
        if (in_array($type, ['image_upload','file_upload'], true)) {
            if (!empty($value)) echo '<p><a href="' . esc_url((string)$value) . '" target="_blank" rel="noopener">Ver ficheiro atual</a></p>';
            echo '<input type="url" class="large-text" id="' . esc_attr($id) . '" name="' . esc_attr($field_name) . '" value="' . esc_attr((string)$value) . '" placeholder="https://">';
            return;
        }
        echo '<input type="text" class="regular-text" id="' . esc_attr($id) . '" name="' . esc_attr($field_name) . '" value="' . esc_attr(is_array($value) ? wp_json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value) . '">';
    }

    private static function extract_posted_answer(string $key, string $type) {
        $raw = $_POST['answers'][$key] ?? null;
        if ($type === 'checkbox') return !empty($raw) ? 1 : 0;
        if (is_array($raw)) return array_map('sanitize_text_field', wp_unslash($raw));
        $raw = is_string($raw) ? wp_unslash($raw) : $raw;
        switch ($type) {
            case 'number':
            case 'currency':
            case 'percent':
                return ($raw === '' || $raw === null) ? '' : (float) str_replace(',', '.', (string) $raw);
            case 'textarea':
                return sanitize_textarea_field((string) $raw);
            case 'image_upload':
            case 'file_upload':
                return esc_url_raw((string) $raw);
            default:
                return sanitize_text_field((string) $raw);
        }
    }

    private static function upsert_answer(int $submission_id, string $key, string $label, string $type, $value) {
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . FormsModule::table_answers() . ' WHERE submission_id=%d AND question_key=%s LIMIT 1', $submission_id, $key), ARRAY_A);
        $stored = self::prepare_answer_storage($value, $type);
        $data = [
            'question_label' => $label,
            'value_text' => $stored['text'],
            'value_number' => $stored['number'],
            'value_json' => $stored['json'],
        ];
        $formats = ['%s','%s','%f','%s'];
        if ($existing) {
            $wpdb->update(FormsModule::table_answers(), $data, ['id' => (int)$existing['id']], $formats, ['%d']);
        } else {
            $wpdb->insert(FormsModule::table_answers(), [
                'submission_id' => $submission_id,
                'question_key' => $key,
                'question_label' => $label,
                'value_text' => $stored['text'],
                'value_number' => $stored['number'],
                'value_json' => $stored['json'],
                'created_at' => current_time('mysql'),
            ], ['%d','%s','%s','%s','%f','%s','%s']);
        }
    }

    private static function prepare_answer_storage($value, string $type): array {
        $text = null; $number = null; $json = null;
        if (is_array($value)) {
            $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
            $text = $json;
        } elseif (in_array($type, ['number','currency','percent'], true) && $value !== '') {
            $number = (float) $value;
            $text = (string) $value;
        } elseif ($type === 'checkbox') {
            $number = $value ? 1 : 0;
            $text = $value ? '1' : '0';
        } else {
            $text = is_scalar($value) ? (string) $value : wp_json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return ['text' => $text, 'number' => $number, 'json' => $json];
    }

    private static function answer_raw_value(?array $answer, string $type) {
        if (!$answer) return '';
        if (!empty($answer['value_json'])) {
            $decoded = json_decode($answer['value_json'], true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        }
        if (in_array($type, ['number','currency','percent'], true) && $answer['value_number'] !== null) return $answer['value_number'];
        if ($type === 'checkbox') return !empty($answer['value_number']) || $answer['value_text'] === '1';
        return $answer['value_text'] ?? '';
    }

    private static function product_matrix_rows_from_answer(array $answer): ?array {
        if (empty($answer['value_json'])) return null;
        $decoded = json_decode((string)$answer['value_json'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) return null;
        $is_matrix = false;
        $rows = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) continue;
            if (array_key_exists('qty', $row) || array_key_exists('quantity', $row) || array_key_exists('ref', $row) || array_key_exists('reference', $row)) $is_matrix = true;
            $ref = sanitize_text_field((string)($row['ref'] ?? $row['reference'] ?? ''));
            $name = sanitize_text_field((string)($row['name'] ?? $row['product'] ?? ''));
            $qty = $row['qty'] ?? ($row['after'] ?? ($row['quantity'] ?? ''));
            $before = array_key_exists('before', $row) ? $row['before'] : null;
            $after = array_key_exists('after', $row) ? $row['after'] : null;
            if ($ref === '' && $name === '' && $qty === '' && $before === null && $after === null) continue;
            $out_row = ['ref'=>$ref,'name'=>$name,'qty'=>$qty];
            if ($before !== null || $after !== null) { $out_row['before'] = $before; $out_row['after'] = $after !== null ? $after : $qty; }
            $rows[] = $out_row;
        }
        return $is_matrix ? $rows : null;
    }

    private static function answer_to_string(array $answer): string {
        if (!empty($answer['value_json'])) {
            $matrix = self::product_matrix_rows_from_answer($answer);
            if ($matrix !== null) {
                $parts = [];
                foreach ($matrix as $row) {
                    $label = trim(((string)($row['ref'] ?? '') ? (string)$row['ref'] . ' · ' : '') . ((string)($row['name'] ?? '') ?: 'Produto'));
                    $qty = (string)($row['qty'] ?? '');
                    $before = array_key_exists('before', $row) ? (string)$row['before'] : '';
                    $after = array_key_exists('after', $row) ? (string)$row['after'] : $qty;
                    if ($label !== '' && ($before !== '' || $after !== '')) $parts[] = $label . ': ' . ($before !== '' ? $before . ' > ' : '') . $after;
                }
                return implode('; ', $parts);
            }
            $decoded = json_decode($answer['value_json'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (is_array($decoded)) return implode(', ', array_map('strval', $decoded));
                return (string) $decoded;
            }
            return (string) $answer['value_json'];
        }
        if ($answer['value_number'] !== null && $answer['value_text'] === null) return (string) $answer['value_number'];
        return (string) ($answer['value_text'] ?? '');
    }
}
