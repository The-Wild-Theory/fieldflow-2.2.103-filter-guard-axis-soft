<?php
namespace RoutesPro\Services\Planning;

use RoutesPro\Support\GeoPT;

if (!defined('ABSPATH')) exit;

/**
 * GeoPartitionService
 *
 * Provides all geographic utility methods for the route planning pipeline.
 * Extracted from CampaignLocations to allow pre-partitioning of candidates
 * BEFORE scoring — ensuring geography enters the process early, not as a
 * late penalty.
 *
 * Pipeline phase: used primarily in Phase 2 (Geo Partition) and Phase 3
 * (Intra-partition scoring), but available at any phase.
 */
class GeoPartitionService {

    // -------------------------------------------------------------------------
    // Distance utilities
    // -------------------------------------------------------------------------

    public static function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earth * $c;
    }

    public static function pointHasCoordinates(array $point): bool {
        return is_numeric($point['lat'] ?? null) && is_numeric($point['lng'] ?? null);
    }

    public static function safeHaversine(array $from, array $to): float {
        if (!self::pointHasCoordinates($from) || !self::pointHasCoordinates($to)) return 0.0;
        return self::haversine_km((float)$from['lat'], (float)$from['lng'], (float)$to['lat'], (float)$to['lng']);
    }

    /**
     * Distance from a task/item to the base (start point), averaged with
     * return to end point if both are defined.
     */
    public static function baseDistanceKm(array $item, array $startPoint = [], array $endPoint = []): float {
        if (!self::pointHasCoordinates($item)) return 0.0;
        $km = 0.0;
        $legs = 0;
        if (self::pointHasCoordinates($startPoint)) {
            $km += self::safeHaversine($startPoint, $item);
            $legs++;
        }
        if (self::pointHasCoordinates($endPoint)) {
            $km += self::safeHaversine($item, $endPoint);
            $legs++;
        }
        return $legs > 0 ? $km / $legs : 0.0;
    }

    // -------------------------------------------------------------------------
    // Zone / macro-zone / axis / corridor keys
    // -------------------------------------------------------------------------

    public static function normalizeAsciiKey(string $value): string {
        $value = strtolower(trim($value));
        if ($value === '') return '';
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') $value = $converted;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim((string)$value);
    }

    /**
     * Fine-grained city/district cluster key (uses GeoPT canonical names).
     */
    public static function taskZoneKey(array $task): string {
        return GeoPT::cluster_key(
            (string)($task['city'] ?? ''),
            (string)($task['district'] ?? ''),
            (string)($task['address'] ?? '')
        );
    }

    /**
     * Macro-zone key: north/centre/south + coast/interior (based on district).
     */
    public static function taskMacroZoneKey(array $task): string {
        $district = self::normalizeAsciiKey((string)($task['district'] ?? ''));
        $city     = self::normalizeAsciiKey((string)($task['city'] ?? ''));
        $lng      = is_numeric($task['lng'] ?? null) ? (float)$task['lng'] : null;

        $north   = ['braga', 'braganca', 'porto', 'viana do castelo', 'vila real'];
        $centre  = ['aveiro', 'castelo branco', 'coimbra', 'guarda', 'leiria', 'viseu'];
        $lisbon  = ['lisboa', 'santarem', 'setubal'];
        $south   = ['beja', 'evora', 'faro', 'portalegre'];
        $islands = ['acores', 'madeira', 'ilha da madeira', 'ilha de sao miguel', 'ilha terceira'];
        $interior = ['braganca', 'vila real', 'viseu', 'guarda', 'castelo branco', 'portalegre', 'evora', 'beja'];
        $coast   = ['viana do castelo', 'braga', 'porto', 'aveiro', 'coimbra', 'leiria', 'lisboa', 'setubal', 'faro'];

        $base = $district !== '' ? $district : $city;
        if ($base === '') return '';
        if (in_array($base, $islands, true)) return 'ilhas';

        $vertical = 'centro';
        if (in_array($base, $north, true))       $vertical = 'norte';
        elseif (in_array($base, $south, true))   $vertical = 'sul';
        elseif (in_array($base, $lisbon, true))  $vertical = 'centro-litoral';
        elseif (in_array($base, $centre, true))  $vertical = 'centro';

        $horizontal = '';
        if (in_array($base, $interior, true))     $horizontal = 'interior';
        elseif (in_array($base, $coast, true))    $horizontal = 'litoral';
        elseif ($lng !== null) $horizontal = ($lng < -8.15 ? 'litoral' : 'interior');

        return trim($vertical . ($horizontal !== '' ? '|' . $horizontal : ''));
    }

    /**
     * Axis key relative to the start (base) point: north/south + coast/interior.
     * This is the core of the "axis lock" logic introduced in 2.2.102.
     */
    public static function taskBaseAxisKey(array $task, array $startPoint = []): string {
        if (!self::pointHasCoordinates($task) || !self::pointHasCoordinates($startPoint)) {
            return self::taskMacroZoneKey($task);
        }

        $dLat = (float)$task['lat'] - (float)$startPoint['lat'];
        $dLng = (float)$task['lng'] - (float)$startPoint['lng'];

        $northSouth = abs($dLat) < 0.035 ? 'eixo' : ($dLat > 0 ? 'norte' : 'sul');
        $eastWest   = abs($dLng) < 0.045 ? 'eixo' : ($dLng > 0 ? 'interior' : 'litoral');

        $macro = self::taskMacroZoneKey($task);
        if (strpos($macro, 'interior') !== false) $eastWest = 'interior';
        elseif (strpos($macro, 'litoral') !== false) $eastWest = 'litoral';

        return trim('base|' . $northSouth . '|' . $eastWest);
    }

    /**
     * Route corridor key: segment (departure/middle/arrival) + side + coast/interior.
     * Enables grouping tasks along the same operational corridor.
     */
    public static function taskRouteCorridorKey(array $task, array $startPoint = [], array $endPoint = []): string {
        if (!self::pointHasCoordinates($task)) return self::taskMacroZoneKey($task);
        $macro = self::taskMacroZoneKey($task);
        if (!self::pointHasCoordinates($startPoint)) return $macro;
        if (!self::pointHasCoordinates($endPoint)) return self::taskBaseAxisKey($task, $startPoint);

        $sx = (float)$startPoint['lng'];
        $sy = (float)$startPoint['lat'];
        $ex = (float)$endPoint['lng'];
        $ey = (float)$endPoint['lat'];
        $px = (float)$task['lng'];
        $py = (float)$task['lat'];

        $vx   = $ex - $sx;
        $vy   = $ey - $sy;
        $len2 = ($vx * $vx) + ($vy * $vy);
        if ($len2 <= 0.000001) return self::taskBaseAxisKey($task, $startPoint);

        $t       = (($px - $sx) * $vx + ($py - $sy) * $vy) / $len2;
        $segment = 'meio';
        if ($t < 0.22) $segment = 'partida';
        elseif ($t > 0.78) $segment = 'chegada';

        $cross = ($vx * ($py - $sy)) - ($vy * ($px - $sx));
        $side  = abs($cross) < 0.05 ? 'eixo' : ($cross > 0 ? 'norte' : 'sul');

        $horizontal = '';
        if (strpos($macro, 'interior') !== false) $horizontal = 'interior';
        elseif (strpos($macro, 'litoral') !== false) $horizontal = 'litoral';
        else $horizontal = ((float)$task['lng'] < -8.15) ? 'litoral' : 'interior';

        return trim($segment . '|' . $side . '|' . $horizontal);
    }

    /**
     * Normalized position along the start→end corridor axis (-1 to 2 range).
     */
    public static function taskRouteCorridorPosition(array $task, array $startPoint = [], array $endPoint = []): float {
        if (!self::pointHasCoordinates($task)
            || !self::pointHasCoordinates($startPoint)
            || !self::pointHasCoordinates($endPoint)) {
            return self::baseDistanceKm($task, $startPoint, $endPoint);
        }
        $sx = (float)$startPoint['lng'];
        $sy = (float)$startPoint['lat'];
        $ex = (float)$endPoint['lng'];
        $ey = (float)$endPoint['lat'];
        $px = (float)$task['lng'];
        $py = (float)$task['lat'];

        $vx   = $ex - $sx;
        $vy   = $ey - $sy;
        $len2 = ($vx * $vx) + ($vy * $vy);
        if ($len2 <= 0.000001) return 0.0;

        $t = (($px - $sx) * $vx + ($py - $sy) * $vy) / $len2;
        return round(max(-1.0, min(2.0, $t)), 4);
    }

    /**
     * Additional penalty when two corridor keys are geographically opposite
     * (e.g. north vs south, coast vs interior). Used to strengthen axis coherence.
     */
    public static function corridorOppositionPenalty(string $dominantKey, string $candidateKey): float {
        $a = strtolower($dominantKey);
        $b = strtolower($candidateKey);
        $penalty = 0.0;
        if ((strpos($a, 'norte') !== false && strpos($b, 'sul') !== false)
            || (strpos($a, 'sul') !== false && strpos($b, 'norte') !== false)) {
            $penalty += 1450.0;
        }
        if ((strpos($a, 'interior') !== false && strpos($b, 'litoral') !== false)
            || (strpos($a, 'litoral') !== false && strpos($b, 'interior') !== false)) {
            $penalty += 760.0;
        }
        return $penalty;
    }

    /**
     * True if two tasks share the same fine-grained zone key.
     */
    public static function sameZoneScore($a, $b): bool {
        if (!is_array($a) || !is_array($b)) return false;
        $za = self::taskZoneKey((array)$a);
        return $za !== '' && $za === self::taskZoneKey((array)$b);
    }

    // -------------------------------------------------------------------------
    // Geo clustering
    // -------------------------------------------------------------------------

    /**
     * Determine an adaptive cluster radius for a set of locations, based on
     * the median nearest-neighbour distance (urban ≈ 5 km, mixed ≈ 10 km,
     * rural ≈ 15 km).
     */
    public static function defaultClusterRadiusKm(array $locations): float {
        $points = [];
        foreach ($locations as $location) {
            if (self::pointHasCoordinates((array)$location)) $points[] = (array)$location;
        }
        if (count($points) < 2) return 8.0;

        $sample  = array_slice($points, 0, 60);
        $nearest = [];
        foreach ($sample as $i => $a) {
            $best = PHP_FLOAT_MAX;
            foreach ($sample as $j => $b) {
                if ($i === $j) continue;
                $km = self::safeHaversine($a, $b);
                if ($km > 0 && $km < $best) $best = $km;
            }
            if ($best < PHP_FLOAT_MAX) $nearest[] = $best;
        }
        if (!$nearest) return 8.0;
        sort($nearest);
        $median = $nearest[(int)floor(count($nearest) / 2)];
        if ($median <= 3.0) return 5.0;
        if ($median <= 8.0) return 10.0;
        return 15.0;
    }

    /**
     * Build geo clusters using a radius-based expansion algorithm.
     * Limits cluster radius to prevent mega-clusters that mix coast and interior
     * (2.2.101 Geo Routing Brain fix).
     */
    public static function buildClusters(array $locations, float $radius_km = 8.0, array $startPoint = [], array $endPoint = []): array {
        $radius          = max(1.0, min(35.0, $radius_km));
        $maxClusterRadius = min(55.0, max(12.0, $radius * 2.05));

        $candidates = [];
        foreach (array_values($locations) as $idx => $location) {
            $location = (array)$location;
            if (!self::pointHasCoordinates($location)) continue;
            if (!isset($location['_ff_geo_index'])) $location['_ff_geo_index'] = $idx;
            $candidates[] = $location;
        }

        $clusters  = [];
        $used      = [];
        $clusterId = 1;

        foreach ($candidates as $i => $anchor) {
            if (isset($used[$i])) continue;
            $queue   = [$i];
            $used[$i] = true;
            $members  = [];

            while ($queue) {
                $currentIndex = array_shift($queue);
                $current      = $candidates[$currentIndex];
                $members[]    = $current;

                foreach ($candidates as $j => $candidate) {
                    if (isset($used[$j])) continue;
                    $km = self::safeHaversine($current, $candidate);
                    if ($km <= $radius) {
                        $candidateMembers  = $members;
                        $candidateMembers[] = $candidate;
                        $candidateSummary  = self::buildClusterSummary($candidateMembers, 0, $startPoint, $endPoint);
                        if ((float)($candidateSummary['radius_km'] ?? 0.0) <= $maxClusterRadius) {
                            $used[$j] = true;
                            $queue[]  = $j;
                        }
                    }
                }
            }

            $cluster    = self::buildClusterSummary($members, $clusterId, $startPoint, $endPoint);
            $clusters[] = $cluster;
            $clusterId++;
        }

        usort($clusters, function($a, $b) {
            $ca = count((array)($a['locations'] ?? []));
            $cb = count((array)($b['locations'] ?? []));
            if ($ca !== $cb) return $cb <=> $ca;
            return ((float)($b['density_score'] ?? 0)) <=> ((float)($a['density_score'] ?? 0));
        });

        return $clusters;
    }

    /**
     * Build a summary (centroid, radius, density, etc.) for a set of locations.
     */
    public static function buildClusterSummary(array $locations, int $clusterId, array $startPoint = [], array $endPoint = []): array {
        $lat       = 0.0;
        $lng       = 0.0;
        $count     = 0;
        $cities    = [];
        $districts = [];
        $periodicity = [];

        foreach ($locations as $location) {
            $location = (array)$location;
            if (!self::pointHasCoordinates($location)) continue;
            $lat += (float)$location['lat'];
            $lng += (float)$location['lng'];
            $count++;
            $city     = trim((string)($location['city'] ?? ''));
            $district = trim((string)($location['district'] ?? ''));
            if ($city !== '') $cities[$city]       = ($cities[$city] ?? 0) + 1;
            if ($district !== '') $districts[$district] = ($districts[$district] ?? 0) + 1;
            $freq = max(1, (int)($location['frequency_count'] ?? 1));
            $periodicity['P' . $freq] = ($periodicity['P' . $freq] ?? 0) + 1;
        }

        if ($count <= 0) {
            return [
                'cluster_id'           => $clusterId,
                'locations'            => [],
                'center_lat'           => null,
                'center_lng'           => null,
                'distance_from_base'   => 0.0,
                'return_distance'      => 0.0,
                'avg_internal_distance' => 0.0,
                'density_score'        => 0.0,
                'dispersion_score'     => 0.0,
                'dominant_city'        => '',
                'dominant_district'    => '',
                'periodicity_mix'      => [],
            ];
        }

        $center    = ['lat' => $lat / $count, 'lng' => $lng / $count];
        $sumCenter = 0.0;
        $maxCenter = 0.0;

        foreach ($locations as $location) {
            $location = (array)$location;
            if (!self::pointHasCoordinates($location)) continue;
            $km = self::safeHaversine($center, $location);
            $sumCenter += $km;
            $maxCenter = max($maxCenter, $km);
        }

        arsort($cities);
        arsort($districts);

        $avgInternal = $count > 0 ? $sumCenter / $count : 0.0;
        $density     = self::calculateClusterDensity([
            'locations'            => $locations,
            'avg_internal_distance' => $avgInternal,
        ]);

        return [
            'cluster_id'           => $clusterId,
            'locations'            => array_values($locations),
            'center_lat'           => $center['lat'],
            'center_lng'           => $center['lng'],
            'distance_from_base'   => self::pointHasCoordinates($startPoint)
                ? round(self::safeHaversine($startPoint, $center), 2) : 0.0,
            'return_distance'      => self::pointHasCoordinates($endPoint)
                ? round(self::safeHaversine($center, $endPoint), 2) : 0.0,
            'avg_internal_distance' => round($avgInternal, 2),
            'radius_km'            => round($maxCenter, 2),
            'density_score'        => round($density, 3),
            'dispersion_score'     => round($avgInternal + ($maxCenter * 0.65), 2),
            'dominant_city'        => $cities ? (string)array_key_first($cities) : '',
            'dominant_district'    => $districts ? (string)array_key_first($districts) : '',
            'periodicity_mix'      => $periodicity,
        ];
    }

    public static function calculateClusterDensity(array $cluster): float {
        $locations   = array_values((array)($cluster['locations'] ?? []));
        $count       = count($locations);
        if ($count <= 0) return 0.0;
        $avgInternal = (float)($cluster['avg_internal_distance'] ?? 0.0);
        return $count / max(1.0, $avgInternal);
    }

    // -------------------------------------------------------------------------
    // Day-level geo helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the dominant zone key for a planned day (most-visited city/district).
     */
    public static function dominantZoneForDay(array $day): string {
        $zones = [];
        foreach ((array)($day['items'] ?? []) as $it) {
            $z = self::taskZoneKey((array)$it);
            if ($z !== '') $zones[$z] = ($zones[$z] ?? 0) + 1;
        }
        if (!$zones) return '';
        arsort($zones);
        return (string)array_key_first($zones);
    }

    /**
     * Returns the dominant route corridor key for a planned day.
     */
    public static function dominantCorridorForDay(array $day): string {
        $keys = [];
        foreach ((array)($day['items'] ?? []) as $it) {
            $k = (string)($it['route_corridor_key'] ?? '');
            if ($k !== '') $keys[$k] = ($keys[$k] ?? 0) + 1;
        }
        if (!$keys) return '';
        arsort($keys);
        return (string)array_key_first($keys);
    }

    // -------------------------------------------------------------------------
    // Geo metrics for a set of items
    // -------------------------------------------------------------------------

    /**
     * Compute geographic metrics for a set of items (items already placed in a day).
     */
    public static function routeMetricsForItems(array $items, array $startPoint = [], array $endPoint = []): array {
        $points = [];
        foreach ($items as $item) {
            $item = (array)$item;
            if (self::pointHasCoordinates($item)) $points[] = $item;
        }
        $count = count($points);
        if ($count <= 0) {
            return [
                'access_km'             => 0.0,
                'local_km'              => 0.0,
                'return_km'             => 0.0,
                'total_km'              => 0.0,
                'avg_internal_distance' => 0.0,
                'density_score'         => 0.0,
                'dispersion_score'      => 0.0,
                'radius_km'             => 0.0,
            ];
        }

        $lat = array_sum(array_map(function($p) { return (float)$p['lat']; }, $points)) / $count;
        $lng = array_sum(array_map(function($p) { return (float)$p['lng']; }, $points)) / $count;
        $center = ['lat' => $lat, 'lng' => $lng];

        $sumCenter = 0.0;
        $maxCenter = 0.0;
        $pairSum   = 0.0;
        $pairCount = 0;

        for ($i = 0; $i < $count; $i++) {
            $kmCenter   = self::safeHaversine($center, $points[$i]);
            $sumCenter += $kmCenter;
            $maxCenter  = max($maxCenter, $kmCenter);
            for ($j = $i + 1; $j < $count; $j++) {
                $pairSum += self::safeHaversine($points[$i], $points[$j]);
                $pairCount++;
            }
        }

        $avgInternal = $count > 0 ? $sumCenter / $count : 0.0;
        $avgPair     = $pairCount > 0 ? $pairSum / $pairCount : $avgInternal;
        $localKm     = $count > 1 ? $avgPair * max(1, $count - 1) : 0.0;
        $access      = self::pointHasCoordinates($startPoint) ? self::safeHaversine($startPoint, $center) : 0.0;
        $return      = self::pointHasCoordinates($endPoint) ? self::safeHaversine($center, $endPoint) : 0.0;
        $density     = $count / max(1.0, $avgInternal);
        $dispersion  = $avgInternal + ($maxCenter * 0.65);

        return [
            'access_km'             => round($access, 2),
            'local_km'              => round($localKm, 2),
            'return_km'             => round($return, 2),
            'total_km'              => round($access + $localKm + $return, 2),
            'avg_internal_distance' => round($avgInternal, 2),
            'density_score'         => round($density, 3),
            'dispersion_score'      => round($dispersion, 2),
            'radius_km'             => round($maxCenter, 2),
        ];
    }

    // -------------------------------------------------------------------------
    // Seed selection
    // -------------------------------------------------------------------------

    /**
     * Find the best seed task (first task to place) from a pool, avoiding
     * over-represented zones and preferring proximity to the start point.
     */
    public static function findBestSeedIndex(array $taskPool, array $existingDays, array $startPoint = []): int {
        if (!$taskPool) return 0;
        $zoneLoad = [];
        foreach ($existingDays as $day) {
            foreach ((array)($day['items'] ?? []) as $item) {
                $zone = self::taskZoneKey($item);
                if ($zone === '') continue;
                $zoneLoad[$zone] = ($zoneLoad[$zone] ?? 0) + 1;
            }
        }
        $bestIndex = 0;
        $bestScore = PHP_FLOAT_MAX;
        foreach (array_values($taskPool) as $i => $task) {
            $zone  = self::taskZoneKey($task);
            $score = (float)($zoneLoad[$zone] ?? 0) * 50;
            if (is_numeric($startPoint['lat'] ?? null) && is_numeric($startPoint['lng'] ?? null)
                && is_numeric($task['lat'] ?? null) && is_numeric($task['lng'] ?? null)) {
                $score += self::haversine_km(
                    (float)$startPoint['lat'], (float)$startPoint['lng'],
                    (float)$task['lat'], (float)$task['lng']
                );
            }
            $score -= (float)((int)($task['priority'] ?? 0)) * 3;
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestIndex = $i;
            }
        }
        return $bestIndex;
    }

    // -------------------------------------------------------------------------
    // Human-readable labels
    // -------------------------------------------------------------------------

    public static function humanZoneLabel(string $key): string {
        $key = trim($key);
        if ($key === '') return 'Sem zona dominante';
        return str_replace(['|', '_'], [' / ', ' '], $key);
    }

    public static function humanCorridorLabel(string $key): string {
        $key = trim($key);
        if ($key === '') return 'Sem corredor dominante';
        return ucfirst(str_replace(['|', '_'], [' / ', ' '], $key));
    }
}
