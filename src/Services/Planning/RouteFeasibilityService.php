<?php
namespace RoutesPro\Services\Planning;

if (!defined('ABSPATH')) exit;

/**
 * RouteFeasibilityService
 *
 * Pipeline Phase 5 (Validate Guard Rails) + Phase 6 (Controlled Rebalance).
 *
 * Validates that a produced plan respects hard constraints (max stops/day,
 * max work minutes, corridor coherence) and provides light rebalancing
 * helpers that move visits between days without destroying geo coherence.
 *
 * The key principle: rebalancing is ALWAYS corridor-aware. A visit from a
 * North/coast cluster is never moved to a South/interior day just to
 * equalise load — it either stays unassigned or is placed on a day with
 * compatible corridor.
 */
class RouteFeasibilityService {

    // -------------------------------------------------------------------------
    // Guard-rail validation
    // -------------------------------------------------------------------------

    /**
     * Check whether a candidate visit can be placed on a given day.
     *
     * Enforces:
     *  - max stops per day
     *  - no duplicate store on the same day
     *  - total operational time (visit + travel + lunch) within the day's hard limit
     */
    public static function canPlaceVisitOnDay(
        array $day,
        array $candidate,
        array $options,
        int $maxStops,
        int $targetWorkMin,
        int $lunchMin
    ): bool {
        $currentStops = (int)($day['stops'] ?? count((array)($day['items'] ?? [])));
        if ($currentStops >= $maxStops) return false;

        // No duplicates
        $candidateId = (int)($candidate['id'] ?? 0);
        foreach ((array)($day['items'] ?? []) as $it) {
            if ((int)($it['id'] ?? 0) === $candidateId) return false;
        }

        $tmpItems = array_values(array_merge((array)($day['items'] ?? []), [$candidate]));
        $visitMin = 0;
        foreach ($tmpItems as $it) $visitMin += max(0, (int)($it['visit_duration_min'] ?? 45));

        $startPoint = (array)($day['start_point'] ?? $options['start_point'] ?? []);
        $endPoint   = (array)($day['end_point'] ?? $options['end_point'] ?? []);
        $travelMin  = RouteScoringService::estimateTravelMinutesFast($tmpItems, $startPoint, $endPoint);

        $hasOvertime  = !empty($day['allow_overtime']);
        $extraMin     = $hasOvertime ? (int)($day['extra_minutes'] ?? 0) : 0;
        $hardLimit    = min(600, max(60, (int)($day['hard_limit_minutes'] ?? ($targetWorkMin + $extraMin))));

        return ($visitMin + $travelMin + ($tmpItems ? $lunchMin : 0)) <= $hardLimit
            && count($tmpItems) <= $maxStops;
    }

    // -------------------------------------------------------------------------
    // Plan-level validation
    // -------------------------------------------------------------------------

