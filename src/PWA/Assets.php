<?php
namespace RoutesPro\PWA;

if (!defined('ABSPATH')) exit;

class Assets {
    public static function register(): void {
        add_action('wp_head', [self::class, 'headTags'], 2);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
    }

    private static function available(?string $profile = null): bool {
        $profile = $profile ? Settings::normalizeProfile($profile) : Settings::detectPageProfile();
        if (!Settings::isEnabled($profile)) return false;
        $o = Settings::get();
        if (!empty($o['require_login']) && !is_user_logged_in()) return false;
        return true;
    }

    public static function headTags(): void {
        $profile = Settings::detectPageProfile();
        if (!self::available($profile)) return;
        $o = Settings::get();
        $theme_color = sanitize_hex_color(Settings::profileGet($profile, 'theme_color', '#111827')) ?: '#111827';
        ?>
        <link rel="manifest" href="<?php echo esc_url(Settings::manifestUrl($profile)); ?>">
        <meta name="theme-color" content="<?php echo esc_attr($theme_color); ?>">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="<?php echo esc_attr(Settings::profileGet($profile, 'short_name', 'FieldFlow')); ?>">
        <meta name="apple-mobile-web-app-status-bar-style" content="<?php echo esc_attr($o['ios_status_bar']); ?>">
        <link rel="apple-touch-icon" href="<?php echo esc_url(Settings::iconUrl('apple', 180, $profile)); ?>">
        <?php
        $css = $profile === Settings::PROFILE_CLIENT ? ($o['client_custom_css'] ?? '') : ($o['custom_css'] ?? '');
        if (!empty($css)) {
            echo '<style id="fieldflow-pwa-custom-css">' . wp_strip_all_tags($css) . '</style>' . "\n";
        }
    }

    public static function enqueue(): void {
        $profile = Settings::detectPageProfile();
        if (!self::available($profile)) return;
        wp_enqueue_style('fieldflow-pwa', ROUTESPRO_URL . 'assets/pwa/pwa.css', [], ROUTESPRO_VERSION);
        wp_enqueue_script('fieldflow-pwa-install', ROUTESPRO_URL . 'assets/pwa/install.js', [], ROUTESPRO_VERSION, true);
        wp_localize_script('fieldflow-pwa-install', 'FieldFlowPWA', InstallPrompt::config($profile));
    }
}
