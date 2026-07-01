<?php
namespace RoutesPro\PWA;

if (!defined('ABSPATH')) exit;

class ManifestController {
    public static function serve(): void {
        $profile = Settings::normalizeProfile(sanitize_key($_GET['fieldflow_pwa_profile'] ?? Settings::PROFILE_OPERATIVE));
        $o = Settings::get();
        $start = Settings::appPageUrl($profile);
        $manifest = [
            'name' => Settings::profileGet($profile, 'app_name', 'FieldFlow'),
            'short_name' => Settings::profileGet($profile, 'short_name', 'FieldFlow'),
            'description' => Settings::profileGet($profile, 'description', ''),
            'start_url' => add_query_arg(['fieldflow_pwa_v' => ROUTESPRO_VERSION, 'fieldflow_pwa_profile' => $profile], $start),
            'scope' => home_url('/'),
            'display' => 'standalone',
            'id' => home_url('/?fieldflow_mobile_app=' . $profile),
            'orientation' => 'any',
            'prefer_related_applications' => false,
            'background_color' => sanitize_hex_color(Settings::profileGet($profile, 'background_color', '#f8fafc')) ?: '#f8fafc',
            'theme_color' => sanitize_hex_color(Settings::profileGet($profile, 'theme_color', '#111827')) ?: '#111827',
            'icons' => [
                ['src' => Settings::iconUrl('main', 192, $profile), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => Settings::iconUrl('main', 512, $profile), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ];
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate, max-age=0');
        echo wp_json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
