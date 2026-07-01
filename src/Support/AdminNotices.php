<?php
namespace RoutesPro\Support;

if (!defined('ABSPATH')) exit;

class AdminNotices {
    public static function register(): void {
        add_action('admin_notices', [self::class, 'render']);
    }

    public static function render(): void {
        if (!current_user_can('routespro_manage')) return;
        if (!is_admin()) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screen_id = $screen && !empty($screen->id) ? (string) $screen->id : '';
        if ($screen_id !== '' && strpos($screen_id, 'routespro') === false && strpos($screen_id, 'toplevel_page_routespro') === false) {
            return;
        }

        $messages = [];
        if (LicenseManager::isRemoteMode() && !LicenseManager::remoteReady()) {
            $messages[] = ['error', 'Modo remoto ativo, mas a API Azure de licenciamento não está configurada.'];
        } elseif (!LicenseManager::isActive()) {
            $messages[] = ['warning', 'Licença por ativar. O produto continua funcional, mas a distribuição comercial deve sair com licença validada.'];
        } elseif (!LicenseManager::isBoundToCurrentDomain()) {
            $messages[] = ['error', 'A licença ativa não coincide com o domínio atual. Revê a ativação antes de distribuir esta instalação.'];
        }

        $remoteError = (string) LicenseManager::get('remote_last_error', '');
        if ($remoteError !== '' && !LicenseManager::isActive()) {
            $messages[] = ['warning', 'Licenciamento remoto: ' . $remoteError];
        }

        $health = SystemHealth::status();
        foreach ($health['checks'] as $check) {
            if ($check['status'] === 'critical') {
                $messages[] = ['error', 'Health check crítico em ' . $check['label'] . ': ' . $check['detail']];
                break;
            }
        }

        if (Config::get('ai_provider', 'none') !== 'none' && !Config::providerReady((string) Config::get('ai_provider', 'none') === 'google' ? 'google_ai' : ((string) Config::get('ai_provider') === 'azure' ? 'azure_ai' : ((string) Config::get('ai_provider') === 'openai' ? 'openai' : 'copilot')))) {
            $messages[] = ['warning', 'Fornecedor de IA selecionado sem credenciais completas.'];
        }

        foreach ($messages as [$type, $message]) {
            printf('<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr($type), esc_html($message));
        }
    }
}
