<?php
namespace RoutesPro\Rest;

use WP_REST_Request;
use WP_REST_Response;
use RoutesPro\Support\Permissions;

class StatsController {
    const NS = 'routespro/v1';

    public function register_routes() {
        register_rest_route(self::NS, '/stats', [
            'methods'  => 'GET',
            'callback' => [$this, 'stats'],
            'permission_callback' => function(){ return current_user_can('routespro_manage') || Permissions::can_access_front(); }
        ]);
        register_rest_route(self::NS, '/heatmap', [
            'methods'  => 'GET',
            'callback' => [$this, 'heatmap'],
            'permission_callback' => function(){ return current_user_can('routespro_manage') || Permissions::can_access_front(); }
        ]);
    }

    private function build_where(WP_REST_Request $req, array &$args, string $routeAlias = 'r'): string {
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $from = sanitize_text_field($req->get_param('from')) ?: date('Y-m-d', strtotime('-7 days'));
        $to   = sanitize_text_field($req->get_param('to'))   ?: date('Y-m-d');
        $client_id  = absint($req->get_param('client_id') ?: 0);
        $project_id = absint($req->get_param('project_id') ?: 0);
        $user_id    = absint($req->get_param('user_id') ?: 0);
        $role       = sanitize_text_field($req->get_param('role') ?: '');
        $location_id = absint($req->get_param('location_id') ?: 0);

        $scopeCheck = Permissions::assert_scope_or_error($client_id, $project_id);
        if (is_wp_error($scopeCheck)) return '';

        $where = ["{$routeAlias}.date BETWEEN %s AND %s"];
        $args  = [$from, $to];
        if ($client_id)  { $where[] = "{$routeAlias}.client_id = %d";  $args[] = $client_id; }
        if ($project_id) { $where[] = "{$routeAlias}.project_id = %d"; $args[] = $project_id; }
        list($scopeSql, $scopeArgs) = Permissions::scope_sql($routeAlias);
        if ($scopeSql !== '1=1') { $where[] = $scopeSql; $args = array_merge($args, $scopeArgs); }

        if ($location_id) {
            $where[] = "EXISTS (SELECT 1 FROM {$px}route_stops s_loc WHERE s_loc.route_id = {$routeAlias}.id AND s_loc.location_id = %d)";
            $args[] = $location_id;
        }

        if ($user_id && $role) {
            $where[] = "({$routeAlias}.owner_user_id = %d OR EXISTS (SELECT 1 FROM {$px}assignments ax WHERE ax.route_id = {$routeAlias}.id AND ax.user_id = %d AND ax.role = %s))";
            $args[] = $user_id; $args[] = $user_id; $args[] = $role;
        } elseif ($user_id) {
            $where[] = "({$routeAlias}.owner_user_id = %d OR EXISTS (SELECT 1 FROM {$px}assignments ax WHERE ax.route_id = {$routeAlias}.id AND ax.user_id = %d))";
            $args[] = $user_id; $args[] = $user_id;
        } elseif ($role) {
            $where[] = "EXISTS (SELECT 1 FROM {$px}assignments ax WHERE ax.route_id = {$routeAlias}.id AND ax.role = %s)";
            $args[] = $role;
        }
        return $wpdb->prepare(implode(' AND ', $where), ...$args);
    }

