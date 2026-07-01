<?php
namespace RoutesPro\PWA;

if (!defined('ABSPATH')) exit;

class Router {
    public static function register(): void {
        add_rewrite_rule('^fieldflow-manifest\.json$', 'index.php?fieldflow_pwa_manifest=1', 'top');
        add_rewrite_rule('^fieldflow-service-worker\.js$', 'index.php?fieldflow_pwa_sw=1', 'top');
        add_rewrite_rule('^fieldflow-offline/?$', 'index.php?fieldflow_pwa_offline=1', 'top');
        add_filter('query_vars', [self::class, 'queryVars']);
        add_action('template_redirect', [self::class, 'maybeServe'], 0);
        add_action('init', [self::class, 'maybeFlush'], 99);
    }

    public static function queryVars(array $vars): array {
        $vars[] = 'fieldflow_pwa_manifest';
        $vars[] = 'fieldflow_pwa_sw';
        $vars[] = 'fieldflow_pwa_offline';
        return $vars;
    }

    public static function maybeFlush(): void {
        $flag = 'fieldflow_pwa_rewrite_' . ROUTESPRO_VERSION;
        if (get_option($flag)) return;
        flush_rewrite_rules(false);
        update_option($flag, 1, false);
    }

    private static function requested(string $key): bool {
        return (bool) get_query_var($key) || isset($_GET[$key]);
    }

    public static function maybeServe(): void {
        if (self::requested('fieldflow_pwa_manifest')) {
            ManifestController::serve();
        }
        if (self::requested('fieldflow_pwa_sw')) {
            ServiceWorkerController::serve();
        }
        if (self::requested('fieldflow_pwa_offline')) {
            self::serveOffline();
        }
    }

    public static function serveOffline(): void {
        $o = Settings::get();
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        nocache_headers();
        $title = esc_html($o['offline_title']);
        $msg = esc_html($o['offline_message']);
        $theme = esc_attr($o['theme_color']);
        $bg = esc_attr($o['background_color']);
        echo '<!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>' . $title . '</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:' . $bg . ';font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#111827}.card{max-width:560px;margin:24px;padding:28px;border-radius:28px;background:#fff;box-shadow:0 24px 70px rgba(15,23,42,.14);border:1px solid #e5e7eb}.pill{display:inline-flex;border-radius:999px;background:' . $theme . ';color:#fff;padding:8px 12px;font-weight:800;font-size:12px;letter-spacing:.08em;text-transform:uppercase}h1{font-size:32px;line-height:1.05;margin:18px 0 10px}p{color:#475569;font-size:16px;line-height:1.6}.btn{display:inline-flex;margin-top:14px;background:' . $theme . ';color:#fff;text-decoration:none;border-radius:16px;padding:12px 16px;font-weight:800}</style></head><body><main class="card"><span class="pill">FieldFlow</span><h1>' . $title . '</h1><p>' . $msg . '</p><a class="btn" href="' . esc_url(Settings::appPageUrl()) . '">Tentar novamente</a></main></body></html>';
        exit;
    }
}
