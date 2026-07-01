<?php
namespace RoutesPro\Services\Planning;

if (!defined('ABSPATH')) exit;

/**
 * RouteScoringService
 *
 * Pipeline Phase 3 — Geographic intra-partition scoring.
 *
 * Provides scoring helpers that quantify how well a candidate fits a given
 * day, taking into account geo cluster coherence, corridor alignment,
 * axis opposition, and detour cost. All scoring methods return a float
 * where LOWER is better (min-cost selection).
 *
 * These helpers are used both in the initial assignment phase and during
 * rebalancing to ensure geo coherence is preserved.
 */
class RouteScoringService {

    /**
     * Compute the geographic fit score of adding a candidate to a day.
     *
     * A lower score means a better geo fit. Penalises:
     *  - corridor/axis opposition (opposite ends of the route)
     *  - zone mismatch (different city/district cluster)
     *  - large detour distance
     *
     * Rewards:
     *  - same geo cluster as day's anchor
     *  - same corridor key
     *  - proximity to already-placed items
     *
     * @param array $candidate The candidate task to evaluate.
     * @param array $day       The day plan (items already placed).
     * @param array $options   Planning options (start_point, end_point, route_strategy).
     * @return float Score (lower = better fit).
     */
    public static function geoFitScore(array $candidate, array $day, array $options = []): float {
        $startPoint = is_array($options['start_point'] ?? null) ? (array)$options['start_point'] : [];
        $endPoint   = is_array($options['end_point'] ?? null) ? (array)$options['end_point'] : [];
        $items      = (array)($day['items'] ?? []);

        if (!$items) {
            // Empty day — cost is distance from base
            return GeoPartitionService::pointHasCoordinates($candidate)
                ? GeoPartitionService::baseDistanceKm($candidate, $startPoint, $endPoint) * 1.4
                : 999.0;
        }

        $score = 0.0;

        // --- Geo cluster coherence ---
        $anchorCluster = (int)(($items[0] ?? [])['geo_cluster_id'] ?? 0);
        $candidateCluster = (int)($candidate['geo_cluster_id'] ?? 0);
        if ($anchorCluster > 0 && $candidateCluster > 0) {
            if ($anchorCluster === $candidateCluster) {
                $score -= 280.0; // strong bonus for same cluster
            } else {
                $score += 380.0; // penalty for different cluster
            }
        }

        // --- Zone key coherence ---
        $dominantZone    = GeoPartitionService::dominantZoneForDay($day);
        $candidateZone   = GeoPartitionService::taskZoneKey($candidate);
        if ($dominantZone !== '' && $candidateZone !== '') {
            if ($dominantZone === $candidateZone) {
                $score -= 180.0;
            } elseif ($dominantZone !== $candidateZone) {
                $score += 150.0;
            }
        }

        // --- Corridor key coherence ---
        $dominantCorridor  = GeoPartitionService::dominantCorridorForDay($day);
        $candidateCorridor = GeoPartitionService::taskRouteCorridorKey($candidate, $startPoint, $endPoint);
        if ($dominantCorridor !== '' && $candidateCorridor !== '') {
            if ($dominantCorridor === $candidateCorridor) {
                $score -= 220.0; // bonus for same corridor
            } else {
                $opposition = GeoPartitionService::corridorOppositionPenalty($dominantCorridor, $candidateCorridor);
                $score += 300.0 + $opposition;
            }
        }

        // --- Proximity to nearest item already placed ---
        if (GeoPartitionService::pointHasCoordinates($candidate)) {
            $nearestKm = PHP_FLOAT_MAX;
            foreach ($items as $it) {
                if (!GeoPartitionService::pointHasCoordinates((array)$it)) continue;
                $km = GeoPartitionService::safeHaversine((array)$it, $candidate);
                if ($km < $nearestKm) $nearestKm = $km;
            }
            if (is_finite($nearestKm)) {
                $score += min(560.0, $nearestKm * 12.0);
                if ($nearestKm <= 6.0)  $score -= 160.0;
                if ($nearestKm > 24.0)  $score += min(360.0, ($nearestKm - 24.0) * 18.0);
            }
        }

        return $score;
    }