    public function stats(WP_REST_Request $req) {
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $args = [];
        $sqlWhere = $this->build_where($req, $args, 'r');
        if ($sqlWhere === '') return new \WP_Error('forbidden', 'Sem acesso ao âmbito pedido.', ['status' => 403]);

        $from = sanitize_text_field($req->get_param('from')) ?: date('Y-m-d', strtotime('-7 days'));
        $to   = sanitize_text_field($req->get_param('to'))   ?: date('Y-m-d');

        $total_routes = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT r.id FROM {$px}routes r WHERE $sqlWhere GROUP BY r.id) x");
        $total_stops = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT DISTINCT s.id FROM {$px}route_stops s INNER JOIN {$px}routes r ON r.id = s.route_id WHERE $sqlWhere) x");
        $done_stops = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT DISTINCT s.id FROM {$px}route_stops s INNER JOIN {$px}routes r ON r.id = s.route_id WHERE $sqlWhere AND s.status IN ('done','completed')) x");
        $completed_routes = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT r.id FROM {$px}routes r WHERE $sqlWhere AND NOT EXISTS (SELECT 1 FROM {$px}route_stops s WHERE s.route_id = r.id AND s.status NOT IN ('done','completed')) GROUP BY r.id) x");
        $on_time = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT DISTINCT e.id FROM {$px}events e INNER JOIN {$px}route_stops s ON s.id = e.route_stop_id INNER JOIN {$px}routes r ON r.id = s.route_id INNER JOIN {$px}locations l ON l.id = s.location_id WHERE $sqlWhere AND e.event_type = 'checkin' AND l.window_start IS NOT NULL AND l.window_end IS NOT NULL AND TIME(e.created_at) BETWEEN l.window_start AND l.window_end) x");
        $with_windows = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT DISTINCT s.id FROM {$px}route_stops s INNER JOIN {$px}routes r ON r.id = s.route_id INNER JOIN {$px}locations l ON l.id = s.location_id WHERE $sqlWhere AND l.window_start IS NOT NULL AND l.window_end IS NOT NULL) x");
        $avg_stop_secs = (int)$wpdb->get_var("SELECT AVG(t.diff_s) FROM (SELECT DISTINCT s.id, TIMESTAMPDIFF(SECOND, ci.created_at, co.created_at) AS diff_s FROM {$px}route_stops s INNER JOIN {$px}routes r ON r.id = s.route_id LEFT JOIN {$px}events ci ON ci.route_stop_id = s.id AND ci.event_type = 'checkin' LEFT JOIN {$px}events co ON co.route_stop_id = s.id AND co.event_type = 'checkout' WHERE $sqlWhere AND ci.id IS NOT NULL AND co.id IS NOT NULL) t");

        $res = [
            'from' => $from,
            'to'   => $to,
            'total_routes'        => $total_routes,
            'completed_routes'    => $completed_routes,
            'completion_rate'     => $total_routes ? round($completed_routes/$total_routes*100,1) : 0,
            'total_stops'         => $total_stops,
            'done_stops'          => $done_stops,
            'done_rate'           => $total_stops ? round($done_stops/$total_stops*100,1) : 0,
            'avg_stops_per_route' => $total_routes ? round($total_stops/$total_routes,2) : 0,
            'on_time_rate'        => $with_windows ? round($on_time/$with_windows*100,1) : null,
            'avg_stop_minutes'    => $avg_stop_secs ? round($avg_stop_secs/60,1) : null,
        ];

        $rows = $wpdb->get_results("SELECT r.id as route_id, r.date, r.status as route_status, p.name as project_name, c.name as client_name, COALESCE((SELECT COALESCE(NULLIF(uo.display_name,''), uo.user_login) FROM {$wpdb->users} uo WHERE uo.ID = r.owner_user_id LIMIT 1),(SELECT COALESCE(NULLIF(ua.display_name,''), ua.user_login) FROM {$px}assignments a2 JOIN {$wpdb->users} ua ON ua.ID = a2.user_id WHERE a2.route_id = r.id ORDER BY a2.id ASC LIMIT 1)) AS user_name, COUNT(s.id) as stops, SUM(CASE WHEN s.status IN ('done','completed') THEN 1 ELSE 0 END) as stops_done, ROUND(100.0 * SUM(CASE WHEN s.status IN ('done','completed') THEN 1 ELSE 0 END)/NULLIF(COUNT(s.id),0),1) as done_rate FROM {$px}routes r LEFT JOIN {$px}route_stops s ON s.route_id = r.id LEFT JOIN {$px}projects p ON p.id = r.project_id LEFT JOIN {$px}clients c ON c.id = r.client_id LEFT JOIN {$px}assignments a ON a.route_id = r.id WHERE $sqlWhere GROUP BY r.id ORDER BY r.date DESC, r.id DESC LIMIT 500", ARRAY_A);
        $res['by_day'] = $rows ?: [];
        $periodDays = max(1, (int) floor((strtotime($to) - strtotime($from)) / DAY_IN_SECONDS) + 1);
        $previousTo = date('Y-m-d', strtotime($from . ' -1 day'));
        $previousFrom = date('Y-m-d', strtotime($previousTo . ' -' . max(0, $periodDays - 1) . ' days'));
        $res['previous_period'] = ['from' => $previousFrom, 'to' => $previousTo];
        $res['previous_summary'] = $this->range_summary($req, $previousFrom, $previousTo);
        $res['rankings'] = $this->top_rankings($rows ?: []);
        $res['form_analytics'] = $this->build_form_analytics($req, $from, $to);
        return new WP_REST_Response($res, 200);
    }

    private function build_form_analytics(WP_REST_Request $req, string $from, string $to): array {
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $bindings_table = $px . 'form_analytics_bindings';
        $dashboards_table = $px . 'analytics_dashboards';
        $groups_table = $px . 'analytics_store_groups';
        $items_table = $px . 'analytics_store_group_items';
        $records_table = $px . 'form_records';
        $versions_table = $px . 'form_record_versions';
        $values_table = $px . 'form_record_values';
        $client_id  = absint($req->get_param('client_id') ?: 0);
        $project_id = absint($req->get_param('project_id') ?: 0);
        $route_id   = absint($req->get_param('route_id') ?: 0);
        $location_filter = absint($req->get_param('location_id') ?: 0);
        $product_filter = sanitize_text_field((string)($req->get_param('product') ?: ''));

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $bindings_table));
        if ($exists !== $bindings_table) return ['dashboards' => [], 'metrics' => []];

        $dashboard_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $dashboards_table)) === $dashboards_table;
        $group_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $groups_table)) === $groups_table;
        $item_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $items_table)) === $items_table;

        $dashboards = [];
        if ($dashboard_exists) {
            $dash_where = ['is_active=1'];
            $dash_args = [];
            if ($client_id) { $dash_where[] = '(client_id IS NULL OR client_id=0 OR client_id=%d)'; $dash_args[] = $client_id; }
            if ($project_id) { $dash_where[] = '(project_id IS NULL OR project_id=0 OR project_id=%d)'; $dash_args[] = $project_id; }
            if ($route_id) { $dash_where[] = '(route_id IS NULL OR route_id=0 OR route_id=%d)'; $dash_args[] = $route_id; }
            $dash_sql = "SELECT * FROM {$dashboards_table} WHERE " . implode(' AND ', $dash_where) . " ORDER BY sort_order ASC, id ASC LIMIT 100";
            $dashboards = $dash_args ? ($wpdb->get_results($wpdb->prepare($dash_sql, ...$dash_args), ARRAY_A) ?: []) : ($wpdb->get_results($dash_sql, ARRAY_A) ?: []);
        }
        $dashboard_map = [];
        foreach ($dashboards as $d) {
            $dashboard_map[(int)$d['id']] = [
                'id' => (int)$d['id'],
                'title' => (string)$d['title'],
                'description' => (string)($d['description'] ?? ''),
                'layout_type' => (string)($d['layout_type'] ?? 'mixed'),
                'sort_order' => (int)($d['sort_order'] ?? 10),
            ];
        }

        $bindings = $wpdb->get_results("SELECT * FROM {$bindings_table} WHERE is_active = 1 ORDER BY id ASC LIMIT 500", ARRAY_A) ?: [];
        if (!$bindings && !$dashboards) return ['dashboards' => [], 'metrics' => []];

        $client_names = []; $project_names = []; $location_names = [];
        foreach (($wpdb->get_results("SELECT id,name FROM {$px}clients", ARRAY_A) ?: []) as $r) { $client_names[(int)$r['id']] = (string)$r['name']; }
        foreach (($wpdb->get_results("SELECT id,name FROM {$px}projects", ARRAY_A) ?: []) as $r) { $project_names[(int)$r['id']] = (string)$r['name']; }
        foreach (($wpdb->get_results("SELECT id,name FROM {$px}locations", ARRAY_A) ?: []) as $r) { $location_names[(int)$r['id']] = (string)$r['name']; }

        $metrics = [];
        $legacy_dashboard_added = false;
        foreach ($bindings as $binding) {
            $settings = json_decode((string)($binding['settings_json'] ?? ''), true);
            if (!is_array($settings)) $settings = [];
            $bClient = absint($settings['client_id'] ?? 0);
            $bProject = absint($settings['project_id'] ?? 0);
            $bRoute = absint($settings['route_id'] ?? 0);
            $dashboard_id = absint($settings['dashboard_id'] ?? 0);
            $store_group_id = absint($settings['store_group_id'] ?? 0);
            $show_empty = array_key_exists('show_empty', $settings) ? (bool)$settings['show_empty'] : true;
            $show_table = array_key_exists('show_table', $settings) ? (bool)$settings['show_table'] : true;
            $show_kpi = array_key_exists('show_kpi', $settings) ? (bool)$settings['show_kpi'] : ((string)$binding['chart_type'] === 'kpi');
            $sort_order = (int)($settings['sort_order'] ?? 10);

            if ($client_id && $bClient && $client_id !== $bClient) continue;
            if ($project_id && $bProject && $project_id !== $bProject) continue;
            if ($route_id && $bRoute && $route_id !== $bRoute) continue;
            if ($dashboard_id && !isset($dashboard_map[$dashboard_id])) continue;
            if (!$dashboard_id) {
                $dashboard_id = 0;
                if (!$legacy_dashboard_added) {
                    $dashboard_map[0] = ['id'=>0,'title'=>'Analytics operacional','description'=>'Métricas analíticas configuradas no Centro de Atribuições.','layout_type'=>'mixed','sort_order'=>999];
                    $legacy_dashboard_added = true;
                }
            }

            $store_ids = [];
            if ($store_group_id && $group_exists && $item_exists) {
                $store_ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT location_id FROM {$items_table} WHERE group_id=%d", $store_group_id)) ?: []);
                if (!$store_ids && !$show_empty) continue;
            }
            if ($location_filter) {
                if ($store_ids && !in_array($location_filter, $store_ids, true)) {
                    $store_ids = [$location_filter];
                } elseif (!$store_ids) {
                    $store_ids = [$location_filter];
                }
            }

            $where = ["r.form_id = %d", "rv.question_key = %s", "DATE(v.submitted_at) BETWEEN %s AND %s"];
            $args = [(int)$binding['form_id'], (string)$binding['question_key'], $from, $to];
            if ($client_id) { $where[] = "v.client_id = %d"; $args[] = $client_id; }
            if ($project_id) { $where[] = "v.project_id = %d"; $args[] = $project_id; }
            if ($route_id) { $where[] = "v.route_id = %d"; $args[] = $route_id; }
            list($scopeSql, $scopeArgs) = Permissions::scope_sql('', 'v.client_id', 'v.project_id');
            if ($scopeSql !== '1=1') { $where[] = $scopeSql; $args = array_merge($args, $scopeArgs); }
            if ($bClient) { $where[] = "v.client_id = %d"; $args[] = $bClient; }
            if ($bProject) { $where[] = "v.project_id = %d"; $args[] = $bProject; }
            if ($bRoute) { $where[] = "v.route_id = %d"; $args[] = $bRoute; }
            if ($store_ids) {
                $where[] = 'v.location_id IN (' . implode(',', array_fill(0, count($store_ids), '%d')) . ')';
                $args = array_merge($args, $store_ids);
            }
            $sql = "SELECT v.submitted_at, v.route_id, v.project_id, v.client_id, v.location_id, rv.value_text, rv.value_number, rv.value_json
                    FROM {$values_table} rv
                    INNER JOIN {$versions_table} v ON v.id = rv.version_id
                    INNER JOIN {$records_table} r ON r.id = rv.record_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY v.submitted_at ASC, rv.id ASC
                    LIMIT 5000";
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
            $product_options = [];
            $expanded_rows = [];
            foreach ($rows as $row) {
                $decoded = !empty($row['value_json']) ? json_decode((string)$row['value_json'], true) : null;
                $is_matrix = is_array($decoded) && isset($decoded[0]) && is_array($decoded[0]) && (array_key_exists('qty', $decoded[0]) || array_key_exists('quantity', $decoded[0]) || array_key_exists('ref', $decoded[0]) || array_key_exists('reference', $decoded[0]));
                if (!$is_matrix) { $expanded_rows[] = $row; continue; }
                foreach ($decoded as $mrow) {
                    if (!is_array($mrow)) continue;
                    $ref = trim((string)($mrow['ref'] ?? $mrow['reference'] ?? ''));
                    $name = trim((string)($mrow['name'] ?? $mrow['product'] ?? ''));
                    $label = trim(($ref ? $ref . ' · ' : '') . ($name ?: 'Produto'));
                    $qty = $mrow['qty'] ?? ($mrow['after'] ?? ($mrow['quantity'] ?? ''));
                    $before = $mrow['before'] ?? null;
                    $after = $mrow['after'] ?? $qty;
                    if ($label === '') continue;
                    $product_options[$label] = $label;
                    if ($product_filter !== '' && $product_filter !== $label) continue;
                    $r2 = $row;
                    $r2['product_label'] = $label;
                    $r2['value_text'] = $label;
                    $r2['value_before'] = is_numeric($before) ? (float)$before : null;
                    $r2['value_after'] = is_numeric($after) ? (float)$after : (is_numeric($qty) ? (float)$qty : null);
                    $r2['value_growth'] = ($r2['value_before'] !== null && $r2['value_after'] !== null) ? round((float)$r2['value_after'] - (float)$r2['value_before'], 2) : null;
                    $r2['value_number'] = $r2['value_after'];
                    $expanded_rows[] = $r2;
                }
            }
            $rows = $expanded_rows;

            $group_name = '';
            if ($store_group_id && $group_exists) $group_name = (string)($wpdb->get_var($wpdb->prepare("SELECT name FROM {$groups_table} WHERE id=%d", $store_group_id)) ?: '');
            $metric = [
                'dashboard_id' => $dashboard_id,
                'dashboard_title' => (string)($dashboard_map[$dashboard_id]['title'] ?? 'Analytics operacional'),
                'metric_key' => (string)$binding['metric_key'],
                'metric_label' => (string)$binding['metric_label'],
                'chart_type' => (string)$binding['chart_type'],
                'aggregation' => (string)$binding['aggregation'],
                'dimension' => (string)$binding['dimension'],
                'secondary_dimension' => (string)($settings['secondary_dimension'] ?? ''),
                'store_group_id' => $store_group_id,
                'store_group_name' => $group_name,
                'show_empty' => $show_empty ? 1 : 0,
                'show_table' => $show_table ? 1 : 0,
                'show_kpi' => $show_kpi ? 1 : 0,
                'sort_order' => $sort_order,
                'series' => [],
                'rows' => [],
                'value' => null,
                'note' => '',
                'record_count' => count($rows),
                'product_options' => array_values($product_options),
                'product_filter' => $product_filter,
            ];

            $chart = (string)$binding['chart_type'];
            $aggregation = (string)$binding['aggregation'];
            $dimension = (string)$binding['dimension'];
            $metric['value'] = $rows ? $this->aggregate_metric_value($rows, $aggregation) : null;
            $metric['note'] = $rows ? (ucfirst($aggregation) . ' · ' . count($rows) . ' registos') : 'Sem dados no período selecionado';

            if ($chart === 'pie') {
                $buckets = [];
                foreach ($rows as $row) {
                    $label = trim((string)($row['value_text'] ?? ''));
                    if ($label === '') $label = $row['value_number'] !== null ? (string)$row['value_number'] : 'Sem valor';
                    if (!isset($buckets[$label])) $buckets[$label] = 0;
                    $buckets[$label] += ($aggregation === 'sum' && $row['value_number'] !== null) ? (float)$row['value_number'] : 1;
                }
                foreach ($buckets as $label => $value) $metric['series'][] = ['label' => (string)$label, 'value' => round((float)$value, 2)];
            } elseif ($chart !== 'kpi' && $chart !== 'table') {
                $buckets = [];
                foreach ($rows as $row) {
                    $label = $this->dimension_label($row, $dimension, $client_names, $project_names, $location_names);
                    if (!isset($buckets[$label])) $buckets[$label] = [];
                    $buckets[$label][] = $row;
                }
                foreach ($buckets as $label => $groupRows) $metric['series'][] = ['label' => (string)$label, 'value' => $this->aggregate_metric_value($groupRows, $aggregation)];
            }

            $recent = array_slice(array_reverse($rows), 0, 80);
            foreach ($recent as $row) {
                $metric['rows'][] = [
                    'date' => substr((string)$row['submitted_at'], 0, 10),
                    'value' => $this->analytics_row_value_label($row),
                    'before' => array_key_exists('value_before', $row) && $row['value_before'] !== null ? round((float)$row['value_before'], 2) : '',
                    'after' => array_key_exists('value_after', $row) && $row['value_after'] !== null ? round((float)$row['value_after'], 2) : '',
                    'growth' => array_key_exists('value_growth', $row) && $row['value_growth'] !== null ? round((float)$row['value_growth'], 2) : '',
                    'location' => $location_names[(int)($row['location_id'] ?? 0)] ?? ('#' . (int)($row['location_id'] ?? 0)),
                    'route' => (int)($row['route_id'] ?? 0),
                    'status' => !empty($row['product_label']) ? ('Produto: ' . (string)$row['product_label']) : 'Com dados',
                ];
            }
            if (!$metric['rows'] && $show_empty) {
                $scope_locations = $this->analytics_scope_locations($client_id ?: $bClient, $project_id ?: $bProject, $store_ids);
                foreach (array_slice($scope_locations, 0, 1000) as $loc) {
                    $metric['rows'][] = [
                        'date' => '',
                        'value' => '',
                        'location' => (string)($loc['name'] ?? ('#' . (int)($loc['id'] ?? 0))),
                        'route' => 0,
                        'status' => 'Sem dados',
                    ];
                }
            }
            if (!$show_empty && !$rows) continue;
            $metrics[] = $metric;
        }
        usort($metrics, function($a, $b){
            $ad = (int)($a['dashboard_id'] ?? 0); $bd = (int)($b['dashboard_id'] ?? 0);
            if ($ad !== $bd) return $ad <=> $bd;
            return ((int)($a['sort_order'] ?? 10)) <=> ((int)($b['sort_order'] ?? 10));
        });
        $dashboards_out = array_values($dashboard_map);
        usort($dashboards_out, function($a,$b){ return ((int)($a['sort_order'] ?? 10)) <=> ((int)($b['sort_order'] ?? 10)); });
        return ['dashboards' => $dashboards_out, 'metrics' => $metrics];
    }


    private function analytics_row_value_label(array $row): string {
        if (array_key_exists('value_before', $row) || array_key_exists('value_after', $row)) {
            $before = array_key_exists('value_before', $row) && $row['value_before'] !== null ? round((float)$row['value_before'], 2) : '';
            $after = array_key_exists('value_after', $row) && $row['value_after'] !== null ? round((float)$row['value_after'], 2) : '';
            $growth = array_key_exists('value_growth', $row) && $row['value_growth'] !== null ? round((float)$row['value_growth'], 2) : '';
            $suffix = ($growth !== '' ? ' (' . ($growth > 0 ? '+' : '') . $growth . ')' : '');
            if ($before !== '' || $after !== '') return (string)$before . ' > ' . (string)$after . $suffix;
        }
        return $row['value_number'] !== null ? (string)round((float)$row['value_number'], 2) : (string)($row['value_text'] ?? '');
    }

    private function analytics_scope_locations(int $client_id = 0, int $project_id = 0, array $location_ids = []): array {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        if ($location_ids) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $location_ids))));
            if (!$ids) return [];
            $in = implode(',', array_fill(0, count($ids), '%d'));
            return $wpdb->get_results($wpdb->prepare("SELECT id,name FROM {$px}locations WHERE id IN ({$in}) ORDER BY name ASC", ...$ids), ARRAY_A) ?: [];
        }
        if ($project_id) {
            return $wpdb->get_results($wpdb->prepare("SELECT DISTINCT l.id,l.name FROM {$px}locations l INNER JOIN {$px}campaign_locations cl ON cl.location_id=l.id WHERE cl.project_id=%d ORDER BY l.name ASC", $project_id), ARRAY_A) ?: [];
        }
        if ($client_id) {
            return $wpdb->get_results($wpdb->prepare("SELECT id,name FROM {$px}locations WHERE client_id=%d ORDER BY name ASC LIMIT 500", $client_id), ARRAY_A) ?: [];
        }
        return [];
    }

    private function dimension_label(array $row, string $dimension, array $client_names, array $project_names, array $location_names): string {
        switch ($dimension) {
            case 'client_id':
                $id = (int)($row['client_id'] ?? 0); return $client_names[$id] ?? ('Cliente #' . $id);
            case 'project_id':
                $id = (int)($row['project_id'] ?? 0); return $project_names[$id] ?? ('Campanha #' . $id);
            case 'location_id':
                $id = (int)($row['location_id'] ?? 0); return $location_names[$id] ?? ('Local #' . $id);
            case 'route_id':
                return 'Rota #' . (int)($row['route_id'] ?? 0);
            case 'product':
            case 'product_label':
                return (string)($row['product_label'] ?? 'Produto');
            case 'submitted_at':
            default:
                return substr((string)($row['submitted_at'] ?? ''), 0, 10);
        }
    }

    private function aggregate_metric_value(array $rows, string $aggregation) {
        $count = count($rows);
        if ($aggregation === 'count') return $count;
        $numeric = [];
        foreach ($rows as $row) {
            if (isset($row['value_number']) && $row['value_number'] !== null && $row['value_number'] !== '') $numeric[] = (float)$row['value_number'];
        }
        if ($aggregation === 'latest') {
            $last = end($rows);
            if (!$last) return 0;
            if (isset($last['value_number']) && $last['value_number'] !== null && $last['value_number'] !== '') return round((float)$last['value_number'], 2);
            return (string)($last['value_text'] ?? '');
        }
        if ($aggregation === 'growth') {
            $growth = [];
            foreach ($rows as $row) if (isset($row['value_growth']) && $row['value_growth'] !== null && $row['value_growth'] !== '') $growth[] = (float)$row['value_growth'];
            return $growth ? round(array_sum($growth), 2) : 0;
        }
        if (!$numeric) return $count;
        if ($aggregation === 'avg') return round(array_sum($numeric) / max(1, count($numeric)), 2);
        return round(array_sum($numeric), 2);
    }

    private function range_summary(WP_REST_Request $req, string $from, string $to): array {
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $clone = new \WP_REST_Request('GET', '/');
        foreach (['client_id','project_id','user_id','role','route_id'] as $key) {
            $value = $req->get_param($key);
            if ($value !== null && $value !== '') $clone->set_param($key, $value);
        }
        $clone->set_param('from', $from);
        $clone->set_param('to', $to);
        $args = [];
        $sqlWhere = $this->build_where($clone, $args, 'r');
        if ($sqlWhere === '') {
            return [
                'total_routes' => 0,
                'completed_routes' => 0,
                'total_stops' => 0,
                'done_stops' => 0,
                'done_rate' => 0,
                'completion_rate' => 0,
            ];
        }
        $total_routes = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT r.id FROM {$px}routes r WHERE $sqlWhere GROUP BY r.id) x");
        $total_stops = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT DISTINCT s.id FROM {$px}route_stops s INNER JOIN {$px}routes r ON r.id = s.route_id WHERE $sqlWhere) x");
        $done_stops = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT DISTINCT s.id FROM {$px}route_stops s INNER JOIN {$px}routes r ON r.id = s.route_id WHERE $sqlWhere AND s.status IN ('done','completed')) x");
        $completed_routes = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT r.id FROM {$px}routes r WHERE $sqlWhere AND NOT EXISTS (SELECT 1 FROM {$px}route_stops s WHERE s.route_id = r.id AND s.status NOT IN ('done','completed')) GROUP BY r.id) x");
        return [
            'total_routes' => $total_routes,
            'completed_routes' => $completed_routes,
            'completion_rate' => $total_routes ? round($completed_routes/$total_routes*100,1) : 0,
            'total_stops' => $total_stops,
            'done_stops' => $done_stops,
            'done_rate' => $total_stops ? round($done_stops/$total_stops*100,1) : 0,
        ];
    }

    private function top_rankings(array $rows): array {
        $project = [];
        $owner = [];
        foreach ($rows as $row) {
            $p = trim((string)($row['project_name'] ?? '')) ?: 'Sem campanha';
            $o = trim((string)($row['user_name'] ?? '')) ?: 'Sem owner';
            $project[$p] = ($project[$p] ?? 0) + (int)($row['stops_done'] ?? 0);
            $owner[$o] = ($owner[$o] ?? 0) + (int)($row['stops_done'] ?? 0);
        }
        arsort($project);
        arsort($owner);
        return [
            'top_project' => key($project) ?: '',
            'top_project_value' => $project ? (int)reset($project) : 0,
            'top_owner' => key($owner) ?: '',
            'top_owner_value' => $owner ? (int)reset($owner) : 0,
        ];
    }

    public function heatmap(WP_REST_Request $req) {
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $args = [];
        $sqlWhere = $this->build_where($req, $args, 'r');
        if ($sqlWhere === '') return new \WP_Error('forbidden', 'Sem acesso ao âmbito pedido.', ['status' => 403]);
        $rows = $wpdb->get_results("SELECT t.lat, t.lng, COUNT(*) as c FROM (SELECT DISTINCT s.id, l.lat, l.lng FROM {$px}route_stops s INNER JOIN {$px}routes r ON r.id = s.route_id INNER JOIN {$px}locations l ON l.id = s.location_id WHERE $sqlWhere AND l.lat IS NOT NULL AND l.lng IS NOT NULL) t GROUP BY t.lat, t.lng LIMIT 5000", ARRAY_A);
        return new WP_REST_Response(['points'=>$rows ?: []], 200);
    }
}
