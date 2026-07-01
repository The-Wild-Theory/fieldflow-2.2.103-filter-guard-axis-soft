<?php
namespace RoutesPro\Services;

use RoutesPro\Support\TollEstimator;

if (!defined('ABSPATH')) exit;

class RouteMetricsService {
    public static function maybeRefreshGoogleRoute(int $routeId, array $route = [], bool $force = false): array {
        if ($routeId <= 0 || !class_exists('\\RoutesPro\\Services\\GoogleRoutes') || !GoogleRoutes::enabled()) return ['ok'=>false,'skipped'=>true,'reason'=>'provider_disabled'];
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        if (!$route) $route = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$px}routes WHERE id=%d", $routeId), ARRAY_A) ?: [];
        if (!$route) return ['ok'=>false,'error'=>'Rota não encontrada.'];
        $meta = self::decode((string)($route['meta_json'] ?? ''));
        if (!$force && !empty($meta['generated_week_plan'])) {
            return ['ok'=>false,'skipped'=>true,'reason'=>'generated_week_plan_uses_saved_metrics','meta'=>$meta];
        }
        $metrics = is_array($meta['route_metrics'] ?? null) ? (array)$meta['route_metrics'] : [];
        $refreshVersion = 'google_routes_tolls_v2';
        if (!$force && (string)($metrics['routing_provider'] ?? '') === 'google_routes' && !empty($metrics['routing_calculated_at'])) {
            $hasCurrentVersion = (string)($metrics['routing_refresh_version'] ?? '') === $refreshVersion;
            $cacheDays = max(1, (int) \RoutesPro\Support\Config::get('routing_cache_days', 30));
            $lastAttemptTs = strtotime((string)($metrics['routing_last_google_refresh_attempt_at'] ?? $metrics['routing_calculated_at'] ?? '')) ?: 0;
            $recentAttempt = $lastAttemptTs > 0 && (time() - $lastAttemptTs) < ($cacheDays * DAY_IN_SECONDS);
            if ($hasCurrentVersion && $recentAttempt) return ['ok'=>true,'skipped'=>true,'metrics'=>$metrics,'meta'=>$meta];
        }
        $stops = $wpdb->get_results($wpdb->prepare("SELECT rs.*, l.name AS location_name, l.address, l.lat, l.lng, l.city, l.district, l.county, l.postal_code FROM {$px}route_stops rs LEFT JOIN {$px}locations l ON l.id=rs.location_id WHERE rs.route_id=%d ORDER BY rs.seq ASC, rs.id ASC", $routeId), ARRAY_A) ?: [];
        $start = is_array($meta['start_point'] ?? null) ? (array)$meta['start_point'] : [];
        $end = is_array($meta['end_point'] ?? null) ? (array)$meta['end_point'] : [];
        $points = [self::pointForRouting($start, 'Partida')];
        foreach ($stops as $stop) $points[] = self::pointForRouting(['name'=>$stop['location_name'] ?? '', 'address'=>$stop['address'] ?? '', 'lat'=>$stop['lat'] ?? null, 'lng'=>$stop['lng'] ?? null], 'PDV');
        $points[] = self::pointForRouting($end, 'Chegada');
        $routing = GoogleRoutes::calculateRoute($points);
        if (empty($routing['ok'])) return ['ok'=>false,'error'=>(string)($routing['error'] ?? 'Falha no cálculo Google Routes.')];
        $legs = array_values((array)($routing['legs'] ?? []));
        $visitMin = 0;
        foreach ($stops as $idx => $stop) {
            $stopMeta = self::decode((string)($stop['meta_json'] ?? ''));
            $visit = isset($stopMeta['visit_time_min']) ? (int)$stopMeta['visit_time_min'] : (int)round(((int)($stop['duration_s'] ?? 0)) / 60);
            $visitMin += max(0, $visit);
            $leg = $legs[$idx] ?? [];
            if ($leg) {
                $stopMeta['distance_from_prev_km'] = round((float)($leg['distance_km'] ?? 0), 2);
                $stopMeta['travel_min_from_prev'] = round((float)($leg['travel_min'] ?? 0), 1);
                $stopMeta['toll_cost_eur_from_prev'] = round((float)($leg['toll_cost_eur'] ?? 0), 2);
                $stopMeta['toll_provider'] = 'google_routes';
                $stopMeta['toll_is_real_api'] = !empty($leg['toll_has_price']) ? 1 : 0;
                $stopMeta['routing_provider'] = 'google_routes';
                $wpdb->update($px . 'route_stops', ['meta_json' => wp_json_encode($stopMeta, JSON_UNESCAPED_UNICODE)], ['id' => (int)$stop['id']]);
            }
        }
        $endLeg = $legs[count($stops)] ?? [];
        $newMetrics = array_merge($metrics, [
            'distance_km'=>round((float)($routing['distance_km'] ?? 0), 2), 'travel_min'=>round((float)($routing['travel_min'] ?? 0), 1), 'visit_min'=>$visitMin, 'work_min'=>(int)round((float)($routing['travel_min'] ?? 0) + $visitMin), 'total_min'=>(int)round((float)($routing['travel_min'] ?? 0) + $visitMin),
            'end_leg_distance_km'=>round((float)($endLeg['distance_km'] ?? 0), 2), 'end_leg_travel_min'=>round((float)($endLeg['travel_min'] ?? 0), 1), 'end_leg_toll_cost_eur'=>round((float)($endLeg['toll_cost_eur'] ?? 0), 2),
            'toll_cost_eur'=>round((float)($routing['toll_cost_eur'] ?? 0), 2), 'toll_estimated_eur'=>round((float)($routing['toll_estimated_eur'] ?? $routing['toll_cost_eur'] ?? 0), 2), 'toll_model'=>(string)($routing['toll_model'] ?? 'Google Routes API'), 'toll_provider'=>'google_routes', 'toll_is_real_api'=>!empty($routing['toll_is_real_api']) ? 1 : 0, 'toll_fallback_internal'=>!empty($routing['toll_fallback_internal']) ? 1 : 0, 'routing_provider'=>'google_routes', 'routing_preference'=>(string)($routing['routing_preference'] ?? ''), 'routing_mode'=>(string)($routing['route_mode'] ?? ''), 'routing_calculated_at'=>(string)($routing['calculated_at'] ?? current_time('mysql')), 'routing_last_google_refresh_attempt_at'=>current_time('mysql'), 'routing_refresh_version'=>$refreshVersion,
        ]);
        $meta['route_metrics'] = $newMetrics;
        $meta['plan_summary'] = array_merge(is_array($meta['plan_summary'] ?? null) ? (array)$meta['plan_summary'] : [], $newMetrics);
        $wpdb->update($px . 'routes', ['meta_json' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE)], ['id' => $routeId]);
        return ['ok'=>true,'metrics'=>$newMetrics,'meta'=>$meta,'routing'=>$routing];
    }

    private static function pointForRouting(array $point, string $fallbackName): array { return ['name'=>(string)($point['name'] ?? $fallbackName), 'address'=>(string)($point['address'] ?? ''), 'lat'=>is_numeric($point['lat'] ?? null) ? (float)$point['lat'] : null, 'lng'=>is_numeric($point['lng'] ?? null) ? (float)$point['lng'] : null]; }
    private static function decode(string $json): array { if ($json === '') return []; $data = json_decode($json, true); return is_array($data) ? $data : []; }
}
