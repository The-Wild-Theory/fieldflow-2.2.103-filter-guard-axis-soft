<?php
namespace RoutesPro\Services\Planning;

if (!defined('ABSPATH')) exit;

class PlanQualityScorer {
    public static function score(array $plan): array {
        $days = (array)($plan['days'] ?? []);
        $unassigned = (array)($plan['unassigned'] ?? []);
        $warnings = [];
        $totalStops = 0;
        $warningCount = 0;
        $overtimeMin = 0;
        foreach ($days as $day) {
            $items = (array)($day['items'] ?? []);
            $totalStops += count($items);
            $overtimeMin += max(0, (int)($day['overtime_min'] ?? 0));
            foreach ($items as $item) {
                if (!empty($item['periodicity_warning'])) {
                    $warningCount++;
                    $warnings[] = (string)$item['periodicity_warning'];
                }
                if (!empty($item['time_window_warning'])) {
                    $warningCount++;
                    $warnings[] = (string)$item['time_window_warning'];
                }
            }
        }
        $missing = count($unassigned);
        $score = 100;
        $score -= min(45, $missing * 8);
        $score -= min(25, $warningCount * 3);
        $score -= min(20, (int)ceil($overtimeMin / 30));
        $score = max(0, min(100, $score));
        $required = $totalStops + $missing;
        $coverage = $required > 0 ? round(($totalStops / $required) * 100, 1) : 100.0;
        return [
            'quality_score' => $score,
            'coverage_rate' => $coverage,
            'warnings' => array_values(array_unique(array_slice($warnings, 0, 25))),
            'hard_errors' => $missing > 0 ? [sprintf('%d visita(s) ficaram por atribuir.', $missing)] : [],
        ];
    }

    public static function attach(array $plan): array {
        $score = self::score($plan);
        $plan['quality_score'] = $score['quality_score'];
        $plan['coverage_rate'] = $score['coverage_rate'];
        $plan['warnings'] = $score['warnings'];
        $plan['hard_errors'] = $score['hard_errors'];
        return $plan;
    }
}
