from pathlib import Path
p=Path('/mnt/data/ff_portal_work/src/Repositories/RouteAccessRepository.php')
s=p.read_text()
start=s.index('    public static function listRoutes(array $filters = []) {')
end=s.index('\n}', start)  # last class close? this finds first closing? Need find function end manually by class close last? Actually function ends before final }
# use rfind before class close
end=s.rfind('    }')+6
new=r'''    public static function listRoutes(array $filters = []) {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';

        $date = sanitize_text_field((string)($filters['date'] ?? ''));
        $date_from = sanitize_text_field((string)($filters['date_from'] ?? ''));
        $date_to = sanitize_text_field((string)($filters['date_to'] ?? ''));
        $mine = !empty($filters['mine']);
        $client_id = absint($filters['client_id'] ?? 0);
        $project_id = absint($filters['project_id'] ?? 0);
        $owner_user_id = absint($filters['owner_user_id'] ?? 0);
        $location_id = absint($filters['location_id'] ?? 0);
        $limit = max(1, min(1000, absint($filters['limit'] ?? 500)));

        $where = [];
        $args = [];

        if ($date_from || $date_to) {
            if ($date_from) {
                $where[] = 'r.date >= %s';
                $args[] = $date_from;
            }
            if ($date_to) {
                $where[] = 'r.date <= %s';
                $args[] = $date_to;
            }
        } else {
            if (!$date) {
                $date = current_time('Y-m-d');
            }
            $where[] = 'r.date = %s';
            $args[] = $date;
        }

        if ($client_id) {
            $where[] = 'r.client_id = %d';
            $args[] = $client_id;
        }
        if ($project_id) {
            $where[] = 'r.project_id = %d';
            $args[] = $project_id;
        }
        if ($owner_user_id) {
            $where[] = "(r.owner_user_id = %d OR EXISTS (SELECT 1 FROM {$px}assignments ao WHERE ao.route_id = r.id AND ao.user_id = %d AND ao.is_active = 1))";
            $args[] = $owner_user_id;
            $args[] = $owner_user_id;
        }
        if ($location_id) {
            $where[] = "EXISTS (SELECT 1 FROM {$px}route_stops sl WHERE sl.route_id = r.id AND sl.location_id = %d)";
            $args[] = $location_id;
        }

        if ($mine) {
            $uid = get_current_user_id();
            if (!$uid) {
                return new WP_Error('forbidden', 'Não autenticado', ['status' => 401]);
            }
            $where[] = "(r.owner_user_id = %d OR EXISTS (SELECT 1 FROM {$px}assignments ax WHERE ax.route_id = r.id AND ax.user_id = %d AND ax.is_active = 1))";
            $args[] = $uid;
            $args[] = $uid;
        } else {
            $scopeCheck = Permissions::assert_scope_or_error($client_id, $project_id);
            if (is_wp_error($scopeCheck)) {
                return $scopeCheck;
            }

            list($scopeSql, $scopeArgs) = Permissions::scope_sql('r');
            if ($scopeSql !== '1=1') {
                $where[] = $scopeSql;
                $args = array_merge($args, $scopeArgs);
            }
        }

        if (!$where) {
            $where[] = '1=1';
        }

        $select = "SELECT DISTINCT r.*, c.name AS client_name, p.name AS project_name,
                   COALESCE(NULLIF(owner.display_name,''), owner.user_login) AS owner_name,
                   COUNT(rs.id) AS stops_count,
                   SUM(CASE WHEN rs.status IN ('done','completed') THEN 1 ELSE 0 END) AS stops_done";
        $sql = "$select
                FROM {$px}routes r
                LEFT JOIN {$px}clients c ON c.id = r.client_id
                LEFT JOIN {$px}projects p ON p.id = r.project_id
                LEFT JOIN {$wpdb->users} owner ON owner.ID = r.owner_user_id
                LEFT JOIN {$px}route_stops rs ON rs.route_id = r.id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY r.id
                ORDER BY r.date ASC, r.id ASC
                LIMIT %d";
        $args[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];

        foreach ($rows as &$row) {
            $stops = (int)($row['stops_count'] ?? 0);
            $done = (int)($row['stops_done'] ?? 0);
            $row['stops_count'] = $stops;
            $row['stops_done'] = $done;
            $row['done_rate'] = $stops > 0 ? round(($done / $stops) * 100, 1) : 0;
            $meta = !empty($row['meta_json']) ? json_decode((string)$row['meta_json'], true) : [];
            if (!is_array($meta)) $meta = [];
            $row['distance_km'] = self::extractDistanceKm($meta);
            if ($row['distance_km'] <= 0) {
                $row['distance_km'] = self::estimateRouteDistanceKm((int)($row['id'] ?? 0), $meta);
            }
            $row['distance_km'] = round((float)$row['distance_km'], 2);
        }
        unset($row);

        return $rows;
    }

    private static function extractDistanceKm(array $meta): float {
        foreach ([['route_metrics','distance_km'], ['plan_summary','distance_km'], ['metrics','distance_km']] as $path) {
            $bucket = $meta[$path[0]] ?? null;
            if (is_array($bucket) && isset($bucket[$path[1]]) && is_numeric($bucket[$path[1]])) {
                return (float)$bucket[$path[1]];
            }
        }
        if (isset($meta['distance_km']) && is_numeric($meta['distance_km'])) {
            return (float)$meta['distance_km'];
        }
        if (isset($meta['total_distance_km']) && is_numeric($meta['total_distance_km'])) {
            return (float)$meta['total_distance_km'];
        }
        return 0.0;
    }

    private static function estimateRouteDistanceKm(int $route_id, array $route_meta = []): float {
        if ($route_id <= 0) return 0.0;
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $stops = $wpdb->get_results($wpdb->prepare("SELECT meta_json FROM {$px}route_stops WHERE route_id=%d ORDER BY seq ASC, id ASC", $route_id), ARRAY_A) ?: [];
        $km = 0.0;
        foreach ($stops as $stop) {
            $meta = !empty($stop['meta_json']) ? json_decode((string)$stop['meta_json'], true) : [];
            if (!is_array($meta)) $meta = [];
            if (isset($meta['distance_from_prev_km']) && is_numeric($meta['distance_from_prev_km'])) {
                $km += (float)$meta['distance_from_prev_km'];
            }
            if (isset($meta['leg_distance_km']) && is_numeric($meta['leg_distance_km'])) {
                $km += (float)$meta['leg_distance_km'];
            }
        }
        $metrics = is_array($route_meta['route_metrics'] ?? null) ? (array)$route_meta['route_metrics'] : (is_array($route_meta['plan_summary'] ?? null) ? (array)$route_meta['plan_summary'] : []);
        if (isset($metrics['end_leg_distance_km']) && is_numeric($metrics['end_leg_distance_km'])) {
            $km += (float)$metrics['end_leg_distance_km'];
        }
        if (isset($metrics['return_distance_km']) && is_numeric($metrics['return_distance_km'])) {
            $km += (float)$metrics['return_distance_km'];
        }
        return round($km, 2);
    }
'''
# replace old function content up to before class closing. easier: from function start to final class closing-2? need preserve class closing.
# locate exact old function end as penultimate line before class close.
old=s[start:s.rfind('\n}')]
s=s[:start]+new+s[s.rfind('\n}'):]
p.write_text(s)
print('[OK] patched repository')
