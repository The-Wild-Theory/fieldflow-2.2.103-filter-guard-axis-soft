<?php
namespace RoutesPro\Services;

use RoutesPro\Support\Config;
use RoutesPro\Support\TollEstimator;

if (!defined('ABSPATH')) exit;

class GoogleRoutes {
    public static function enabled(): bool {
        return (string) Config::get('routing_provider', 'internal') === 'google_routes' && Config::providerReady('google_routes');
    }

    public static function configured(): bool {
        return Config::providerReady('google_routes');
    }

    public static function calculateRoute(array $points, array $options = []): array {
        $points = self::normalizePoints($points);
        if (count($points) < 2) return ['ok' => false, 'error' => 'São necessários pelo menos dois pontos para calcular rota.'];
        $ignoreProvider = !empty($options['ignore_provider']);
        $key = trim((string) Config::get('google_routes_api_key', ''));
        if ($key === '') return ['ok' => false, 'error' => 'Google Routes API Key por configurar.'];
        if (!$ignoreProvider && !self::enabled()) {
            $provider = (string) Config::get('routing_provider', 'internal');
            return ['ok' => false, 'error' => $provider === 'google_routes'
                ? 'Google Routes API não está pronta. Confirma se a API key está preenchida.'
                : 'Google Routes API está configurada, mas o provider ativo ainda é o estimador interno. Guarda as definições com provider Google Routes API ou usa o botão Guardar e testar Google Routes.'];
        }

        if (count($points) > 27 && empty($options['single_leg_call'])) return self::calculatePairwise($points, $options);

        $routeMode = (string) Config::get('google_routes_route_mode', 'fastest_tolls');
        $preference = (string) Config::get('google_routes_preference', 'TRAFFIC_AWARE');
        if (!in_array($preference, ['TRAFFIC_AWARE', 'TRAFFIC_AWARE_OPTIMAL', 'TRAFFIC_UNAWARE'], true)) $preference = 'TRAFFIC_AWARE';
        $avoidTolls = $routeMode === 'fastest_no_tolls';
        $cacheDays = max(0, (int) Config::get('routing_cache_days', 30));
        $forceRefresh = !empty($options['force_refresh']);
        $cacheKey = 'ff_google_routes_' . md5(wp_json_encode(['points'=>$points,'mode'=>$routeMode,'pref'=>$preference,'avoid_tolls'=>$avoidTolls,'v'=>4]));
        if (!$forceRefresh && $cacheDays > 0 && function_exists('get_transient')) {
            $cached = get_transient($cacheKey);
            if (is_array($cached) && !empty($cached['ok'])) { $cached['from_cache'] = true; return $cached; }
        }

        $origin = self::waypoint($points[0]);
        $destination = self::waypoint($points[count($points)-1]);
        if (!$origin || !$destination) return ['ok'=>false,'error'=>'Origem ou destino sem morada/coordenadas válidas.'];
        $intermediates = [];
        for ($i=1; $i<count($points)-1; $i++) { $wp = self::waypoint($points[$i]); if ($wp) $intermediates[] = $wp; }
        $body = [
            'origin' => $origin,
            'destination' => $destination,
            'travelMode' => 'DRIVE',
            'routingPreference' => $preference,
            'extraComputations' => ['TOLLS'],
            'routeModifiers' => ['avoidTolls' => $avoidTolls, 'vehicleInfo' => ['emissionType' => 'GASOLINE']],
        ];
        if ($intermediates) $body['intermediates'] = $intermediates;

        $response = wp_remote_post('https://routes.googleapis.com/directions/v2:computeRoutes', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.travelAdvisory.tollInfo,routes.legs.distanceMeters,routes.legs.duration,routes.legs.travelAdvisory.tollInfo',
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) return ['ok'=>false,'error'=>'Erro Google Routes: '.$response->get_error_message()];
        $code = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($payload) ? (string)($payload['error']['message'] ?? 'Resposta inválida da Google Routes API.') : 'Resposta inválida da Google Routes API.';
            return ['ok'=>false,'error'=>self::friendlyError($message),'raw_error'=>$message,'status'=>$code];
        }
        $route = is_array($payload) ? ($payload['routes'][0] ?? []) : [];
        if (!is_array($route) || !$route) return ['ok'=>false,'error'=>'Google Routes API não devolveu rota.'];

