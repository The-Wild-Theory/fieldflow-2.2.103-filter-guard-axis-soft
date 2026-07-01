<?php
namespace RoutesPro\Admin;

use RoutesPro\Support\Config;
use RoutesPro\Support\Request;

class Settings {
    const OPT_KEY = 'routespro_settings';

    /**
     * Defaults globais das opções
     */
    private static function defaults(): array {
        return Config::defaults();
    }

    /**
     * Recupera todas as opções (com defaults) ou uma chave específica.
     */
    public static function get($key = null, $default = null) {
        return Config::get($key, $default);
    }

    private static function moneyFromGoogleRoutesTollInfo(array $tollInfo): array {
        $amount = 0.0;
        $currency = 'EUR';
        $prices = $tollInfo['estimatedPrice'] ?? [];
        if (!is_array($prices)) {
            return ['amount' => 0.0, 'currency' => $currency, 'has_price' => false];
        }
        foreach ($prices as $price) {
            if (!is_array($price)) continue;
            $currency = (string)($price['currencyCode'] ?? $currency);
            $units = (float)($price['units'] ?? 0);
            $nanos = (float)($price['nanos'] ?? 0) / 1000000000;
            $amount += $units + $nanos;
        }
        return ['amount' => round(max(0.0, $amount), 2), 'currency' => $currency, 'has_price' => $amount > 0];
    }

    private static function secondsFromGoogleDuration($duration): int {
        if (is_numeric($duration)) return max(0, (int)$duration);
        if (is_string($duration) && preg_match('/^(\d+(?:\.\d+)?)s$/', $duration, $m)) {
            return max(0, (int)round((float)$m[1]));
        }
        return 0;
    }

    private static function testGoogleRoutesApi(string $origin, string $destination): array {
        if (!class_exists('\\RoutesPro\\Services\\GoogleRoutes')) {
            return ['ok' => false, 'message' => 'Serviço Google Routes indisponível no plugin.'];
        }
        return \RoutesPro\Services\GoogleRoutes::diagnostic($origin, $destination);
    }

