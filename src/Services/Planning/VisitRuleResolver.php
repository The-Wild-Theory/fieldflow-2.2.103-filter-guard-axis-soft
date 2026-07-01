<?php
namespace RoutesPro\Services\Planning;

if (!defined('ABSPATH')) exit;

class VisitRuleResolver {
    public static function applyToRow(array $row): array {
        $row['visit_frequency'] = self::frequency((string)($row['visit_frequency'] ?? 'weekly'));
        $row['frequency_count'] = max(1, min(7, (int)($row['frequency_count'] ?? 1)));
        $row['visit_duration_min'] = max(0, min(360, (int)($row['visit_duration_min'] ?? 45)));
        $row['min_gap_days'] = max(0, min(31, (int)($row['min_gap_days'] ?? 0)));
        $row['max_gap_days'] = max(0, min(90, (int)($row['max_gap_days'] ?? 0)));
        $row['preferred_weekdays'] = self::weekdayList($row['preferred_weekdays'] ?? '');
        $row['blocked_weekdays'] = self::weekdayList($row['blocked_weekdays'] ?? '');
        $row['time_window_start'] = self::timeValue((string)($row['time_window_start'] ?? ''));
        $row['time_window_end'] = self::timeValue((string)($row['time_window_end'] ?? ''));
        $row['allow_auto_reschedule'] = !empty($row['allow_auto_reschedule']) ? 1 : 0;
        $row['allow_overtime'] = !empty($row['allow_overtime']) ? 1 : 0;
        $row['rule_source'] = 'campaign_location';
        return $row;
    }

    public static function sanitizeForStorage(array $data): array {
        $preferred = self::weekdayList($data['preferred_weekdays'] ?? '');
        $blocked = self::weekdayList($data['blocked_weekdays'] ?? '');
        $blocked = array_values(array_diff($blocked, $preferred));
        return [
            'min_gap_days' => max(0, min(31, absint($data['min_gap_days'] ?? 0))),
            'max_gap_days' => max(0, min(90, absint($data['max_gap_days'] ?? 0))),
            'preferred_weekdays' => implode(',', $preferred),
            'blocked_weekdays' => implode(',', $blocked),
            'time_window_start' => self::timeValue((string)($data['time_window_start'] ?? '')),
            'time_window_end' => self::timeValue((string)($data['time_window_end'] ?? '')),
            'allow_auto_reschedule' => empty($data['allow_auto_reschedule']) ? 0 : 1,
            'allow_overtime' => empty($data['allow_overtime']) ? 0 : 1,
            'rule_notes' => sanitize_textarea_field((string)($data['rule_notes'] ?? '')),
        ];
    }

    public static function weekdayList($value): array {
        if (is_array($value)) $parts = $value;
        else $parts = preg_split('/[^0-9]+/', (string)$value, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $part) {
            $day = (int)$part;
            if ($day >= 1 && $day <= 7) $out[$day] = $day;
        }
        return array_values($out);
    }

    public static function hasWeekday($value, int $weekday): bool {
        return in_array(max(1, min(7, $weekday)), self::weekdayList($value), true);
    }

    public static function timeValue(string $value): string {
        $value = trim($value);
        if ($value === '') return '';
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $m)) {
            return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
        }
        return '';
    }

    private static function frequency(string $value): string {
        return in_array($value, ['weekly', 'monthly'], true) ? $value : 'weekly';
    }
}
