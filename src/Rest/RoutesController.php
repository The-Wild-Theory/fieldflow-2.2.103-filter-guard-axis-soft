<?php
namespace RoutesPro\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use RoutesPro\Repositories\RouteAccessRepository;
use RoutesPro\Support\Permissions;

class RoutesController {
    const NS = 'routespro/v1';

    public function register_routes() {
        // /routes
        register_rest_route(self::NS, '/routes', [
            [
                'methods'  => 'GET',
                'callback' => [$this, 'list_routes'],
                'permission_callback' => [$this, 'can_list_routes'],
            ],
            [
                'methods'  => 'POST',
                'callback' => [$this, 'create_route'],
                'permission_callback' => function() { return current_user_can('routespro_manage'); }
            ],
        ]);

        // /routes/{id}
        register_rest_route(self::NS, '/routes/(?P<id>\d+)', [
            [
                'methods'  => 'GET',
                'callback' => [$this, 'get_route'],
                'permission_callback' => [$this, 'can_read_route'],
            ],
            [
                'methods'  => 'PATCH',
                'callback' => [$this, 'update_route'],
                'permission_callback' => [$this, 'can_edit_route'],
            ],
            [
                'methods'  => 'DELETE',
                'callback' => [$this, 'delete_route'],
                'permission_callback' => function() { return current_user_can('routespro_manage'); }
            ],
        ]);

        // /routes/{id}/stops  (GET para debug + DELETE para limpar antes de recriar)
        register_rest_route(self::NS, '/routes/(?P<id>\d+)/stops', [
            [
                'methods'  => 'GET',
                'callback' => [$this, 'list_stops'],
                'permission_callback' => [$this, 'can_read_route'],
            ],
            [
                'methods'  => 'DELETE',
                'callback' => [$this, 'clear_stops'],
                'permission_callback' => function() { return current_user_can('routespro_manage'); }
            ],
        ]);

        // /stops (criar)
        register_rest_route(self::NS, '/stops', [
            [
                'methods'  => 'POST',
                'callback' => [$this, 'create_stop'],
                'permission_callback' => [$this, 'can_create_stop'],
            ]
        ]);

        register_rest_route(self::NS, '/campaign-pdvs', [[
            'methods' => 'GET',
            'callback' => [$this, 'list_campaign_pdvs'],
            'permission_callback' => [$this, 'can_access_campaign_pdvs'],
        ]]);
        register_rest_route(self::NS, '/campaign-pdvs/(?P<id>\d+)', [[
            'methods' => 'PATCH',
            'callback' => [$this, 'update_campaign_pdv'],
            'permission_callback' => [$this, 'can_access_campaign_pdvs'],
        ]]);

        // /stops/{id} (apagar/atualizar)
        register_rest_route(self::NS, '/stops/(?P<id>\d+)', [
            [
                'methods'  => 'DELETE',
                'callback' => [$this, 'delete_stop'],
                'permission_callback' => [$this, 'can_mutate_stop'],
            ],
            [
                'methods'  => 'PATCH',
                'callback' => [$this, 'update_stop_seq_or_status'],
                'permission_callback' => [$this, 'can_mutate_stop'],
            ]
        ]);
    }

    /* =========================================================
     * PERMISSIONS HELPERS
     * ======================================================= */

    private function is_route_owned_by_current($route_id){
        return Permissions::can_access_route((int)$route_id, get_current_user_id());
    }

    public function can_list_routes(WP_REST_Request $req){
        // Permite GET /routes?user_id=me a utilizadores autenticados
        $mine = ($req->get_param('user_id') === 'me');
        if ($mine) return is_user_logged_in();
        // Gestores veem tudo, users com âmbito restrito podem listar por cliente/campanha
        if (current_user_can('routespro_manage')) return true;
        $client_id = absint($req->get_param('client_id') ?: 0);
        $project_id = absint($req->get_param('project_id') ?: 0);
        return !is_wp_error(Permissions::assert_scope_or_error($client_id, $project_id)) && Permissions::can_access_front();
    }

    public function can_read_route(WP_REST_Request $req){
        $id = absint($req['id']);
        return $this->is_route_owned_by_current($id);
    }

    public function can_edit_route(WP_REST_Request $req){
        // Permite PATCH se o utilizador tiver acesso à rota
        $id = absint($req['id']);
        return $this->is_route_owned_by_current($id);
    }


    public function can_access_campaign_pdvs(WP_REST_Request $req){
        if (!is_user_logged_in()) return false;
        if (current_user_can('routespro_manage')) return true;
        return Permissions::can_access_front();
    }