    /**
     * Render da página de Settings (BO)
     */
    public static function render() {
        if (!current_user_can('routespro_manage')) return;

        if (Request::verifyNonce('routespro_settings_categories_nonce', 'routespro_settings_categories')) {
            global $wpdb; $px = $wpdb->prefix . 'routespro_';
            $cat_name = Request::postString('settings_category_name');
            $cat_parent = Request::postInt('settings_category_parent_id') ?: null;
            $cat_type = Request::postString('settings_category_type');
            if ($cat_name !== '') {
                $wpdb->insert($px.'categories', [
                    'parent_id' => $cat_parent,
                    'name' => $cat_name,
                    'slug' => sanitize_title($cat_name),
                    'type' => $cat_type,
                    'is_active' => 1,
                ]);
                echo '<div class="updated notice"><p>Categoria guardada em Settings.</p></div>';
            }
        }

        if (Request::verifyNonce('routespro_license_nonce', 'routespro_license')) {
            $license_action = Request::postKey('routespro_license_action');
            if ($license_action === 'generate_local') {
                $license_plan = Request::postString('routespro_license_plan', 'pro');
                $license_customer = Request::postString('routespro_license_customer', 'LOCAL');
                $license_max = max(1, Request::postInt('routespro_license_max_activations'));
                $generated = \RoutesPro\Support\LicenseManager::generateRequested($license_plan, $license_customer, $license_max);
                if (!empty($generated['success']) && !empty($generated['key'])) {
                    \RoutesPro\Support\LicenseManager::update([
                        'key' => $generated['key'],
                        'plan' => $license_plan,
                        'customer' => strtoupper($license_customer),
                        'max_activations' => $license_max,
                        'source' => \RoutesPro\Support\LicenseManager::isRemoteMode() ? 'remote_seed' : 'local_generated',
                        'last_checked_at' => current_time('mysql'),
                        'notes' => \RoutesPro\Support\LicenseManager::isRemoteMode() ? 'Chave remota gerada no backoffice.' : 'Chave local gerada no backoffice.',
                    ]);
                    echo '<div class="updated notice"><p>' . esc_html(\RoutesPro\Support\LicenseManager::isRemoteMode() ? 'Chave remota gerada. Já ficou preenchida no campo da licença.' : 'Chave local gerada. Já ficou preenchida no campo da licença.') . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html((string) ($generated['message'] ?? 'Falha ao gerar chave.')) . '</p></div>';
                }
            } elseif ($license_action === 'deactivate') {
                \RoutesPro\Support\LicenseManager::deactivate();
                echo '<div class="updated notice"><p>Licença desativada.</p></div>';
            } elseif ($license_action === 'validate_now') {
                \RoutesPro\Support\LicenseManager::validateCurrent(true);
                echo '<div class="updated notice"><p>Licença confirmada localmente. Validação Azure temporariamente ignorada.</p></div>';
            } else {
                $license_key = Request::postString('routespro_license_key');
                $license_plan = Request::postString('routespro_license_plan', 'pro');
                \RoutesPro\Support\LicenseManager::activate($license_key, $license_plan);
                echo '<div class="updated notice"><p>Licença atualizada.</p></div>';
            }
        }

        if (Request::verifyNonce('routespro_settings_nonce', 'routespro_settings')) {

            // Whitelists
            $maps_allowed = ['leaflet','google','azure'];
            $ai_allowed   = ['none','google','azure','openai','copilot'];
            $license_allowed = ['local','remote'];
            $routing_allowed = ['internal','google_routes'];
            $routes_pref_allowed = ['TRAFFIC_AWARE','TRAFFIC_UNAWARE','TRAFFIC_AWARE_OPTIMAL'];
            $route_mode_allowed = ['fastest_tolls','fastest_no_tolls','shortest'];
            $vehicle_profile_allowed = ['car_class1','light_van','commercial'];

            // Monta opções saneadas
            $opts = [
                // Otimizador
                'optimizer_url'         => esc_url_raw($_POST['optimizer_url'] ?? ''),
                'optimizer_api_key'     => sanitize_text_field($_POST['optimizer_api_key'] ?? ''),

                // Mapas
                'maps_provider'         => in_array(($_POST['maps_provider'] ?? 'leaflet'), $maps_allowed, true)
                                            ? $_POST['maps_provider'] : 'leaflet',
                'google_maps_key'       => sanitize_text_field($_POST['google_maps_key'] ?? ''),
                'azure_maps_key'        => sanitize_text_field($_POST['azure_maps_key'] ?? ''),

                // IA
                'ai_provider'           => in_array(($_POST['ai_provider'] ?? 'none'), $ai_allowed, true)
                                            ? $_POST['ai_provider'] : 'none',
                'google_ai_key'         => sanitize_text_field($_POST['google_ai_key'] ?? ''),

                'azure_openai_endpoint' => esc_url_raw($_POST['azure_openai_endpoint'] ?? ''),
                'azure_openai_deployment' => sanitize_text_field($_POST['azure_openai_deployment'] ?? ''),
                'azure_openai_key'      => sanitize_text_field($_POST['azure_openai_key'] ?? ''),

                'openai_api_key'        => sanitize_text_field($_POST['openai_api_key'] ?? ''),
                'openai_base_url'       => esc_url_raw($_POST['openai_base_url'] ?? ''),
                'openai_model'          => sanitize_text_field($_POST['openai_model'] ?? 'gpt-4o-mini'),

                'copilot_webhook_url'   => esc_url_raw($_POST['copilot_webhook_url'] ?? ''),
                'copilot_auth_header'   => sanitize_text_field($_POST['copilot_auth_header'] ?? ''),
                'maps_test_address'      => sanitize_text_field($_POST['maps_test_address'] ?? 'Praça do Comércio, Lisboa'),
                'ai_test_task'           => sanitize_text_field($_POST['ai_test_task'] ?? 'route_notes'),
                'license_mode'           => in_array(($_POST['license_mode'] ?? 'remote'), $license_allowed, true) ? $_POST['license_mode'] : 'remote',
                'license_remote_api_base' => esc_url_raw($_POST['license_remote_api_base'] ?? 'https://func-fieldflow-licensing-a7dkgyfsfmg9dvgt.westeurope-01.azurewebsites.net/api'),
                'license_remote_shared_secret' => trim((string) wp_unslash($_POST['license_remote_shared_secret'] ?? '')),
                'license_remote_admin_secret' => trim((string) wp_unslash($_POST['license_remote_admin_secret'] ?? '')),
                'license_remote_product_id' => sanitize_text_field($_POST['license_remote_product_id'] ?? 'fieldflow'),
                'license_remote_timeout' => max(5, absint($_POST['license_remote_timeout'] ?? 15)),
                'license_remote_validate_interval' => max(1, absint($_POST['license_remote_validate_interval'] ?? 12)),

                // Routing e portagens
                'routing_provider' => in_array(($_POST['routing_provider'] ?? 'internal'), $routing_allowed, true) ? $_POST['routing_provider'] : 'internal',
                'google_routes_api_key' => sanitize_text_field($_POST['google_routes_api_key'] ?? ''),
                'google_routes_preference' => in_array(($_POST['google_routes_preference'] ?? 'TRAFFIC_AWARE'), $routes_pref_allowed, true) ? $_POST['google_routes_preference'] : 'TRAFFIC_AWARE',
                'google_routes_route_mode' => in_array(($_POST['google_routes_route_mode'] ?? 'fastest_tolls'), $route_mode_allowed, true) ? $_POST['google_routes_route_mode'] : 'fastest_tolls',
                'google_routes_vehicle_profile' => in_array(($_POST['google_routes_vehicle_profile'] ?? 'car_class1'), $vehicle_profile_allowed, true) ? $_POST['google_routes_vehicle_profile'] : 'car_class1',
                'routing_fallback_internal' => !empty($_POST['routing_fallback_internal']) ? 1 : 0,
                'routing_cache_days' => max(0, min(365, absint($_POST['routing_cache_days'] ?? 30))),
                'google_routes_test_origin' => sanitize_text_field($_POST['google_routes_test_origin'] ?? 'Lisboa, Portugal'),
                'google_routes_test_destination' => sanitize_text_field($_POST['google_routes_test_destination'] ?? 'Porto, Portugal'),
            ];

            // Persiste (mantém quaisquer chaves antigas que não estejam no form)
            $merged = Config::mergeAndSave($opts);
            Config::clearRoutingCache();

            $settings_notice = 'Settings guardadas. Cache de routing limpo para recalcular as próximas rotas.';
            $settings_notice_type = 'updated';

            if (Request::postKey('routespro_settings_action') === 'save_test_google_routes') {
                $res = self::testGoogleRoutesApi((string) $merged['google_routes_test_origin'], (string) $merged['google_routes_test_destination']);
                if (!empty($res['ok'])) {
                    Config::mergeAndSave(['routing_provider' => 'google_routes']);
                    Config::clearRoutingCache();
                    $settings_notice = ((string)($res['message'] ?? 'Teste Google Routes OK.')) . ' Provider Google Routes API ativado e cache limpo.';
                    $settings_notice_type = 'updated';
                } else {
                    $settings_notice = (string)($res['message'] ?? 'Teste Google Routes falhou.');
                    $settings_notice_type = 'error';
                }
            }
        }


        $settings_notice = isset($settings_notice) ? $settings_notice : '';
        $settings_notice_type = isset($settings_notice_type) ? $settings_notice_type : 'updated';
        if (!empty($_POST['routespro_settings_action']) && !empty($_POST['routespro_settings_tools_nonce']) && wp_verify_nonce($_POST['routespro_settings_tools_nonce'], 'routespro_settings_tools')) {
            $action = Request::postKey('routespro_settings_action');
            $test_address = Request::postString('settings_test_address', self::get('maps_test_address', 'Praça do Comércio, Lisboa'));
            $test_context = wp_strip_all_tags((string)($_POST['settings_ai_context'] ?? 'Resumo da visita: loja com boa visibilidade, stock baixo e necessidade de reposição até sexta.'));
            $test_task = Request::postString('settings_ai_task', self::get('ai_test_task', 'route_notes'));
            $google_routes_origin = Request::postString('google_routes_test_origin', self::get('google_routes_test_origin', 'Lisboa, Portugal'));
            $google_routes_destination = Request::postString('google_routes_test_destination', self::get('google_routes_test_destination', 'Porto, Portugal'));
            if ($action === 'test_maps') {
                $provider = class_exists('\RoutesPro\Services\MapsFactory') ? \RoutesPro\Services\MapsFactory::make() : null;
                if (!$provider) {
                    $settings_notice = 'Fornecedor de mapas não configurado.';
                    $settings_notice_type = 'error';
                } else {
                    $res = $provider->geocode($test_address);
                    if (!empty($res['lat']) && !empty($res['lng'])) {
                        $settings_notice = sprintf('Teste de mapas OK. %s => %s, %s', $test_address, $res['lat'], $res['lng']);
                    } else {
                        $settings_notice = 'Teste de mapas sem resposta válida. Confirma a chave e as APIs ativadas.';
                        $settings_notice_type = 'error';
                    }
                }
            } elseif ($action === 'test_google_routes') {
                $res = self::testGoogleRoutesApi($google_routes_origin, $google_routes_destination);
                $settings_notice = (string)($res['message'] ?? 'Teste Google Routes concluído.');
                if (!empty($res['ok'])) {
                    Config::mergeAndSave(['routing_provider' => 'google_routes', 'google_routes_test_origin' => $google_routes_origin, 'google_routes_test_destination' => $google_routes_destination]);
                    Config::clearRoutingCache();
                    $settings_notice .= ' Provider Google Routes API ativado e cache limpo.';
                }
                $settings_notice_type = !empty($res['ok']) ? 'updated' : 'error';
            } elseif ($action === 'test_ai') {
                $provider = class_exists('\RoutesPro\Services\AIFactory') ? \RoutesPro\Services\AIFactory::make() : null;
                if (!$provider) {
                    $settings_notice = 'Fornecedor de IA não configurado.';
                    $settings_notice_type = 'error';
                } else {
                    $prompt = "Contexto:
" . $test_context . "

Tarefa: " . $test_task . "
Responde em português de Portugal, de forma prática.";
                    $out = $provider->complete($prompt, ['task' => $test_task, 'context' => $test_context, 'max_tokens' => 220, 'temperature' => 0.2]);
                    if (!empty($out)) {
                        $settings_notice = 'Teste de IA OK. Resposta: ' . wp_trim_words(wp_strip_all_tags((string)$out), 30, '...');
                    } else {
                        $settings_notice = 'Teste de IA sem resposta. Confirma credenciais, modelo e endpoint.';
                        $settings_notice_type = 'error';
                    }
                }
            }
        }

        $o = self::get();
        $maps_diag = [
            'Google Maps' => ['ready' => Config::providerReady('google_maps', $o), 'detail' => 'Autocomplete, Places, Geocoding, Directions e Distance Matrix.'],
            'Azure Maps' => ['ready' => Config::providerReady('azure_maps', $o), 'detail' => 'Geocode, directions e matrix no backend.'],
        ];
        $routing_diag = [
            'Google Routes API' => ['ready' => Config::providerReady('google_routes', $o), 'detail' => ((string)($o['routing_provider'] ?? 'internal') === 'google_routes') ? 'Chave pronta e provider ativo para rotas novas, refresh de rotas existentes, portal e exportação.' : 'Chave pronta, mas o provider ativo ainda é o estimador interno. Seleciona Google Routes API e guarda, ou usa Guardar e testar Google Routes.'],
        ];
        $ai_diag = [
            'Google Gemini' => ['ready' => Config::providerReady('google_ai', $o), 'detail' => 'Prompting direto via API Google AI.'],
            'Azure OpenAI' => ['ready' => Config::providerReady('azure_ai', $o), 'detail' => 'Chat completions via deployment Azure.'],
            'OpenAI API' => ['ready' => Config::providerReady('openai', $o), 'detail' => 'Compatível com gpt-4o-mini e outros modelos.'],
            'Webhook IA' => ['ready' => Config::providerReady('copilot', $o), 'detail' => 'Integração por endpoint teu, n8n, Make, Functions ou Cloud Run.'],
        ];
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $settings_categories = $wpdb->get_results("SELECT id,parent_id,name,type,is_active FROM {$px}categories ORDER BY COALESCE(parent_id,0), sort_order, name", ARRAY_A);
        $settings_category_roots = array_values(array_filter($settings_categories, fn($r) => empty($r['parent_id'])));
        ?>
        <div class="wrap">
          <?php \RoutesPro\Admin\Branding::render_header('Settings', 'Configura integrações, mapas e categorias da operação num único ecrã.'); ?>
          <?php if (!empty($settings_notice)): ?><div class="<?php echo esc_attr($settings_notice_type); ?> notice"><p><?php echo esc_html($settings_notice); ?></p></div><?php endif; ?>
          <style>
            .routespro-settings-grid{display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:20px;align-items:start}
            .routespro-settings-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 10px 30px rgba(15,23,42,.06);padding:22px;margin-top:16px}
            .routespro-settings-card h2{margin-top:0}
            .routespro-settings-status{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
            .routespro-settings-pill{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700}
            .routespro-settings-pill.ok{background:#dcfce7;color:#166534}.routespro-settings-pill.no{background:#fee2e2;color:#991b1b}
            .routespro-settings-help{font-size:13px;color:#475569}
            @media (max-width: 1180px){.routespro-settings-grid{grid-template-columns:1fr}}
          </style>
          <div class="routespro-settings-grid"><div>
          <div class="routespro-settings-card">
            <h2>Licenciamento</h2>
            <p class="routespro-settings-help">Agora podes correr licenciamento local ou remoto via Azure. O plugin fala com a tua API para gerar, ativar, validar e desativar licenças.</p>
            <?php $license = \RoutesPro\Support\LicenseManager::all(); $license_summary = \RoutesPro\Support\LicenseManager::summary(); ?>
            <form method="post" style="margin-bottom:18px;">
              <?php wp_nonce_field('routespro_license', 'routespro_license_nonce'); ?>
              <input type="hidden" name="routespro_license_action" value="activate">
              <table class="form-table">
                <tr><th scope="row"><label for="routespro_license_key">Chave</label></th><td><input id="routespro_license_key" name="routespro_license_key" class="regular-text" value="<?php echo esc_attr(\RoutesPro\Support\LicenseManager::get('key', '')); ?>"><p class="description">Máscara: <code><?php echo esc_html($license_summary['masked_key'] ?: 'sem chave'); ?></code></p></td></tr>
                <tr><th scope="row"><label for="routespro_license_plan">Plano</label></th><td><input id="routespro_license_plan" name="routespro_license_plan" class="regular-text" value="<?php echo esc_attr(\RoutesPro\Support\LicenseManager::get('plan', 'starter')); ?>"> <span class="routespro-settings-pill <?php echo \RoutesPro\Support\LicenseManager::isActive() ? 'ok' : 'no'; ?>"><?php echo esc_html(\RoutesPro\Support\LicenseManager::statusLabel()); ?></span></td></tr>
                <tr><th scope="row">Domínio ativo</th><td><code><?php echo esc_html($license_summary['domain']); ?></code><br><span class="routespro-settings-help">Fingerprint: <code><?php echo esc_html($license_summary['fingerprint']); ?></code></span></td></tr>
                <tr><th scope="row">Fonte</th><td><?php echo esc_html((string) ($license['source'] ?? 'remote')); ?><?php if (!empty($license['activated_at'])): ?><br><span class="routespro-settings-help">Ativada em: <?php echo esc_html((string) $license['activated_at']); ?></span><?php endif; ?></td></tr>
                <tr><th scope="row">Modo</th><td><code><?php echo esc_html((string) ($license['mode'] ?? 'remote')); ?></code><?php if (!empty($license['remote_activation_id'])): ?><br><span class="routespro-settings-help">Activation ID: <code><?php echo esc_html((string) $license['remote_activation_id']); ?></code></span><?php endif; ?></td></tr>
                <?php
                  $license_active_for_ui = \RoutesPro\Support\LicenseManager::isActive();
                  $license_source_for_ui = (string) ($license['source'] ?? '');
                  $show_remote_error_for_ui = !empty($license['remote_last_error']) && !$license_active_for_ui;
                ?>
                <tr><th scope="row">Observações</th><td><span class="routespro-settings-help"><?php echo esc_html((string) ($license['notes'] ?? '')); ?></span><?php if ($show_remote_error_for_ui): ?><br><span class="routespro-settings-help" style="color:#991b1b">Erro remoto: <?php echo esc_html((string) $license['remote_last_error']); ?></span><?php elseif (!empty($license['remote_last_error']) && $license_active_for_ui): ?><br><span class="routespro-settings-help" style="color:#64748b">Última validação remota devolveu erro técnico, mas a licença mantém-se ativa pela última ativação válida.</span><?php endif; ?></td></tr>
              </table>
              <p>
                <button class="button button-secondary">Guardar licença</button>
                <?php if (\RoutesPro\Support\LicenseManager::isRemoteMode()): ?><button class="button" name="routespro_license_action" value="validate_now">Confirmar estado</button><?php endif; ?>
                <button class="button" name="routespro_license_action" value="deactivate">Desativar</button>
              </p>
            </form>
            <hr>
            <form method="post">
              <?php wp_nonce_field('routespro_license', 'routespro_license_nonce'); ?>
              <input type="hidden" name="routespro_license_action" value="generate_local">
              <table class="form-table">
                <tr><th scope="row"><label for="routespro_license_customer">Cliente</label></th><td><input id="routespro_license_customer" name="routespro_license_customer" class="regular-text" value="<?php echo esc_attr((string) ($license['customer'] ?? 'LOCAL')); ?>"><p class="description">Usa um código curto, por exemplo TWT, DEMO ou CLIENTEA.</p></td></tr>
                <tr><th scope="row"><label for="routespro_license_max_activations">Ativações</label></th><td><input id="routespro_license_max_activations" type="number" min="1" max="99" name="routespro_license_max_activations" class="small-text" value="<?php echo esc_attr((string) ($license['max_activations'] ?? 1)); ?>"></td></tr>
              </table>
              <p><button class="button button-primary"><?php echo \RoutesPro\Support\LicenseManager::isRemoteMode() ? 'Gerar chave remota' : 'Gerar chave local'; ?></button></p>
            </form>
          </div>
          <div class="routespro-settings-card">
            <h2>Diagnóstico rápido</h2>
            <div class="routespro-settings-status">
              <?php foreach ($maps_diag as $label => $diag): ?>
                <div><strong><?php echo esc_html($label); ?></strong><br><span class="routespro-settings-pill <?php echo !empty($diag['ready']) ? 'ok' : 'no'; ?>"><?php echo !empty($diag['ready']) ? 'Pronto' : 'Por configurar'; ?></span><p class="routespro-settings-help"><?php echo esc_html($diag['detail']); ?></p></div>
              <?php endforeach; ?>
              <?php foreach ($routing_diag as $label => $diag): ?>
                <div><strong><?php echo esc_html($label); ?></strong><br><span class="routespro-settings-pill <?php echo !empty($diag['ready']) ? 'ok' : 'no'; ?>"><?php echo !empty($diag['ready']) ? 'Pronto' : 'Por configurar'; ?></span><p class="routespro-settings-help"><?php echo esc_html($diag['detail']); ?></p></div>
              <?php endforeach; ?>
              <?php foreach ($ai_diag as $label => $diag): ?>
                <div><strong><?php echo esc_html($label); ?></strong><br><span class="routespro-settings-pill <?php echo !empty($diag['ready']) ? 'ok' : 'no'; ?>"><?php echo !empty($diag['ready']) ? 'Pronto' : 'Por configurar'; ?></span><p class="routespro-settings-help"><?php echo esc_html($diag['detail']); ?></p></div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="routespro-settings-card">
            <h2>Testes técnicos</h2>
            <p class="routespro-settings-help">Serve para validar chaves e endpoints sem mexer nas rotinas que já tens em produção.</p>
            <form method="post" style="margin-bottom:18px">
              <?php wp_nonce_field('routespro_settings_tools', 'routespro_settings_tools_nonce'); ?>
              <input type="hidden" name="routespro_settings_action" value="test_maps">
              <table class="form-table"><tr><th scope="row"><label for="settings_test_address">Morada teste</label></th><td><input id="settings_test_address" name="settings_test_address" class="regular-text" value="<?php echo esc_attr($o['maps_test_address']); ?>"> <button class="button">Testar mapas</button></td></tr></table>
            </form>
            <form method="post" style="margin-bottom:18px">
              <?php wp_nonce_field('routespro_settings_tools', 'routespro_settings_tools_nonce'); ?>
              <input type="hidden" name="routespro_settings_action" value="test_google_routes">
              <table class="form-table">
                <tr><th scope="row"><label for="google_routes_test_origin">Teste Google Routes</label></th><td><input id="google_routes_test_origin" name="google_routes_test_origin" class="regular-text" value="<?php echo esc_attr($o['google_routes_test_origin']); ?>" placeholder="Lisboa, Portugal"> <input name="google_routes_test_destination" class="regular-text" value="<?php echo esc_attr($o['google_routes_test_destination']); ?>" placeholder="Porto, Portugal"> <button class="button">Testar Routes API</button><p class="description">Testa distância, duração e portagens devolvidas pela Google Routes API.</p></td></tr>
              </table>
            </form>
            <form method="post">
              <?php wp_nonce_field('routespro_settings_tools', 'routespro_settings_tools_nonce'); ?>
              <input type="hidden" name="routespro_settings_action" value="test_ai">
              <table class="form-table">
                <tr><th scope="row"><label for="settings_ai_task">Tarefa</label></th><td><input id="settings_ai_task" name="settings_ai_task" class="regular-text" value="<?php echo esc_attr($o['ai_test_task']); ?>"></td></tr>
                <tr><th scope="row"><label for="settings_ai_context">Contexto</label></th><td><textarea id="settings_ai_context" name="settings_ai_context" class="large-text" rows="5">Resumo da visita: loja com boa visibilidade, stock baixo e necessidade de reposição até sexta.</textarea><p><button class="button">Testar IA</button></p></td></tr>
              </table>
            </form>
          </div>
          <form method="post">
            <?php wp_nonce_field('routespro_settings','routespro_settings_nonce'); ?>

            <h2 style="margin-top:1em">Licenciamento remoto Azure</h2>
            <table class="form-table">
              <tr>
                <th scope="row"><label for="license_mode">Modo</label></th>
                <td>
                  <select id="license_mode" name="license_mode">
                    <option value="local" <?php selected($o['license_mode'],'local'); ?>>Local</option>
                    <option value="remote" <?php selected($o['license_mode'],'remote'); ?>>Remoto Azure</option>
                  </select>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="license_remote_api_base">API Base</label></th>
                <td><input id="license_remote_api_base" name="license_remote_api_base" class="regular-text" placeholder="https://teu-servico.azurewebsites.net/api" value="<?php echo esc_attr($o['license_remote_api_base']); ?>"/><p class="description">Constante opcional: <code>ROUTESPRO_LICENSE_API_BASE</code>.</p></td>
              </tr>
              <tr>
                <th scope="row"><label for="license_remote_shared_secret">Shared Secret</label></th>
                <td><input id="license_remote_shared_secret" name="license_remote_shared_secret" class="regular-text" value="<?php echo esc_attr($o['license_remote_shared_secret']); ?>"/><p class="description">Assina requests HMAC. Constante opcional: <code>ROUTESPRO_LICENSE_API_SECRET</code>.</p></td>
              </tr>
              <tr>
                <th scope="row"><label for="license_remote_admin_secret">Admin Secret</label></th>
                <td><input id="license_remote_admin_secret" name="license_remote_admin_secret" class="regular-text" value="<?php echo esc_attr($o['license_remote_admin_secret'] ?? ''); ?>"/><p class="description">Usado apenas para gerar chaves remotas. Constante opcional: <code>ROUTESPRO_LICENSE_ADMIN_SECRET</code>.</p></td>
              </tr>
              <tr>
                <th scope="row"><label for="license_remote_product_id">Product ID</label></th>
                <td><input id="license_remote_product_id" name="license_remote_product_id" class="regular-text" value="<?php echo esc_attr($o['license_remote_product_id']); ?>"/><p class="description">Ex.: fieldflow-pro.</p></td>
              </tr>
              <tr>
                <th scope="row"><label for="license_remote_timeout">Timeout API</label></th>
                <td><input id="license_remote_timeout" type="number" min="5" max="60" name="license_remote_timeout" class="small-text" value="<?php echo esc_attr((string) $o['license_remote_timeout']); ?>"/> segundos</td>
              </tr>
              <tr>
                <th scope="row"><label for="license_remote_validate_interval">Intervalo de validação</label></th>
                <td><input id="license_remote_validate_interval" type="number" min="1" max="72" name="license_remote_validate_interval" class="small-text" value="<?php echo esc_attr((string) $o['license_remote_validate_interval']); ?>"/> horas</td>
              </tr>
            </table>

            <h2 style="margin-top:1em">Otimizador (VRP/TSP)</h2>
            <table class="form-table">
              <tr>
                <th scope="row"><label for="optimizer_url">Optimizer URL</label></th>
                <td><input id="optimizer_url" name="optimizer_url" class="regular-text" placeholder="https://teu-servico/vrp" value="<?php echo esc_attr($o['optimizer_url']); ?>"/></td>
              </tr>
              <tr>
                <th scope="row"><label for="optimizer_api_key">Optimizer API Key</label></th>
                <td><input id="optimizer_api_key" name="optimizer_api_key" class="regular-text" value="<?php echo esc_attr($o['optimizer_api_key']); ?>"/></td>
              </tr>
            </table>

            <h2 style="margin-top:1em">Routing & Portagens</h2>
            <p class="routespro-settings-help">Configuração para a próxima camada de cálculo real de rotas e portagens. Mantém o estimador interno como fallback para não partir operação.</p>
            <table class="form-table">
              <tr>
                <th scope="row"><label for="routing_provider">Provider de routing</label></th>
                <td>
                  <select id="routing_provider" name="routing_provider">
                    <option value="internal" <?php selected($o['routing_provider'],'internal'); ?>>Estimador interno</option>
                    <option value="google_routes" <?php selected($o['routing_provider'],'google_routes'); ?>>Google Routes API</option>
                  </select>
                  <p class="description">Para portagens reais, usa Google Routes API. O estimador interno continua disponível como plano B.</p>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="google_routes_api_key">Google Routes API Key</label></th>
                <td><input id="google_routes_api_key" type="password" name="google_routes_api_key" class="regular-text" autocomplete="off" value="<?php echo esc_attr($o['google_routes_api_key']); ?>"/><p class="description">Chave server-side. Se o teste indicar referrer vazio bloqueado, a chave está restringida por domínio. Usa restrição por IP público de saída do servidor e apenas à Routes API. Constante opcional: <code>ROUTESPRO_GOOGLE_ROUTES_API_KEY</code>.</p></td>
              </tr>
              <tr>
                <th scope="row"><label for="google_routes_route_mode">Modo de rota</label></th>
                <td>
                  <select id="google_routes_route_mode" name="google_routes_route_mode">
                    <option value="fastest_tolls" <?php selected($o['google_routes_route_mode'],'fastest_tolls'); ?>>Mais rápida com portagens</option>
                    <option value="fastest_no_tolls" <?php selected($o['google_routes_route_mode'],'fastest_no_tolls'); ?>>Mais rápida evitando portagens</option>
                    <option value="shortest" <?php selected($o['google_routes_route_mode'],'shortest'); ?>>Mais curta, quando suportado</option>
                  </select>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="google_routes_preference">Preferência Google</label></th>
                <td>
                  <select id="google_routes_preference" name="google_routes_preference">
                    <option value="TRAFFIC_AWARE" <?php selected($o['google_routes_preference'],'TRAFFIC_AWARE'); ?>>Traffic aware</option>
                    <option value="TRAFFIC_AWARE_OPTIMAL" <?php selected($o['google_routes_preference'],'TRAFFIC_AWARE_OPTIMAL'); ?>>Traffic aware optimal</option>
                    <option value="TRAFFIC_UNAWARE" <?php selected($o['google_routes_preference'],'TRAFFIC_UNAWARE'); ?>>Traffic unaware</option>
                  </select>
                  <p class="description">Para operação normal, Traffic aware é o melhor equilíbrio entre qualidade e custo.</p>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="google_routes_vehicle_profile">Perfil da viatura</label></th>
                <td>
                  <select id="google_routes_vehicle_profile" name="google_routes_vehicle_profile">
                    <option value="car_class1" <?php selected($o['google_routes_vehicle_profile'],'car_class1'); ?>>Classe 1, ligeiro</option>
                    <option value="light_van" <?php selected($o['google_routes_vehicle_profile'],'light_van'); ?>>Carrinha ligeira</option>
                    <option value="commercial" <?php selected($o['google_routes_vehicle_profile'],'commercial'); ?>>Comercial</option>
                  </select>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="routing_cache_days">Cache de rotas</label></th>
                <td><input id="routing_cache_days" type="number" min="0" max="365" name="routing_cache_days" class="small-text" value="<?php echo esc_attr((string) $o['routing_cache_days']); ?>"/> dias <p class="description">Ajuda a reduzir custos de chamadas repetidas para rotas iguais.</p></td>
              </tr>
              <tr>
                <th scope="row">Fallback</th>
                <td><label><input type="checkbox" name="routing_fallback_internal" value="1" <?php checked(!empty($o['routing_fallback_internal'])); ?>> Usar estimador interno se a API falhar ou não devolver portagem.</label></td>
              </tr>
            </table>
            <p>
              <button class="button button-secondary" name="routespro_settings_action" value="save_test_google_routes">Guardar e testar Google Routes</button>
              <span class="description">Este botão guarda o provider, testa Lisboa → Porto e limpa a cache para o BO, portal e exportações passarem a recalcular com Google.</span>
            </p>

            <h2 style="margin-top:1em">Mapas</h2>
            <table class="form-table">
              <tr>
                <th scope="row"><label for="maps_provider">Fornecedor</label></th>
                <td>
                  <select id="maps_provider" name="maps_provider">
                    <option value="leaflet" <?php selected($o['maps_provider'],'leaflet'); ?>>Leaflet (OSM)</option>
                    <option value="google"  <?php selected($o['maps_provider'],'google'); ?>>Google Maps</option>
                    <option value="azure"   <?php selected($o['maps_provider'],'azure'); ?>>Microsoft Azure Maps</option>
                  </select>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="google_maps_key">Google Maps Key</label></th>
                <td><input id="google_maps_key" name="google_maps_key" class="regular-text" value="<?php echo esc_attr($o['google_maps_key']); ?>"/><p class="description">Também podes definir <code>ROUTESPRO_GOOGLE_MAPS_KEY</code> no wp-config.php.</p></td>
              </tr>
              <tr>
                <th scope="row"><label for="azure_maps_key">Azure Maps Key</label></th>
                <td><input id="azure_maps_key" name="azure_maps_key" class="regular-text" value="<?php echo esc_attr($o['azure_maps_key']); ?>"/><p class="description">Também podes definir <code>ROUTESPRO_AZURE_MAPS_KEY</code> no wp-config.php.</p></td>
              </tr>
            </table>

            <h2 style="margin-top:1em">IA</h2>
            <table class="form-table">
              <tr>
                <th scope="row"><label for="ai_provider">Fornecedor</label></th>
                <td>
                  <select id="ai_provider" name="ai_provider">
                    <option value="none"   <?php selected($o['ai_provider'],'none'); ?>>Nenhum</option>
                    <option value="google" <?php selected($o['ai_provider'],'google'); ?>>Google (Gemini)</option>
                    <option value="azure"  <?php selected($o['ai_provider'],'azure'); ?>>Microsoft (Azure OpenAI)</option>
                    <option value="openai" <?php selected($o['ai_provider'],'openai'); ?>>OpenAI (ChatGPT)</option>
                    <option value="copilot"<?php selected($o['ai_provider'],'copilot'); ?>>Copilot (Webhook)</option>
                  </select>
                </td>
              </tr>

              <tr>
                <th scope="row"><label for="google_ai_key">Google AI Key</label></th>
                <td><input id="google_ai_key" name="google_ai_key" class="regular-text" value="<?php echo esc_attr($o['google_ai_key']); ?>"/><p class="description">Constante opcional: <code>ROUTESPRO_GOOGLE_AI_KEY</code>.</p></td>
              </tr>

              <tr>
                <th scope="row"><label for="azure_openai_endpoint">Azure OpenAI Endpoint</label></th>
                <td><input id="azure_openai_endpoint" name="azure_openai_endpoint" class="regular-text" placeholder="https://xxx.openai.azure.com/" value="<?php echo esc_attr($o['azure_openai_endpoint']); ?>"/></td>
              </tr>
              <tr>
                <th scope="row"><label for="azure_openai_deployment">Azure OpenAI Deployment</label></th>
                <td><input id="azure_openai_deployment" name="azure_openai_deployment" class="regular-text" placeholder="ex: gpt-4o-mini" value="<?php echo esc_attr($o['azure_openai_deployment']); ?>"/></td>
              </tr>
              <tr>
                <th scope="row"><label for="azure_openai_key">Azure OpenAI Key</label></th>
                <td><input id="azure_openai_key" name="azure_openai_key" class="regular-text" value="<?php echo esc_attr($o['azure_openai_key']); ?>"/></td>
              </tr>

              <tr>
                <th scope="row"><label for="openai_api_key">OpenAI API Key</label></th>
                <td><input id="openai_api_key" name="openai_api_key" class="regular-text" value="<?php echo esc_attr($o['openai_api_key']); ?>"/><p class="description">Constante opcional: <code>ROUTESPRO_OPENAI_API_KEY</code>.</p></td>
              </tr>
              <tr>
                <th scope="row"><label for="openai_base_url">OpenAI Base URL (opcional)</label></th>
                <td><input id="openai_base_url" name="openai_base_url" class="regular-text" placeholder="https://api.openai.com" value="<?php echo esc_attr($o['openai_base_url']); ?>"/></td>
              </tr>
              <tr>
                <th scope="row"><label for="openai_model">OpenAI Modelo</label></th>
                <td><input id="openai_model" name="openai_model" class="regular-text" placeholder="gpt-4o-mini" value="<?php echo esc_attr($o['openai_model']); ?>"/></td>
              </tr>

              <tr>
                <th scope="row"><label for="copilot_webhook_url">Copilot Webhook URL</label></th>
                <td><input id="copilot_webhook_url" name="copilot_webhook_url" class="regular-text" placeholder="https://..." value="<?php echo esc_attr($o['copilot_webhook_url']); ?>"/></td>
              </tr>
              <tr>
                <th scope="row"><label for="copilot_auth_header">Copilot Auth Header (opcional)</label></th>
                <td><input id="copilot_auth_header" name="copilot_auth_header" class="regular-text" placeholder="Authorization: Bearer XXX" value="<?php echo esc_attr($o['copilot_auth_header']); ?>"/></td>
              </tr>
            </table>

            <p><button class="button button-primary">Guardar</button></p>
          </form>
          </div>
          <aside>
            <div class="routespro-settings-card" id="routespro-settings-categories">
              <h2>Categorias rápidas</h2>
              <p style="color:#64748b">Cria novas categorias e subcategorias sem sair de Settings.</p>
              <form method="post">
                <?php wp_nonce_field('routespro_settings_categories','routespro_settings_categories_nonce'); ?>
                <table class="form-table"><tbody>
                  <tr><th>Nome</th><td><input type="text" name="settings_category_name" class="regular-text" required></td></tr>
                  <tr><th>Categoria pai</th><td><select name="settings_category_parent_id"><option value="">-- raiz --</option><?php foreach($settings_category_roots as $root): ?><option value="<?php echo intval($root['id']); ?>"><?php echo esc_html($root['name']); ?></option><?php endforeach; ?></select></td></tr>
                  <tr><th>Tipo</th><td><input type="text" name="settings_category_type" class="regular-text" placeholder="horeca ou retalho"></td></tr>
                </tbody></table>
                <p><button class="button button-secondary">Adicionar categoria</button> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=routespro-categories')); ?>">Abrir gestão completa</a></p>
              </form>
            </div>
            <div class="routespro-settings-card">
              <h2>Resumo de categorias</h2>
              <div style="max-height:420px;overflow:auto">
                <table class="widefat striped"><thead><tr><th>Nome</th><th>Pai</th><th>Tipo</th></tr></thead><tbody>
                <?php foreach($settings_categories as $row): $parent=''; foreach($settings_category_roots as $root){ if((int)$root['id']===(int)$row['parent_id']){$parent=$root['name']; break;} } ?>
                  <tr><td><?php echo esc_html($row['name']); ?></td><td><?php echo esc_html($parent); ?></td><td><?php echo esc_html((string)$row['type']); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
              </div>
            </div>
          </aside></div>
        </div>
        <?php
    }
}
