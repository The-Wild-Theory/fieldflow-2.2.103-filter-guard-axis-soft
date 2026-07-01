<?php
namespace RoutesPro\PWA;

if (!defined('ABSPATH')) exit;

class Settings {
    public const OPT_KEY = 'fieldflow_pwa_settings';
    public const PROFILE_OPERATIVE = 'operative';
    public const PROFILE_CLIENT = 'client';

    public static function defaults(): array {
        return [
            'enabled' => 1,
            'require_login' => 1,
            'install_prompt_enabled' => 1,
            'prompt_frequency' => 'weekly',
            'prompt_delay' => 2,
            'display' => 'standalone',
            'orientation' => 'portrait',
            'ios_status_bar' => 'black-translucent',
            'offline_enabled' => 1,
            'cache_assets' => 1,
            'cache_rest' => 0,
            'offline_title' => 'FieldFlow offline',
            'offline_message' => 'Sem ligação neste momento. Assim que a rede voltar, atualiza a página para retomar a operação.',
            'support_url' => '',
            'privacy_url' => '',
            'terms_url' => '',
            'custom_links' => '',
            'custom_css' => '',
            'push_enabled' => 0,
            'push_public_key' => '',
            'push_private_key' => '',

            // App Operativo, shortcode [fieldflow_app]
            'app_page_id' => 0,
            'app_name' => 'FieldFlow Operativo',
            'short_name' => 'FieldFlow',
            'description' => 'App operacional FieldFlow para rotas, reports, mensagens e base comercial.',
            'theme_color' => '#111827',
            'background_color' => '#f8fafc',
            'start_panel' => 'route',
            'icon_id' => 0,
            'apple_icon_id' => 0,
            'install_title' => 'Instalar FieldFlow Operativo',
            'install_text' => 'Acede às tuas rotas, reports, mensagens e base comercial como uma app no telemóvel.',
            'install_button' => 'Instalar App',
            'dismiss_button' => 'Agora não',
            'ios_title' => 'Instalar FieldFlow no iPhone',
            'ios_text' => 'No Safari, toca em Partilhar e escolhe Adicionar ao Ecrã Principal.',
            'menu_route' => 1,
            'menu_discovery' => 1,
            'menu_report' => 1,
            'menu_commercial' => 1,
            'menu_messages' => 1,
            'menu_analytics' => 1,

            // App Cliente, shortcode [fieldflow_client_portal]
            'client_enabled' => 1,
            'client_app_page_id' => 0,
            'client_app_name' => 'FieldFlow Cliente',
            'client_short_name' => 'FF Cliente',
            'client_description' => 'Portal cliente FieldFlow para acompanhar campanhas, rotas, execução e analytics.',
            'client_theme_color' => '#0f172a',
            'client_background_color' => '#f8fafc',
            'client_icon_id' => 0,
            'client_apple_icon_id' => 0,
            'client_install_title' => 'Instalar Portal Cliente',
            'client_install_text' => 'Acompanha campanhas, rotas, relatórios e analytics numa app dedicada para cliente.',
            'client_install_button' => 'Instalar App Cliente',
            'client_ios_title' => 'Instalar Portal Cliente no iPhone',
            'client_ios_text' => 'No Safari, toca em Partilhar e escolhe Adicionar ao Ecrã Principal.',
            'client_support_url' => '',
            'client_privacy_url' => '',
            'client_terms_url' => '',
            'client_custom_links' => '',
            'client_custom_css' => '',
        ];
    }

    public static function get(?string $key = null, $default = null) {
        $saved = get_option(self::OPT_KEY, []);
        $opts = wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
        if ($key === null) return $opts;
        return array_key_exists($key, $opts) ? $opts[$key] : $default;
    }

    public static function isEnabled(?string $profile = null): bool {
        if (!self::get('enabled', 1)) return false;
        if ($profile === self::PROFILE_CLIENT && !self::get('client_enabled', 1)) return false;
        return true;
    }

    public static function normalizeProfile(?string $profile): string {
        return $profile === self::PROFILE_CLIENT ? self::PROFILE_CLIENT : self::PROFILE_OPERATIVE;
    }

    public static function profilePrefix(?string $profile): string {
        return self::normalizeProfile($profile) === self::PROFILE_CLIENT ? 'client_' : '';
    }

    public static function profileGet(string $profile, string $key, $default = null) {
        $profile = self::normalizeProfile($profile);
        if ($profile === self::PROFILE_CLIENT) {
            $client_key = 'client_' . $key;
            $value = self::get($client_key, null);
            if ($value !== null && $value !== '') return $value;
        }
        return self::get($key, $default);
    }

