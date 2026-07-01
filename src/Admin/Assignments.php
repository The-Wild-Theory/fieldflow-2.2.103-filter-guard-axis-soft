<?php
namespace RoutesPro\Admin;

use RoutesPro\Support\AssignmentMatrix;
use RoutesPro\Support\AssignmentResolver;
use RoutesPro\Forms\BindingResolver;
use RoutesPro\Forms\Forms as FormsModule;
use RoutesPro\Forms\ContextQuestions as ContextQuestionService;

class Assignments {
    private static function get_roles(){
        $roles = get_option('routespro_roles');
        if (!is_array($roles) || !$roles) {
            $roles = ['driver','merchandiser','sales','supervisor','implementador','operacional','owner'];
            update_option('routespro_roles', $roles);
        }
        $roles = array_values(array_unique(array_filter(array_map(function($r){ return trim(sanitize_text_field($r)); }, $roles))));
        foreach (['operacional','owner'] as $required_role) {
            if (!in_array($required_role, $roles, true)) $roles[] = $required_role;
        }
        return array_values(array_unique($roles));
    }

    private static function save_roles($roles){
        $clean = array_values(array_unique(array_filter(array_map(function($r){ return trim(sanitize_text_field($r)); }, (array)$roles))));
        update_option('routespro_roles', $clean);
        return $clean;
    }

    private static function admin_url(array $args = []): string {
        return add_query_arg($args, admin_url('admin.php?page=routespro-assignments-hub'));
    }

    private static function selected_multi(array $selected, int $candidate): string {
        return in_array($candidate, $selected, true) ? ' selected' : '';
    }

    private static function redirect_with_state(string $tab, array $args = []): void {
        $base = [
            'page' => 'routespro-assignments-hub',
            'tab' => $tab,
        ];
        foreach (['client_id','project_id','route_id'] as $key) {
            $value = absint($_POST[$key] ?? $_GET[$key] ?? 0);
            if ($value > 0) $base[$key] = $value;
        }
        $url = add_query_arg(array_merge($base, $args), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    private static function render_status_notice(): void {
        $status = sanitize_key($_GET['ff_status'] ?? '');
        if (!$status) return;
        $map = [
            'client_saved' => ['success', 'Atribuição de cliente guardada com sucesso.'],
            'project_saved' => ['success', 'Atribuição de projeto guardada com sucesso.'],
            'route_saved' => ['success', 'Atribuição de rota guardada com sucesso.'],
            'binding_saved' => ['success', 'Ligação de formulário guardada com sucesso.'],
            'binding_deleted' => ['success', 'Ligação de formulário removida com sucesso.'],
            'roles_saved' => ['success', 'Função adicionada com sucesso.'],
            'save_error' => ['error', 'Ocorreu um problema ao gravar. Verifica os dados e tenta novamente.'],
            'binding_invalid' => ['error', 'Para guardar a ligação do formulário tens de escolher um formulário e pelo menos um âmbito.'],
            'analytics_binding_saved' => ['success', 'Ligação analítica guardada com sucesso.'],
            'analytics_binding_deleted' => ['success', 'Ligação analítica removida com sucesso.'],
            'analytics_binding_invalid' => ['error', 'Para guardar a ligação analítica tens de escolher formulário, pergunta, métrica e label.'],
            'analytics_dashboard_saved' => ['success', 'Dashboard analítico guardado com sucesso.'],
            'analytics_dashboard_deleted' => ['success', 'Dashboard analítico removido com sucesso.'],
            'analytics_dashboard_invalid' => ['error', 'Para criar um dashboard analítico tens de indicar pelo menos o nome.'],
            'analytics_group_saved' => ['success', 'Grupo de lojas guardado com sucesso.'],
            'analytics_group_deleted' => ['success', 'Grupo de lojas removido com sucesso.'],
            'analytics_group_invalid' => ['error', 'Para criar um grupo de lojas tens de indicar nome e pelo menos uma loja.'],
        ];
        if (empty($map[$status])) return;
        [$kind, $message] = $map[$status];
        echo '<div class="notice notice-' . esc_attr($kind) . ' is-dismissible"><p><strong>' . esc_html($message) . '</strong></p></div>';
    }

    private static function render_user_options(array $users, array $selected = []): string {
        $html = '';
        foreach ($users as $user) {
            $label = ($user->display_name ?: $user->user_login) . ' [' . $user->user_login . ']';
            if (!empty($user->user_email)) $label .= ' • ' . $user->user_email;
            $html .= '<option value="' . (int)$user->ID . '"' . self::selected_multi($selected, (int)$user->ID) . '>' . esc_html($label) . '</option>';
        }
        return $html;
    }

    private static function resolve_selected_role(array $roles, string $current_role, string $fallback): array {
        $selected_role = trim(sanitize_text_field($current_role));
        if ($selected_role === '') $selected_role = $fallback;
        if (!in_array($selected_role, $roles, true)) $roles[] = $selected_role;
        return [array_values(array_unique($roles)), $selected_role];
    }


    private static function find_client_name(array $clients, int $client_id): string {
        foreach ($clients as $client) {
            if ((int)($client['id'] ?? 0) == $client_id) return (string)($client['name'] ?? '');
        }
        return '';
    }

    private static function find_project_name(array $projects, int $project_id): string {
        foreach ($projects as $project) {
            if ((int)($project['id'] ?? 0) == $project_id) return (string)($project['name'] ?? '');
        }
        return '';
    }



    private static function analytics_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'routespro_form_analytics_bindings';
    }

    private static function analytics_dashboard_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'routespro_analytics_dashboards';
    }

