<?php
namespace RoutesPro\Admin;

use RoutesPro\PWA\Settings;
use RoutesPro\Support\AdminPage;

if (!defined('ABSPATH')) exit;

class PWASettingsPage {
    public static function render(): void {
        if (!current_user_can('routespro_manage')) return;
        if (!empty($_POST['fieldflow_pwa_nonce']) && wp_verify_nonce($_POST['fieldflow_pwa_nonce'], 'fieldflow_pwa_save')) {
            $opts = Settings::sanitize($_POST['fieldflow_pwa'] ?? []);
            update_option(Settings::OPT_KEY, $opts, false);
            flush_rewrite_rules(false);
            AdminPage::notice('Configuração das Apps Mobile / PWA guardada.');
        }
        $o = Settings::get();
        $pages = get_pages(['sort_column' => 'post_title', 'sort_order' => 'ASC']);
        wp_enqueue_media();
        AdminPage::open('Apps Mobile / PWA', 'Configura duas PWAs FieldFlow: uma para o operativo [fieldflow_app] e outra para cliente [fieldflow_client_portal].');
        ?>
        <style>
          .ff-pwa-admin{max-width:1240px}.ff-pwa-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.ff-pwa-card{background:#fff;border:1px solid #dbe3ef;border-radius:18px;padding:18px;box-shadow:0 12px 30px rgba(15,23,42,.06)}.ff-pwa-card h2{margin:0 0 12px}.ff-pwa-card p.description{margin-top:4px;color:#64748b}.ff-pwa-row{margin:12px 0}.ff-pwa-row label{font-weight:700;display:block;margin-bottom:6px}.ff-pwa-row input[type=text],.ff-pwa-row input[type=url],.ff-pwa-row input[type=number],.ff-pwa-row textarea,.ff-pwa-row select{width:100%;max-width:100%}.ff-pwa-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.ff-pwa-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 18px}.ff-pwa-tabs a{text-decoration:none;background:#eef2ff;color:#1e293b;border-radius:999px;padding:8px 12px;font-weight:700}.ff-pwa-preview{background:linear-gradient(135deg,#0f172a,#334155);color:#fff;border-radius:22px;padding:22px}.ff-pwa-preview h3{color:#fff;margin:0 0 8px}.ff-pwa-preview .pill{display:inline-flex;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:999px;padding:7px 10px;margin:4px 4px 0 0}.ff-pwa-media{display:flex;gap:10px;align-items:center}.ff-pwa-media img{width:56px;height:56px;object-fit:cover;border-radius:14px;border:1px solid #cbd5e1;background:#fff}.ff-pwa-app-title{display:flex;align-items:center;justify-content:space-between;gap:12px}.ff-pwa-badge{font-size:12px;border-radius:999px;background:#ecfeff;color:#155e75;padding:6px 10px;font-weight:800}@media(max-width:960px){.ff-pwa-grid{grid-template-columns:1fr}.ff-pwa-checks{grid-template-columns:1fr}}
        </style>
        <div class="ff-pwa-admin">
          <form method="post">
            <?php wp_nonce_field('fieldflow_pwa_save', 'fieldflow_pwa_nonce'); ?>
            <div class="ff-pwa-tabs">
              <a href="#estado">Estado</a><a href="#operativo">App Operativo</a><a href="#cliente">App Cliente</a><a href="#instalacao">Instalação</a><a href="#offline">Offline</a><a href="#links">Links</a><a href="#push">Push</a><a href="#avancado">Avançado</a>
            </div>
            <div class="ff-pwa-grid">
              <section class="ff-pwa-card" id="estado">
                <h2>Estado geral</h2>
                <div class="ff-pwa-row"><label><input type="checkbox" name="fieldflow_pwa[enabled]" value="1" <?php checked($o['enabled'], 1); ?>> Ativar PWAs FieldFlow</label></div>
                <div class="ff-pwa-row"><label><input type="checkbox" name="fieldflow_pwa[client_enabled]" value="1" <?php checked($o['client_enabled'], 1); ?>> Ativar também App Cliente</label></div>
                <div class="ff-pwa-row"><label><input type="checkbox" name="fieldflow_pwa[require_login]" value="1" <?php checked($o['require_login'], 1); ?>> Exigir utilizador autenticado para mostrar convite</label></div>
                <div class="ff-pwa-row"><label>Modo de abertura</label><select name="fieldflow_pwa[display]"><?php foreach (['standalone'=>'Standalone','fullscreen'=>'Fullscreen','minimal-ui'=>'Minimal UI','browser'=>'Browser'] as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($o['display'], $k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></div>
                <div class="ff-pwa-row"><label>Orientação</label><select name="fieldflow_pwa[orientation]"><?php foreach (['portrait'=>'Portrait','landscape'=>'Landscape','any'=>'Any'] as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($o['orientation'], $k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></div>
                <div class="ff-pwa-row"><label>Barra de estado iOS</label><select name="fieldflow_pwa[ios_status_bar]"><?php foreach (['default'=>'Default','black'=>'Black','black-translucent'=>'Black translucent'] as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($o['ios_status_bar'], $k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></div>
              </section>

              <section class="ff-pwa-card" id="instalacao">
                <h2>Instalação</h2>
                <div class="ff-pwa-row"><label><input type="checkbox" name="fieldflow_pwa[install_prompt_enabled]" value="1" <?php checked($o['install_prompt_enabled'], 1); ?>> Mostrar convite de instalação nas páginas configuradas</label></div>
                <div class="ff-pwa-row"><label>Frequência</label><select name="fieldflow_pwa[prompt_frequency]"><?php foreach (['always'=>'Sempre','daily'=>'Uma vez por dia','weekly'=>'Uma vez por semana','once'=>'Apenas uma vez','manual'=>'Manual'] as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($o['prompt_frequency'], $k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></div>
                <div class="ff-pwa-row"><label>Delay em segundos</label><input type="number" min="0" max="30" name="fieldflow_pwa[prompt_delay]" value="<?php echo esc_attr($o['prompt_delay']); ?>"></div>
                <div class="ff-pwa-row"><label>Botão secundário</label><input type="text" name="fieldflow_pwa[dismiss_button]" value="<?php echo esc_attr($o['dismiss_button']); ?>"></div>
              </section>

              <?php self::appCard('operativo', 'App Operativo', 'fieldflow_app', '', $o, $pages); ?>
              <?php self::appCard('cliente', 'App Cliente', 'fieldflow_client_portal', 'client_', $o, $pages); ?>

              <section class="ff-pwa-card" id="links">
                <h2>Links Operativo</h2>
                <div class="ff-pwa-row"><label>URL de suporte</label><input type="url" name="fieldflow_pwa[support_url]" value="<?php echo esc_attr($o['support_url']); ?>"></div>
                <div class="ff-pwa-row"><label>URL de privacidade</label><input type="url" name="fieldflow_pwa[privacy_url]" value="<?php echo esc_attr($o['privacy_url']); ?>"></div>
                <div class="ff-pwa-row"><label>URL de termos</label><input type="url" name="fieldflow_pwa[terms_url]" value="<?php echo esc_attr($o['terms_url']); ?>"></div>
                <div class="ff-pwa-row"><label>Links personalizados</label><textarea name="fieldflow_pwa[custom_links]" rows="4" placeholder="Suporte|https://exemplo.pt"><?php echo esc_textarea($o['custom_links']); ?></textarea></div>
                <div class="ff-pwa-row"><label>Itens ativos no menu operativo</label><div class="ff-pwa-checks"><?php foreach (['menu_route'=>'Minha Rota','menu_discovery'=>'Descobrir','menu_report'=>'Reportar','menu_commercial'=>'Base Comercial','menu_messages'=>'Mensagens','menu_analytics'=>'Analytics'] as $k=>$v): ?><label><input type="checkbox" name="fieldflow_pwa[<?php echo esc_attr($k); ?>]" value="1" <?php checked($o[$k], 1); ?>> <?php echo esc_html($v); ?></label><?php endforeach; ?></div></div>
              </section>

              <section class="ff-pwa-card">
                <h2>Links Cliente</h2>
                <div class="ff-pwa-row"><label>URL de suporte</label><input type="url" name="fieldflow_pwa[client_support_url]" value="<?php echo esc_attr($o['client_support_url']); ?>"></div>
                <div class="ff-pwa-row"><label>URL de privacidade</label><input type="url" name="fieldflow_pwa[client_privacy_url]" value="<?php echo esc_attr($o['client_privacy_url']); ?>"></div>
                <div class="ff-pwa-row"><label>URL de termos</label><input type="url" name="fieldflow_pwa[client_terms_url]" value="<?php echo esc_attr($o['client_terms_url']); ?>"></div>
                <div class="ff-pwa-row"><label>Links personalizados</label><textarea name="fieldflow_pwa[client_custom_links]" rows="4" placeholder="Suporte Cliente|https://exemplo.pt"><?php echo esc_textarea($o['client_custom_links']); ?></textarea></div>
              </section>

              <section class="ff-pwa-card" id="offline">
                <h2>Offline e cache</h2>
                <div class="ff-pwa-row"><label><input type="checkbox" name="fieldflow_pwa[offline_enabled]" value="1" <?php checked($o['offline_enabled'], 1); ?>> Ativar página offline</label></div>
                <div class="ff-pwa-row"><label><input type="checkbox" name="fieldflow_pwa[cache_assets]" value="1" <?php checked($o['cache_assets'], 1); ?>> Cache de assets estáticos</label></div>
                <div class="ff-pwa-row"><label><input type="checkbox" name="fieldflow_pwa[cache_rest]" value="1" <?php checked($o['cache_rest'], 1); ?>> Cache de REST API, usar com cuidado</label></div>
                <div class="ff-pwa-row"><label>Título offline</label><input type="text" name="fieldflow_pwa[offline_title]" value="<?php echo esc_attr($o['offline_title']); ?>"></div>
                <div class="ff-pwa-row"><label>Mensagem offline</label><textarea name="fieldflow_pwa[offline_message]" rows="4"><?php echo esc_textarea($o['offline_message']); ?></textarea></div>
              </section>

              <section class="ff-pwa-card" id="push">
                <h2>Notificações Push</h2>
                <p class="description">Preparado para a fase seguinte. iOS exige app adicionada ao Ecrã Principal e permissão do utilizador.</p>
                <div class="ff-pwa-row"><label><input type="checkbox" name="fieldflow_pwa[push_enabled]" value="1" <?php checked($o['push_enabled'], 1); ?>> Ativar push, fase técnica</label></div>
                <div class="ff-pwa-row"><label>VAPID public key</label><textarea name="fieldflow_pwa[push_public_key]" rows="2"><?php echo esc_textarea($o['push_public_key']); ?></textarea></div>
                <div class="ff-pwa-row"><label>VAPID private key</label><textarea name="fieldflow_pwa[push_private_key]" rows="2"><?php echo esc_textarea($o['push_private_key']); ?></textarea></div>
              </section>

              <section class="ff-pwa-card" id="avancado">
                <h2>Preview técnico</h2>
                <div class="ff-pwa-preview">
                  <h3>Manifest separados</h3>
                  <p>Cada público tem nome, página, ícone, cores e start URL próprios.</p>
                  <span class="pill">Operativo: ?fieldflow_pwa_profile=operative</span>
                  <span class="pill">Cliente: ?fieldflow_pwa_profile=client</span>
                  <span class="pill">Service worker partilhado</span>
                </div>
              </section>
            </div>
            <p style="margin-top:18px"><button class="button button-primary button-hero">Guardar Apps Mobile / PWA</button></p>
          </form>
        </div>
        <script>
        (function(){
          document.querySelectorAll('[data-ff-media]').forEach(function(btn){
            btn.addEventListener('click', function(e){
              e.preventDefault();
              var target = document.getElementById(btn.dataset.target);
              var preview = document.getElementById(btn.dataset.preview);
              var frame = wp.media({title:'Selecionar imagem', multiple:false, library:{type:'image'}});
              frame.on('select', function(){
                var att = frame.state().get('selection').first().toJSON();
                target.value = att.id;
                if(preview) preview.src = att.url;
              });
              frame.open();
            });
          });
        })();
        </script>
        <?php
        AdminPage::close();
    }

    private static function appCard(string $id, string $title, string $shortcode, string $prefix, array $o, array $pages): void {
        $page_key = $prefix . 'app_page_id';
        $app_name = $prefix . 'app_name';
        $short_name = $prefix . 'short_name';
        $description = $prefix . 'description';
        $theme_color = $prefix . 'theme_color';
        $background_color = $prefix . 'background_color';
        $icon_id = $prefix . 'icon_id';
        $apple_icon_id = $prefix . 'apple_icon_id';
        $install_title = $prefix . 'install_title';
        $install_text = $prefix . 'install_text';
        $install_button = $prefix . 'install_button';
        $ios_title = $prefix . 'ios_title';
        $ios_text = $prefix . 'ios_text';
        $custom_css = $prefix . 'custom_css';
        ?>
        <section class="ff-pwa-card" id="<?php echo esc_attr($id); ?>">
          <div class="ff-pwa-app-title"><h2><?php echo esc_html($title); ?></h2><span class="ff-pwa-badge">[<?php echo esc_html($shortcode); ?>]</span></div>
          <div class="ff-pwa-row"><label>Página da app</label><select name="fieldflow_pwa[<?php echo esc_attr($page_key); ?>]"><option value="0">Selecionar página...</option><?php foreach ($pages as $p): ?><option value="<?php echo esc_attr($p->ID); ?>" <?php selected((int)$o[$page_key], (int)$p->ID); ?>><?php echo esc_html($p->post_title); ?></option><?php endforeach; ?></select><p class="description">Seleciona a página onde está o shortcode [<?php echo esc_html($shortcode); ?>].</p></div>
          <div class="ff-pwa-row"><label>Nome da app</label><input type="text" name="fieldflow_pwa[<?php echo esc_attr($app_name); ?>]" value="<?php echo esc_attr($o[$app_name]); ?>"></div>
          <div class="ff-pwa-row"><label>Nome curto</label><input type="text" name="fieldflow_pwa[<?php echo esc_attr($short_name); ?>]" value="<?php echo esc_attr($o[$short_name]); ?>"></div>
          <div class="ff-pwa-row"><label>Descrição</label><textarea name="fieldflow_pwa[<?php echo esc_attr($description); ?>]" rows="3"><?php echo esc_textarea($o[$description]); ?></textarea></div>
          <div class="ff-pwa-row"><label>Cor principal</label><input type="color" name="fieldflow_pwa[<?php echo esc_attr($theme_color); ?>]" value="<?php echo esc_attr($o[$theme_color]); ?>"></div>
          <div class="ff-pwa-row"><label>Cor de fundo</label><input type="color" name="fieldflow_pwa[<?php echo esc_attr($background_color); ?>]" value="<?php echo esc_attr($o[$background_color]); ?>"></div>
          <?php self::mediaField($icon_id, 'Ícone principal', $o[$icon_id], $prefix === 'client_' ? Settings::PROFILE_CLIENT : Settings::PROFILE_OPERATIVE); ?>
          <?php self::mediaField($apple_icon_id, 'Ícone Apple/Safari', $o[$apple_icon_id], $prefix === 'client_' ? Settings::PROFILE_CLIENT : Settings::PROFILE_OPERATIVE); ?>
          <div class="ff-pwa-row"><label>Título do convite</label><input type="text" name="fieldflow_pwa[<?php echo esc_attr($install_title); ?>]" value="<?php echo esc_attr($o[$install_title]); ?>"></div>
          <div class="ff-pwa-row"><label>Texto do convite</label><textarea name="fieldflow_pwa[<?php echo esc_attr($install_text); ?>]" rows="3"><?php echo esc_textarea($o[$install_text]); ?></textarea></div>
          <div class="ff-pwa-row"><label>Botão principal</label><input type="text" name="fieldflow_pwa[<?php echo esc_attr($install_button); ?>]" value="<?php echo esc_attr($o[$install_button]); ?>"></div>
          <div class="ff-pwa-row"><label>Título iOS/Safari</label><input type="text" name="fieldflow_pwa[<?php echo esc_attr($ios_title); ?>]" value="<?php echo esc_attr($o[$ios_title]); ?>"></div>
          <div class="ff-pwa-row"><label>Texto iOS/Safari</label><textarea name="fieldflow_pwa[<?php echo esc_attr($ios_text); ?>]" rows="3"><?php echo esc_textarea($o[$ios_text]); ?></textarea></div>
          <div class="ff-pwa-row"><label>CSS personalizado desta app</label><textarea name="fieldflow_pwa[<?php echo esc_attr($custom_css); ?>]" rows="4" placeholder=".rp-client-premium { ... }"><?php echo esc_textarea($o[$custom_css]); ?></textarea></div>
        </section>
        <?php
    }

    private static function mediaField(string $key, string $label, $value, string $profile = Settings::PROFILE_OPERATIVE): void {
        $id = absint($value);
        $url = $id ? wp_get_attachment_url($id) : Settings::iconUrl('main', 192, $profile);
        echo '<div class="ff-pwa-row"><label>' . esc_html($label) . '</label><div class="ff-pwa-media"><img id="ff-' . esc_attr($key) . '-preview" src="' . esc_url($url) . '" alt=""><input type="number" id="ff-' . esc_attr($key) . '" name="fieldflow_pwa[' . esc_attr($key) . ']" value="' . esc_attr($id) . '" min="0"><button class="button" data-ff-media data-target="ff-' . esc_attr($key) . '" data-preview="ff-' . esc_attr($key) . '-preview">Escolher</button></div></div>';
    }
}