    public static function appPageUrl(string $profile = self::PROFILE_OPERATIVE): string {
        $profile = self::normalizeProfile($profile);
        $key = $profile === self::PROFILE_CLIENT ? 'client_app_page_id' : 'app_page_id';
        $page_id = absint(self::get($key, 0));
        if ($page_id) {
            $url = get_permalink($page_id);
            if ($url) return $url;
        }
        return home_url('/');
    }

    public static function manifestUrl(string $profile = self::PROFILE_OPERATIVE): string {
        return add_query_arg('fieldflow_pwa_profile', self::normalizeProfile($profile), home_url('/?fieldflow_pwa_manifest=1'));
    }

    public static function serviceWorkerUrl(string $profile = self::PROFILE_OPERATIVE): string {
        return add_query_arg([
            'fieldflow_pwa_sw' => 1,
            'fieldflow_pwa_profile' => self::normalizeProfile($profile),
            'v' => ROUTESPRO_VERSION,
        ], home_url('/'));
    }

    public static function offlineUrl(): string {
        return home_url('/?fieldflow_pwa_offline=1');
    }

    public static function iconUrl(string $kind = 'main', int $size = 512, string $profile = self::PROFILE_OPERATIVE): string {
        $profile = self::normalizeProfile($profile);
        $key = $kind === 'apple' ? 'apple_icon_id' : 'icon_id';
        if ($profile === self::PROFILE_CLIENT) $key = 'client_' . $key;
        $id = absint(self::get($key, 0));
        if ($id) {
            $src = wp_get_attachment_image_src($id, [$size, $size]);
            if (!empty($src[0])) return $src[0];
            $url = wp_get_attachment_url($id);
            if ($url) return $url;
        }
        if ($size <= 192 && file_exists(ROUTESPRO_PATH . 'assets/pwa/fieldflow-pwa-icon-192.png')) return ROUTESPRO_URL . 'assets/pwa/fieldflow-pwa-icon-192.png';
        if (file_exists(ROUTESPRO_PATH . 'assets/pwa/fieldflow-pwa-icon-512.png')) return ROUTESPRO_URL . 'assets/pwa/fieldflow-pwa-icon-512.png';
        if (file_exists(ROUTESPRO_PATH . 'assets/logo-twt.png')) return ROUTESPRO_URL . 'assets/logo-twt.png';
        return includes_url('images/w-logo-blue-white-bg.png');
    }

    public static function detectPageProfile(): string {
        $o = self::get();
        $client_page = absint($o['client_app_page_id'] ?? 0);
        $op_page = absint($o['app_page_id'] ?? 0);
        if ($client_page && is_page($client_page)) return self::PROFILE_CLIENT;
        if ($op_page && is_page($op_page)) return self::PROFILE_OPERATIVE;
        if (is_singular()) {
            $post = get_post();
            $content = $post && !empty($post->post_content) ? $post->post_content : '';
            if ($content && has_shortcode($content, 'fieldflow_client_portal')) return self::PROFILE_CLIENT;
            if ($content && has_shortcode($content, 'fieldflow_app')) return self::PROFILE_OPERATIVE;
        }
        return self::PROFILE_OPERATIVE;
    }

