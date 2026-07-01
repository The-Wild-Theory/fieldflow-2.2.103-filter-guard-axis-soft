<?php
namespace RoutesPro\Admin;

use RoutesPro\Forms\RecordService;

if (!defined('ABSPATH')) exit;

class FormHistory {
    public static function render() {
        if (!current_user_can('routespro_manage')) return;
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';

        $filters = [
            'form_id' => absint($_GET['form_id'] ?? 0),
            'client_id' => absint($_GET['client_id'] ?? 0),
            'project_id' => absint($_GET['project_id'] ?? 0),
            'location_id' => absint($_GET['location_id'] ?? 0),
            'record_id' => absint($_GET['record_id'] ?? 0),
        ];

        $forms = $wpdb->get_results('SELECT id, title FROM ' . $wpdb->prefix . 'routespro_forms ORDER BY title ASC', ARRAY_A) ?: [];
        $clients = $wpdb->get_results("SELECT id, name FROM {$px}clients ORDER BY name ASC", ARRAY_A) ?: [];
        $projects = $wpdb->get_results("SELECT id, client_id, name FROM {$px}projects ORDER BY name ASC", ARRAY_A) ?: [];
        $locations = $wpdb->get_results("SELECT id, name FROM {$px}locations ORDER BY name ASC LIMIT 1000", ARRAY_A) ?: [];

        $sql = 'SELECT r.*, f.title AS form_title, c.name AS client_name, p.name AS project_name, l.name AS location_name
            FROM ' . RecordService::table_records() . ' r
            LEFT JOIN ' . $wpdb->prefix . 'routespro_forms f ON f.id = r.form_id
            LEFT JOIN ' . $px . 'clients c ON c.id = r.client_id
            LEFT JOIN ' . $px . 'projects p ON p.id = r.project_id
            LEFT JOIN ' . $px . 'locations l ON l.id = r.location_id
            WHERE 1=1';
        $params = [];
        foreach (['form_id','client_id','project_id','location_id'] as $field) {
            if (!empty($filters[$field])) { $sql .= " AND r.$field = %d"; $params[] = (int)$filters[$field]; }
        }
        $sql .= ' ORDER BY r.last_submitted_at DESC, r.id DESC LIMIT 200';
        if ($params) $sql = $wpdb->prepare($sql, $params);
        $records = $wpdb->get_results($sql, ARRAY_A) ?: [];

        if (!$filters['record_id'] && !empty($records)) $filters['record_id'] = (int)$records[0]['id'];
        $versions = [];
        $diff = [];
        if ($filters['record_id']) {
            $versions = $wpdb->get_results($wpdb->prepare(
                'SELECT v.*, u.display_name AS user_name FROM ' . RecordService::table_versions() . ' v LEFT JOIN ' . $wpdb->users . ' u ON u.ID = v.user_id WHERE v.record_id = %d ORDER BY v.version_no DESC, v.id DESC LIMIT 50',
                $filters['record_id']
            ), ARRAY_A) ?: [];
            if (!empty($versions)) {
                $diff = RecordService::diff_versions((int)$versions[0]['id']);
            }
        }

        echo '<div class="wrap">';
        Branding::render_header('Historico de Formularios');
        echo '<p style="max-width:980px;color:#475569">Fase C, base de backoffice para consultar registos vivos dos formularios por cliente, campanha e local, com timeline de versoes e diferencas face a versao anterior.</p>';
        echo '<style>
            .ffh-grid{display:grid;grid-template-columns:360px minmax(0,1fr);gap:18px;align-items:start}
            .ffh-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px}
            .ffh-table{width:100%;border-collapse:collapse}.ffh-table th,.ffh-table td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}.ffh-table th{font-size:12px;text-transform:uppercase;color:#64748b}.ffh-muted{color:#64748b}.ffh-badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;font-weight:600;font-size:12px}.ffh-diff{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ffh-diff-box{border:1px solid #e5e7eb;border-radius:12px;padding:10px;background:#f8fafc}.ffh-filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}@media (max-width: 1100px){.ffh-grid{grid-template-columns:1fr}}</style>';

        echo '<form method="get" class="ffh-card" style="margin-bottom:18px">';
        echo '<input type="hidden" name="page" value="routespro-form-history">';
        echo '<div class="ffh-filter-grid">';
        self::select('Formulario','form_id',$forms,$filters['form_id'],'title');
        self::select('Cliente','client_id',$clients,$filters['client_id'],'name');
        self::select('Projeto','project_id',$projects,$filters['project_id'],'name');
        self::select('Local','location_id',$locations,$filters['location_id'],'name');
        echo '</div><p><button class="button button-primary">Filtrar</button></p></form>';

        echo '<div class="ffh-grid">';
        echo '<div class="ffh-card"><h2 style="margin-top:0">Registos</h2>';
        if (!$records) { echo '<p class="ffh-muted">Sem registos encontrados para os filtros atuais.</p>'; }
        else {
            echo '<table class="ffh-table"><thead><tr><th>ID</th><th>Contexto</th><th>Atual</th></tr></thead><tbody>';
            foreach ($records as $r) {
                $url = add_query_arg([
                    'page' => 'routespro-form-history',
                    'form_id' => $filters['form_id'],
                    'client_id' => $filters['client_id'],
                    'project_id' => $filters['project_id'],
                    'location_id' => $filters['location_id'],
                    'record_id' => (int)$r['id'],
                ], admin_url('admin.php'));
                echo '<tr>';
                echo '<td><strong>#' . (int)$r['id'] . '</strong><br><span class="ffh-muted">v' . (int)$r['current_version_no'] . '</span></td>';
                echo '<td><a href="' . esc_url($url) . '"><strong>' . esc_html($r['location_name'] ?: 'Sem local') . '</strong></a><br>' . esc_html($r['client_name'] ?: 'Sem cliente') . ' · ' . esc_html($r['project_name'] ?: 'Sem projeto') . '<br><span class="ffh-muted">' . esc_html($r['form_title'] ?: ('Formulario #' . (int)$r['form_id'])) . '</span></td>';
                echo '<td><span class="ffh-badge">' . esc_html($r['status'] ?: 'active') . '</span><br><span class="ffh-muted">' . esc_html($r['last_submitted_at'] ?: '') . '</span></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        echo '<div class="ffh-card"><h2 style="margin-top:0">Timeline e diferencas</h2>';
        if (!$filters['record_id']) { echo '<p class="ffh-muted">Seleciona um registo para ver as versoes.</p>'; }
        else {
            if ($versions) {
                echo '<h3>Versoes</h3><table class="ffh-table"><thead><tr><th>Versao</th><th>Data</th><th>Utilizador</th><th>Contexto</th></tr></thead><tbody>';
                foreach ($versions as $v) {
                    echo '<tr><td><strong>v' . (int)$v['version_no'] . '</strong></td><td>' . esc_html($v['submitted_at'] ?: '') . '</td><td>' . esc_html($v['user_name'] ?: ('User #' . (int)$v['user_id'])) . '</td><td>Rota #' . (int)$v['route_id'] . ' · Stop #' . (int)$v['route_stop_id'] . '</td></tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p class="ffh-muted">Este registo ainda nao tem versoes listaveis.</p>';
            }

            echo '<h3 style="margin-top:18px">Ultima alteracao face a versao anterior</h3>';
            if (!$diff) { echo '<p class="ffh-muted">Sem diferencas detetadas ou ainda sem versao anterior.</p>'; }
            else {
                echo '<div class="ffh-diff">';
                foreach ($diff as $item) {
                    echo '<div class="ffh-diff-box"><strong>' . esc_html($item['label']) . '</strong><div class="ffh-muted" style="margin-top:8px">Antes</div><div>' . esc_html($item['before']) . '</div><div class="ffh-muted" style="margin-top:8px">Depois</div><div><strong>' . esc_html($item['after']) . '</strong></div></div>';
                }
                echo '</div>';
            }
        }
        echo '</div></div></div>';
    }

    private static function select(string $label, string $name, array $rows, int $selected, string $text_key): void {
        echo '<label><strong>' . esc_html($label) . '</strong><br><select name="' . esc_attr($name) . '" style="width:100%">';
        echo '<option value="0">Todos</option>';
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $text = (string)($row[$text_key] ?? ('#' . $id));
            echo '<option value="' . $id . '"' . selected($selected, $id, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select></label>';
    }
}
