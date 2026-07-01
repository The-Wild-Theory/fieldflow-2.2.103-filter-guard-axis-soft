<?php
namespace RoutesPro\Repositories;

use RoutesPro\Support\Permissions;
use WP_Error;

if (!defined('ABSPATH')) exit;

class RouteAccessRepository {
    public static function listRoutes(array $filters = []) {
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
            // Nunca recalcular silenciosamente no portal. O front deve mostrar os valores gravados na rota criada.
            // Nas rotas de sugestao automatica, privilegiamos o resumo do plano, para evitar somar dados Google/otimizacao duplicados.
            $storedDistance = self::extractDisplayDistanceKm((int)($row['id'] ?? 0), $meta);
            $row['distance_km'] = $storedDistance;
            $row['distance_km'] = round((float)$row['distance_km'], 2);
            $row['toll_cost_eur'] = self::extractDisplayTollCostEur((int)($row['id'] ?? 0), $meta);
            if ($row['toll_cost_eur'] <= 0 && $row['distance_km'] > 0) {
                $row['toll_cost_eur'] = \RoutesPro\Support\TollEstimator::costFromKm((float)$row['distance_km'], 'route');
            }
            $row['toll_cost_eur'] = round((float)$row['toll_cost_eur'], 2);
            $row['toll_model'] = self::extractTollModel($meta);
            $row['toll_provider'] = self::extractTollProvider($meta);
            $row['toll_is_real_api'] = self::extractTollIsRealApi($meta);
        }
        unset($row);

        return $rows;
    }

    private static function hasRouteMetrics(array $meta): bool {
        return is_array($meta['route_metrics'] ?? null) && (isset($meta['route_metrics']['distance_km']) || isset($meta['route_metrics']['toll_cost_eur']));
    }

    private static function extractDisplayDistanceKm(int $route_id, array $meta): float {
        // Rotas criadas pela Sugestão automática devem mostrar no portal o mesmo critério visual do BO.
        // Em versões anteriores algumas rotas guardaram em portal_summary/route_metrics a distância Google/técnica,
        // que é maior e não bate com os cartões da Sugestão. Para estas rotas, recalculamos pelo mesmo modelo
        // leve do BO: sequência dos stops + ponto de partida + ponto de chegada, sem multiplicadores Google.
        if (self::isAutomaticSuggestionRoute($meta)) {
            $suggestionKm = self::estimateSuggestionRouteDistanceKm($route_id, $meta);
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
        return self::estimateRouteDistanceKm($route_id, $meta);
    }

    private static function extractDisplayTollCostEur(int $route_id, array $meta): float {
        if (self::isAutomaticSuggestionRoute($meta)) {
            $suggestionKm = self::estimateSuggestionRouteDistanceKm($route_id, $meta);
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


    private static function isAutomaticSuggestionRoute(array $meta): bool {
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

    private static function estimateSuggestionRouteDistanceKm(int $route_id, array $route_meta = []): float {
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
            $km += self::haversineKm($points[$i-1][0], $points[$i-1][1], $points[$i][0], $points[$i][1]);
        }
        return round($km, 2);
    }

    private static function extractDistanceKm(array $meta): float {
        foreach ([['route_metrics','distance_km'], ['metrics','distance_km'], ['plan_summary','distance_km']] as $path) {
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


    private static function extractTollCostEur(array $meta): float {
        foreach ([['route_metrics','toll_cost_eur'], ['route_metrics','toll_estimated_eur'], ['plan_summary','toll_cost_eur'], ['plan_summary','toll_estimated_eur'], ['metrics','toll_cost_eur']] as $path) {
            $bucket = $meta[$path[0]] ?? null;
            if (is_array($bucket) && isset($bucket[$path[1]]) && is_numeric($bucket[$path[1]])) {
                return (float)$bucket[$path[1]];
            }
        }
        if (isset($meta['toll_cost_eur']) && is_numeric($meta['toll_cost_eur'])) return (float)$meta['toll_cost_eur'];
        return 0.0;
    }

    private static function extractTollModel(array $meta): string {
        foreach (['portal_summary', 'generated_plan_summary', 'original_plan_summary', 'plan_summary', 'route_metrics', 'metrics'] as $bucketKey) {
            $bucket = $meta[$bucketKey] ?? null;
            if (is_array($bucket) && !empty($bucket['toll_model'])) return (string)$bucket['toll_model'];
        }
        return '';
    }

    private static function extractTollProvider(array $meta): string {
        foreach (['portal_summary', 'generated_plan_summary', 'original_plan_summary', 'plan_summary', 'route_metrics', 'metrics'] as $bucketKey) {
            $bucket = $meta[$bucketKey] ?? null;
            if (is_array($bucket) && !empty($bucket['toll_provider'])) return (string)$bucket['toll_provider'];
            if (is_array($bucket) && !empty($bucket['routing_provider'])) return (string)$bucket['routing_provider'];
        }
        return '';
    }

    private static function extractTollIsRealApi(array $meta): int {
        foreach (['portal_summary', 'generated_plan_summary', 'original_plan_summary', 'plan_summary', 'route_metrics', 'metrics'] as $bucketKey) {
            $bucket = $meta[$bucketKey] ?? null;
            if (is_array($bucket) && array_key_exists('toll_is_real_api', $bucket)) return !empty($bucket['toll_is_real_api']) ? 1 : 0;
        }
        return 0;
    }

    private static function estimateRouteDistanceKm(int $route_id, array $route_meta = []): float {
        if ($route_id <= 0) return 0.0;
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $stops = $wpdb->get_results($wpdb->prepare("SELECT rs.meta_json, l.lat, l.lng FROM {$px}route_stops rs LEFT JOIN {$px}locations l ON l.id=rs.location_id WHERE rs.route_id=%d ORDER BY rs.seq ASC, rs.id ASC", $route_id), ARRAY_A) ?: [];
        $km = 0.0;
        $hasStoredLegs = false;
        foreach ($stops as $stop) {
            $meta = !empty($stop['meta_json']) ? json_decode((string)$stop['meta_json'], true) : [];
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
                $km += self::haversineKm($points[$i-1][0], $points[$i-1][1], $points[$i][0], $points[$i][1]) * 1.25;
            }
        }
        $metrics = is_array($route_meta['route_metrics'] ?? null) ? (array)$route_meta['route_metrics'] : (is_array($route_meta['plan_summary'] ?? null) ? (array)$route_meta['plan_summary'] : []);
        if (isset($metrics['end_leg_distance_km']) && is_numeric($metrics['end_leg_distance_km']) && $hasStoredLegs) {
            $km += (float)$metrics['end_leg_distance_km'];
        }
        if (isset($metrics['return_distance_km']) && is_numeric($metrics['return_distance_km']) && $hasStoredLegs) {
            $km += (float)$metrics['return_distance_km'];
        }
        return round($km, 2);
    }

    private static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $earth * asin(min(1, sqrt($a)));
    }

}