    private function campaign_projects_for_request(WP_REST_Request $req): array {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $client_id = absint($req->get_param('client_id') ?: 0);
        $project_id = absint($req->get_param('project_id') ?: 0);
        [$client_id, $project_id] = Permissions::sanitize_scope_selection($client_id, $project_id);
        if ($project_id > 0) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT id, client_id, name FROM {$px}projects WHERE id=%d", $project_id), ARRAY_A);
            return $row ? [$row] : [];
        }
        $where = ['1=1']; $args = [];
        if ($client_id > 0) { $where[] = 'client_id=%d'; $args[] = $client_id; }
        if (!current_user_can('routespro_manage')) {
            $scope = Permissions::get_scope();
            $allowedProjects = array_values(array_filter(array_map('absint', (array)($scope['project_ids'] ?? []))));
            $allowedClients = array_values(array_filter(array_map('absint', (array)($scope['client_ids'] ?? []))));
            $parts = [];
            if ($allowedProjects) { $parts[] = 'id IN (' . implode(',', array_fill(0, count($allowedProjects), '%d')) . ')'; $args = array_merge($args, $allowedProjects); }
            if ($allowedClients) { $parts[] = 'client_id IN (' . implode(',', array_fill(0, count($allowedClients), '%d')) . ')'; $args = array_merge($args, $allowedClients); }
            $where[] = $parts ? '(' . implode(' OR ', $parts) . ')' : '1=0';
        }
        $sql = "SELECT id, client_id, name FROM {$px}projects WHERE " . implode(' AND ', $where) . " ORDER BY name ASC";
        return $args ? ($wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: []) : ($wpdb->get_results($sql, ARRAY_A) ?: []);
    }

    public function list_campaign_pdvs(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $projects = $this->campaign_projects_for_request($req);
        $projectIds = array_values(array_filter(array_map(fn($r)=>absint($r['id'] ?? 0), $projects)));
        if (!$projectIds) return new WP_REST_Response(['rows'=>[], 'users'=>[], 'summary'=>['total'=>0,'filtered'=>0,'active'=>0,'with_owner'=>0,'with_coords'=>0,'routes_count'=>0,'routes_km'=>0], 'page'=>1, 'total_pages'=>1], 200);
        $where = ['cl.project_id IN (' . implode(',', array_fill(0, count($projectIds), '%d')) . ')'];
        $args = $projectIds;
        $q = sanitize_text_field((string)($req->get_param('q') ?: ''));
        if ($q !== '') { $like = '%' . $wpdb->esc_like($q) . '%'; $where[] = '(l.name LIKE %s OR l.address LIKE %s OR l.city LIKE %s OR l.phone LIKE %s OR l.postal_code LIKE %s OR p.name LIKE %s OR c.name LIKE %s)'; array_push($args, $like, $like, $like, $like, $like, $like, $like); }
        $status = sanitize_text_field((string)($req->get_param('status') ?: ''));
        if (in_array($status, ['active','paused'], true)) { $where[] = 'cl.status=%s'; $args[] = $status; }
        $active = (string)($req->get_param('active') ?? '');
        if ($active === '1' || $active === '0') { $where[] = 'cl.is_active=%d'; $args[] = (int)$active; }
        $owner = absint($req->get_param('owner_user_id') ?: 0);
        if ($owner > 0) { $where[] = 'cl.assigned_to=%d'; $args[] = $owner; }
        $page = max(1, absint($req->get_param('page') ?: 1));
        $perPage = max(5, min(100, absint($req->get_param('per_page') ?: 25)));
        $baseJoin = " FROM {$px}campaign_locations cl INNER JOIN {$px}locations l ON l.id=cl.location_id INNER JOIN {$px}projects p ON p.id=cl.project_id LEFT JOIN {$px}clients c ON c.id=p.client_id LEFT JOIN {$px}categories cat ON cat.id=l.category_id LEFT JOIN {$px}categories scat ON scat.id=l.subcategory_id LEFT JOIN {$wpdb->users} owner ON owner.ID=cl.assigned_to WHERE " . implode(' AND ', $where);
        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*)" . $baseJoin, ...$args));
        $offset = ($page - 1) * $perPage;
        $select = "SELECT cl.id AS link_id, cl.project_id, cl.location_id, cl.status AS campaign_status, cl.priority, cl.visit_frequency, cl.frequency_count, cl.visit_duration_min, cl.min_gap_days, cl.max_gap_days, cl.preferred_weekdays, cl.blocked_weekdays, cl.time_window_start, cl.time_window_end, cl.allow_auto_reschedule, cl.allow_overtime, cl.rule_notes, cl.assigned_to, cl.is_active AS campaign_active, l.id, l.name, l.address, l.city, l.postal_code, l.phone, l.lat, l.lng, cat.name AS category_name, scat.name AS subcategory_name, p.name AS project_name, c.name AS client_name, owner.display_name AS assigned_to_name";
        $rows = $wpdb->get_results($wpdb->prepare($select . $baseJoin . " ORDER BY p.name ASC, cl.priority DESC, l.city ASC, l.name ASC LIMIT %d OFFSET %d", ...array_merge($args, [$perPage, $offset])), ARRAY_A) ?: [];
        $sumRows = $wpdb->get_results($wpdb->prepare("SELECT cl.is_active, cl.assigned_to, l.lat, l.lng" . $baseJoin, ...$args), ARRAY_A) ?: [];
        $summary = ['total'=>$total, 'filtered'=>$total, 'active'=>0, 'with_owner'=>0, 'with_coords'=>0, 'routes_count'=>0, 'routes_km'=>0];
        foreach ($sumRows as $r) { if (!empty($r['is_active'])) $summary['active']++; if (!empty($r['assigned_to'])) $summary['with_owner']++; if (($r['lat'] ?? '') !== '' && ($r['lng'] ?? '') !== '') $summary['with_coords']++; }
        $routeWhere = ['project_id IN (' . implode(',', array_fill(0, count($projectIds), '%d')) . ')']; $routeArgs = $projectIds;
        if ($owner > 0) { $routeWhere[] = 'owner_user_id=%d'; $routeArgs[] = $owner; }
        $routeSummary = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(distance_km),0) AS km FROM {$px}routes WHERE " . implode(' AND ', $routeWhere), ...$routeArgs), ARRAY_A) ?: [];
        $summary['routes_count'] = (int)($routeSummary['cnt'] ?? 0); $summary['routes_km'] = (float)($routeSummary['km'] ?? 0);
        $userIds = [];
        foreach ($projects as $pr) { foreach (Permissions::get_associated_user_ids((int)($pr['client_id'] ?? 0), (int)($pr['id'] ?? 0)) as $uid) $userIds[$uid] = $uid; }
        foreach ($rows as $r) { $uid = absint($r['assigned_to'] ?? 0); if ($uid) $userIds[$uid] = $uid; }
        $users = $userIds ? get_users(['include'=>array_values($userIds), 'orderby'=>'display_name', 'order'=>'ASC', 'fields'=>['ID','display_name','user_login']]) : [];
        return new WP_REST_Response(['rows'=>$rows, 'users'=>$users, 'summary'=>$summary, 'page'=>$page, 'total_pages'=>max(1, (int)ceil($total / $perPage))], 200);
    }

    public function update_campaign_pdv(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $link_id = absint($req['id'] ?? 0);
        $row = $wpdb->get_row($wpdb->prepare("SELECT cl.id, cl.project_id, p.client_id FROM {$px}campaign_locations cl INNER JOIN {$px}projects p ON p.id=cl.project_id WHERE cl.id=%d", $link_id), ARRAY_A);
        if (!$row) return new WP_Error('not_found', 'PDV de campanha não encontrado.', ['status'=>404]);
        $scope = Permissions::assert_scope_or_error((int)($row['client_id'] ?? 0), (int)($row['project_id'] ?? 0));
        if (is_wp_error($scope)) return $scope;
        $p = $req->get_json_params() ?: [];
        $freq = sanitize_text_field((string)($p['visit_frequency'] ?? 'weekly')); if (!in_array($freq, ['weekly','monthly'], true)) $freq = 'weekly';
        $status = sanitize_text_field((string)($p['status'] ?? 'active')); if (!in_array($status, ['active','paused'], true)) $status = 'active';
        $payload = [
            'assigned_to' => absint($p['assigned_to'] ?? 0),
            'visit_frequency' => $freq,
            'frequency_count' => max(1, min(7, absint($p['frequency_count'] ?? 1))),
            'visit_duration_min' => max(0, min(360, absint($p['visit_duration_min'] ?? 45))),
            'priority' => max(0, min(999, absint($p['priority'] ?? 0))),
            'min_gap_days' => max(0, min(31, absint($p['min_gap_days'] ?? 0))),
            'max_gap_days' => max(0, min(90, absint($p['max_gap_days'] ?? 0))),
            'preferred_weekdays' => sanitize_text_field((string)($p['preferred_weekdays'] ?? '')),
            'blocked_weekdays' => sanitize_text_field((string)($p['blocked_weekdays'] ?? '')),
            'time_window_start' => sanitize_text_field((string)($p['time_window_start'] ?? '')),
            'time_window_end' => sanitize_text_field((string)($p['time_window_end'] ?? '')),
            'allow_auto_reschedule' => !empty($p['allow_auto_reschedule']) ? 1 : 0,
            'allow_overtime' => !empty($p['allow_overtime']) ? 1 : 0,
            'rule_notes' => sanitize_textarea_field((string)($p['rule_notes'] ?? '')),
            'is_active' => !empty($p['is_active']) ? 1 : 0,
            'status' => $status,
        ];
        $wpdb->update($px . 'campaign_locations', $payload, ['id'=>$link_id], ['%d','%s','%d','%d','%d','%d','%d','%s','%s','%s','%s','%d','%d','%s','%d','%s'], ['%d']);
        return new WP_REST_Response(['ok'=>true, 'id'=>$link_id], 200);
    }

    public function can_create_stop(WP_REST_Request $req){
        // Verifica route_id no corpo
        $p = $req->get_json_params() ?: [];
        $route_id = absint($p['route_id'] ?? 0);
        return $route_id ? $this->is_route_owned_by_current($route_id) : current_user_can('routespro_manage');
    }

    public function can_mutate_stop(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $id = absint($req['id']);
        $route_id = (int)$wpdb->get_var($wpdb->prepare("SELECT route_id FROM {$px}route_stops WHERE id=%d",$id));
        return $route_id ? $this->is_route_owned_by_current($route_id) : current_user_can('routespro_manage');
    }

    /* =========================================================
     * UTILS
     * ======================================================= */

    /**
     * Converte ISO8601/datetime-local para formato MySQL (UTC).
     * Aceita: "2025-10-29T09:30", "2025-10-29T09:30:00Z", etc.
     */
    private function iso_to_mysql( $val ){
        if (!$val) return null;
        $ts = strtotime($val);
        if (!$ts) return null;
        // Armazena em UTC
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /* =========================================================
     * ROUTES
     * ======================================================= */

    public function list_routes(WP_REST_Request $req) {
        $date = sanitize_text_field($req->get_param('date') ?: '');
        $date_from = sanitize_text_field($req->get_param('date_from') ?: '');
        $date_to = sanitize_text_field($req->get_param('date_to') ?: '');
        $filters = [
            'mine' => ($req->get_param('user_id') === 'me'),
            'client_id' => absint($req->get_param('client_id') ?: 0),
            'project_id' => absint($req->get_param('project_id') ?: 0),
            'owner_user_id' => absint($req->get_param('owner_user_id') ?: 0),
            'location_id' => absint($req->get_param('location_id') ?: 0),
            'limit' => absint($req->get_param('limit') ?: 500),
        ];
        if ($date_from || $date_to) {
            $filters['date_from'] = $date_from;
            $filters['date_to'] = $date_to;
        } else {
            $filters['date'] = $date ?: current_time('Y-m-d');
        }

        $routes = RouteAccessRepository::listRoutes($filters);

        if (is_wp_error($routes)) {
            return $routes;
        }

        return new WP_REST_Response(['routes' => $routes], 200);
    }

    public function get_route(WP_REST_Request $req) {
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $id = absint($req['id']);

        $route = $wpdb->get_row($wpdb->prepare("SELECT r.*, c.name AS client_name, p.name AS project_name, COALESCE(NULLIF(owner.display_name,''), owner.user_login) AS owner_name FROM {$px}routes r LEFT JOIN {$px}clients c ON c.id = r.client_id LEFT JOIN {$px}projects p ON p.id = r.project_id LEFT JOIN {$wpdb->users} owner ON owner.ID = r.owner_user_id WHERE r.id=%d", $id), ARRAY_A);
        if (!$route) return new WP_Error('not_found', 'Route not found', ['status'=>404]);
        if (!$this->is_route_owned_by_current($id)) return new WP_Error('forbidden','Sem acesso à rota', ['status'=>403]);

        $stops = $wpdb->get_results($wpdb->prepare("
            SELECT rs.*, l.name AS location_name, l.address, l.lat, l.lng
            FROM {$px}route_stops rs
            INNER JOIN {$px}locations l ON l.id = rs.location_id
            WHERE rs.route_id = %d
            ORDER BY rs.seq ASC, rs.id ASC
        ", $id), ARRAY_A);

        // assignments (utilizadores + função)
        $assigns = $wpdb->get_results($wpdb->prepare("
            SELECT a.id, a.user_id, a.role,
                   u.display_name, u.user_email, u.user_login
            FROM {$px}assignments a
            LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
            WHERE a.route_id = %d
            ORDER BY a.id ASC
        ", $id), ARRAY_A);

        // Flatten para o front
        foreach ($stops as &$stop) {
            $stop_meta = !empty($stop['meta_json']) ? json_decode((string) $stop['meta_json'], true) : [];
            if (is_array($stop_meta)) {
                $stop['visit_time_min'] = max(0, (int) ($stop_meta['visit_time_min'] ?? 0));
                $stop['visit_time_mode'] = sanitize_text_field((string) ($stop_meta['visit_time_mode'] ?? ''));
            }
        }
        unset($stop);

        $route_meta = !empty($route['meta_json']) ? json_decode((string) $route['meta_json'], true) : [];
        if (!is_array($route_meta)) $route_meta = [];
        // Nao recalcular métricas no portal: o detalhe deve refletir a rota criada e gravada.
        $done_count = 0;
        foreach ($stops as $stop_row) {
            if (in_array((string)($stop_row['status'] ?? ''), ['done','completed'], true)) $done_count++;
        }
        $distance_km = $this->route_display_distance_km($id, $route_meta);
        $toll_cost_eur = $this->route_display_toll_cost_eur($id, $route_meta);
        if ($toll_cost_eur <= 0 && $distance_km > 0) $toll_cost_eur = \RoutesPro\Support\TollEstimator::costFromKm((float)$distance_km, 'route');

        $payload = array_merge($route, [
            'stops'      => $stops,
            'assignments'=> $assigns,
            'stops_count'=> count($stops),
            'stops_done' => $done_count,
            'done_rate'  => count($stops) > 0 ? round(($done_count / count($stops)) * 100, 1) : 0,
            'distance_km'=> round((float) $distance_km, 2),
            'toll_cost_eur'=> round((float) $toll_cost_eur, 2),
            'toll_model'=> $this->route_toll_model($route_meta),
            'toll_provider'=> $this->route_toll_provider($route_meta),
            'toll_is_real_api'=> $this->route_toll_is_real_api($route_meta),
        ]);

        return new WP_REST_Response($payload, 200);
    }

    public function create_route(WP_REST_Request $req) {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $data = $req->get_json_params() ?: [];
        $uid  = get_current_user_id();

        $client_id = absint($data['client_id'] ?? 0);
        if (!$client_id) return new WP_Error('bad_request', 'client_id obrigatório', ['status'=>400]);

        $project_id = absint($data['project_id'] ?? 0) ?: null;

        // owner: se admin passar explicitamente, respeita; senão, current user
        $owner_id = $uid;
        if (isset($data['owner_user_id']) && current_user_can('routespro_manage')) {
            $owner_id = absint($data['owner_user_id']) ?: $uid;
        }

        $row = [
            'client_id'     => $client_id,
            'date'          => sanitize_text_field($data['date'] ?? current_time('Y-m-d')),
            'status'        => sanitize_text_field($data['status'] ?? 'draft'),
            'owner_user_id' => $owner_id,
            'meta_json'     => wp_json_encode($data['meta'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE),
        ];
        if ($project_id !== null) { $row['project_id'] = $project_id; }

        $ok = $wpdb->insert("{$px}routes", $row);
        if (!$ok) return new WP_Error('db_error', $wpdb->last_error ?: 'DB insert falhou', ['status'=>500]);

        $id = (int)$wpdb->insert_id;
        // assignment default para o owner
        \RoutesPro\Support\AssignmentMatrix::sync_route_owner_assignment((int)$id, (int)$owner_id, 'owner');

        return new WP_REST_Response(['id'=>$id], 201);
    }

    public function update_route(WP_REST_Request $req) {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $id = absint($req['id']);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$px}routes WHERE id=%d", $id));
        if (!$exists) return new WP_Error('not_found','Rota não existe', ['status'=>404]);
        if (!$this->is_route_owned_by_current($id)) return new WP_Error('forbidden','Sem acesso à rota', ['status'=>403]);

        $data = $req->get_json_params() ?: [];
        $fields = []; $formats = []; $where = ['id'=>$id];

        if (isset($data['status']))        { $fields['status']        = sanitize_text_field($data['status']);        $formats[]='%s'; }
        if (isset($data['owner_user_id'])) { $fields['owner_user_id'] = absint($data['owner_user_id']);              $formats[]='%d'; }
        if (isset($data['meta']))          { $fields['meta_json']     = wp_json_encode($data['meta'], JSON_UNESCAPED_UNICODE); $formats[]='%s'; }
        if (isset($data['date']))          { $fields['date']          = sanitize_text_field($data['date']);          $formats[]='%s'; }
        if (array_key_exists('client_id', $data))  { $fields['client_id']  = absint($data['client_id']);  $formats[]='%d'; }

        $set_project_null = false;
        if (array_key_exists('project_id', $data)) {
            $pid = absint($data['project_id']) ?: null;
            if ($pid === null) {
                // Forçar NULL de forma explícita
                $set_project_null = true;
            } else {
                $fields['project_id'] = $pid;
                $formats[] = '%d';
            }
        }

        if (!$fields && !$set_project_null) {
            return new WP_REST_Response(['ok'=>true], 200);
        }

        // Update normal
        if ($fields) {
            $ok = $wpdb->update("{$px}routes", $fields, $where, $formats, ['%d']);
            if ($ok === false) return new WP_Error('db_error', $wpdb->last_error ?: 'DB update falhou', ['status'=>500]);
        }

        // Set project_id = NULL caso pedido
        if ($set_project_null) {
            $q = $wpdb->prepare("UPDATE {$px}routes SET project_id = NULL WHERE id = %d", $id);
            $ok2 = $wpdb->query($q);
            if ($ok2 === false) return new WP_Error('db_error', $wpdb->last_error ?: 'DB update falhou (project_id NULL)', ['status'=>500]);
        }

        return new WP_REST_Response(['ok'=>true], 200);
    }

    public function delete_route(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $id = absint($req['id']);
        if (!current_user_can('routespro_manage')) return new WP_Error('forbidden','Apenas BO pode apagar rotas', ['status'=>403]);

        // limpeza em cascata
        $stop_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$px}route_stops WHERE route_id=%d", $id));
        if ($stop_ids) {
            $in = implode(',', array_map('intval', $stop_ids));
            $wpdb->query("DELETE FROM {$px}events WHERE route_stop_id IN ($in)");
        }
        $wpdb->delete($px.'assignments',  ['route_id'=>$id], ['%d']);
        $wpdb->delete($px.'route_stops',  ['route_id'=>$id], ['%d']);
        $ok = $wpdb->delete($px.'routes', ['id'=>$id], ['%d']);
        if ($ok === false) return new WP_Error('db_error', $wpdb->last_error ?: 'DB delete falhou', ['status'=>500]);

        return new WP_REST_Response(['ok'=>true],200);
    }

    /* =========================================================
     * STOPS
     * ======================================================= */

    public function list_stops(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $id = absint($req['id']);
        if (!$this->is_route_owned_by_current($id)) return new WP_Error('forbidden','Sem acesso à rota', ['status'=>403]);

        $stops = $wpdb->get_results($wpdb->prepare("
            SELECT rs.*, l.name AS location_name, l.address, l.lat, l.lng
            FROM {$px}route_stops rs
            INNER JOIN {$px}locations l ON l.id = rs.location_id
            WHERE rs.route_id = %d
            ORDER BY rs.seq ASC, rs.id ASC
        ", $id), ARRAY_A);

        return new WP_REST_Response(['stops'=>$stops], 200);
    }

    public function clear_stops(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        if (!current_user_can('routespro_manage')) return new WP_Error('forbidden','Apenas BO pode limpar paragens', ['status'=>403]);

        $route_id = absint($req['id']);
        if (!$route_id) return new WP_Error('bad_request','route_id em falta', ['status'=>400]);

        // apagar eventos relacionados às paragens desta rota
        $stop_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$px}route_stops WHERE route_id=%d", $route_id));
        if ($stop_ids) {
            $in = implode(',', array_map('intval', $stop_ids));
            $wpdb->query("DELETE FROM {$px}events WHERE route_stop_id IN ($in)");
        }

        $ok = $wpdb->delete($px.'route_stops', ['route_id'=>$route_id], ['%d']);
        if ($ok === false) return new WP_Error('db_error', $wpdb->last_error ?: 'DB delete falhou', ['status'=>500]);

        return new WP_REST_Response(['ok'=>true], 200);
    }

    public function create_stop(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $p = $req->get_json_params() ?: [];

        $route_id = absint($p['route_id'] ?? 0);
        $loc_id   = absint($p['location_id'] ?? 0);
        if (!$route_id || !$loc_id) return new WP_Error('bad_request','route_id/location_id obrigatórios', ['status'=>400]);
        if (!$this->is_route_owned_by_current($route_id)) return new WP_Error('forbidden','Sem acesso à rota', ['status'=>403]);

        // seq explícito ou next auto
        if (isset($p['seq']) && $p['seq'] !== null && $p['seq'] !== '') {
            $seq = absint($p['seq']);
        } else {
            $seq = (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(seq),-1)+1 FROM {$px}route_stops WHERE route_id=%d", $route_id));
        }

        $visit_time_min = isset($p['visit_time_min']) ? max(0, (int) $p['visit_time_min']) : 0;
        $visit_time_mode = sanitize_text_field((string) ($p['visit_time_mode'] ?? ''));
        $row = [
            'route_id'    => $route_id,
            'location_id' => $loc_id,
            'seq'         => $seq,
            'status'      => sanitize_text_field($p['status'] ?? 'pending'),
            'note'        => sanitize_text_field($p['note'] ?? ''),
            'meta_json'   => wp_json_encode(['visit_time_min' => $visit_time_min, 'visit_time_mode' => $visit_time_mode], JSON_UNESCAPED_UNICODE),
        ];
        $ok = $wpdb->insert($px.'route_stops', $row, ['%d','%d','%d','%s','%s','%s']);
        if (!$ok) return new WP_Error('db_error', $wpdb->last_error ?: 'DB insert falhou', ['status'=>500]);

        return new WP_REST_Response(['id'=>$wpdb->insert_id],201);
    }

    public function delete_stop(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $id = absint($req['id']);
        $route_id = (int)$wpdb->get_var($wpdb->prepare("SELECT route_id FROM {$px}route_stops WHERE id=%d",$id));
        if (!$route_id) return new WP_Error('not_found','Stop não existe',['status'=>404]);
        if (!$this->is_route_owned_by_current($route_id)) return new WP_Error('forbidden','Sem acesso à rota', ['status'=>403]);

        // apagar eventos relacionados (se existir tabela)
        $wpdb->delete($px.'events', ['route_stop_id'=>$id], ['%d']);
        $ok = $wpdb->delete($px.'route_stops', ['id'=>$id], ['%d']);
        if ($ok === false) return new WP_Error('db_error', $wpdb->last_error ?: 'DB delete falhou', ['status'=>500]);

        return new WP_REST_Response(['ok'=>true],200);
    }

    public function update_stop_seq_or_status(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix.'routespro_';
        $id = absint($req['id']);
        $p  = $req->get_json_params() ?: [];

        $route_id = (int)$wpdb->get_var($wpdb->prepare("SELECT route_id FROM {$px}route_stops WHERE id=%d",$id));
        if (!$route_id) return new WP_Error('not_found','Stop não existe',['status'=>404]);
        if (!$this->is_route_owned_by_current($route_id)) return new WP_Error('forbidden','Sem acesso à rota', ['status'=>403]);

        // normalização de datas
        $arrived_at  = isset($p['arrived_at'])  ? $this->iso_to_mysql($p['arrived_at'])   : null;
        $departed_at = isset($p['departed_at']) ? $this->iso_to_mysql($p['departed_at'])  : null;

        $fields = []; $formats = [];

        // existentes
        if (isset($p['seq']))           { $fields['seq']           = absint($p['seq']);                       $formats[]='%d'; }
        if (isset($p['status']))        { $fields['status']        = sanitize_text_field($p['status']);       $formats[]='%s'; }
        if (isset($p['note']))          { $fields['note']          = sanitize_text_field($p['note']);         $formats[]='%s'; }

        // novos campos de reporte
        if (isset($p['fail_reason']))   { $fields['fail_reason']   = sanitize_text_field($p['fail_reason']);  $formats[]='%s'; }
        if (isset($p['photo_url']))     { $fields['photo_url']     = esc_url_raw($p['photo_url']);            $formats[]='%s'; }
        if (isset($p['signature_data'])){ $fields['signature_data']= $p['signature_data'];                    $formats[]='%s'; } // dataURL base64

        if ($arrived_at !== null)       { $fields['arrived_at']    = $arrived_at;                             $formats[]='%s'; }
        if ($departed_at !== null)      { $fields['departed_at']   = $departed_at;                            $formats[]='%s'; }
        if (isset($p['duration_s']))    { $fields['duration_s']    = absint($p['duration_s']);                $formats[]='%d'; }

        if (isset($p['qty']))           { $fields['qty']           = floatval($p['qty']);                     $formats[]='%f'; }
        if (isset($p['weight']))        { $fields['weight']        = floatval($p['weight']);                  $formats[]='%f'; }
        if (isset($p['volume']))        { $fields['volume']        = floatval($p['volume']);                  $formats[]='%f'; }

        if (isset($p['real_lat']))      { $fields['real_lat']      = floatval($p['real_lat']);                $formats[]='%f'; }
        if (isset($p['real_lng']))      { $fields['real_lng']      = floatval($p['real_lng']);                $formats[]='%f'; }

        if (!$fields) return new WP_REST_Response(['ok'=>true],200);

        $ok = $wpdb->update($px.'route_stops', $fields, ['id'=>$id], $formats, ['%d']);
        if ($ok === false) return new WP_Error('db_error', $wpdb->last_error ?: 'DB update falhou', ['status'=>500]);

        return new WP_REST_Response(['ok'=>true],200);
    }

    private function route_display_distance_km(int $route_id, array $meta): float {
        // Para rotas vindas da Sugestão automática, o front deve bater com o BO da sugestão.
        // O BO usa distância leve pela sequência de lojas + partida + chegada, não a distância técnica Google.
        if ($this->is_automatic_suggestion_route($meta)) {
            $suggestionKm = $this->estimate_suggestion_route_distance_km($route_id, $meta);
            if ($suggestionKm > 0) return $suggestionKm;
        }
        foreach (['portal_summary', 'generated_plan_summary', 'original_plan_summary', 'plan_summary', 'route_metrics', 'metrics'] as $bucketKey) {
            $bucket = $meta[$bucketKey] ?? null;
            if (!is_array($bucket)) continue;
            if (!empty($bucket['routing_refresh_version']) && $bucketKey === 'plan_summary') continue;
            if (isset($bucket['suggestion_distance_km']) && is_numeric($bucket['suggestion_distance_km'])) return (float)$bucket['suggestion_distance_km'];
            if (isset($bucket['distance_km']) && is_numeric($bucket['distance_km'])) return (float)$bucket['distance_km'];
            if (isset($bucket['total_distance_km']) && is_numeric($bucket['total_distance_km'])) return (float)$bucket['total_distance_km'];
        }
        if (isset($meta['distance_km']) && is_numeric($meta['distance_km'])) return (float)$meta['distance_km'];
        if (isset($meta['total_distance_km']) && is_numeric($meta['total_distance_km'])) return (float)$meta['total_distance_km'];
        return $this->estimate_route_distance_km($route_id, $meta);
    }

    private function route_display_toll_cost_eur(int $route_id, array $meta): float {
        if ($this->is_automatic_suggestion_route($meta)) {
            $suggestionKm = $this->estimate_suggestion_route_distance_km($route_id, $meta);
            if ($suggestionKm > 0) return (float) \RoutesPro\Support\TollEstimator::costFromKm($suggestionKm, 'route');
        }
        foreach (['portal_summary', 'generated_plan_summary', 'original_plan_summary', 'plan_summary', 'route_metrics', 'metrics'] as $bucketKey) {
            $bucket = $meta[$bucketKey] ?? null;
            if (!is_array($bucket)) continue;
            if (!empty($bucket['routing_refresh_version']) && $bucketKey === 'plan_summary') continue;
            foreach (['suggestion_toll_cost_eur', 'toll_cost_eur', 'toll_estimated_eur'] as $key) {
                if (isset($bucket[$key]) && is_numeric($bucket[$key])) return (float)$bucket[$key];
            }
        }
        if (isset($meta['toll_cost_eur']) && is_numeric($meta['toll_cost_eur'])) return (float)$meta['toll_cost_eur'];
        return 0.0;
    }


    private function is_automatic_suggestion_route(array $meta): bool {
        if (!empty($meta['generated_week_plan'])) return true;
        foreach (['portal_summary', 'generated_plan_summary', 'original_plan_summary', 'plan_summary'] as $bucketKey) {
            $bucket = $meta[$bucketKey] ?? null;
            if (!is_array($bucket)) continue;
            foreach (['source', 'created_from', 'origin'] as $key) {
                if (!empty($bucket[$key]) && strpos((string)$bucket[$key], 'automatic_route_suggestion') !== false) return true;
            }
        }
        return false;
    }

    private function estimate_suggestion_route_distance_km(int $route_id, array $route_meta = []): float {
        if ($route_id <= 0) return 0.0;
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $stops = $wpdb->get_results($wpdb->prepare("SELECT l.lat, l.lng FROM {$px}route_stops rs LEFT JOIN {$px}locations l ON l.id=rs.location_id WHERE rs.route_id=%d ORDER BY rs.seq ASC, rs.id ASC", $route_id), ARRAY_A) ?: [];
        $points = [];
        $start = is_array($route_meta['start_point'] ?? null) ? (array)$route_meta['start_point'] : [];
        if (is_numeric($start['lat'] ?? null) && is_numeric($start['lng'] ?? null)) $points[] = [(float)$start['lat'], (float)$start['lng']];
        foreach ($stops as $stop) {
            if (is_numeric($stop['lat'] ?? null) && is_numeric($stop['lng'] ?? null)) $points[] = [(float)$stop['lat'], (float)$stop['lng']];
        }
        $end = is_array($route_meta['end_point'] ?? null) ? (array)$route_meta['end_point'] : [];
        if (is_numeric($end['lat'] ?? null) && is_numeric($end['lng'] ?? null)) $points[] = [(float)$end['lat'], (float)$end['lng']];
        $km = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $km += $this->haversine_km($points[$i-1][0], $points[$i-1][1], $points[$i][0], $points[$i][1]);
        }
        return round($km, 2);
    }

    private function has_route_metrics(array $meta): bool {
        return is_array($meta['route_metrics'] ?? null) && (isset($meta['route_metrics']['distance_km']) || isset($meta['route_metrics']['toll_cost_eur']));
    }

    private function route_distance_km(array $meta): float {
        foreach ([['route_metrics','distance_km'], ['metrics','distance_km'], ['plan_summary','distance_km']] as $path) {
            $bucket = $meta[$path[0]] ?? null;
            if (is_array($bucket) && isset($bucket[$path[1]]) && is_numeric($bucket[$path[1]])) {
                return (float) $bucket[$path[1]];
            }
        }
        if (isset($meta['distance_km']) && is_numeric($meta['distance_km'])) return (float) $meta['distance_km'];
        if (isset($meta['total_distance_km']) && is_numeric($meta['total_distance_km'])) return (float) $meta['total_distance_km'];
        return 0.0;
    }


    private function route_toll_cost_eur(array $meta): float {
        foreach ([['route_metrics','toll_cost_eur'], ['route_metrics','toll_estimated_eur'], ['plan_summary','toll_cost_eur'], ['plan_summary','toll_estimated_eur'], ['metrics','toll_cost_eur']] as $path) {
            $bucket = $meta[$path[0]] ?? null;
            if (is_array($bucket) && isset($bucket[$path[1]]) && is_numeric($bucket[$path[1]])) return (float) $bucket[$path[1]];
        }
        if (isset($meta['toll_cost_eur']) && is_numeric($meta['toll_cost_eur'])) return (float) $meta['toll_cost_eur'];
        return 0.0;
    }

    private function route_toll_model(array $meta): string {
        foreach (['portal_summary', 'generated_plan_summary', 'original_plan_summary', 'plan_summary', 'route_metrics', 'metrics'] as $bucketKey) {
            $bucket = $meta[$bucketKey] ?? null;
            if (is_array($bucket) && !empty($bucket['toll_model'])) return (string) $bucket['toll_model'];
        }
        return '';
    }

    private function route_toll_provider(array $meta): string {
        foreach (['portal_summary', 'generated_plan_summary', 'original_plan_summary', 'plan_summary', 'route_metrics', 'metrics'] as $bucketKey) {
            $bucket = $meta[$bucketKey] ?? null;
            if (is_array($bucket) && !empty($bucket['toll_provider'])) return (string) $bucket['toll_provider'];
            if (is_array($bucket) && !empty($bucket['routing_provider'])) return (string) $bucket['routing_provider'];
        }
        return '';
    }

    private function route_toll_is_real_api(array $meta): int {
        foreach (['portal_summary', 'generated_plan_summary', 'original_plan_summary', 'plan_summary', 'route_metrics', 'metrics'] as $bucketKey) {
            $bucket = $meta[$bucketKey] ?? null;
            if (is_array($bucket) && array_key_exists('toll_is_real_api', $bucket)) return !empty($bucket['toll_is_real_api']) ? 1 : 0;
        }
        return 0;
    }

    private function estimate_route_distance_km(int $route_id, array $route_meta = []): float {
        if ($route_id <= 0) return 0.0;
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $stops = $wpdb->get_results($wpdb->prepare("SELECT rs.meta_json, l.lat, l.lng FROM {$px}route_stops rs LEFT JOIN {$px}locations l ON l.id=rs.location_id WHERE rs.route_id=%d ORDER BY rs.seq ASC, rs.id ASC", $route_id), ARRAY_A) ?: [];
        $km = 0.0;
        $hasStoredLegs = false;
        foreach ($stops as $stop) {
            $meta = !empty($stop['meta_json']) ? json_decode((string) $stop['meta_json'], true) : [];
            if (!is_array($meta)) $meta = [];
            foreach (['distance_from_prev_km', 'leg_distance_km'] as $key) {
                if (isset($meta[$key]) && is_numeric($meta[$key]) && (float)$meta[$key] > 0) {
                    $km += (float)$meta[$key];
                    $hasStoredLegs = true;
                    break;
                }
            }
        }
        if (!$hasStoredLegs) {
            $points = [];
            $start = is_array($route_meta['start_point'] ?? null) ? (array)$route_meta['start_point'] : [];
            if (is_numeric($start['lat'] ?? null) && is_numeric($start['lng'] ?? null)) $points[] = [(float)$start['lat'], (float)$start['lng']];
            foreach ($stops as $stop) {
                if (is_numeric($stop['lat'] ?? null) && is_numeric($stop['lng'] ?? null)) $points[] = [(float)$stop['lat'], (float)$stop['lng']];
            }
            $end = is_array($route_meta['end_point'] ?? null) ? (array)$route_meta['end_point'] : [];
            if (is_numeric($end['lat'] ?? null) && is_numeric($end['lng'] ?? null)) $points[] = [(float)$end['lat'], (float)$end['lng']];
            for ($i = 1; $i < count($points); $i++) {
                $km += $this->haversine_km($points[$i-1][0], $points[$i-1][1], $points[$i][0], $points[$i][1]) * 1.25;
            }
        }
        $metrics = is_array($route_meta['route_metrics'] ?? null) ? (array) $route_meta['route_metrics'] : (is_array($route_meta['plan_summary'] ?? null) ? (array) $route_meta['plan_summary'] : []);
        if ($hasStoredLegs && isset($metrics['end_leg_distance_km']) && is_numeric($metrics['end_leg_distance_km'])) $km += (float) $metrics['end_leg_distance_km'];
        if ($hasStoredLegs && isset($metrics['return_distance_km']) && is_numeric($metrics['return_distance_km'])) $km += (float) $metrics['return_distance_km'];
        return round($km, 2);
    }

    private function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $earth * asin(min(1, sqrt($a)));
    }

}
