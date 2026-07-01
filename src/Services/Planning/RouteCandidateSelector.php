<?php
namespace RoutesPro\Services\Planning;

if (!defined('ABSPATH')) exit;

/**
 * RouteCandidateSelector
 *
 * Pipeline Phase 1 (Selection) + Phase 2 (Geo Partition).
 *
 * Filters eligible candidates by hard rules and pre-annotates them with
 * full geographic metadata BEFORE any scoring occurs. This ensures that
 * geography enters the planning process at the earliest possible stage,
 * preventing mismatches that arise when geo enters only as a late penalty.
 *
 * Key innovation vs. previous approach:
 *  - Geo clustering is computed ONCE upfront for ALL candidates.
 *  - Every candidate is tagged with its cluster_id, axis_key, corridor_key,
 *    and macro_zone_key before the scoring phase begins.
 *  - Candidates are sorted by cadence group first, then by geo cluster,
 *    so that P4 anchors from the same cluster are placed together and P1/P2/P3
 *    candidates from the same cluster follow naturally.
 */
class RouteCandidateSelector {

    /**
     * Phase 1 — Filter eligible candidates.
     *
     * Applies hard rules: campaign active, location active, valid coordinates.
     * Also applies VisitRuleResolver to normalise visit-rule fields.
     *
     * @param array $linked Raw campaign_locations rows (joined with locations).
     * @return array Eligible candidates ready for geo annotation.
     */
    public static function filterEligible(array $linked): array {
        $eligible = [];
        foreach ($linked as $row) {
            $row = (array)$row;
            // Hard rule: campaign must be active
            if (empty($row['campaign_active'])) continue;
            if (($row['campaign_status'] ?? 'active') !== 'active') continue;
            // Hard rule: location must have valid coordinates
            if (!GeoPartitionService::pointHasCoordinates($row)) continue;
            // Normalise visit rules
            $row = VisitRuleResolver::applyToRow($row);
            $eligible[] = $row;
        }
        return $eligible;
    }

    /**
     * Phase 2 — Geo pre-partition.
     *
     * Annotates every candidate with:
     *   - geo_cluster_id / geo_cluster_density / geo_cluster_dispersion
     *   - geo_cluster_access_km / geo_cluster_radius_km
     *   - route_corridor_key / route_corridor_position
     *   - _base_axis_key / _zone_key / _macro_zone_key
     *
     * This metadata is computed once here, before any scoring loop, so the
     * planner can use it without recomputing per-candidate in each iteration.
     *
     * @param array $candidates Already filtered candidates (from filterEligible).
     * @param array $options    Planning options (must include start_point / end_point).
     * @return array Candidates with full geo metadata attached.
     */
    public static function annotateWithGeo(array $candidates, array $options): array {
        if (!$candidates) return [];

        $startPoint = is_array($options['start_point'] ?? null) ? (array)$options['start_point'] : [];
        $endPoint   = is_array($options['end_point'] ?? null) ? (array)$options['end_point'] : [];

        // Tag each candidate with a sequential index for cluster look-up
        foreach ($candidates as $idx => &$c) {
            $c['_ff_geo_index'] = $idx;
        }
        unset($c);

        // Build geo clusters once for the full candidate pool
        $radiusKm  = GeoPartitionService::defaultClusterRadiusKm($candidates);
        $clusters  = GeoPartitionService::buildClusters($candidates, $radiusKm, $startPoint, $endPoint);

        // Map: geo_index → cluster summary
        $clusterByIndex = [];
        foreach ($clusters as $cluster) {
            foreach ((array)($cluster['locations'] ?? []) as $loc) {
                $idx = isset($loc['_ff_geo_index']) ? (int)$loc['_ff_geo_index'] : -1;
                if ($idx >= 0) $clusterByIndex[$idx] = $cluster;
            }
        }

        // Annotate each candidate
        foreach ($candidates as $idx => &$candidate) {
            $cluster = $clusterByIndex[$idx] ?? null;
            if ($cluster) {
                $candidate['geo_cluster_id']         = (int)($cluster['cluster_id'] ?? 0);
                $candidate['geo_cluster_density']    = (float)($cluster['density_score'] ?? 0.0);
                $candidate['geo_cluster_dispersion'] = (float)($cluster['dispersion_score'] ?? 0.0);
                $candidate['geo_cluster_access_km']  = (float)($cluster['distance_from_base'] ?? 0.0);
                $candidate['geo_cluster_radius_km']  = (float)($cluster['radius_km'] ?? 0.0);
            }
            $candidate['route_corridor_key']      = GeoPartitionService::taskRouteCorridorKey($candidate, $startPoint, $endPoint);
            $candidate['route_corridor_position'] = GeoPartitionService::taskRouteCorridorPosition($candidate, $startPoint, $endPoint);
            $candidate['_base_axis_key']          = GeoPartitionService::taskBaseAxisKey($candidate, $startPoint);
            $candidate['_zone_key']               = GeoPartitionService::taskZoneKey($candidate);
            $candidate['_macro_zone_key']         = GeoPartitionService::taskMacroZoneKey($candidate);
        }
        unset($candidate);

        return $candidates;
    }