        $distanceKm = round(((float)($route['distanceMeters'] ?? 0)) / 1000, 3);
        $travelMin = round(self::durationSeconds($route['duration'] ?? '') / 60, 1);
        $routeToll = self::moneyFromTollInfo($route['travelAdvisory']['tollInfo'] ?? []);
        $routeTollAmount = round((float)($routeToll['amount'] ?? 0), 2);
        $routeHasTollPrice = !empty($routeToll['has_price']) && $routeTollAmount > 0;
        $legs = [];
        $legTollTotal = 0.0;
        foreach ((array)($route['legs'] ?? []) as $leg) {
            if (!is_array($leg)) continue;
            $legToll = self::moneyFromTollInfo($leg['travelAdvisory']['tollInfo'] ?? []);
            $legRow = [
                'distance_km' => round(((float)($leg['distanceMeters'] ?? 0)) / 1000, 3),
                'travel_min' => round(self::durationSeconds($leg['duration'] ?? '') / 60, 1),
                'toll_cost_eur' => round((float)($legToll['amount'] ?? 0), 2),
                'toll_currency' => (string)($legToll['currency'] ?? 'EUR'),
                'toll_has_price' => !empty($legToll['has_price']),
                'routing_provider' => 'google_routes',
            ];
            $legTollTotal += (float)$legRow['toll_cost_eur'];
            $legs[] = $legRow;
        }
        if (!$legs && count($points) === 2) {
            $legs[] = ['distance_km'=>$distanceKm,'travel_min'=>$travelMin,'toll_cost_eur'=>$routeTollAmount,'toll_currency'=>(string)($routeToll['currency'] ?? 'EUR'),'toll_has_price'=>$routeHasTollPrice,'routing_provider'=>'google_routes'];
            $legTollTotal = $routeTollAmount;
        }
        if ($routeHasTollPrice && $legTollTotal <= 0 && $legs) {
            $sumDistance = array_sum(array_map(static function($leg){ return max(0.0, (float)($leg['distance_km'] ?? 0)); }, $legs));
            $remaining = $routeTollAmount; $last = count($legs)-1;
            foreach ($legs as $idx => &$legRow) {
                if ($idx === $last) $value = round(max(0.0, $remaining), 2);
                else { $share = $sumDistance > 0 ? ((float)$legRow['distance_km'] / $sumDistance) : (1 / count($legs)); $value = round($routeTollAmount * $share, 2); $remaining -= $value; }
                $legRow['toll_cost_eur'] = $value; $legRow['toll_has_price'] = true; $legRow['toll_allocated_from_route_total'] = true;
            }
            unset($legRow);
        }
        $fallbackUsed = false;
        $tollAmount = $routeHasTollPrice ? $routeTollAmount : 0.0;
        $model = $routeHasTollPrice ? 'Google Routes API TOLLS, portagens devolvidas pela API' : 'Google Routes API, rota rápida com portagens permitidas';
        if ($avoidTolls) $model = 'Google Routes API, rota rápida evitando portagens';
        if ($tollAmount <= 0 && !empty(Config::get('routing_fallback_internal', 1))) {
            $fallback = TollEstimator::estimateFromKm($distanceKm, 'route');
            $tollAmount = round((float)($fallback['cost_eur'] ?? 0), 2);
            $fallbackUsed = true;
            $model = 'Google Routes API para distância/tempo, portagens estimadas por fallback interno';
            foreach ($legs as &$legRow) {
                if ((float)($legRow['toll_cost_eur'] ?? 0) <= 0 && (float)($legRow['distance_km'] ?? 0) > 0) {
                    $segFallback = TollEstimator::estimateFromKm((float)$legRow['distance_km'], 'segment');
                    $legRow['toll_cost_eur'] = round((float)($segFallback['cost_eur'] ?? 0), 2);
                    $legRow['toll_has_price'] = false;
                    $legRow['toll_fallback_internal'] = true;
                }
            }
            unset($legRow);
        }
        $result = [
            'ok'=>true,'provider'=>'google_routes','routing_provider'=>'google_routes','route_mode'=>$routeMode,'routing_preference'=>$preference,
            'distance_km'=>$distanceKm,'travel_min'=>$travelMin,'duration_seconds'=>self::durationSeconds($route['duration'] ?? ''),
            'toll_cost_eur'=>round($tollAmount,2),'toll_estimated_eur'=>round($tollAmount,2),'toll_currency'=>(string)($routeToll['currency'] ?? 'EUR'),
            'toll_has_price'=>$routeHasTollPrice,'toll_is_real_api'=>$routeHasTollPrice ? 1 : 0,'toll_fallback_internal'=>$fallbackUsed ? 1 : 0,
            'toll_model'=>$model,'legs'=>$legs,'calculated_at'=>function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),'from_cache'=>false,
        ];
        if (!$forceRefresh && $cacheDays > 0 && function_exists('set_transient')) set_transient($cacheKey, $result, $cacheDays * DAY_IN_SECONDS);
        return $result;
    }

    public static function diagnostic(string $origin, string $destination): array {
        $result = self::calculateRoute([['address'=>$origin], ['address'=>$destination]], ['single_leg_call'=>true, 'ignore_provider'=>true, 'force_refresh'=>true]);
        if (empty($result['ok'])) return ['ok'=>false,'message'=>'Teste Google Routes falhou: '.(string)($result['error'] ?? 'erro desconhecido')];
        $toll = (float)($result['toll_cost_eur'] ?? 0);
        $tollLabel = $toll > 0 ? TollEstimator::formatEuro($toll) : 'sem valor de portagem devolvido';
        $real = !empty($result['toll_is_real_api']) ? 'portagem Google' : 'fallback interno para portagens';
        return ['ok'=>true,'message'=>sprintf('Teste Google Routes OK. %s para %s: %.1f km, %d min, portagens: %s (%s).', $origin, $destination, (float)($result['distance_km'] ?? 0), (int)round((float)($result['travel_min'] ?? 0)), $tollLabel, $real),'result'=>$result];
    }

    private static function calculatePairwise(array $points, array $options = []): array {
        $legs=[]; $distance=0.0; $travel=0.0; $toll=0.0; $allReal=true;
        for ($i=0; $i<count($points)-1; $i++) {
            $res = self::calculateRoute([$points[$i], $points[$i+1]], array_merge($options, ['single_leg_call'=>true]));
            if (empty($res['ok'])) return $res;
            $leg = $res['legs'][0] ?? ['distance_km'=>(float)($res['distance_km'] ?? 0),'travel_min'=>(float)($res['travel_min'] ?? 0),'toll_cost_eur'=>(float)($res['toll_cost_eur'] ?? 0),'toll_has_price'=>!empty($res['toll_is_real_api']),'routing_provider'=>'google_routes'];
            $legs[]=$leg; $distance+=(float)($leg['distance_km'] ?? 0); $travel+=(float)($leg['travel_min'] ?? 0); $toll+=(float)($leg['toll_cost_eur'] ?? 0); if (empty($res['toll_is_real_api'])) $allReal=false;
        }
        return ['ok'=>true,'provider'=>'google_routes','routing_provider'=>'google_routes','route_mode'=>(string)Config::get('google_routes_route_mode','fastest_tolls'),'routing_preference'=>(string)Config::get('google_routes_preference','TRAFFIC_AWARE'),'distance_km'=>round($distance,3),'travel_min'=>round($travel,1),'duration_seconds'=>(int)round($travel*60),'toll_cost_eur'=>round($toll,2),'toll_estimated_eur'=>round($toll,2),'toll_currency'=>'EUR','toll_has_price'=>$allReal,'toll_is_real_api'=>$allReal ? 1 : 0,'toll_fallback_internal'=>$allReal ? 0 : 1,'toll_model'=>$allReal ? 'Google Routes API TOLLS, portagens por segmento' : 'Google Routes API com fallback interno em pelo menos um segmento','legs'=>$legs,'calculated_at'=>function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),'from_cache'=>false];
    }

    private static function normalizePoints(array $points): array {
        $out=[];
        foreach ($points as $point) {
            if (!is_array($point)) continue;
            $lat = is_numeric($point['lat'] ?? null) ? (float)$point['lat'] : null;
            $lng = is_numeric($point['lng'] ?? null) ? (float)$point['lng'] : null;
            $address = trim((string)($point['address'] ?? ''));
            $name = trim((string)($point['name'] ?? ''));
            if ($lat === null && $lng === null && $address === '' && $name === '') continue;
            $out[] = ['name'=>$name,'address'=>$address,'lat'=>$lat,'lng'=>$lng];
        }
        return $out;
    }

    private static function waypoint(array $point): ?array {
        $lat = is_numeric($point['lat'] ?? null) ? (float)$point['lat'] : null;
        $lng = is_numeric($point['lng'] ?? null) ? (float)$point['lng'] : null;
        if ($lat !== null && $lng !== null) return ['location'=>['latLng'=>['latitude'=>$lat,'longitude'=>$lng]]];
        $address = trim((string)($point['address'] ?? ''));
        if ($address !== '') return ['address'=>$address];
        $name = trim((string)($point['name'] ?? ''));
        if ($name !== '') return ['address'=>$name];
        return null;
    }

    private static function moneyFromTollInfo($tollInfo): array {
        $amount=0.0; $currency='EUR'; $prices = is_array($tollInfo) ? ($tollInfo['estimatedPrice'] ?? []) : [];
        if (!is_array($prices)) return ['amount'=>0.0,'currency'=>$currency,'has_price'=>false];
        foreach ($prices as $price) { if (!is_array($price)) continue; $currency=(string)($price['currencyCode'] ?? $currency); $amount += (float)($price['units'] ?? 0) + ((float)($price['nanos'] ?? 0) / 1000000000); }
        return ['amount'=>round(max(0.0,$amount),2),'currency'=>$currency,'has_price'=>$amount > 0];
    }

    private static function durationSeconds($duration): int {
        if (is_numeric($duration)) return max(0, (int)$duration);
        if (is_string($duration) && preg_match('/^(\d+(?:\.\d+)?)s$/', $duration, $m)) return max(0, (int)round((float)$m[1]));
        return 0;
    }

    private static function friendlyError(string $message): string {
        if (stripos($message, 'referer') !== false || stripos($message, 'referrer') !== false) return $message . ' A chave está restringida por HTTP referrer. Para chamadas feitas pelo WordPress/backend, usa uma API key server-side restrita ao IP público de saída do servidor e limitada apenas à Routes API.';
        if (stripos($message, 'API key not valid') !== false || stripos($message, 'invalid api key') !== false) return $message . ' Confirma se a chave está correta e se a Routes API está ativa no mesmo projeto.';
        if (stripos($message, 'billing') !== false) return $message . ' Confirma se o projeto Google Cloud tem billing ativo.';
        return $message;
    }
}