    private static function analytics_store_group_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'routespro_analytics_store_groups';
    }

    private static function analytics_store_group_items_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'routespro_analytics_store_group_items';
    }

    private static function extract_schema_fields(string $schema_json): array {
        $schema_json = trim($schema_json);
        if ($schema_json === '') return [];
        $decoded = json_decode($schema_json, true);
        if (!is_array($decoded)) return [];

        $questions = [];
        if (isset($decoded['questions']) && is_array($decoded['questions'])) {
            $questions = $decoded['questions'];
        } elseif (isset($decoded['fields']) && is_array($decoded['fields'])) {
            $questions = $decoded['fields'];
        } elseif (array_keys($decoded) === range(0, count($decoded) - 1)) {
            $questions = $decoded;
        }

        $out = [];
        foreach ($questions as $row) {
            if (!is_array($row)) continue;
            $question_key = sanitize_key((string) ($row['key'] ?? $row['name'] ?? $row['slug'] ?? ''));
            if ($question_key === '') continue;
            $out[] = [
                'question_key' => $question_key,
                'question_label' => (string) ($row['label'] ?? $row['title'] ?? $question_key),
                'question_type' => sanitize_key((string) ($row['type'] ?? 'text')),
            ];
        }

        return $out;
    }

    private static function analytics_options(string $type): array {
        $map = [
            'chart' => [
                'line' => 'Linha',
                'bar' => 'Barras',
                'pie' => 'Pizza',
                'table' => 'Tabela',
                'kpi' => 'KPI',
            ],
            'aggregation' => [
                'latest' => 'Último valor',
                'sum' => 'Soma',
                'avg' => 'Média',
                'count' => 'Contagem',
            ],
            'dimension' => [
                'submitted_at' => 'Data da visita',
                'location_id' => 'Local',
                'route_id' => 'Rota',
                'project_id' => 'Campanha',
                'client_id' => 'Cliente',
            ],
            'scope' => [
                'client_project_location' => 'Cliente + campanha + local',
                'client_project' => 'Cliente + campanha',
                'client_only' => 'Só cliente',
                'global' => 'Global',
            ],
        ];
        return $map[$type] ?? [];
    }

    private static function render_simple_options(array $options, string $selected = ''): string {
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . esc_attr((string)$value) . '"' . selected($selected, (string)$value, false) . '>' . esc_html((string)$label) . '</option>';
        }
        return $html;
    }

    private static function analytics_question_options(array $questions): string {
        if (!$questions) {
            return '<option value="">Sem perguntas disponíveis ainda</option>';
        }
        $html = '<option value="">Seleciona uma pergunta</option>';
        $currentFormId = 0;
        foreach ($questions as $row) {
            $formId = (int)($row['form_id'] ?? 0);
            if ($formId !== $currentFormId) {
                if ($currentFormId !== 0) {
                    $html .= '</optgroup>';
                }
                $currentFormId = $formId;
                $html .= '<optgroup label="Formulário #' . $formId . '">';
            }
            $key = (string)($row['question_key'] ?? '');
            $label = (string)($row['question_label'] ?? $key);
            $html .= '<option value="' . esc_attr($key) . '">' . esc_html($label . ' [' . $key . ']') . '</option>';
        }
        if ($currentFormId !== 0) {
            $html .= '</optgroup>';
        }
        return $html;
    }

    private static function render_tab_nav(string $tab): void {
        $tabs = [
            'overview' => 'Visão geral',
            'clients' => 'Clientes',
            'projects' => 'Projetos',
            'routes' => 'Rotas',
            'forms' => 'Formulários',
            'analytics' => 'Analytics',
        ];
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:18px">';
        foreach ($tabs as $slug => $label) {
            $class = $slug === $tab ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url(self::admin_url(['tab' => $slug])) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private static function handle_post_actions(): void {
        if (!current_user_can('routespro_manage')) return;
        global $wpdb;

        if (!empty($_POST['routespro_role_nonce']) && wp_verify_nonce($_POST['routespro_role_nonce'], 'routespro_role_manage')) {
            $new = sanitize_text_field($_POST['new_role'] ?? '');
            if ($new !== '') {
                self::save_roles(array_merge(self::get_roles(), [$new]));
                self::redirect_with_state(sanitize_key($_GET['tab'] ?? 'overview'), ['ff_status' => 'roles_saved']);
            }
        }

        $action = sanitize_key($_POST['routespro_assignment_hub_action'] ?? '');
        if (!$action) return;
        check_admin_referer('routespro_assignment_hub_' . $action);
        $tab = sanitize_key($_GET['tab'] ?? 'overview');

        switch ($action) {
            case 'save_client_scope':
                $client_id = absint($_POST['client_id'] ?? 0);
                $user_ids = array_map('absint', (array)($_POST['associated_user_ids'] ?? []));
                AssignmentResolver::save_client_user_ids($client_id, $user_ids);
                self::redirect_with_state('clients', ['client_id' => $client_id, 'ff_status' => $wpdb->last_error ? 'save_error' : 'client_saved']);
                break;
            case 'save_project_scope':
                $project_id = absint($_POST['project_id'] ?? 0);
                $user_ids = array_map('absint', (array)($_POST['associated_user_ids'] ?? []));
                $owner_ids = array_map('absint', (array)($_POST['owner_user_ids'] ?? []));
                $owner_role = sanitize_text_field($_POST['owner_role'] ?? 'owner');
                AssignmentResolver::save_project_assignments($project_id, $user_ids, $owner_ids, $owner_role ?: 'owner');
                self::redirect_with_state('projects', ['project_id' => $project_id, 'ff_status' => $wpdb->last_error ? 'save_error' : 'project_saved']);
                break;
            case 'save_route_scope':
                $route_id = absint($_POST['route_id'] ?? 0);
                $owner_user_id = absint($_POST['owner_user_id'] ?? 0);
                $team_user_ids = array_map('absint', (array)($_POST['team_user_ids'] ?? []));
                $team_role = sanitize_text_field($_POST['team_role'] ?? 'operacional');
                AssignmentResolver::save_route_assignments($route_id, $owner_user_id, $team_user_ids, $team_role ?: 'operacional');
                self::redirect_with_state('routes', ['route_id' => $route_id, 'ff_status' => $wpdb->last_error ? 'save_error' : 'route_saved']);
                break;
            case 'save_form_binding':
                $data = [
                    'form_id' => absint($_POST['form_id'] ?? 0),
                    'client_id' => absint($_POST['client_id'] ?? 0),
                    'project_id' => absint($_POST['project_id'] ?? 0),
                    'route_id' => absint($_POST['route_id'] ?? 0),
                    'stop_id' => absint($_POST['stop_id'] ?? 0),
                    'location_id' => absint($_POST['location_id'] ?? 0),
                    'mode' => sanitize_key($_POST['mode'] ?? 'route_and_form'),
                    'priority' => max(0, min(999, (int)($_POST['priority'] ?? 10))),
                    'is_active' => !empty($_POST['is_active']) ? 1 : 0,
                    'created_at' => current_time('mysql'),
                ];
                if ($data['form_id'] && ($data['client_id'] || $data['project_id'] || $data['route_id'] || $data['stop_id'] || $data['location_id'])) {
                    $wpdb->insert(BindingResolver::table(), $data, ['%d','%d','%d','%d','%d','%d','%s','%d','%d','%s']);
                    self::redirect_with_state('forms', [
                        'client_id' => $data['client_id'],
                        'project_id' => $data['project_id'],
                        'route_id' => $data['route_id'],
                        'ff_status' => $wpdb->last_error ? 'save_error' : 'binding_saved'
                    ]);
                } else {
                    self::redirect_with_state('forms', ['ff_status' => 'binding_invalid']);
                }
                break;
            case 'delete_form_binding':
                $id = absint($_POST['binding_id'] ?? 0);
                if ($id) {
                    $wpdb->delete(BindingResolver::table(), ['id' => $id], ['%d']);
                }
                self::redirect_with_state('forms', ['ff_status' => $wpdb->last_error ? 'save_error' : 'binding_deleted']);
                break;
            case 'save_analytics_dashboard':
                $title = sanitize_text_field($_POST['dashboard_title'] ?? '');
                $client_id = absint($_POST['client_id'] ?? 0);
                $project_id = absint($_POST['project_id'] ?? 0);
                $route_id = absint($_POST['route_id'] ?? 0);
                if ($title !== '') {
                    $wpdb->insert(self::analytics_dashboard_table(), [
                        'client_id' => $client_id ?: null,
                        'project_id' => $project_id ?: null,
                        'route_id' => $route_id ?: null,
                        'title' => $title,
                        'description' => wp_kses_post($_POST['dashboard_description'] ?? ''),
                        'visibility' => sanitize_key($_POST['dashboard_visibility'] ?? 'client_portal'),
                        'layout_type' => sanitize_key($_POST['dashboard_layout_type'] ?? 'mixed'),
                        'sort_order' => (int)($_POST['dashboard_sort_order'] ?? 10),
                        'is_active' => !empty($_POST['dashboard_is_active']) ? 1 : 0,
                    ], ['%d','%d','%d','%s','%s','%s','%s','%d','%d']);
                    self::redirect_with_state('analytics', ['client_id'=>$client_id,'project_id'=>$project_id,'route_id'=>$route_id,'ff_status'=>$wpdb->last_error ? 'save_error' : 'analytics_dashboard_saved']);
                }
                self::redirect_with_state('analytics', ['ff_status'=>'analytics_dashboard_invalid']);
                break;
            case 'delete_analytics_dashboard':
                $id = absint($_POST['analytics_dashboard_id'] ?? 0);
                if ($id) $wpdb->delete(self::analytics_dashboard_table(), ['id'=>$id], ['%d']);
                self::redirect_with_state('analytics', ['ff_status'=>$wpdb->last_error ? 'save_error' : 'analytics_dashboard_deleted']);
                break;
            case 'save_analytics_store_group':
                $name = sanitize_text_field($_POST['store_group_name'] ?? '');
                $client_id = absint($_POST['client_id'] ?? 0);
                $project_id = absint($_POST['project_id'] ?? 0);
                $location_ids = array_values(array_unique(array_filter(array_map('absint', (array)($_POST['store_group_location_ids'] ?? [])))));
                if ($name !== '' && $location_ids) {
                    $wpdb->insert(self::analytics_store_group_table(), [
                        'client_id' => $client_id ?: null,
                        'project_id' => $project_id ?: null,
                        'name' => $name,
                        'group_type' => 'manual',
                        'rule_json' => wp_json_encode(['mode'=>'manual','location_ids'=>$location_ids]),
                        'is_active' => !empty($_POST['store_group_is_active']) ? 1 : 0,
                    ], ['%d','%d','%s','%s','%s','%d']);
                    $group_id = (int)$wpdb->insert_id;
                    foreach ($location_ids as $location_id) {
                        $wpdb->replace(self::analytics_store_group_items_table(), ['group_id'=>$group_id,'location_id'=>$location_id], ['%d','%d']);
                    }
                    self::redirect_with_state('analytics', ['client_id'=>$client_id,'project_id'=>$project_id,'ff_status'=>$wpdb->last_error ? 'save_error' : 'analytics_group_saved']);
                }
                self::redirect_with_state('analytics', ['ff_status'=>'analytics_group_invalid']);
                break;
            case 'delete_analytics_store_group':
                $id = absint($_POST['analytics_store_group_id'] ?? 0);
                if ($id) {
                    $wpdb->delete(self::analytics_store_group_items_table(), ['group_id'=>$id], ['%d']);
                    $wpdb->delete(self::analytics_store_group_table(), ['id'=>$id], ['%d']);
                }
                self::redirect_with_state('analytics', ['ff_status'=>$wpdb->last_error ? 'save_error' : 'analytics_group_deleted']);
                break;
            case 'save_analytics_binding':
                $form_id = absint($_POST['form_id'] ?? 0);
                $question_key = sanitize_key($_POST['question_key'] ?? '');
                $metric_key = sanitize_key($_POST['metric_key'] ?? '');
                $metric_label = sanitize_text_field($_POST['metric_label'] ?? '');
                $chart_type = sanitize_key($_POST['chart_type'] ?? 'line');
                $aggregation = sanitize_key($_POST['aggregation'] ?? 'latest');
                $dimension = sanitize_key($_POST['dimension'] ?? 'submitted_at');
                $scope_mode = sanitize_key($_POST['scope_mode'] ?? 'client_project_location');
                $is_active = !empty($_POST['is_active']) ? 1 : 0;
                $settings_json = wp_json_encode([
                    'client_id' => absint($_POST['client_id'] ?? 0),
                    'project_id' => absint($_POST['project_id'] ?? 0),
                    'route_id' => absint($_POST['route_id'] ?? 0),
                    'dashboard_id' => absint($_POST['dashboard_id'] ?? 0),
                    'store_group_id' => absint($_POST['store_group_id'] ?? 0),
                    'secondary_dimension' => sanitize_key($_POST['secondary_dimension'] ?? ''),
                    'show_kpi' => !empty($_POST['show_kpi']) ? 1 : 0,
                    'show_table' => !empty($_POST['show_table']) ? 1 : 0,
                    'show_empty' => !empty($_POST['show_empty']) ? 1 : 0,
                    'sort_order' => (int)($_POST['sort_order'] ?? 10),
                ]);
                if ($form_id && $question_key !== '' && $metric_key !== '' && $metric_label !== '') {
                    $table = self::analytics_table();
                    $existing_id = absint($_POST['analytics_binding_id'] ?? 0);
                    $data = [
                        'form_id' => $form_id,
                        'question_key' => $question_key,
                        'metric_key' => $metric_key,
                        'metric_label' => $metric_label,
                        'chart_type' => $chart_type,
                        'aggregation' => $aggregation,
                        'dimension' => $dimension,
                        'scope_mode' => $scope_mode,
                        'settings_json' => $settings_json,
                        'is_active' => $is_active,
                    ];
                    $formats = ['%d','%s','%s','%s','%s','%s','%s','%s','%s','%d'];
                    if ($existing_id) {
                        $wpdb->update($table, $data, ['id' => $existing_id], $formats, ['%d']);
                    } else {
                        $wpdb->insert($table, $data, $formats);
                    }
                    self::redirect_with_state('analytics', [
                        'client_id' => absint($_POST['client_id'] ?? 0),
                        'project_id' => absint($_POST['project_id'] ?? 0),
                        'route_id' => absint($_POST['route_id'] ?? 0),
                        'ff_status' => $wpdb->last_error ? 'save_error' : 'analytics_binding_saved'
                    ]);
                } else {
                    self::redirect_with_state('analytics', ['ff_status' => 'analytics_binding_invalid']);
                }
                break;
            case 'delete_analytics_binding':
                $id = absint($_POST['analytics_binding_id'] ?? 0);
                if ($id) {
                    $wpdb->delete(self::analytics_table(), ['id' => $id], ['%d']);
                }
                self::redirect_with_state('analytics', ['ff_status' => $wpdb->last_error ? 'save_error' : 'analytics_binding_deleted']);
                break;
        }
    }

    public static function render_hub() {
        if (!current_user_can('routespro_manage')) return;
        self::handle_post_actions();
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $tab = sanitize_key($_GET['tab'] ?? 'overview');
        $roles = self::get_roles();
        $users = get_users(['orderby' => 'display_name', 'order' => 'ASC']);
        $clients = $wpdb->get_results("SELECT id, name, meta_json FROM {$px}clients ORDER BY name ASC", ARRAY_A) ?: [];
        $projects = $wpdb->get_results("SELECT id, client_id, name, meta_json FROM {$px}projects ORDER BY name ASC", ARRAY_A) ?: [];
        $routes = $wpdb->get_results("SELECT id, client_id, project_id, owner_user_id, date, status FROM {$px}routes ORDER BY date DESC, id DESC LIMIT 300", ARRAY_A) ?: [];
        $forms = $wpdb->get_results('SELECT id, title, status, schema_json FROM ' . FormsModule::table() . ' ORDER BY id DESC LIMIT 200', ARRAY_A) ?: [];
        $stops = $wpdb->get_results("SELECT rs.id, rs.route_id, rs.seq, COALESCE(l.name,'PDV') AS location_name FROM {$px}route_stops rs LEFT JOIN {$px}locations l ON l.id=rs.location_id ORDER BY rs.id DESC LIMIT 300", ARRAY_A) ?: [];
        $locations = $wpdb->get_results("SELECT id, name FROM {$px}locations ORDER BY name ASC LIMIT 500", ARRAY_A) ?: [];

        $selected_client_id = absint($_GET['client_id'] ?? ($_POST['client_id'] ?? 0));
        $selected_project_id = absint($_GET['project_id'] ?? ($_POST['project_id'] ?? 0));
        $selected_route_id = absint($_GET['route_id'] ?? ($_POST['route_id'] ?? 0));
        if (!$selected_project_id && $selected_route_id) {
            foreach ($routes as $route) if ((int)$route['id'] === $selected_route_id) $selected_project_id = (int)($route['project_id'] ?? 0);
        }
        if (!$selected_client_id && $selected_project_id) {
            foreach ($projects as $project) if ((int)$project['id'] === $selected_project_id) $selected_client_id = (int)($project['client_id'] ?? 0);
        }
        if (!$selected_client_id && $selected_route_id) {
            foreach ($routes as $route) if ((int)$route['id'] === $selected_route_id) $selected_client_id = (int)($route['client_id'] ?? 0);
        }

        echo '<div class="wrap">';
        Branding::render_header('Centro de Atribuições');
        self::render_status_notice();
        echo '<style>
            .ff-hub-grid{display:grid;grid-template-columns:280px minmax(0,1fr);gap:18px;align-items:start}
            .ff-hub-sidebar,.ff-hub-main{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px}
            .ff-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px}
            .ff-kpi{border:1px solid #e5e7eb;border-radius:14px;padding:16px;background:linear-gradient(180deg,#fff,#f8fafc)}
            .ff-kpi strong{display:block;font-size:24px;line-height:1.1;margin-top:6px}
            .ff-chip{display:inline-block;padding:6px 10px;border-radius:999px;background:#eef2ff;color:#3730a3;font-weight:600;font-size:12px;margin-right:8px;margin-bottom:8px}
            .ff-section-title{margin:0 0 12px 0;font-size:18px}
            .ff-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
            .ff-note{color:#64748b;font-size:13px}
            .ff-sticky{position:sticky;top:46px}
            .ff-list{margin:0;padding-left:18px}
            .ff-list li{margin:0 0 6px 0}
            .ff-table td code{white-space:normal;word-break:break-word}
            @media (max-width: 980px){.ff-hub-grid{grid-template-columns:1fr}.ff-sticky{position:static}}
        </style>';
        self::render_tab_nav($tab);
        echo '<div class="ff-hub-grid">';
        echo '<aside class="ff-hub-sidebar ff-sticky">';
        echo '<h2 class="ff-section-title">Contexto de trabalho</h2>';
        echo '<form method="get" data-ff-context="sidebar">';
        echo '<input type="hidden" name="page" value="routespro-assignments-hub">';
        echo '<input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
        echo '<p><label><strong>Cliente</strong><br><select name="client_id" data-ff-role="client" style="width:100%"><option value="0">Todos</option>';
        foreach ($clients as $client) echo '<option value="' . (int)$client['id'] . '"' . selected($selected_client_id, (int)$client['id'], false) . '>' . esc_html($client['name']) . '</option>';
        echo '</select></label></p>';
        echo '<p><label><strong>Projeto</strong><br><select name="project_id" data-ff-role="project" style="width:100%"><option value="0">Todos</option>';
        foreach ($projects as $project) {
            $hidden = $selected_client_id && (int)$project['client_id'] !== $selected_client_id ? ' style="display:none"' : '';
            echo '<option value="' . (int)$project['id'] . '" data-client-id="' . (int)$project['client_id'] . '"' . $hidden . selected($selected_project_id, (int)$project['id'], false) . '>' . esc_html($project['name'] . ' #' . (int)$project['id']) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label><strong>Rota</strong><br><select name="route_id" data-ff-role="route" style="width:100%"><option value="0">Todas</option>';
        foreach ($routes as $route) {
            $hidden = '';
            if ($selected_client_id && (int)$route['client_id'] !== $selected_client_id) $hidden = ' style="display:none"';
            if ($selected_project_id && (int)$route['project_id'] !== $selected_project_id) $hidden = ' style="display:none"';
            $label = '#' . (int)$route['id'] . ' · ' . ($route['date'] ?: 'sem data') . ' · ' . ($route['status'] ?: '');
            echo '<option value="' . (int)$route['id'] . '" data-client-id="' . (int)$route['client_id'] . '" data-project-id="' . (int)$route['project_id'] . '"' . $hidden . selected($selected_route_id, (int)$route['id'], false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><button class="button button-primary">Aplicar contexto</button></p>';
        echo '</form>';
        echo '<hr style="margin:18px 0">';
        echo '<div class="ff-note"><strong>Fase 1:</strong> as atribuições passam a estar centradas aqui. Os ecrãs antigos continuam disponíveis por URL, mas deixam de ser o caminho principal.</div>';
        echo '</aside>';
        echo '<section class="ff-hub-main">';

        if ($tab === 'overview') {
            $counts = AssignmentResolver::get_overview_counts();
            echo '<h2 class="ff-section-title">Resumo operacional</h2>';
            echo '<div class="ff-kpi-grid">';
            $labels = [
                'clients' => 'Clientes',
                'projects' => 'Projetos',
                'routes' => 'Rotas',
                'project_assignments' => 'Owners de projeto',
                'route_assignments' => 'Atribuições de rota',
                'form_bindings' => 'Ligações de formulários',
            ];
            foreach ($labels as $key => $label) {
                echo '<div class="ff-kpi"><span class="ff-note">' . esc_html($label) . '</span><strong>' . (int)($counts[$key] ?? 0) . '</strong></div>';
            }
            echo '</div>';
            echo '<div class="routespro-card" style="margin-top:18px">';
            echo '<h3 style="margin-top:0">Modelo recomendado</h3>';
            echo '<span class="ff-chip">1. Cliente define universo</span>';
            echo '<span class="ff-chip">2. Projeto define equipa</span>';
            echo '<span class="ff-chip">3. Rota define owner e exceções</span>';
            echo '<span class="ff-chip">4. Formulários herdam contexto</span>';
            echo '<ul class="ff-list"><li>Cliente: visibilidade macro para equipa, cliente e supervisão.</li><li>Projeto: equipa operacional e responsáveis ativos.</li><li>Rota: owner operacional e equipa do dia.</li><li>Formulários: regra automática por cliente, projeto, rota, paragem ou local.</li></ul>';
            echo '</div>';
        } elseif ($tab === 'clients') {
            $selected = AssignmentResolver::get_client_user_ids($selected_client_id);
            echo '<h2 class="ff-section-title">Atribuição de cliente</h2>';
            echo '<p class="ff-note"><strong>Aba Clientes.</strong> Aqui defines a base de acesso ao cliente. O que guardares aqui deve servir de universo para projetos, rotas e formulários.</p>';
            echo '<div class="routespro-card" style="margin:12px 0 18px 0"><strong>Estado guardado agora:</strong> ' . ($selected_client_id ? esc_html(count($selected) . ' utilizadores associados ao cliente selecionado.') : 'Seleciona um cliente para veres o estado guardado.') . '</div>';
            echo '<form method="post">';
            wp_nonce_field('routespro_assignment_hub_save_client_scope');
            echo '<input type="hidden" name="routespro_assignment_hub_action" value="save_client_scope">';
            echo '<input type="hidden" name="tab" value="clients">';
            echo '<div class="ff-form-grid">';
            echo '<p><label><strong>Cliente</strong><br><select name="client_id" style="width:100%" required><option value="">Seleciona</option>';
            foreach ($clients as $client) echo '<option value="' . (int)$client['id'] . '"' . selected($selected_client_id, (int)$client['id'], false) . '>' . esc_html($client['name']) . '</option>';
            echo '</select></label></p>';
            echo '<p style="grid-column:1/-1"><label><strong>Utilizadores associados</strong><br><select name="associated_user_ids[]" multiple size="12" style="width:min(100%,760px)">' . self::render_user_options($users, $selected) . '</select></label><br><span class="ff-note">Estes utilizadores passam a ver o cliente e servem de base para o resto das heranças.</span></p>';
            echo '</div>';
            submit_button('Guardar atribuição de cliente');
            echo '</form>';
        } elseif ($tab === 'projects') {
            $project = AssignmentResolver::get_project_context($selected_project_id);
            $associated = array_map('intval', (array)($project['associated_user_ids'] ?? []));
            $owners = array_map(function($row){ return (int)($row['user_id'] ?? 0); }, array_filter((array)($project['owners'] ?? []), fn($row) => !empty($row['is_active'])));
            $project_owner_role = 'owner';
            foreach ((array)($project['owners'] ?? []) as $project_owner_row) {
                if (!empty($project_owner_row['is_active']) && !empty($project_owner_row['role'])) { $project_owner_role = (string)$project_owner_row['role']; break; }
            }
            [$project_role_options, $project_owner_role] = self::resolve_selected_role($roles, $project_owner_role, 'owner');
            echo '<h2 class="ff-section-title">Atribuição de campanha / projeto</h2>';
            echo '<p class="ff-note"><strong>Aba Campanhas / Projetos.</strong> Primeiro filtras o cliente, depois a campanha. O save é sempre feito para a campanha selecionada, nunca para todas.</p>';
            echo '<div class="routespro-card" style="margin:12px 0 18px 0"><strong>Estado guardado agora:</strong> ' . ($selected_project_id ? esc_html((self::find_client_name($clients, (int)($project['client_id'] ?? $selected_client_id)) ?: 'Sem cliente') . ' · ' . (self::find_project_name($projects, $selected_project_id) ?: ('Campanha #' . $selected_project_id)) . ' · ' . count($associated) . ' utilizadores com acesso, ' . count($owners) . ' responsáveis ativos.') : 'Seleciona uma campanha para veres o estado guardado.') . '</div>';
            echo '<form method="post">';
            wp_nonce_field('routespro_assignment_hub_save_project_scope');
            echo '<input type="hidden" name="routespro_assignment_hub_action" value="save_project_scope">';
            echo '<input type="hidden" name="tab" value="projects">';
            echo '<div class="ff-form-grid" data-ff-context="project-form">';
            echo '<p><label><strong>Cliente / Marca</strong><br><select name="client_id" data-ff-role="client" style="width:100%"><option value="0">Todos</option>';
            foreach ($clients as $client) echo '<option value="' . (int)$client['id'] . '"' . selected($selected_client_id, (int)$client['id'], false) . '>' . esc_html($client['name']) . '</option>';
            echo '</select></label><br><span class="ff-note">Filtra as campanhas deste cliente.</span></p>';
            echo '<p><label><strong>Campanha / Projeto</strong><br><select name="project_id" data-ff-role="project" style="width:100%" required><option value="">Seleciona</option>';
            foreach ($projects as $item) { $clientName = self::find_client_name($clients, (int)$item['client_id']); echo '<option value="' . (int)$item['id'] . '" data-client-id="' . (int)$item['client_id'] . '"' . selected($selected_project_id, (int)$item['id'], false) . '>' . esc_html(($clientName ? $clientName . ' · ' : '') . $item['name'] . ' #' . (int)$item['id']) . '</option>'; }
            echo '</select></label><br><span class="ff-note">O save fica preso à campanha selecionada.</span></p>';
            echo '<p><label><strong>Função dos responsáveis</strong><br><select name="owner_role" style="width:100%">';
            foreach ($project_role_options as $role) echo '<option value="' . esc_attr($role) . '"' . selected($role, $project_owner_role, false) . '>' . esc_html($role) . '</option>';
            echo '</select></label></p>';
            echo '<p style="grid-column:1/-1"><label><strong>Utilizadores com acesso ao projeto</strong><br><select name="associated_user_ids[]" multiple size="10" style="width:min(100%,760px)">' . self::render_user_options($users, $associated) . '</select></label></p>';
            echo '<p style="grid-column:1/-1"><label><strong>Responsáveis ativos do projeto</strong><br><select name="owner_user_ids[]" multiple size="8" style="width:min(100%,760px)">' . self::render_user_options($users, $owners) . '</select></label><br><span class="ff-note">Estes users alimentam BO, front e sincronização operacional da campanha.</span></p>';
            echo '</div>';
            submit_button('Guardar atribuição de projeto');
            echo '</form>';
        } elseif ($tab === 'routes') {
            $route = AssignmentResolver::get_route_context($selected_route_id);
            $owner_id = (int)($route['owner_user_id'] ?? 0);
            $team_ids = array_map(function($row){ return (int)($row['user_id'] ?? 0); }, array_filter((array)($route['assignments'] ?? []), fn($row) => !empty($row['is_active']) && (($row['role'] ?? '') !== 'owner')));
            $route_meta = json_decode((string)($route['meta_json'] ?? ''), true);
            if (!is_array($route_meta)) $route_meta = [];
            $route_team_role = (string)($route_meta['default_team_role'] ?? 'operacional');
            foreach ((array)($route['assignments'] ?? []) as $route_assignment_row) {
                if (!empty($route_assignment_row['is_active']) && (($route_assignment_row['role'] ?? '') !== 'owner') && !empty($route_assignment_row['role'])) { $route_team_role = (string)$route_assignment_row['role']; break; }
            }
            [$route_role_options, $route_team_role] = self::resolve_selected_role($roles, $route_team_role, 'operacional');
            $assignable = AssignmentMatrix::get_assignable_users((int)($route['client_id'] ?? $selected_client_id), (int)($route['project_id'] ?? $selected_project_id));
            if (!$assignable) $assignable = $users;
            echo '<h2 class="ff-section-title">Atribuição de rota</h2>';
            echo '<p class="ff-note"><strong>Aba Rotas.</strong> Primeiro escolhes cliente, depois campanha, e só depois a rota. Todos os filtros são dinâmicos e o save fica preso à rota selecionada.</p>';
            echo '<div class="routespro-card" style="margin:12px 0 18px 0"><strong>Estado guardado agora:</strong> ' . ($selected_route_id ? esc_html('owner: ' . ($owner_id ? '#' . $owner_id : 'sem owner') . ', equipa adicional: ' . count($team_ids) . ' utilizadores.') : 'Seleciona cliente, campanha e rota para veres o estado guardado.') . '</div>';
            echo '<form method="post">';
            wp_nonce_field('routespro_assignment_hub_save_route_scope');
            echo '<input type="hidden" name="routespro_assignment_hub_action" value="save_route_scope">';
            echo '<input type="hidden" name="tab" value="routes">';
            echo '<div class="ff-form-grid" data-ff-context="route-form">';
            echo '<p><label><strong>Cliente / Marca</strong><br><select name="client_id" data-ff-role="client" style="width:100%"><option value="0">Todos</option>';
            foreach ($clients as $client) echo '<option value="' . (int)$client['id'] . '"' . selected((int)($route['client_id'] ?? $selected_client_id), (int)$client['id'], false) . '>' . esc_html($client['name']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Campanha / Projeto</strong><br><select name="project_id" data-ff-role="project" style="width:100%"><option value="0">Todos</option>';
            foreach ($projects as $item) { $clientName = self::find_client_name($clients, (int)$item['client_id']); echo '<option value="' . (int)$item['id'] . '" data-client-id="' . (int)$item['client_id'] . '"' . selected((int)($route['project_id'] ?? $selected_project_id), (int)$item['id'], false) . '>' . esc_html(($clientName ? $clientName . ' · ' : '') . $item['name'] . ' #' . (int)$item['id']) . '</option>'; }
            echo '</select></label></p>';
            echo '<p style="grid-column:1/-1"><label><strong>Rota</strong><br><select name="route_id" data-ff-role="route" style="width:100%" required><option value="">Seleciona</option>';
            foreach ($routes as $item) {
                $projectName = self::find_project_name($projects, (int)($item['project_id'] ?? 0));
                $clientId = (int)($item['client_id'] ?? 0);
                $projectId = (int)($item['project_id'] ?? 0);
                $label = '#' . (int)$item['id'] . ' · ' . ($projectName ?: 'Sem campanha') . ' · ' . ($item['date'] ?: 'sem data') . ' · ' . ($item['status'] ?: '');
                echo '<option value="' . (int)$item['id'] . '" data-client-id="' . $clientId . '" data-project-id="' . $projectId . '"' . selected($selected_route_id, (int)$item['id'], false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></label><br><span class="ff-note">A lista de rotas reage ao cliente e à campanha.</span></p>';
            echo '<p><label><strong>Owner operacional</strong><br><select name="owner_user_id" style="width:100%"><option value="0">Sem owner</option>' . self::render_user_options($assignable, $owner_id ? [$owner_id] : []) . '</select></label></p>';
            echo '<p><label><strong>Função da equipa</strong><br><select name="team_role" style="width:100%">';
            foreach ($route_role_options as $role) echo '<option value="' . esc_attr($role) . '"' . selected($role, $route_team_role, false) . '>' . esc_html($role) . '</option>';
            echo '</select></label></p>';
            echo '<p style="grid-column:1/-1"><label><strong>Equipa adicional da rota</strong><br><select name="team_user_ids[]" multiple size="10" style="width:min(100%,760px)">' . self::render_user_options($assignable, $team_ids) . '</select></label><br><span class="ff-note">O owner é sempre sincronizado, mesmo que não o seleciones novamente aqui.</span></p>';
            echo '</div>';
            submit_button('Guardar atribuição de rota');
            echo '</form>';
        } elseif ($tab === 'forms') {
            $bindings = AssignmentResolver::get_form_bindings(['client_id' => $selected_client_id, 'project_id' => $selected_project_id, 'route_id' => $selected_route_id]);
            echo '<h2 class="ff-section-title">Formulários por contexto</h2>';
            echo '<p class="ff-note"><strong>Aba Formulários.</strong> Primeiro filtras cliente e campanha, depois a rota. O save fica ligado exatamente ao contexto que selecionares.</p>';
            echo '<div class="routespro-card" style="margin:12px 0 18px 0"><strong>Estado guardado agora:</strong> ' . esc_html(count($bindings) . ' ligações encontradas para o contexto atual.') . '</div>';
            echo '<form method="post">';
            wp_nonce_field('routespro_assignment_hub_save_form_binding');
            echo '<input type="hidden" name="routespro_assignment_hub_action" value="save_form_binding">';
            echo '<input type="hidden" name="tab" value="forms">';
            echo '<div class="ff-form-grid" data-ff-context="binding-form">';
            echo '<p><label><strong>Formulário</strong><br><select name="form_id" style="width:100%" required><option value="">Seleciona</option>';
            foreach ($forms as $form) echo '<option value="' . (int)$form['id'] . '">' . esc_html(($form['title'] ?: 'Sem título') . ' #' . (int)$form['id']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Modo</strong><br><select name="mode" style="width:100%"><option value="route_and_form">Rota e formulário</option><option value="form_only">Só formulário</option><option value="route_only">Só rota</option></select></label></p>';
            echo '<p><label><strong>Prioridade</strong><br><input type="number" name="priority" value="10" min="0" max="999" style="width:120px"></label></p>';
            echo '<p><label><strong>Cliente / Marca</strong><br><select name="client_id" data-ff-role="client" style="width:100%"><option value="0">Nenhum</option>';
            foreach ($clients as $client) echo '<option value="' . (int)$client['id'] . '"' . selected($selected_client_id, (int)$client['id'], false) . '>' . esc_html($client['name']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Campanha / Projeto</strong><br><select name="project_id" data-ff-role="project" style="width:100%"><option value="0">Nenhum</option>';
            foreach ($projects as $project) echo '<option value="' . (int)$project['id'] . '" data-client-id="' . (int)$project['client_id'] . '"' . selected($selected_project_id, (int)$project['id'], false) . '>' . esc_html($project['name'] . ' #' . (int)$project['id']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Rota</strong><br><select name="route_id" data-ff-role="route" style="width:100%"><option value="0">Nenhuma</option>';
            foreach ($routes as $route) {
                $label = '#' . (int)$route['id'] . ' · ' . ($route['date'] ?: 'sem data');
                echo '<option value="' . (int)$route['id'] . '" data-client-id="' . (int)($route['client_id'] ?? 0) . '" data-project-id="' . (int)($route['project_id'] ?? 0) . '"' . selected($selected_route_id, (int)$route['id'], false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></label></p>';
            echo '<p><label><strong>Paragem</strong><br><select name="stop_id" style="width:100%"><option value="0">Nenhuma</option>';
            foreach ($stops as $stop) {
                $label = '#' . (int)$stop['id'] . ' · Rota #' . (int)$stop['route_id'] . ' · ' . ($stop['location_name'] ?: 'PDV');
                echo '<option value="' . (int)$stop['id'] . '">' . esc_html($label) . '</option>';
            }
            echo '</select></label></p>';
            echo '<p><label><strong>Local</strong><br><select name="location_id" style="width:100%"><option value="0">Nenhum</option>';
            foreach ($locations as $location) echo '<option value="' . (int)$location['id'] . '">' . esc_html($location['name'] . ' #' . (int)$location['id']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><input type="checkbox" name="is_active" value="1" checked> Ligação ativa</label></p>';
            echo '</div>';
            submit_button('Guardar ligação de formulário');
            echo '</form>';

            echo '<h3 style="margin-top:28px">Ligações existentes</h3>';
            echo '<table class="widefat striped ff-table"><thead><tr><th>ID</th><th>Formulário</th><th>Âmbito</th><th>Modo</th><th>Prioridade</th><th>Ação</th></tr></thead><tbody>';
            if (!$bindings) {
                echo '<tr><td colspan="6">Sem ligações para este contexto.</td></tr>';
            } else {
                foreach ($bindings as $row) {
                    $scope = [];
                    if (!empty($row['client_id'])) $scope[] = 'Cliente ' . ($row['client_name'] ?: '#' . (int)$row['client_id']);
                    if (!empty($row['project_id'])) $scope[] = 'Projeto ' . ($row['project_name'] ?: '#' . (int)$row['project_id']);
                    if (!empty($row['route_id'])) $scope[] = 'Rota #' . (int)$row['route_id'] . ' ' . ($row['route_date'] ?: '');
                    if (!empty($row['location_id'])) $scope[] = 'Local ' . ($row['location_name'] ?: '#' . (int)$row['location_id']);
                    if (!empty($row['stop_id'])) $scope[] = 'Paragem #' . (int)$row['stop_id'];
                    echo '<tr><td>' . (int)$row['id'] . '</td><td>' . esc_html($row['form_title'] ?: ('#' . (int)$row['form_id'])) . '</td><td>' . esc_html(implode(' | ', $scope)) . '</td><td>' . esc_html($row['mode']) . '</td><td>' . (int)$row['priority'] . '</td><td><form method="post" style="margin:0">';
                    wp_nonce_field('routespro_assignment_hub_delete_form_binding');
                    echo '<input type="hidden" name="routespro_assignment_hub_action" value="delete_form_binding"><input type="hidden" name="binding_id" value="' . (int)$row['id'] . '"><button class="button button-small" onclick="return confirm(\'Remover ligação?\')">Apagar</button></form></td></tr>';
                }
            }
            echo '</tbody></table>';
        } elseif ($tab === 'analytics') {
            $analytics_questions = [];
            $analytics_question_map = [];
            foreach ($forms as $form) {
                $form_id = (int)($form['id'] ?? 0);
                $schema_questions = self::extract_schema_fields((string)($form['schema_json'] ?? ''));
                $analytics_question_map[$form_id] = $schema_questions;
                foreach ($schema_questions as $question) {
                    $analytics_questions[] = [
                        'form_id' => $form_id,
                        'question_key' => $question['question_key'],
                        'question_label' => $question['question_label'],
                        'question_type' => $question['question_type'],
                    ];
                }
            }
            $context_table = ContextQuestionService::table();
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $context_table)) === $context_table) {
                $ctx_where = ['is_active=1'];
                $ctx_args = [];
                if ($selected_client_id) { $ctx_where[] = '(client_id=0 OR client_id=%d)'; $ctx_args[] = $selected_client_id; }
                if ($selected_project_id) { $ctx_where[] = '(project_id=0 OR project_id=%d)'; $ctx_args[] = $selected_project_id; }
                $ctx_sql = 'SELECT id, form_id, question_key, question_label, question_type, context_type, client_id, project_id, location_id FROM ' . $context_table . ' WHERE ' . implode(' AND ', $ctx_where) . ' ORDER BY priority ASC, id ASC LIMIT 1000';
                $ctx_questions = $ctx_args ? ($wpdb->get_results($wpdb->prepare($ctx_sql, ...$ctx_args), ARRAY_A) ?: []) : ($wpdb->get_results($ctx_sql, ARRAY_A) ?: []);
                foreach ($ctx_questions as $cq) {
                    $target_form_ids = [];
                    $ctx_form_id = (int)($cq['form_id'] ?? 0);
                    if ($ctx_form_id > 0) {
                        $target_form_ids[] = $ctx_form_id;
                    } else {
                        foreach ($forms as $form_for_ctx) $target_form_ids[] = (int)($form_for_ctx['id'] ?? 0);
                    }
                    foreach (array_unique(array_filter($target_form_ids)) as $target_form_id) {
                        $key = sanitize_key((string)($cq['question_key'] ?? ''));
                        if ($key === '') continue;
                        $exists = false;
                        foreach (($analytics_question_map[$target_form_id] ?? []) as $existing_question) {
                            if (($existing_question['question_key'] ?? '') === $key) { $exists = true; break; }
                        }
                        if ($exists) continue;
                        $label = trim((string)($cq['question_label'] ?? $key));
                        $analytics_question_map[$target_form_id][] = [
                            'question_key' => $key,
                            'question_label' => $label . ' · Pergunta contextual',
                            'question_type' => sanitize_key((string)($cq['question_type'] ?? 'text')),
                            'source' => 'context_question',
                        ];
                        $analytics_questions[] = [
                            'form_id' => $target_form_id,
                            'question_key' => $key,
                            'question_label' => $label,
                            'question_type' => sanitize_key((string)($cq['question_type'] ?? 'text')),
                        ];
                    }
                }
            }
            $dash_table = self::analytics_dashboard_table();
            $group_table = self::analytics_store_group_table();
            $item_table = self::analytics_store_group_items_table();
            $analytics_dashboards = $wpdb->get_results("SELECT * FROM {$dash_table} ORDER BY sort_order ASC, id ASC", ARRAY_A) ?: [];
            $analytics_groups = $wpdb->get_results("SELECT g.*, COUNT(i.location_id) AS locations_count FROM {$group_table} g LEFT JOIN {$item_table} i ON i.group_id=g.id GROUP BY g.id ORDER BY g.name ASC", ARRAY_A) ?: [];
            $analytics_locations = $wpdb->get_results("SELECT id, client_id, project_id, name, city, district FROM {$px}locations WHERE is_active=1 ORDER BY name ASC LIMIT 2000", ARRAY_A) ?: [];
            $bindings_sql = 'SELECT b.*, f.title AS form_title FROM ' . self::analytics_table() . ' b LEFT JOIN ' . FormsModule::table() . ' f ON f.id=b.form_id WHERE 1=1';
            $analytics_bindings = $wpdb->get_results($bindings_sql, ARRAY_A) ?: [];

            echo '<h2>Analytics de formulários</h2>';
            echo '<p><strong>Fase D.</strong> Cria dashboards analíticos, grupos de lojas e widgets. O portal cliente passa a mostrar a estrutura configurada mesmo quando ainda não existem respostas.</p>';
            echo '<div class="routespro-card" style="margin:12px 0 18px 0"><strong>Estado guardado agora:</strong> ' . esc_html(count($analytics_dashboards) . ' dashboards, ' . count($analytics_groups) . ' grupos de lojas e ' . count($analytics_bindings) . ' métricas/widgets.') . '</div>';

            echo '<div class="ff-form-grid" style="align-items:start">';
            echo '<div class="routespro-card"><h3 style="margin-top:0">1. Criar dashboard</h3><form method="post">';
            wp_nonce_field('routespro_assignment_hub_save_analytics_dashboard');
            echo '<input type="hidden" name="routespro_assignment_hub_action" value="save_analytics_dashboard"><input type="hidden" name="tab" value="analytics">';
            echo '<p><label><strong>Cliente / Marca</strong><br><select name="client_id" data-ff-role="client" style="width:100%"><option value="0">Todos</option>';
            foreach ($clients as $client) echo '<option value="' . (int)$client['id'] . '"' . selected($selected_client_id, (int)$client['id'], false) . '>' . esc_html($client['name']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Campanha / Projeto</strong><br><select name="project_id" data-ff-role="project" style="width:100%"><option value="0">Todos</option>';
            foreach ($projects as $project) echo '<option value="' . (int)$project['id'] . '" data-client-id="' . (int)$project['client_id'] . '"' . selected($selected_project_id, (int)$project['id'], false) . '>' . esc_html($project['name'] . ' #' . (int)$project['id']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Rota</strong><br><select name="route_id" data-ff-role="route" style="width:100%"><option value="0">Todas</option>';
            foreach ($routes as $route) echo '<option value="' . (int)$route['id'] . '" data-client-id="' . (int)($route['client_id'] ?? 0) . '" data-project-id="' . (int)($route['project_id'] ?? 0) . '">#' . (int)$route['id'] . ' · ' . esc_html($route['date'] ?: 'sem data') . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Nome do dashboard</strong><br><input type="text" name="dashboard_title" placeholder="Frentes e execução por loja" style="width:100%" required></label></p>';
            echo '<p><label><strong>Descrição</strong><br><textarea name="dashboard_description" rows="2" style="width:100%" placeholder="Quadro para acompanhamento executivo do cliente."></textarea></label></p>';
            echo '<p><label><strong>Layout</strong><br><select name="dashboard_layout_type" style="width:100%"><option value="mixed">Misto: KPIs + gráficos + tabelas</option><option value="executive">Resumo executivo</option><option value="table">Tabelas detalhadas</option></select></label></p>';
            echo '<p><label><strong>Ordem</strong><br><input type="number" name="dashboard_sort_order" value="10" style="width:100%"></label></p>';
            echo '<p><label><input type="checkbox" name="dashboard_is_active" value="1" checked> Dashboard ativo e visível no portal</label></p>';
            submit_button('Guardar dashboard');
            echo '</form></div>';

            echo '<div class="routespro-card"><h3 style="margin-top:0">2. Criar grupo de lojas</h3><form method="post">';
            wp_nonce_field('routespro_assignment_hub_save_analytics_store_group');
            echo '<input type="hidden" name="routespro_assignment_hub_action" value="save_analytics_store_group"><input type="hidden" name="tab" value="analytics">';
            echo '<p><label><strong>Cliente</strong><br><select name="client_id" data-ff-role="client" style="width:100%"><option value="0">Todos</option>';
            foreach ($clients as $client) echo '<option value="' . (int)$client['id'] . '"' . selected($selected_client_id, (int)$client['id'], false) . '>' . esc_html($client['name']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Campanha</strong><br><select name="project_id" data-ff-role="project" style="width:100%"><option value="0">Todas</option>';
            foreach ($projects as $project) echo '<option value="' . (int)$project['id'] . '" data-client-id="' . (int)$project['client_id'] . '"' . selected($selected_project_id, (int)$project['id'], false) . '>' . esc_html($project['name'] . ' #' . (int)$project['id']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Nome do grupo</strong><br><input type="text" name="store_group_name" placeholder="Grande Lisboa, Norte, Auchan, Top 20" style="width:100%" required></label></p>';
            echo '<p><label><strong>Lojas / Locais</strong><br><input type="search" class="regular-text" data-ff-store-group-search placeholder="Pesquisar por nome, cidade, distrito ou ID" style="width:100%;max-width:none;margin-bottom:8px"></label>';
            echo '<span style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px"><button type="button" class="button" data-ff-store-select="visible">Selecionar visíveis</button><button type="button" class="button" data-ff-store-select="clear">Limpar seleção</button><button type="button" class="button" data-ff-store-select="client">Selecionar cliente atual</button><button type="button" class="button" data-ff-store-select="project">Selecionar campanha atual</button><span class="ff-note" data-ff-store-count>0 selecionadas</span></span>';
            echo '<select name="store_group_location_ids[]" multiple size="14" data-ff-store-group-select style="width:100%;min-height:320px">';
            foreach ($analytics_locations as $loc) {
                $label = '#' . (int)$loc['id'] . ' · ' . (string)$loc['name'];
                if (!empty($loc['city'])) $label .= ' · ' . (string)$loc['city'];
                if (!empty($loc['district'])) $label .= ' · ' . (string)$loc['district'];
                echo '<option value="' . (int)$loc['id'] . '" data-search="' . esc_attr(strtolower(remove_accents($label))) . '" data-client-id="' . (int)($loc['client_id'] ?? 0) . '" data-project-id="' . (int)($loc['project_id'] ?? 0) . '">' . esc_html($label) . '</option>';
            }
            echo '</select><span class="ff-note">Pesquisa, filtra por cliente/campanha e usa seleção em massa. Assim não tens de picar 276 lojas uma a uma, que isso é castigo bíblico disfarçado de backoffice.</span></p>';
            echo '<p><label><input type="checkbox" name="store_group_is_active" value="1" checked> Grupo ativo</label></p>';
            submit_button('Guardar grupo');
            echo '</form></div>';
            echo '</div>';

            echo '<div class="routespro-card" style="margin-top:18px"><h3 style="margin-top:0">3. Criar widget / métrica</h3><form method="post">';
            wp_nonce_field('routespro_assignment_hub_save_analytics_binding');
            echo '<input type="hidden" name="routespro_assignment_hub_action" value="save_analytics_binding"><input type="hidden" name="tab" value="analytics">';
            echo '<div class="ff-form-grid" data-ff-context="analytics-form">';
            echo '<p><label><strong>Dashboard</strong><br><select name="dashboard_id" style="width:100%"><option value="0">Dashboard legacy / automático</option>';
            foreach ($analytics_dashboards as $dash) echo '<option value="' . (int)$dash['id'] . '">' . esc_html($dash['title'] . ' #' . (int)$dash['id']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Grupo de lojas</strong><br><select name="store_group_id" style="width:100%"><option value="0">Todas as lojas do âmbito</option>';
            foreach ($analytics_groups as $grp) echo '<option value="' . (int)$grp['id'] . '">' . esc_html($grp['name'] . ' (' . (int)$grp['locations_count'] . ' lojas)') . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Cliente / Marca</strong><br><select name="client_id" data-ff-role="client" style="width:100%"><option value="0">Todos</option>';
            foreach ($clients as $client) echo '<option value="' . (int)$client['id'] . '"' . selected($selected_client_id, (int)$client['id'], false) . '>' . esc_html($client['name']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Campanha / Projeto</strong><br><select name="project_id" data-ff-role="project" style="width:100%"><option value="0">Todos</option>';
            foreach ($projects as $project) echo '<option value="' . (int)$project['id'] . '" data-client-id="' . (int)$project['client_id'] . '"' . selected($selected_project_id, (int)$project['id'], false) . '>' . esc_html($project['name'] . ' #' . (int)$project['id']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Rota</strong><br><select name="route_id" data-ff-role="route" style="width:100%"><option value="0">Todas</option>';
            foreach ($routes as $route) echo '<option value="' . (int)$route['id'] . '" data-client-id="' . (int)($route['client_id'] ?? 0) . '" data-project-id="' . (int)($route['project_id'] ?? 0) . '">#' . (int)$route['id'] . ' · ' . esc_html($route['date'] ?: 'sem data') . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Formulário</strong><br><select name="form_id" data-ff-analytics-form style="width:100%" required><option value="">Seleciona</option>';
            foreach ($forms as $form) echo '<option value="' . (int)$form['id'] . '">' . esc_html(($form['title'] ?: 'Sem título') . ' #' . (int)$form['id']) . '</option>';
            echo '</select></label></p>';
            echo '<p><label><strong>Pergunta</strong><br><select name="question_key" data-ff-analytics-question style="width:100%" required><option value="">Seleciona um formulário primeiro</option></select></label></p>';
            echo '<p><label><strong>Chave da métrica</strong><br><input type="text" name="metric_key" placeholder="frentes_atuais" style="width:100%" required></label></p>';
            echo '<p><label><strong>Label</strong><br><input type="text" name="metric_label" placeholder="Frentes atuais" style="width:100%" required></label></p>';
            echo '<p><label><strong>Gráfico</strong><br><select name="chart_type" style="width:100%">' . self::render_simple_options(self::analytics_options('chart'), 'line') . '</select></label></p>';
            echo '<p><label><strong>Agregação</strong><br><select name="aggregation" style="width:100%">' . self::render_simple_options(self::analytics_options('aggregation'), 'latest') . '</select></label></p>';
            echo '<p><label><strong>Dimensão principal</strong><br><select name="dimension" style="width:100%">' . self::render_simple_options(self::analytics_options('dimension'), 'location_id') . '</select></label></p>';
            echo '<p><label><strong>Dimensão secundária</strong><br><select name="secondary_dimension" style="width:100%"><option value="">Nenhuma</option>' . self::render_simple_options(self::analytics_options('dimension'), '') . '</select></label></p>';
            echo '<p><label><strong>Modo de scope</strong><br><select name="scope_mode" style="width:100%">' . self::render_simple_options(self::analytics_options('scope'), 'client_project_location') . '</select></label></p>';
            echo '<p><label><strong>Ordem</strong><br><input type="number" name="sort_order" value="10" style="width:100%"></label></p>';
            echo '<p><label><input type="checkbox" name="show_kpi" value="1" checked> Mostrar KPI no topo</label><br><label><input type="checkbox" name="show_table" value="1" checked> Mostrar tabela detalhada</label><br><label><input type="checkbox" name="show_empty" value="1" checked> Mostrar mesmo sem dados</label><br><label><input type="checkbox" name="is_active" value="1" checked> Widget ativo</label></p>';
            echo '</div>';
            submit_button('Guardar widget analítico');
            echo '</form></div>';

            $analytics_question_map_json = wp_json_encode($analytics_question_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            echo '<script>(function(){var formSel=document.querySelector("[data-ff-analytics-form]");var questionSel=document.querySelector("[data-ff-analytics-question]");var questionMap=' . ($analytics_question_map_json ?: '{}') . ';function escHtml(s){return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/\"/g,"&quot;").replace(/\'/g,"&#039;");}function renderQuestions(){if(!formSel||!questionSel)return;var formId=String(formSel.value||"");var items=questionMap[formId]||[];var html="";if(!formId){html="<option value=\"\">Seleciona um formulário primeiro</option>";}else if(!items.length){html="<option value=\"\">Sem perguntas disponíveis neste formulário</option>";}else{html="<option value=\"\">Seleciona uma pergunta</option>";items.forEach(function(item){var key=String(item.question_key||"");if(!key)return;var label=String(item.question_label||key);var source=String(item.source||"")==="context_question"?" · contextual":"";html+="<option value=\""+escHtml(key)+"\">"+escHtml(label)+source+" ["+escHtml(key)+"]</option>";});}questionSel.innerHTML=html;}if(formSel){formSel.addEventListener("change",renderQuestions);renderQuestions();}function bindStorePicker(){var sel=document.querySelector("[data-ff-store-group-select]");if(!sel)return;var search=document.querySelector("[data-ff-store-group-search]");var count=document.querySelector("[data-ff-store-count]");var card=sel.closest(".routespro-card")||document;function updateCount(){if(count)count.textContent=Array.from(sel.options).filter(function(o){return o.selected;}).length+" selecionadas";}function currentClient(){var c=card.querySelector("[data-ff-role=client]");return c?String(c.value||"0"):"0";}function currentProject(){var p=card.querySelector("[data-ff-role=project]");return p?String(p.value||"0"):"0";}function filter(){var q=(search&&search.value?search.value:"").toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g,"");var c=currentClient();var p=currentProject();Array.from(sel.options).forEach(function(o){var ok=true;if(q&&String(o.dataset.search||"").indexOf(q)===-1)ok=false;if(c!=="0"&&String(o.dataset.clientId||"0")!==c)ok=false;if(p!=="0"&&String(o.dataset.projectId||"0")!==p)ok=false;o.hidden=!ok;});}if(search)search.addEventListener("input",filter);card.querySelectorAll("[data-ff-role=client],[data-ff-role=project]").forEach(function(el){el.addEventListener("change",filter);});document.querySelectorAll("[data-ff-store-select]").forEach(function(btn){btn.addEventListener("click",function(){var mode=btn.dataset.ffStoreSelect;var c=currentClient();var p=currentProject();Array.from(sel.options).forEach(function(o){if(mode==="clear"){o.selected=false;return;}if(o.hidden)return;if(mode==="client"&&c!=="0"&&String(o.dataset.clientId||"0")!==c)return;if(mode==="project"&&p!=="0"&&String(o.dataset.projectId||"0")!==p)return;o.selected=true;});updateCount();});});sel.addEventListener("change",updateCount);filter();updateCount();}bindStorePicker();})();</script>';

            echo '<h3 style="margin-top:28px">Dashboards existentes</h3><table class="widefat striped ff-table"><thead><tr><th>ID</th><th>Dashboard</th><th>Âmbito</th><th>Layout</th><th>Estado</th><th>Ação</th></tr></thead><tbody>';
            if (!$analytics_dashboards) echo '<tr><td colspan="6">Sem dashboards configurados.</td></tr>';
            foreach ($analytics_dashboards as $dash) {
                echo '<tr><td>' . (int)$dash['id'] . '</td><td><strong>' . esc_html($dash['title']) . '</strong><br><span class="ff-note">' . esc_html(wp_strip_all_tags((string)$dash['description'])) . '</span></td><td>Cliente #' . (int)$dash['client_id'] . ' | Projeto #' . (int)$dash['project_id'] . ' | Rota #' . (int)$dash['route_id'] . '</td><td>' . esc_html($dash['layout_type']) . ' · ordem ' . (int)$dash['sort_order'] . '</td><td>' . ((int)$dash['is_active'] ? 'Ativo' : 'Inativo') . '</td><td><form method="post" style="margin:0">';
                wp_nonce_field('routespro_assignment_hub_delete_analytics_dashboard');
                echo '<input type="hidden" name="routespro_assignment_hub_action" value="delete_analytics_dashboard"><input type="hidden" name="analytics_dashboard_id" value="' . (int)$dash['id'] . '"><button class="button button-small" onclick="return confirm(\'Remover dashboard analítico?\')">Apagar</button></form></td></tr>';
            }
            echo '</tbody></table>';

            echo '<h3 style="margin-top:28px">Grupos de lojas</h3><table class="widefat striped ff-table"><thead><tr><th>ID</th><th>Grupo</th><th>Âmbito</th><th>Lojas</th><th>Ação</th></tr></thead><tbody>';
            if (!$analytics_groups) echo '<tr><td colspan="5">Sem grupos de lojas.</td></tr>';
            foreach ($analytics_groups as $grp) {
                echo '<tr><td>' . (int)$grp['id'] . '</td><td><strong>' . esc_html($grp['name']) . '</strong><br><span class="ff-note">' . esc_html($grp['group_type']) . '</span></td><td>Cliente #' . (int)$grp['client_id'] . ' | Projeto #' . (int)$grp['project_id'] . '</td><td>' . (int)$grp['locations_count'] . '</td><td><form method="post" style="margin:0">';
                wp_nonce_field('routespro_assignment_hub_delete_analytics_store_group');
                echo '<input type="hidden" name="routespro_assignment_hub_action" value="delete_analytics_store_group"><input type="hidden" name="analytics_store_group_id" value="' . (int)$grp['id'] . '"><button class="button button-small" onclick="return confirm(\'Remover grupo de lojas?\')">Apagar</button></form></td></tr>';
            }
            echo '</tbody></table>';

            echo '<h3 style="margin-top:28px">Widgets existentes</h3>';
            echo '<table class="widefat striped ff-table"><thead><tr><th>ID</th><th>Dashboard</th><th>Formulário</th><th>Pergunta</th><th>Métrica</th><th>Visualização</th><th>Scope</th><th>Ação</th></tr></thead><tbody>';
            if (!$analytics_bindings) {
                echo '<tr><td colspan="8">Sem widgets analíticos.</td></tr>';
            } else {
                $dashNames=[]; foreach($analytics_dashboards as $d){ $dashNames[(int)$d['id']]=$d['title']; }
                $grpNames=[]; foreach($analytics_groups as $g){ $grpNames[(int)$g['id']]=$g['name']; }
                foreach ($analytics_bindings as $row) {
                    $scopeSettings = json_decode((string)($row['settings_json'] ?? '{}'), true); if(!is_array($scopeSettings)) $scopeSettings=[];
                    $scopeLabel = [];
                    if (!empty($scopeSettings['client_id'])) $scopeLabel[] = 'Cliente #' . (int)$scopeSettings['client_id'];
                    if (!empty($scopeSettings['project_id'])) $scopeLabel[] = 'Projeto #' . (int)$scopeSettings['project_id'];
                    if (!empty($scopeSettings['route_id'])) $scopeLabel[] = 'Rota #' . (int)$scopeSettings['route_id'];
                    if (!$scopeLabel) $scopeLabel[] = 'Global';
                    $dashId=(int)($scopeSettings['dashboard_id'] ?? 0); $grpId=(int)($scopeSettings['store_group_id'] ?? 0);
                    echo '<tr><td>' . (int)$row['id'] . '</td>';
                    echo '<td>' . esc_html($dashId && isset($dashNames[$dashId]) ? $dashNames[$dashId] : 'Legacy / automático') . '</td>';
                    echo '<td>' . esc_html($row['form_title'] ?: ('#' . (int)$row['form_id'])) . '</td>';
                    echo '<td><code>' . esc_html($row['question_key']) . '</code></td>';
                    echo '<td><strong>' . esc_html($row['metric_label']) . '</strong><br><span class="ff-note"><code>' . esc_html($row['metric_key']) . '</code></span></td>';
                    echo '<td>' . esc_html($row['chart_type']) . ' · ' . esc_html($row['aggregation']) . '<br><span class="ff-note">Dimensão: ' . esc_html($row['dimension']) . ($grpId ? ' · Grupo: ' . esc_html($grpNames[$grpId] ?? ('#'.$grpId)) : '') . '</span></td>';
                    echo '<td>' . esc_html(implode(' | ', $scopeLabel)) . '<br><span class="ff-note">' . esc_html($row['scope_mode']) . '</span></td>';
                    echo '<td><form method="post" style="margin:0">';
                    wp_nonce_field('routespro_assignment_hub_delete_analytics_binding');
                    echo '<input type="hidden" name="routespro_assignment_hub_action" value="delete_analytics_binding"><input type="hidden" name="analytics_binding_id" value="' . (int)$row['id'] . '"><button class="button button-small" onclick="return confirm(\'Remover widget analítico?\')">Apagar</button></form></td></tr>';
                }
            }
            echo '</tbody></table>';
        }

        echo '</section></div>';
        echo '<script>(function(){
            function syncSelectVisibility(root){
                const client = root.querySelector("[data-ff-role=client]");
                const project = root.querySelector("[data-ff-role=project]");
                const route = root.querySelector("[data-ff-role=route]");
                const clientVal = client ? (client.value || "0") : "0";
                const projectVal = project ? (project.value || "0") : "0";
                if (project) {
                    Array.from(project.options).forEach(function(opt){
                        if (!opt.dataset.clientId) return;
                        const visible = clientVal === "0" || opt.dataset.clientId === clientVal;
                        opt.hidden = !visible;
                        if (!visible && opt.selected) project.value = "0";
                    });
                }
                if (route) {
                    Array.from(route.options).forEach(function(opt){
                        if (!opt.dataset.clientId && !opt.dataset.projectId) return;
                        const matchClient = clientVal === "0" || opt.dataset.clientId === clientVal;
                        const matchProject = projectVal === "0" || opt.dataset.projectId === projectVal;
                        const visible = matchClient && matchProject;
                        opt.hidden = !visible;
                        if (!visible && opt.selected) route.value = "0";
                    });
                }
            }
            function bindContext(root){
                if (!root) return;
                const client = root.querySelector("[data-ff-role=client]");
                const project = root.querySelector("[data-ff-role=project]");
                const route = root.querySelector("[data-ff-role=route]");
                syncSelectVisibility(root);
                if (client) client.addEventListener("change", function(){
                    if (project && project.selectedOptions[0] && project.selectedOptions[0].dataset.clientId && project.selectedOptions[0].dataset.clientId !== this.value) project.value = "0";
                    if (route) route.value = "0";
                    syncSelectVisibility(root);
                });
                if (project) project.addEventListener("change", function(){
                    const opt = this.selectedOptions[0];
                    if (client && opt && opt.dataset.clientId && client.value !== opt.dataset.clientId) client.value = opt.dataset.clientId;
                    if (route) route.value = "0";
                    syncSelectVisibility(root);
                });
                if (route) route.addEventListener("change", function(){
                    const opt = this.selectedOptions[0];
                    if (!opt) return;
                    if (client && opt.dataset.clientId && client.value !== opt.dataset.clientId) client.value = opt.dataset.clientId;
                    if (project && opt.dataset.projectId && project.value !== opt.dataset.projectId) project.value = opt.dataset.projectId;
                    syncSelectVisibility(root);
                });
            }
            document.querySelectorAll("[data-ff-context]").forEach(bindContext);
            const sidebar = document.querySelector(".ff-hub-sidebar form");
            if (sidebar) bindContext(sidebar);
        })();</script>';
        echo '</div>';
    }

    public static function render() {
        echo '<div class="wrap">';
        echo '<div class="notice notice-info"><p>Esta página legacy foi substituída pelo <strong>Centro de Atribuições</strong>.</p><p><a class="button button-primary" href="' . esc_url(self::admin_url()) . '">Abrir Centro de Atribuições</a></p></div>';
        echo '</div>';
    }
}