    /**
     * Validate a plan against hard constraints. Returns validation result
     * with errors and warnings.
     *
     * @param array $days       Planned days.
     * @param array $unassigned Unassigned tasks.
     * @param array $options    Planning options.
     * @param int   $maxStops   Hard cap for stops/day.
     * @param int   $targetWorkMin Soft target for work minutes/day.
     * @param int   $lunchMin   Lunch break minutes.
     * @return array {valid: bool, errors: string[], warnings: string[]}
     */
    public static function validate(
        array $days,
        array $unassigned,
        array $options,
        int $maxStops,
        int $targetWorkMin,
        int $lunchMin
    ): array {
        $errors   = [];
        $warnings = [];

        foreach ($days as $day) {
            $stops   = (int)($day['stops'] ?? count((array)($day['items'] ?? [])));
            $workMin = (int)round(
                (float)($day['travel_min'] ?? 0)
                + (float)($day['visit_min'] ?? 0)
                + (int)($day['lunch_min'] ?? 0)
            );
            $date    = (string)($day['date'] ?? 'desconhecido');

            if ($stops > $maxStops) {
                $errors[] = sprintf('Dia %s excede o máximo de %d visitas (%d colocadas).', $date, $maxStops, $stops);
            }
            if ($workMin > $targetWorkMin + 150) {
                $errors[] = sprintf('Dia %s em situação crítica: %d min operacionais (limite %d + 150).', $date, $workMin, $targetWorkMin);
            } elseif ($workMin > $targetWorkMin) {
                $warnings[] = sprintf('Dia %s acima das horas úteis: %d min vs %d min configurados.', $date, $workMin, $targetWorkMin);
            }

            // Geo coherence check: warn if corridor mix is extreme
            $corridorKey = GeoPartitionService::dominantCorridorForDay($day);
            foreach ((array)($day['items'] ?? []) as $it) {
                $itCorridor = (string)($it['route_corridor_key'] ?? '');
                if ($itCorridor === '' || $corridorKey === '') continue;
                $opposition = GeoPartitionService::corridorOppositionPenalty($corridorKey, $itCorridor);
                if ($opposition >= 1450.0) {
                    $warnings[] = sprintf(
                        'Dia %s tem mistura de corredor oposto (%s vs %s).',
                        $date,
                        GeoPartitionService::humanCorridorLabel($corridorKey),
                        GeoPartitionService::humanCorridorLabel($itCorridor)
                    );
                    break;
                }
            }
        }

        if ($unassigned) {
            $warnings[] = sprintf('%d visita(s) ficaram por atribuir.', count($unassigned));
        }

        return [
            'valid'    => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    // -------------------------------------------------------------------------
    // Corridor-aware light rebalancing
    // -------------------------------------------------------------------------

    /**
     * Attempt to fill "lonely" days (1 visit only) by pulling a compatible
     * visit from a nearby day, using corridor fit to ensure geo coherence.
     *
     * This is a light rebalance: it only moves a visit when the corridor fit
     * score is below a threshold, preventing zigzag routes caused by naive
     * load-levelling.
     *
     * @param array $days         Current planned days.
     * @param array $options      Planning options.
     * @param int   $maxStops     Hard cap for stops/day.
     * @param int   $targetWorkMin Soft target for work minutes/day.
     * @param int   $lunchMin     Lunch break minutes.
     * @return array Rebalanced days.
     */
    public static function fillLonelyDaysFromCorridor(
        array $days,
        array $options,
        int $maxStops,
        int $targetWorkMin,
        int $lunchMin
    ): array {
        if (count($days) <= 1) return $days;

        foreach ($days as $lonelyIdx => &$lonelyDay) {
            if (count((array)($lonelyDay['items'] ?? [])) !== 1) continue;
            if ((int)($lonelyDay['stops'] ?? 1) !== 1) continue;

            // Look for a donor day with > 1 visit and a compatible corridor
            $bestDonorIdx  = null;
            $bestCandidate = null;
            $bestFit       = PHP_FLOAT_MAX;

            foreach ($days as $donorIdx => $donorDay) {
                if ($donorIdx === $lonelyIdx) continue;
                $donorItems = array_values((array)($donorDay['items'] ?? []));
                if (count($donorItems) < 2) continue;

                foreach ($donorItems as $itemIdx => $item) {
                    if ((int)($item['configured_frequency_count'] ?? ($item['frequency_count'] ?? 1)) >= 4) continue;
                    $fit = RouteScoringService::corridorFitScore($lonelyDay, $item, $options);
                    if ($fit < $bestFit) {
                        $bestFit       = $fit;
                        $bestDonorIdx  = $donorIdx;
                        $bestCandidate = ['item' => $item, 'idx' => $itemIdx];
                    }
                }
            }

            if ($bestDonorIdx === null || $bestFit >= PHP_FLOAT_MAX) continue;
            if ($bestFit > 650.0) continue; // corridor too far for a light move

            // Perform the move
            $item        = $bestCandidate['item'];
            $donorItems  = array_values((array)($days[$bestDonorIdx]['items'] ?? []));
            array_splice($donorItems, $bestCandidate['idx'], 1);
            $days[$bestDonorIdx]['items'] = $donorItems;
            $days[$bestDonorIdx]['stops'] = count($donorItems);

            $lonelyDay['items'][] = $item;
            $lonelyDay['stops']   = count($lonelyDay['items']);
        }
        unset($lonelyDay);

        return $days;
    }

    /**
     * Enforce the hard daily stop cap across all days.
     * Moves excess visits to $unassigned (passed by reference).
     *
     * @param array  $days      Current planned days.
     * @param array  $unassigned Unassigned tasks (modified in place).
     * @param array  $options    Planning options.
     * @param int    $maxStops   Hard cap for stops/day.
     * @return array Days with cap enforced.
     */
    public static function enforceHardDailyStopCap(
        array $days,
        array &$unassigned,
        array $options,
        int $maxStops
    ): array {
        foreach ($days as &$day) {
            $items = array_values((array)($day['items'] ?? []));
            if (count($items) <= $maxStops) continue;

            // Sort items: movable (P1/P2/P3) first, anchors (P4+) last
            usort($items, function($a, $b) {
                $fa = max(1, (int)($a['configured_frequency_count'] ?? ($a['frequency_count'] ?? 1)));
                $fb = max(1, (int)($b['configured_frequency_count'] ?? ($b['frequency_count'] ?? 1)));
                // Lower frequency = more movable = lower priority score
                $pa = $fa >= 4 ? 100 : ($fa === 1 ? 10 : ($fa === 2 ? 30 : 60));
                $pb = $fb >= 4 ? 100 : ($fb === 1 ? 10 : ($fb === 2 ? 30 : 60));
                return $pa <=> $pb;
            });

            while (count($items) > $maxStops) {
                $removed      = array_shift($items);
                $unassigned[] = $removed;
            }

            $day['items'] = array_values($items);
            $day['stops'] = count($day['items']);
        }
        unset($day);

        return $days;
    }
}
