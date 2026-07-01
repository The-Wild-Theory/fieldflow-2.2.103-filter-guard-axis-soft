<?php
namespace RoutesPro\Admin;

if (!defined('ABSPATH')) exit;

class CampaignBranding {
    const OPT_KEY = 'routespro_campaign_branding';

    public static function render(): void {
        if (!current_user_can('routespro_manage')) wp_die('Sem permissões.');
        global $wpdb;
        $clients_tbl = $wpdb->prefix . 'routespro_clients';
        $projects_tbl = $wpdb->prefix . 'routespro_projects';

        $selected_client_id = absint($_REQUEST['client_id'] ?? 0);
        $selected_project_id = absint($_REQUEST['project_id'] ?? 0);
        $items = get_option(self::OPT_KEY, []);
        if (!is_array($items)) $items = [];

        if (!empty($_POST['routespro_campaign_branding_nonce']) && wp_verify_nonce($_POST['routespro_campaign_branding_nonce'], 'routespro_campaign_branding')) {
            $action = sanitize_text_field($_POST['branding_action'] ?? 'save');
            $client_id = absint($_POST['client_id'] ?? 0);
            $project_id = absint($_POST['project_id'] ?? 0);
            $key = self::make_key($client_id, $project_id);
            if ($action === 'delete') {
                unset($items[$key]);
                update_option(self::OPT_KEY, $items, false);
                echo '<div class="notice notice-success"><p>Branding da campanha removido.</p></div>';
            } else {
                $logo_id = absint($_POST['logo_id'] ?? 0);
                $logo_url = esc_url_raw($_POST['logo_url'] ?? '');
                if (!$logo_url && $logo_id) $logo_url = wp_get_attachment_url($logo_id) ?: '';
                $items[$key] = [
                    'client_id' => $client_id,
                    'project_id' => $project_id,
                    'logo_id' => $logo_id,
                    'logo_url' => $logo_url,
                    'updated_at' => current_time('mysql'),
                ];
                update_option(self::OPT_KEY, $items, false);
                echo '<div class="notice notice-success"><p>Branding da campanha guardado.</p></div>';
            }
            $selected_client_id = $client_id;
            $selected_project_id = $project_id;
        }

        $clients = $wpdb->get_results("SELECT id, name FROM {$clients_tbl} ORDER BY name ASC", ARRAY_A) ?: [];
        $project_sql = "SELECT p.id, p.name, c.name AS client_name, p.client_id FROM {$projects_tbl} p LEFT JOIN {$clients_tbl} c ON c.id=p.client_id";
        $project_args = [];
        if ($selected_client_id > 0) {
            $project_sql .= ' WHERE p.client_id=%d';
            $project_args[] = $selected_client_id;
        }
        $project_sql .= ' ORDER BY c.name ASC, p.name ASC';
        $projects = $project_args ? ($wpdb->get_results($wpdb->prepare($project_sql, ...$project_args), ARRAY_A) ?: []) : ($wpdb->get_results($project_sql, ARRAY_A) ?: []);

        $current = $items[self::make_key($selected_client_id, $selected_project_id)] ?? ['logo_id' => 0, 'logo_url' => ''];

        echo '<div class="wrap">';
        Branding::render_header('Branding por campanha', 'Define o logo do cliente por cliente e campanha, para usar no BO e nas exportações PDF.');
        echo '<div class="routespro-card" style="max-width:1180px">';
        echo '<form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin-bottom:18px">';
        echo '<input type="hidden" name="page" value="routespro-campaign-branding">';
        echo '<p><label><strong>Cliente</strong><br><select name="client_id" onchange="this.form.submit()"><option value="0">Todos</option>';
        foreach ($clients as $client) echo '<option value="' . (int)$client['id'] . '" ' . selected($selected_client_id, (int)$client['id'], false) . '>' . esc_html($client['name']) . '</option>';
        echo '</select></label></p>';
        echo '<p><label><strong>Campanha</strong><br><select name="project_id"><option value="0">Selecionar campanha</option>';
        foreach ($projects as $project) {
            $label = ($project['client_name'] ? $project['client_name'] . ' , ' : '') . ($project['name'] ?? ('#' . (int)$project['id']));
            echo '<option value="' . (int)$project['id'] . '" ' . selected($selected_project_id, (int)$project['id'], false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label></p>';
        submit_button('Filtrar', 'secondary', '', false);
        echo '</form>';

        echo '<form method="post">';
        wp_nonce_field('routespro_campaign_branding', 'routespro_campaign_branding_nonce');
        echo '<input type="hidden" name="client_id" value="' . (int)$selected_client_id . '">';
        echo '<input type="hidden" name="project_id" value="' . (int)$selected_project_id . '">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Cliente</th><td>' . esc_html(self::resolve_client_name($clients, $selected_client_id)) . '</td></tr>';
        echo '<tr><th>Campanha</th><td>' . esc_html(self::resolve_project_name($projects, $selected_project_id)) . '</td></tr>';
        echo '<tr><th>Logo do cliente</th><td>';
        echo '<input type="hidden" name="logo_id" id="routespro-campaign-logo-id" value="' . (int)($current['logo_id'] ?? 0) . '">';
        echo '<input type="text" name="logo_url" id="routespro-campaign-logo-url" value="' . esc_attr($current['logo_url'] ?? '') . '" class="regular-text" placeholder="URL do logo"> ';
        echo '<button type="button" class="button" id="routespro-campaign-logo-pick">Escolher logo</button> ';
        echo '<button type="button" class="button-link-delete" id="routespro-campaign-logo-clear">Limpar</button>';
        echo '<p class="description">Recomendado, logo horizontal em JPG ou PNG. Este branding fica ligado ao par cliente + campanha.</p>';
        $preview = !empty($current['logo_url']) ? '<img src="' . esc_url($current['logo_url']) . '" style="max-height:72px;width:auto;display:block;border:1px solid #e5e7eb;border-radius:10px;padding:10px;background:#fff">' : '<div style="padding:14px;border:1px dashed #cbd5e1;border-radius:10px;color:#64748b;display:inline-block">Sem logo escolhido.</div>';
        echo '<div id="routespro-campaign-logo-preview" style="margin-top:12px">' . $preview . '</div>';
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button('Guardar branding');
        if ($selected_project_id > 0 || $selected_client_id > 0) {
            echo '<button class="button-link-delete" name="branding_action" value="delete" onclick="return confirm(\'Remover branding desta combinação?\')">Remover branding</button>';
        }
        echo '</form>';
        echo '</div>';

        echo '<div class="routespro-card" style="margin-top:18px">';
        echo '<h2 style="margin-top:0">Brandings configurados</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Cliente</th><th>Campanha</th><th>Logo</th><th>Atualizado</th></tr></thead><tbody>';
        if (!$items) {
            echo '<tr><td colspan="4">Ainda não existem logos por campanha.</td></tr>';
        } else {
            foreach ($items as $item) {
                $client_name = $item['client_id'] ? ($wpdb->get_var($wpdb->prepare("SELECT name FROM {$clients_tbl} WHERE id=%d", (int)$item['client_id'])) ?: ('#' . (int)$item['client_id'])) : 'Sem cliente';
                $project_name = $item['project_id'] ? ($wpdb->get_var($wpdb->prepare("SELECT name FROM {$projects_tbl} WHERE id=%d", (int)$item['project_id'])) ?: ('#' . (int)$item['project_id'])) : 'Sem campanha';
                $logo = !empty($item['logo_url']) ? '<img src="' . esc_url($item['logo_url']) . '" style="max-height:40px;width:auto">' : 'Sem logo';
                echo '<tr><td>' . esc_html($client_name) . '</td><td>' . esc_html($project_name) . '</td><td>' . $logo . '</td><td>' . esc_html((string)($item['updated_at'] ?? '')) . '</td></tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';

        wp_enqueue_media();
        add_action('admin_footer', [self::class, 'render_media_script']);
    }

    public static function render_media_script(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'fieldflow_page_routespro-campaign-branding') return;
        echo '<script>document.addEventListener("DOMContentLoaded",function(){var btn=document.getElementById("routespro-campaign-logo-pick"),clear=document.getElementById("routespro-campaign-logo-clear"),url=document.getElementById("routespro-campaign-logo-url"),id=document.getElementById("routespro-campaign-logo-id"),preview=document.getElementById("routespro-campaign-logo-preview"),frame=null;function render(){var v=(url&&url.value)||"";preview.innerHTML=v?"<img src=\""+v.replace(/\"/g,"&quot;")+"\" style=\"max-height:72px;width:auto;display:block;border:1px solid #e5e7eb;border-radius:10px;padding:10px;background:#fff\">":"<div style=\"padding:14px;border:1px dashed #cbd5e1;border-radius:10px;color:#64748b;display:inline-block\">Sem logo escolhido.</div>";} if(btn){btn.addEventListener("click",function(e){e.preventDefault(); if(frame){frame.open(); return;} frame=wp.media({title:"Escolher logo da campanha",button:{text:"Usar este logo"},multiple:false}); frame.on("select",function(){var a=frame.state().get("selection").first().toJSON(); if(url) url.value=a.url||""; if(id) id.value=a.id||""; render();}); frame.open();});} if(clear){clear.addEventListener("click",function(e){e.preventDefault(); if(url) url.value=""; if(id) id.value="0"; render();});} if(url){url.addEventListener("input",render);} render();});</script>';
    }

    private static function resolve_client_name(array $clients, int $client_id): string {
        foreach ($clients as $client) if ((int)$client['id'] === $client_id) return (string)$client['name'];
        return $client_id > 0 ? ('#' . $client_id) : 'Sem cliente selecionado';
    }

    private static function resolve_project_name(array $projects, int $project_id): string {
        foreach ($projects as $project) if ((int)$project['id'] === $project_id) return (string)$project['name'];
        return $project_id > 0 ? ('#' . $project_id) : 'Sem campanha selecionada';
    }

    private static function make_key(int $client_id, int $project_id): string {
        return $client_id . '|' . $project_id;
    }
}
