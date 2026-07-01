<?php
namespace RoutesPro\Services\Planning;

use RoutesPro\Admin\CampaignLocations;

if (!defined('ABSPATH')) exit;

/**
 * RoutePlanningPipeline
 *
 * Explicit geographic route-planning pipeline (Option A).
 *
 * Orchestrates the following phases, making each step explicit and
 * independently testable:
 *
 *  Phase 1 — Candidate Selection
 *      Hard rules: active campaign, active location, valid coordinates,
 *      cadence / owner filters.  Handled by RouteCandidateSelector.
 *
 *  Phase 2 — Geo Pre-Partition
 *      Clusters all candidates geographically BEFORE any scoring loop,
 *      attaches cluster_id, axis_key, corridor_key, macro_zone_key.
 *      This is the core architectural fix: geography enters as a
 *      first-class constraint, not as a late scoring penalty.
 *      Handled by RouteCandidateSelector + GeoPartitionService.
 *
 *  Phase 3 — Scoring (intra-partition)
 *      Week/month scoring with geo-coherent candidates already sorted.
 *      Delegated to CampaignLocations internal planner via _run_pipeline_internal().
 *      RouteScoringService provides helper methods used by the planner.
 *
 *  Phase 4 — Route Sequencing
 *      Nearest-neighbour + 2-opt per day.
 *      Delegated to CampaignLocations (which calls RouteCalculator).
 *
 *  Phase 5 — Guard-rail Validation
 *      Hard caps: max stops/day, max work minutes, corridor coherence.
 *      RouteFeasibilityService::validate() is called after the plan is built.
 *
 *  Phase 6 — Controlled Rebalance
 *      Light corridor-aware rebalancing.
 *      Delegated to CampaignLocations rebalance methods (corridor-aware).
 *
 * Usage:
 *   $plan = RoutePlanningPipeline::run($linked, 'weekly', '2025-01-06', 'pt', $options);
 */
class RoutePlanningPipeline {

    /**
     * Run the full planning pipeline.
     *
     * @param array  $linked         Raw campaign_locations rows.
     * @param string $scope          'weekly' or 'monthly'.
     * @param string $base_date      ISO date string (start of period).
     * @param string $holiday_country Country code for holiday calendar ('pt'|'es').
     * @param array  $options        Normalised planning options.
     * @return array Complete plan structure (days, preview_days, summary, …).
     */
    public static function run(
        array $linked,
        string $scope,
        string $base_date,
        string $holiday_country,
        array $options
    ): array {
        // ---------------------------------------------------------------
        // Phase 1 + 2: Filter eligible candidates and geo pre-partition.
        //
        // All candidates are geo-annotated (cluster_id, corridor_key, axis_key,
        // macro_zone_key) BEFORE the scoring phase.  The sort order returned
        // by RouteCandidateSelector::prepare() groups candidates by cadence
        // group then geo cluster, so the scheduling algorithms naturally place
        // geo-coherent groups on the same days.
        // ---------------------------------------------------------------
        $annotated = RouteCandidateSelector::prepare($linked, $options);

        // Signal to the internal planner that geo annotation is done.
        // The planner will honour pre-computed geo fields (route_corridor_key,
        // geo_cluster_id, etc.) and skip redundant re-annotation.
        $options['_geo_pre_annotated'] = true;

        // ---------------------------------------------------------------
        // Phase 3 – 6: Delegate to CampaignLocations internal planner.
        //
        // The internal planner handles scoring, sequencing, guard-rail
        // enforcement, and rebalancing.  It uses the pre-computed geo
        // metadata carried by the annotated candidates.
        // ---------------------------------------------------------------
        $plan = CampaignLocations::_run_pipeline_internal(
            $annotated,
            $scope,
            $base_date,
            $holiday_country,
            $options
        );

        // ---------------------------------------------------------------
        // Phase 5: Guard-rail validation (smoke check).
        //
        // Attach feasibility validation results to the plan so callers can
        // surface errors/warnings without re-running the full pipeline.
        // ---------------------------------------------------------------
        $maxStops      = max(1, (int)($options['max_stops_per_day'] ?? 12));
        $targetWorkMin = max(60, (int)($options['work_minutes'] ?? 480));
        $lunchMin      = max(0, (int)($options['lunch_minutes'] ?? 60));

        $feasibility = RouteFeasibilityService::validate(
            (array)($plan['days'] ?? []),
            (array)($plan['unassigned'] ?? []),
            $options,
            $maxStops,
            $targetWorkMin,
            $lunchMin
        );

        $plan['feasibility'] = $feasibility;

        // Merge feasibility warnings into plan-level warnings (non-breaking)
        if (!empty($feasibility['warnings'])) {
            $existing = (array)($plan['warnings'] ?? []);
            $plan['warnings'] = array_values(array_unique(array_merge($existing, $feasibility['warnings'])));
        }
        if (!empty($feasibility['errors'])) {
            $existing = (array)($plan['hard_errors'] ?? []);
            $plan['hard_errors'] = array_values(array_unique(array_merge($existing, $feasibility['errors'])));
        }

        return $plan;
    }
}
