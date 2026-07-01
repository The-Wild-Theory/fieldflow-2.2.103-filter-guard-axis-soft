<?php
namespace RoutesPro\Support;

if (!defined('ABSPATH')) exit;

class Features {
    public static function defaults(): array {
        return [
            'client_portal' => true,
            'team_messaging' => true,
            'system_health' => true,
            'system_logs' => true,
            'ai_tools' => true,
            'premium_branding' => true,
        ];
    }

    public static function all(): array {
        $saved = get_option('routespro_feature_flags', []);
        return wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
    }

    public static function enabled(string $flag): bool {
        $flags = self::all();
        if (!array_key_exists($flag, $flags)) return false;
        $enabled = (bool) $flags[$flag];
        $licensed = LicenseManager::get('features', []);
        if (is_array($licensed) && array_key_exists($flag, $licensed)) {
            return $enabled && (bool) $licensed[$flag];
        }
        return $enabled;
    }
}
