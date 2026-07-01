<?php
namespace RoutesPro\PWA;

if (!defined('ABSPATH')) exit;

class InstallPrompt {
    private static bool $appRendered = false;
    private static bool $clientRendered = false;

    public static function register(): void {
        add_action('wp_footer', [self::class, 'render'], 40);
    }

    public static function markAppRendered(): void { self::$appRendered = true; }
    public static function markClientRendered(): void { self::$clientRendered = true; }

    public static function currentProfile(): string {
        $detected = Settings::detectPageProfile();
        if (self::$clientRendered) return Settings::PROFILE_CLIENT;
        if (self::$appRendered) return Settings::PROFILE_OPERATIVE;
        return $detected;
    }

    public static function isAppContext(?string $profile = null): bool {
        $profile = Settings::normalizeProfile($profile ?: self::currentProfile());
        $o = Settings::get();
        $page_key = $profile === Settings::PROFILE_CLIENT ? 'client_app_page_id' : 'app_page_id';
        $shortcode = $profile === Settings::PROFILE_CLIENT ? 'fieldflow_client_portal' : 'fieldflow_app';
        $page_id = absint($o[$page_key] ?? 0);
        if ($page_id && is_page($page_id)) return true;
        if ($profile === Settings::PROFILE_CLIENT && self::$clientRendered) return true;
        if ($profile === Settings::PROFILE_OPERATIVE && self::$appRendered) return true;
        if (is_singular()) {
            $post = get_post();
            if ($post && !empty($post->post_content) && has_shortcode($post->post_content, $shortcode)) return true;
        }
        return false;
    }

    public static function shouldRender(): bool {
        $profile = self::currentProfile();
        if (!Settings::isEnabled($profile)) return false;
        $o = Settings::get();
        if (empty($o['install_prompt_enabled']) || $o['prompt_frequency'] === 'manual') return false;
        if (!empty($o['require_login']) && !is_user_logged_in()) return false;
        return self::isAppContext($profile);
    }

    public static function config(?string $profile = null): array {
        $profile = Settings::normalizeProfile($profile ?: self::currentProfile());
        $o = Settings::get();
        return [
            'enabled' => (bool) Settings::isEnabled($profile),
            'profile' => $profile,
            'version' => ROUTESPRO_VERSION . '-' . $profile,
            'manifestUrl' => Settings::manifestUrl($profile),
            'serviceWorkerUrl' => Settings::serviceWorkerUrl($profile),
            'swUrl' => Settings::serviceWorkerUrl($profile),
            'scope' => home_url('/'),
            'frequency' => $o['prompt_frequency'],
            'delay' => max(0, absint($o['prompt_delay'])) * 1000,
            'appName' => Settings::profileGet($profile, 'app_name', 'FieldFlow'),
            'showMode' => 'app_page',
            'serverApp' => self::isAppContext($profile),
            'startPanel' => $profile === Settings::PROFILE_CLIENT ? '' : $o['start_panel'],
            'menu' => [
                'route' => !empty($o['menu_route']),
                'discovery' => !empty($o['menu_discovery']),
                'report' => !empty($o['menu_report']),
                'commercial' => !empty($o['menu_commercial']),
                'messages' => !empty($o['menu_messages']),
                'analytics' => !empty($o['menu_analytics']),
            ],
            'links' => [
                'support' => $profile === Settings::PROFILE_CLIENT ? ($o['client_support_url'] ?? '') : ($o['support_url'] ?? ''),
                'privacy' => $profile === Settings::PROFILE_CLIENT ? ($o['client_privacy_url'] ?? '') : ($o['privacy_url'] ?? ''),
                'terms' => $profile === Settings::PROFILE_CLIENT ? ($o['client_terms_url'] ?? '') : ($o['terms_url'] ?? ''),
            ],
            'isLoggedIn' => is_user_logged_in(),
            'installButton' => Settings::profileGet($profile, 'install_button', 'Instalar App'),
        ];
    }

    public static function render(): void {
        if (!self::shouldRender()) return;
        $profile = self::currentProfile();
        $o = Settings::get();
        $show_server = self::isAppContext($profile);
        $install_title = Settings::profileGet($profile, 'install_title', 'Instalar FieldFlow');
        $install_text = Settings::profileGet($profile, 'install_text', 'Instala esta app no teu dispositivo.');
        $install_button = Settings::profileGet($profile, 'install_button', 'Instalar App');
        $dismiss_button = $o['dismiss_button'] ?? 'Agora não';
        $ios_title = Settings::profileGet($profile, 'ios_title', 'Instalar no iPhone');
        $ios_text = Settings::profileGet($profile, 'ios_text', 'No Safari, toca em Partilhar e escolhe Adicionar ao Ecrã Principal.');
        ?>
        <div class="ff-pwa-prompt" data-ff-pwa-prompt data-ff-overlay="1" data-ff-profile="<?php echo esc_attr($profile); ?>" role="dialog" aria-live="polite" data-server-app="<?php echo esc_attr($show_server ? '1' : '0'); ?>" data-frequency="<?php echo esc_attr($o['prompt_frequency']); ?>" hidden>
            <button type="button" class="ff-pwa-dismiss" data-ff-pwa-dismiss aria-label="Fechar">×</button>
            <div class="ff-pwa-prompt-copy">
                <strong data-ff-pwa-title-default><?php echo esc_html($install_title); ?></strong>
                <strong data-ff-pwa-title-ios hidden><?php echo esc_html($ios_title); ?></strong>
                <span data-ff-pwa-text-default><?php echo esc_html($install_text); ?></span>
                <span data-ff-pwa-text-ios hidden><?php echo esc_html($ios_text); ?></span>
            </div>
            <button type="button" class="ff-pwa-install" data-ff-pwa-install><?php echo esc_html($install_button); ?></button>
        </div>
        <div class="ff-pwa-help" data-ff-pwa-help data-ff-overlay="1" data-ff-profile="<?php echo esc_attr($profile); ?>" role="dialog" aria-live="polite" hidden>
            <button type="button" class="ff-pwa-help-close" data-ff-pwa-help-close aria-label="Fechar">×</button>
            <strong><?php echo esc_html($ios_title); ?></strong>
            <p><?php echo esc_html($ios_text); ?></p>
            <p class="ff-pwa-help-device" data-ff-ios><strong>Safari/iPhone/iPad:</strong><br>Toca no botão <strong>Partilhar</strong> do Safari, normalmente na barra inferior ou superior, e escolhe <strong>Adicionar ao Ecrã Principal</strong>. Depois confirma em <strong>Adicionar</strong>.</p>
            <p class="ff-pwa-help-device" data-ff-android><strong>Android/Chrome:</strong><br>Se o Chrome ainda não abrir a janela nativa, toca no menu ⋮ do browser e escolhe Instalar app ou Adicionar ao ecrã principal. Se essa opção não aparecer, o Chrome ainda não validou manifest/service worker.</p>
            <p class="ff-pwa-help-device" data-ff-desktop><strong>Desktop:</strong><br>Se o botão nativo ainda não abrir, confirma que estás em HTTPS e procura o ícone de instalação na barra de endereço do Chrome/Edge.</p>
        </div>
        <?php
    }
}