    /**
     * Sort annotated candidates for geo-coherent planning:
     *   1. Cadence group: P4+ anchors → P1 (flexible) → P2 → P3
     *   2. Within cadence group: geo cluster id (keeps geo-related tasks together)
     *   3. Within cluster: zone key (city/district)
     *   4. Within zone: priority descending
     *   5. Deterministic tie-break: name
     *
     * This sort order propagates into the scheduling algorithms so that
     * anchor stores are placed first, and nearby stores (same cluster) follow
     * immediately — reducing zigzag routes.
     *
     * @param array $candidates Annotated candidates (from annotateWithGeo).
     * @return array Sorted candidates.
     */
    public static function sortByGeoCoherence(array $candidates): array {
        usort($candidates, function($a, $b) {
            $ca = max(1, (int)($a['frequency_count'] ?? 1));
            $cb = max(1, (int)($b['frequency_count'] ?? 1));
            // Rank: P4+ = 1 (anchor), P1 = 2 (geo-flexible), P2 = 3, P3 = 4
            $ra = $ca >= 4 ? 1 : ($ca === 1 ? 2 : ($ca === 2 ? 3 : 4));
            $rb = $cb >= 4 ? 1 : ($cb === 1 ? 2 : ($cb === 2 ? 3 : 4));
            if ($ra !== $rb) return $ra <=> $rb;
            if ($ca !== $cb) return $cb <=> $ca;

            // Geo cluster coherence (within cadence group)
            $clA = (int)($a['geo_cluster_id'] ?? 0);
            $clB = (int)($b['geo_cluster_id'] ?? 0);
            if ($clA !== $clB && $clA > 0 && $clB > 0) return $clA <=> $clB;

            // Zone then priority then name
            $zA = (string)($a['_zone_key'] ?? GeoPartitionService::taskZoneKey((array)$a));
            $zB = (string)($b['_zone_key'] ?? GeoPartitionService::taskZoneKey((array)$b));
            if ($zA !== $zB) return strcmp($zA, $zB);
            $pa = (int)($a['priority'] ?? 0);
            $pb = (int)($b['priority'] ?? 0);
            if ($pa !== $pb) return $pb <=> $pa;
            return strcmp(strtolower((string)($a['name'] ?? '')), strtolower((string)($b['name'] ?? '')));
        });
        return $candidates;
    }

    /**
     * Combined convenience method: filter → annotate → sort.
     *
     * This is the single entry point that implements both Phase 1 and Phase 2
     * of the pipeline. The returned candidates are geo-annotated and sorted
     * for geo-coherent scheduling.
     *
     * @param array $linked  Raw campaign_locations rows.
     * @param array $options Planning options.
     * @return array Filtered, annotated, and sorted candidates.
     */
    public static function prepare(array $linked, array $options): array {
        $eligible   = self::filterEligible($linked);
        $annotated  = self::annotateWithGeo($eligible, $options);
        return self::sortByGeoCoherence($annotated);
    }
}
