<?php
namespace RoutesPro\Support;

if (!defined('ABSPATH')) exit;

class TollEstimator {
    public static function options(): array {
        $defaults = [
            'enabled' => true,
            'eur_per_toll_km' => 0.075,
            'tollable_share' => 0.58,
            'min_segment_km' => 3.0,
            'round_to' => 0.05,
            'model' => 'Estimativa classe 1, rota rápida com portagens permitidas',
        ];
        $stored = function_exists('get_option') ? get_option('routespro_toll_estimator', []) : [];
        if (!is_array($stored)) $stored = [];
        $opts = array_merge($defaults, $stored);
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('routespro_toll_estimator_options', $opts);
            if (is_array($filtered)) $opts = array_merge($opts, $filtered);
        }
        $opts['enabled'] = !empty($opts['enabled']);
        $opts['eur_per_toll_km'] = max(0.0, (float)($opts['eur_per_toll_km'] ?? $defaults['eur_per_toll_km']));
        $opts['tollable_share'] = max(0.0, min(1.0, (float)($opts['tollable_share'] ?? $defaults['tollable_share'])));
        $opts['min_segment_km'] = max(0.0, (float)($opts['min_segment_km'] ?? $defaults['min_segment_km']));
        $opts['round_to'] = max(0.01, (float)($opts['round_to'] ?? $defaults['round_to']));
        $opts['model'] = trim((string)($opts['model'] ?? $defaults['model'])) ?: $defaults['model'];
        return $opts;
    }

    public static function estimateFromKm(float $distanceKm, string $context = 'route'): array {
        $opts = self::options();
        $km = max(0.0, $distanceKm);
        $cost = 0.0;
        if (!empty($opts['enabled']) && $km > 0.0) {
            if ($context !== 'segment' || $km >= (float)$opts['min_segment_km']) {
                $raw = $km * (float)$opts['tollable_share'] * (float)$opts['eur_per_toll_km'];
                $cost = self::roundCurrency($raw, (float)$opts['round_to']);
            }
        }
        return [
            'distance_km' => round($km, 2),
            'cost_eur' => round($cost, 2),
            'model' => (string)$opts['model'],
            'tollable_share' => (float)$opts['tollable_share'],
            'eur_per_toll_km' => (float)$opts['eur_per_toll_km'],
            'estimated' => true,
        ];
    }

    public static function costFromKm(float $distanceKm, string $context = 'route'): float {
        $estimate = self::estimateFromKm($distanceKm, $context);
        return (float)($estimate['cost_eur'] ?? 0.0);
    }

    public static function formatEuro(float $value): string {
        return number_format(max(0.0, $value), 2, ',', ' ') . ' €';
    }

    private static function roundCurrency(float $value, float $step): float {
        if ($step <= 0.0) return round($value, 2);
        return round(round($value / $step) * $step, 2);
    }
}