    public static function sanitize(array $raw): array {
        $d = self::defaults();
        $display = sanitize_key($raw['display'] ?? $d['display']);
        if (!in_array($display, ['standalone','fullscreen','minimal-ui','browser'], true)) $display = $d['display'];
        $orientation = sanitize_key($raw['orientation'] ?? $d['orientation']);
        if (!in_array($orientation, ['portrait','landscape','any'], true)) $orientation = $d['orientation'];
        $freq = sanitize_key($raw['prompt_frequency'] ?? $d['prompt_frequency']);
        if (!in_array($freq, ['always','daily','weekly','once','manual'], true)) $freq = $d['prompt_frequency'];
        $panel = sanitize_key($raw['start_panel'] ?? $d['start_panel']);
        if (!in_array($panel, ['route','discovery','report','commercial','messages','analytics'], true)) $panel = $d['start_panel'];
        $status = sanitize_key($raw['ios_status_bar'] ?? $d['ios_status_bar']);
        if (!in_array($status, ['default','black','black-translucent'], true)) $status = $d['ios_status_bar'];

        return [
            'enabled' => empty($raw['enabled']) ? 0 : 1,
            'require_login' => empty($raw['require_login']) ? 0 : 1,
            'install_prompt_enabled' => empty($raw['install_prompt_enabled']) ? 0 : 1,
            'prompt_frequency' => $freq,
            'prompt_delay' => max(0, min(30, absint($raw['prompt_delay'] ?? $d['prompt_delay']))),
            'display' => $display,
            'orientation' => $orientation,
            'ios_status_bar' => $status,
            'offline_enabled' => empty($raw['offline_enabled']) ? 0 : 1,
            'cache_assets' => empty($raw['cache_assets']) ? 0 : 1,
            'cache_rest' => empty($raw['cache_rest']) ? 0 : 1,
            'offline_title' => sanitize_text_field($raw['offline_title'] ?? $d['offline_title']),
            'offline_message' => sanitize_textarea_field($raw['offline_message'] ?? $d['offline_message']),
            'support_url' => esc_url_raw($raw['support_url'] ?? ''),
            'privacy_url' => esc_url_raw($raw['privacy_url'] ?? ''),
            'terms_url' => esc_url_raw($raw['terms_url'] ?? ''),
            'custom_links' => sanitize_textarea_field($raw['custom_links'] ?? ''),
            'custom_css' => wp_kses_post($raw['custom_css'] ?? ''),
            'push_enabled' => empty($raw['push_enabled']) ? 0 : 1,
            'push_public_key' => sanitize_textarea_field($raw['push_public_key'] ?? ''),
            'push_private_key' => sanitize_textarea_field($raw['push_private_key'] ?? ''),

            'app_page_id' => absint($raw['app_page_id'] ?? 0),
            'app_name' => sanitize_text_field($raw['app_name'] ?? $d['app_name']),
            'short_name' => sanitize_text_field($raw['short_name'] ?? $d['short_name']),
            'description' => sanitize_textarea_field($raw['description'] ?? $d['description']),
            'theme_color' => sanitize_hex_color($raw['theme_color'] ?? $d['theme_color']) ?: $d['theme_color'],
            'background_color' => sanitize_hex_color($raw['background_color'] ?? $d['background_color']) ?: $d['background_color'],
            'start_panel' => $panel,
            'icon_id' => absint($raw['icon_id'] ?? 0),
            'apple_icon_id' => absint($raw['apple_icon_id'] ?? 0),
            'install_title' => sanitize_text_field($raw['install_title'] ?? $d['install_title']),
            'install_text' => sanitize_textarea_field($raw['install_text'] ?? $d['install_text']),
            'install_button' => sanitize_text_field($raw['install_button'] ?? $d['install_button']),
            'dismiss_button' => sanitize_text_field($raw['dismiss_button'] ?? $d['dismiss_button']),
            'ios_title' => sanitize_text_field($raw['ios_title'] ?? $d['ios_title']),
            'ios_text' => sanitize_textarea_field($raw['ios_text'] ?? $d['ios_text']),
            'menu_route' => empty($raw['menu_route']) ? 0 : 1,
            'menu_discovery' => empty($raw['menu_discovery']) ? 0 : 1,
            'menu_report' => empty($raw['menu_report']) ? 0 : 1,
            'menu_commercial' => empty($raw['menu_commercial']) ? 0 : 1,
            'menu_messages' => empty($raw['menu_messages']) ? 0 : 1,
            'menu_analytics' => empty($raw['menu_analytics']) ? 0 : 1,

            'client_enabled' => empty($raw['client_enabled']) ? 0 : 1,
            'client_app_page_id' => absint($raw['client_app_page_id'] ?? 0),
            'client_app_name' => sanitize_text_field($raw['client_app_name'] ?? $d['client_app_name']),
            'client_short_name' => sanitize_text_field($raw['client_short_name'] ?? $d['client_short_name']),
            'client_description' => sanitize_textarea_field($raw['client_description'] ?? $d['client_description']),
            'client_theme_color' => sanitize_hex_color($raw['client_theme_color'] ?? $d['client_theme_color']) ?: $d['client_theme_color'],
            'client_background_color' => sanitize_hex_color($raw['client_background_color'] ?? $d['client_background_color']) ?: $d['client_background_color'],
            'client_icon_id' => absint($raw['client_icon_id'] ?? 0),
            'client_apple_icon_id' => absint($raw['client_apple_icon_id'] ?? 0),
            'client_install_title' => sanitize_text_field($raw['client_install_title'] ?? $d['client_install_title']),
            'client_install_text' => sanitize_textarea_field($raw['client_install_text'] ?? $d['client_install_text']),
            'client_install_button' => sanitize_text_field($raw['client_install_button'] ?? $d['client_install_button']),
            'client_ios_title' => sanitize_text_field($raw['client_ios_title'] ?? $d['client_ios_title']),
            'client_ios_text' => sanitize_textarea_field($raw['client_ios_text'] ?? $d['client_ios_text']),
            'client_support_url' => esc_url_raw($raw['client_support_url'] ?? ''),
            'client_privacy_url' => esc_url_raw($raw['client_privacy_url'] ?? ''),
            'client_terms_url' => esc_url_raw($raw['client_terms_url'] ?? ''),
            'client_custom_links' => sanitize_textarea_field($raw['client_custom_links'] ?? ''),
            'client_custom_css' => wp_kses_post($raw['client_custom_css'] ?? ''),
        ];
    }
}