    /**
     * Compute the corridor fit score for adding a candidate to a "lonely" day
     * (a day with only 1 visit). Used by the corridor-gap-fill rebalancing step.
     *
     * Returns PHP_FLOAT_MAX if the candidate is geographically incompatible.
     *
     * @param array $lonelyDay  Day with exactly 1 visit.
     * @param array $candidate  Candidate to evaluate for addition.
     * @param array $options    Planning options.
     * @return float Score (lower = better fit), or PHP_FLOAT_MAX if incompatible.
     */
    public static function corridorFitScore(array $lonelyDay, array $candidate, array $options = []): float {
        $items  = array_values((array)($lonelyDay['items'] ?? []));
        $anchor = $items[0] ?? [];

        $detour    = self::routeDetourKm($lonelyDay, $candidate, $options);
        $anchorKm  = GeoPartitionService::pointHasCoordinates((array)$anchor)
                     && GeoPartitionService::pointHasCoordinates($candidate)
            ? GeoPartitionService::safeHaversine((array)$anchor, $candidate)
            : 999.0;

        $sameZoneBonus = 0.0;
        $anchorZone    = GeoPartitionService::taskZoneKey((array)$anchor);
        $candidateZone = GeoPartitionService::taskZoneKey($candidate);
        if ($anchorZone !== '' && $anchorZone === $candidateZone) $sameZoneBonus = 32.0;

        $baseDistance  = self::estimateDayDistanceKmFast(
            (array)($lonelyDay['items'] ?? []),
            (array)($lonelyDay['start_point'] ?? $options['start_point'] ?? []),
            (array)($lonelyDay['end_point'] ?? $options['end_point'] ?? [])
        );
        $detourLimit = max(22.0, min(75.0, ($baseDistance * 0.28) + 14.0));
        if ($detour > $detourLimit && $anchorKm > 45.0) return PHP_FLOAT_MAX;

        return ($detour * 9.0) + ($anchorKm * 2.25) - $sameZoneBonus;
    }

    /**
     * Calculate the additional route distance (km) caused by inserting a
     * candidate into a day's existing item list.
     */
    public static function routeDetourKm(array $day, array $candidate, array $options = []): float {
        $startPoint = (array)($day['start_point'] ?? $options['start_point'] ?? []);
        $endPoint   = (array)($day['end_point'] ?? $options['end_point'] ?? []);
        $items      = array_values((array)($day['items'] ?? []));

        $before = self::estimateDayDistanceKmFast($items, $startPoint, $endPoint);
        $after  = self::estimateDayDistanceKmFast(array_merge($items, [$candidate]), $startPoint, $endPoint);
        return max(0.0, $after - $before);
    }

    /**
     * Fast haversine-based route distance estimation (no API calls).
     * Used for scoring and rebalancing where accuracy > speed trade-off
     * favours the latter.
     */
    public static function estimateDayDistanceKmFast(array $items, array $startPoint = [], array $endPoint = []): float {
        $prev  = GeoPartitionService::pointHasCoordinates($startPoint) ? $startPoint : null;
        $total = 0.0;
        foreach ($items as $item) {
            $item = (array)$item;
            if (!GeoPartitionService::pointHasCoordinates($item)) continue;
            if ($prev !== null) $total += GeoPartitionService::safeHaversine($prev, $item);
            $prev = $item;
        }
        if ($prev !== null && GeoPartitionService::pointHasCoordinates($endPoint)) {
            $total += GeoPartitionService::safeHaversine($prev, $endPoint);
        }
        return round($total, 2);
    }

    /**
     * Estimate travel time (minutes) from a fast haversine distance.
     * Uses a 1.6× road factor and 60 km/h average speed.
     */
    public static function estimateTravelMinutesFast(array $items, array $startPoint = [], array $endPoint = []): int {
        $km = self::estimateDayDistanceKmFast($items, $startPoint, $endPoint);
        return (int)round($km * 1.6 / 60.0 * 60.0); // km * road_factor / speed_kmh * 60 min
    }
}
