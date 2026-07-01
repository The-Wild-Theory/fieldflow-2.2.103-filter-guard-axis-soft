<?php
namespace RoutesPro\Services\Planning;

if (!defined('ABSPATH')) exit;

/**
 * RouteSequencerService
 *
 * Pipeline Phase 4 — Route Sequencing.
 *
 * Wraps RouteCalculator to provide a clean sequencing interface for the
 * pipeline. Adds geo-aware pre-sorting before 2-opt optimisation so that
 * the initial seed is geographically sensible (closest to start point).
 */
class RouteSequencerService {

    /** @var RouteCalculator */
    private RouteCalculator $calculator;

    public function __construct(?RouteCalculator $calculator = null) {
        $this->calculator = $calculator ?? new RouteCalculator();
    }

    /**
     * Sequence a set of stops into the best estimated route.
     *
     * Uses RouteCalculator (nearest-neighbour + 2-opt + projection) for the
     * actual optimisation. Pre-sorts the input by geo cluster to give the
     * algorithm a geo-coherent starting point.
     *
     * @param array $items      Stop items (each must have lat/lng or will be ignored).
     * @param array $startPoint Start/base point {lat, lng}.
     * @param array $endPoint   End/return point {lat, lng} (optional).
     * @return array Sequenced items.
     */
    public function sequence(array $items, array $startPoint = [], array $endPoint = []): array {
        if (!$items) return [];

        // Pre-sort by geo cluster → corridor position to give 2-opt a
        // geo-coherent starting sequence (avoids obvious zigzag seeds).
        $items = $this->preSortByGeo($items, $startPoint, $endPoint);

        return $this->calculator->reorderStops($items, $startPoint, $endPoint);
    }

    /**
     * Sort items by geo cluster (keeps clustered stops together) then by
     * corridor position (approximates the order along the day's route axis).
     */
    private function preSortByGeo(array $items, array $startPoint, array $endPoint): array {
        usort($items, function($a, $b) use ($startPoint, $endPoint) {
            $clA = (int)($a['geo_cluster_id'] ?? 0);
            $clB = (int)($b['geo_cluster_id'] ?? 0);
            if ($clA !== $clB && $clA > 0 && $clB > 0) return $clA <=> $clB;

            $posA = is_numeric($a['route_corridor_position'] ?? null)
                ? (float)$a['route_corridor_position']
                : GeoPartitionService::taskRouteCorridorPosition((array)$a, $startPoint, $endPoint);
            $posB = is_numeric($b['route_corridor_position'] ?? null)
                ? (float)$b['route_corridor_position']
                : GeoPartitionService::taskRouteCorridorPosition((array)$b, $startPoint, $endPoint);

            return $posA <=> $posB;
        });
        return $items;
    }
}
