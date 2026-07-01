<?php
namespace RoutesPro\Admin;

if (!defined('ABSPATH')) exit;

class Branding {
    const CAMPAIGN_LOGOS_OPT = 'routespro_campaign_branding';
    private static $campaign_logo_cache = [];
    private static $prepared_logo_cache = [];

    public static function render_header(string $title = 'FieldFlow Pro', string $subtitle = 'Propriedade Intelectual da The Wild Theory', array $context = []): void {
        $logos = self::get_header_logos($context);
        echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 18px;margin:16px 0 20px 0">';
        echo '<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">';
        foreach ($logos as $logo) {
            echo '<img src="' . esc_url($logo['url']) . '" alt="' . esc_attr($logo['alt']) . '" style="height:' . (int)$logo['height'] . 'px;width:auto;display:block" />';
        }
        echo '<div>';
        echo '<h1 style="margin:0;font-size:22px;line-height:1.2">' . esc_html($title) . '</h1>';
        echo '<div style="margin-top:4px;color:#6b7280;font-size:12px">' . esc_html($subtitle) . '</div>';
        echo '</div>';
        echo '</div>';
        echo '<div style="color:#111827;font-size:12px;font-weight:600">FieldFlow Pro</div>';
        echo '</div>';
    }

    public static function enqueue_menu_branding(): void {
        add_action('admin_head', function () {
            $logo = esc_url(ROUTESPRO_URL . 'assets/logo-twt.png');
            echo '<style>
                #toplevel_page_routespro .wp-menu-image img{padding:4px 0 0 0;opacity:1;max-width:20px;height:auto}
                .routespro-meta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:12px 0 16px}
                .routespro-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px}
                .routespro-flex{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
                .routespro-hidden{display:none}
                .routespro-map{height:420px;border:1px solid #d1d5db;border-radius:12px;overflow:hidden;background:#f9fafb}
            </style>';
            echo '<script>document.addEventListener("DOMContentLoaded",function(){var mi=document.querySelector("#toplevel_page_routespro .wp-menu-image");if(mi&&!mi.querySelector("img")){mi.innerHTML="<img src=\'' . $logo . '\' alt=\'TWT\' />";}});</script>';
        });
    }

    public static function get_campaign_logo_url(int $client_id = 0, int $project_id = 0): string {
        $cache_key = $client_id . '|' . $project_id;
        if (isset(self::$campaign_logo_cache[$cache_key])) return self::$campaign_logo_cache[$cache_key];
        $items = get_option(self::CAMPAIGN_LOGOS_OPT, []);
        if (!is_array($items) || (!$client_id && !$project_id)) return '';
        $keys = [];
        if ($client_id && $project_id) $keys[] = $client_id . '|' . $project_id;
        if ($project_id) $keys[] = '0|' . $project_id;
        if ($client_id) $keys[] = $client_id . '|0';
        foreach ($keys as $key) {
            if (!empty($items[$key]['logo_url'])) return self::$campaign_logo_cache[$cache_key] = esc_url_raw((string)$items[$key]['logo_url']);
        }
        return self::$campaign_logo_cache[$cache_key] = '';
    }

    public static function get_header_logos(array $context = []): array {
        $logos = [[
            'url' => ROUTESPRO_URL . 'assets/logo-twt.png',
            'alt' => 'The Wild Theory',
            'height' => 42,
        ]];
        $client_id = absint($context['client_id'] ?? 0);
        $project_id = absint($context['project_id'] ?? 0);
        $campaign_logo = self::get_campaign_logo_url($client_id, $project_id);
        if ($campaign_logo) {
            $logos[] = [
                'url' => $campaign_logo,
                'alt' => 'Logo cliente',
                'height' => 42,
            ];
        }
        return $logos;
    }

    public static function maybe_prepare_pdf_logo_file(string $source): string {
        $source = trim($source);
        if ($source === '') return '';
        $path = self::resolve_local_file_path($source);
        if (!$path || !is_readable($path)) return '';
        $cache_key = $path . '|' . (string)@filemtime($path);
        if (isset(self::$prepared_logo_cache[$cache_key]) && is_string(self::$prepared_logo_cache[$cache_key])) {
            $cached = self::$prepared_logo_cache[$cache_key];
            if ($cached === '' || file_exists($cached)) return $cached;
        }
        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg'], true)) return self::$prepared_logo_cache[$cache_key] = $path;
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) return self::$prepared_logo_cache[$cache_key] = '';
        $raw = @file_get_contents($path);
        if ($raw === false) return self::$prepared_logo_cache[$cache_key] = '';
        $image = @imagecreatefromstring($raw);
        if (!$image) return self::$prepared_logo_cache[$cache_key] = '';

        $w = imagesx($image);
        $h = imagesy($image);
        $canvas = imagecreatetruecolor($w, $h);
        if (!$canvas) {
            imagedestroy($image);
            return self::$prepared_logo_cache[$cache_key] = '';
        }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagealphablending($canvas, true);
        imagecopy($canvas, $image, 0, 0, 0, 0, $w, $h);

        $tmp = wp_tempnam('fieldflow-logo.jpg');
        if (!$tmp) {
            imagedestroy($canvas);
            imagedestroy($image);
            return self::$prepared_logo_cache[$cache_key] = '';
        }
        imagejpeg($canvas, $tmp, 94);
        imagedestroy($canvas);
        imagedestroy($image);
        return self::$prepared_logo_cache[$cache_key] = $tmp;
    }

    public static function resolve_local_file_path(string $source): string {
        if ($source === '') return '';
        if (strpos($source, ABSPATH) === 0 && file_exists($source)) return $source;
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['baseurl']) && strpos($source, $uploads['baseurl']) === 0) {
            $candidate = $uploads['basedir'] . substr($source, strlen($uploads['baseurl']));
            if (file_exists($candidate)) return $candidate;
        }
        if (strpos($source, content_url()) === 0) {
            $candidate = WP_CONTENT_DIR . substr($source, strlen(content_url()));
            if (file_exists($candidate)) return $candidate;
        }
        if (strpos($source, site_url()) === 0) {
            $candidate = ABSPATH . ltrim(substr($source, strlen(site_url())), '/');
            if (file_exists($candidate)) return $candidate;
        }
        return file_exists($source) ? $source : '';
    }
}
