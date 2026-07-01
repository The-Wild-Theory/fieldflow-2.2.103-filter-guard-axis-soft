<?php
namespace RoutesPro\Admin;

use RoutesPro\Forms\ContextQuestions as ContextQuestionService;
use RoutesPro\Support\AdminPage;

if (!defined('ABSPATH')) exit;

class ContextQuestions {
    public static function render() {
        if (!current_user_can('routespro_manage')) return;
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        self::ensure_table();
        $notice = '';

        if (!empty($_POST['routespro_context_questions_nonce']) && wp_verify_nonce($_POST['routespro_context_questions_nonce'], 'routespro_context_questions')) {
            $action = sanitize_key($_POST['routespro_context_questions_action'] ?? '');
            if ($action === 'save') {
                $notice = self::save_question();
            } elseif ($action === 'delete') {
                $id = absint($_POST['id'] ?? 0);
                if ($id) {
                    $wpdb->delete(ContextQuestionService::table(), ['id' => $id], ['%d']);
                    $notice = 'Pergunta removida.';
                }
            } elseif ($action === 'bulk_import') {
                $notice = self::handle_bulk_import();
            }
        }

        $edit_id = absint($_GET['edit'] ?? 0);
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . ContextQuestionService::table() . ' WHERE id=%d', $edit_id), ARRAY_A) : [];
        if (!is_array($edit)) $edit = [];

        $client_id = absint($_GET['client_id'] ?? 0);
        $project_id = absint($_GET['project_id'] ?? 0);
        $where = ['1=1'];
        $args = [];
        if ($client_id) { $where[] = 'q.client_id=%d'; $args[] = $client_id; }
        if ($project_id) { $where[] = 'q.project_id=%d'; $args[] = $project_id; }
        $sql = "SELECT q.*, c.name AS client_name, p.name AS project_name, l.name AS location_name, f.title AS form_title
                FROM " . ContextQuestionService::table() . " q
                LEFT JOIN {$px}clients c ON c.id=q.client_id
                LEFT JOIN {$px}projects p ON p.id=q.project_id
                LEFT JOIN {$px}locations l ON l.id=q.location_id
                LEFT JOIN {$px}forms f ON f.id=q.form_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY q.updated_at DESC, q.id DESC LIMIT 300";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : ($wpdb->get_results($sql, ARRAY_A) ?: []);
        $clients = $wpdb->get_results("SELECT id,name FROM {$px}clients ORDER BY name ASC", ARRAY_A) ?: [];
        $projects = $client_id ? $wpdb->get_results($wpdb->prepare("SELECT id,name,client_id FROM {$px}projects WHERE client_id=%d ORDER BY name ASC", $client_id), ARRAY_A) : ($wpdb->get_results("SELECT id,name,client_id FROM {$px}projects ORDER BY name ASC LIMIT 300", ARRAY_A) ?: []);
        $locations = self::location_options($client_id, $project_id);
        $forms = self::form_options($client_id, $project_id);

        AdminPage::open('Perguntas contextuais', 'Configura perguntas específicas por cliente, campanha e loja/local, incluindo importação em massa por CSV.');
        if ($notice) echo '<div class="updated notice"><p>' . esc_html($notice) . '</p></div>';
        ?>
        <div class="routespro-card" style="margin-top:16px">
          <h2 style="margin-top:0">Filtro</h2>
          <form method="get" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
            <input type="hidden" name="page" value="routespro-context-questions">
            <label>Cliente<br><select name="client_id"><option value="0">Todos</option><?php foreach ($clients as $c): ?><option value="<?php echo esc_attr($c['id']); ?>" <?php selected($client_id, (int)$c['id']); ?>><?php echo esc_html($c['name']); ?></option><?php endforeach; ?></select></label>
            <label>Campanha<br><select name="project_id"><option value="0">Todas</option><?php foreach ($projects as $p): ?><option value="<?php echo esc_attr($p['id']); ?>" <?php selected($project_id, (int)$p['id']); ?>><?php echo esc_html($p['name']); ?></option><?php endforeach; ?></select></label>
            <button class="button">Filtrar</button>
          </form>
        </div>

        <div class="routespro-card" style="margin-top:16px">
          <h2 style="margin-top:0"><?php echo $edit ? 'Editar pergunta contextual' : 'Nova pergunta contextual'; ?></h2>
          <form method="post">
            <?php wp_nonce_field('routespro_context_questions', 'routespro_context_questions_nonce'); ?>
            <input type="hidden" name="routespro_context_questions_action" value="save">
            <input type="hidden" name="id" value="<?php echo esc_attr((int)($edit['id'] ?? 0)); ?>">
            <table class="form-table" role="presentation">
              <tr><th>Cliente</th><td><input type="number" name="client_id" value="<?php echo esc_attr((int)($edit['client_id'] ?? $client_id)); ?>" class="small-text"> <span class="description">0 permite regra global.</span></td></tr>
              <tr><th>Campanha</th><td><input type="number" name="project_id" value="<?php echo esc_attr((int)($edit['project_id'] ?? $project_id)); ?>" class="small-text"> <span class="description">0 aplica a todas as campanhas do âmbito.</span></td></tr>
              <tr><th>Formulário</th><td>
                <select name="form_id" class="regular-text" style="min-width:420px;max-width:720px">
                  <option value="0" <?php selected((int)($edit['form_id'] ?? 0), 0); ?>>Qualquer formulário associado ao âmbito</option>
                  <?php foreach ($forms as $form): ?>
                    <option value="<?php echo esc_attr((int)$form['id']); ?>" <?php selected((int)($edit['form_id'] ?? 0), (int)$form['id']); ?>><?php echo esc_html('#' . (int)$form['id'] . ' - ' . $form['title'] . (!empty($form['binding_label']) ? ' - ' . $form['binding_label'] : '')); ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="description">Opcional. Usa isto quando a pergunta só deve aparecer no formulário ligado à campanha. Se ficar em “Qualquer”, aparece em todos os formulários que batam com cliente, campanha e loja.</p>
              </td></tr>
              <tr><th>Loja / Local</th><td>
                <select name="location_ids[]" multiple size="10" class="regular-text" style="min-width:420px;max-width:720px;height:auto">
                  <option value="0" <?php selected((int)($edit['location_id'] ?? 0), 0); ?>>Todas as lojas do âmbito</option>
                  <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo esc_attr((int)$loc['id']); ?>" <?php selected((int)($edit['location_id'] ?? 0), (int)$loc['id']); ?>><?php echo esc_html('#' . (int)$loc['id'] . ' · ' . $loc['name'] . (!empty($loc['external_ref']) ? ' · ' . $loc['external_ref'] : '')); ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="description">Seleciona uma ou várias lojas. Usa Ctrl/Cmd para multi-seleção. A opção “Todas” cria uma regra aplicável a todo o âmbito.</p>
              </td></tr>
              <tr><th>Tipo de momento</th><td><select name="context_type">
                <?php foreach (['pre_cheio'=>'Pré-cheio','campanha'=>'Campanha','tra'=>'TRA','nova_atividade'=>'Nova atividade','custom'=>'Outro'] as $k=>$label): ?><option value="<?php echo esc_attr($k); ?>" <?php selected(($edit['context_type'] ?? 'pre_cheio'), $k); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
              </select></td></tr>
              <tr><th>Pergunta</th><td><input type="text" name="question_label" class="regular-text" value="<?php echo esc_attr((string)($edit['question_label'] ?? '')); ?>" required></td></tr>
              <tr><th>Chave técnica</th><td><input type="text" name="question_key" class="regular-text" value="<?php echo esc_attr((string)($edit['question_key'] ?? '')); ?>"> <span class="description">Opcional. Mantém estável para preservar reporting.</span></td></tr>
              <tr><th>Tipo de resposta</th><td><select name="question_type">
                <?php foreach (['select'=>'Lista','radio'=>'Escolha única','text'=>'Texto','textarea'=>'Texto longo','number'=>'Número','checkbox'=>'Checkbox','image_upload'=>'Imagem','file_upload'=>'Ficheiro'] as $k=>$label): ?><option value="<?php echo esc_attr($k); ?>" <?php selected(($edit['question_type'] ?? 'select'), $k); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
              </select></td></tr>
              <tr><th>Opções</th><td><textarea name="options" rows="3" class="large-text" placeholder="Sim&#10;Não&#10;Não aplicável"><?php echo esc_textarea(self::options_text((string)($edit['question_options_json'] ?? ''))); ?></textarea><p class="description">Uma opção por linha, usado em Lista e Escolha única.</p></td></tr>
              <tr><th>Ajuda</th><td><input type="text" name="help_text" class="regular-text" value="<?php echo esc_attr((string)($edit['help_text'] ?? '')); ?>"></td></tr>
              <tr><th>Estado</th><td><label><input type="checkbox" name="is_required" value="1" <?php checked(!empty($edit['is_required'])); ?>> Obrigatória</label> &nbsp; <label><input type="checkbox" name="is_active" value="1" <?php checked(!isset($edit['is_active']) || !empty($edit['is_active'])); ?>> Ativa</label> &nbsp; Prioridade <input type="number" name="priority" value="<?php echo esc_attr((int)($edit['priority'] ?? 100)); ?>" class="small-text"></td></tr>
            </table>
            <p><button class="button button-primary">Guardar pergunta</button></p>
          </form>
        </div>

        <div class="routespro-card" style="margin-top:16px">
          <h2 style="margin-top:0">Importação em massa por loja</h2>
          <p>CSV recomendado: <code>client_id,project_id,form_id,location_id,external_ref,location_name,context_type,question_label,question_type,options,is_required,is_active,priority</code>.</p>
          <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('routespro_context_questions', 'routespro_context_questions_nonce'); ?>
            <input type="hidden" name="routespro_context_questions_action" value="bulk_import">
            <p><input type="file" name="context_questions_csv" accept=".csv,text/csv" required> <button class="button button-secondary">Importar CSV</button></p>
          </form>
        </div>

        <div class="routespro-card" style="margin-top:16px">
          <h2 style="margin-top:0">Regras configuradas</h2>
          <table class="widefat striped"><thead><tr><th>ID</th><th>Cliente</th><th>Campanha</th><th>Loja</th><th>Formulário</th><th>Tipo</th><th>Pergunta</th><th>Resposta</th><th>Estado</th><th></th></tr></thead><tbody>
          <?php if (!$rows): ?><tr><td colspan="9">Sem perguntas configuradas.</td></tr><?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo esc_html((string)$r['id']); ?></td>
              <td><?php echo esc_html($r['client_name'] ?: ((int)$r['client_id'] ? '#' . (int)$r['client_id'] : 'Global')); ?></td>
              <td><?php echo esc_html($r['project_name'] ?: ((int)$r['project_id'] ? '#' . (int)$r['project_id'] : 'Todas')); ?></td>
              <td><?php echo esc_html($r['location_name'] ?: ((int)$r['location_id'] ? '#' . (int)$r['location_id'] : 'Todas')); ?></td>
              <td><?php echo esc_html($r['form_title'] ?: ((int)($r['form_id'] ?? 0) ? '#' . (int)$r['form_id'] : 'Qualquer')); ?></td>
              <td><?php echo esc_html((string)$r['context_type']); ?></td>
              <td><strong><?php echo esc_html((string)$r['question_label']); ?></strong><br><code><?php echo esc_html((string)$r['question_key']); ?></code></td>
              <td><?php echo esc_html((string)$r['question_type']); ?></td>
              <td><?php echo !empty($r['is_active']) ? 'Ativa' : 'Inativa'; ?><?php echo !empty($r['is_required']) ? ', obrigatória' : ''; ?></td>
              <td><a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=routespro-context-questions&edit=' . (int)$r['id'])); ?>">Editar</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Remover esta pergunta?')"><?php wp_nonce_field('routespro_context_questions', 'routespro_context_questions_nonce'); ?><input type="hidden" name="routespro_context_questions_action" value="delete"><input type="hidden" name="id" value="<?php echo esc_attr((int)$r['id']); ?>"><button class="button button-small">Apagar</button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody></table>
        </div>
        <?php
        AdminPage::close();
    }

    private static function save_question(): string {
        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        $label = sanitize_text_field(wp_unslash($_POST['question_label'] ?? ''));
        if ($label === '') return 'Pergunta em falta.';
        $contextType = sanitize_key($_POST['context_type'] ?? 'custom') ?: 'custom';
        $clientId = absint($_POST['client_id'] ?? 0);
        $projectId = absint($_POST['project_id'] ?? 0);
        $formId = absint($_POST['form_id'] ?? 0);
        $rawLocations = isset($_POST['location_ids']) ? (array) wp_unslash($_POST['location_ids']) : [$_POST['location_id'] ?? 0];
        $locationIds = array_values(array_unique(array_map('absint', $rawLocations)));
        if (!$locationIds) $locationIds = [0];
        if (in_array(0, $locationIds, true) && count($locationIds) > 1) {
            $locationIds = array_values(array_filter($locationIds, static fn($v) => (int)$v > 0));
        }
        $baseKey = sanitize_key($_POST['question_key'] ?? '');
        $common = [
            'client_id' => $clientId,
            'project_id' => $projectId,
            'form_id' => $formId,
            'context_type' => $contextType,
            'question_label' => $label,
            'question_type' => sanitize_key($_POST['question_type'] ?? 'select') ?: 'select',
            'question_options_json' => wp_json_encode(self::parse_options(wp_unslash($_POST['options'] ?? '')), JSON_UNESCAPED_UNICODE),
            'help_text' => sanitize_text_field(wp_unslash($_POST['help_text'] ?? '')),
            'is_required' => !empty($_POST['is_required']) ? 1 : 0,
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'priority' => (int)($_POST['priority'] ?? 100),
            'updated_at' => current_time('mysql'),
        ];
        $saved = 0;
        foreach ($locationIds as $locationId) {
            $campaignLocationId = self::campaign_location_id($projectId, $locationId);
            $key = $baseKey;
            if (!$key) {
                $key = ContextQuestionService::make_question_key($contextType, $label, $locationId);
            } elseif (count($locationIds) > 1 && $locationId > 0) {
                $key = sanitize_key($baseKey . '_' . $locationId);
            }
            $row = $common + [
                'location_id' => $locationId,
                'campaign_location_id' => $campaignLocationId,
                'question_key' => $key,
            ];
            if ($id && count($locationIds) === 1) {
                $wpdb->update(ContextQuestionService::table(), $row, ['id' => $id]);
            } else {
                $existing = (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . ContextQuestionService::table() . ' WHERE client_id=%d AND project_id=%d AND location_id=%d AND form_id=%d AND question_key=%s LIMIT 1', $clientId, $projectId, $locationId, $formId, $key));
                if ($existing) {
                    $wpdb->update(ContextQuestionService::table(), $row, ['id' => $existing]);
                } else {
                    $row['created_at'] = current_time('mysql');
                    $wpdb->insert(ContextQuestionService::table(), $row);
                }
            }
            $saved++;
        }
        if ($id && $saved === 1) return 'Pergunta atualizada.';
        return $saved . ' pergunta(s) criada(s) ou atualizada(s).';
    }

    private static function handle_bulk_import(): string {
        if (empty($_FILES['context_questions_csv']['tmp_name'])) return 'Ficheiro CSV em falta.';
        global $wpdb;
        $fh = fopen($_FILES['context_questions_csv']['tmp_name'], 'r');
        if (!$fh) return 'Não foi possível abrir o CSV.';
        $headers = fgetcsv($fh, 0, ',');
        if (!$headers) { fclose($fh); return 'CSV sem cabeçalho.'; }
        $headers = array_map(static fn($h) => sanitize_key((string)$h), $headers);
        $count = 0; $line = 1;
        while (($row = fgetcsv($fh, 0, ',')) !== false) {
            $line++;
            $data = [];
            foreach ($headers as $i => $h) $data[$h] = $row[$i] ?? '';
            $clientId = absint($data['client_id'] ?? 0);
            $projectId = absint($data['project_id'] ?? 0);
            $formId = absint($data['form_id'] ?? 0);
            if (!$formId && !empty($data['form_title'])) $formId = self::resolve_form_id((string)$data['form_title'], $clientId, $projectId);
            $locationId = absint($data['location_id'] ?? 0);
            if (!$locationId) $locationId = self::resolve_location_id($clientId, $projectId, (string)($data['external_ref'] ?? ''), (string)($data['location_name'] ?? ''));
            $label = sanitize_text_field((string)($data['question_label'] ?? ''));
            if (!$label) continue;
            $contextType = sanitize_key((string)($data['context_type'] ?? 'custom')) ?: 'custom';
            $key = sanitize_key((string)($data['question_key'] ?? '')) ?: ContextQuestionService::make_question_key($contextType, $label, $locationId);
            $campaignLocationId = 0;
            if ($projectId && $locationId) {
                $px = $wpdb->prefix . 'routespro_';
                $campaignLocationId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$px}campaign_locations WHERE project_id=%d AND location_id=%d LIMIT 1", $projectId, $locationId));
            }
            $insert = [
                'client_id' => $clientId,
                'project_id' => $projectId,
                'form_id' => $formId,
                'location_id' => $locationId,
                'campaign_location_id' => $campaignLocationId,
                'context_type' => $contextType,
                'question_key' => $key,
                'question_label' => $label,
                'question_type' => sanitize_key((string)($data['question_type'] ?? 'select')) ?: 'select',
                'question_options_json' => wp_json_encode(self::parse_options((string)($data['options'] ?? '')), JSON_UNESCAPED_UNICODE),
                'help_text' => sanitize_text_field((string)($data['help_text'] ?? '')),
                'is_required' => self::truthy($data['is_required'] ?? '0') ? 1 : 0,
                'is_active' => array_key_exists('is_active', $data) ? (self::truthy($data['is_active']) ? 1 : 0) : 1,
                'priority' => isset($data['priority']) && is_numeric($data['priority']) ? (int)$data['priority'] : 100,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ];
            $existing = (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . ContextQuestionService::table() . ' WHERE client_id=%d AND project_id=%d AND location_id=%d AND form_id=%d AND question_key=%s LIMIT 1', $clientId, $projectId, $locationId, $formId, $key));
            if ($existing) {
                unset($insert['created_at']);
                $wpdb->update(ContextQuestionService::table(), $insert, ['id' => $existing]);
            } else {
                $wpdb->insert(ContextQuestionService::table(), $insert);
            }
            $count++;
        }
        fclose($fh);
        return $count . ' pergunta(s) importada(s) ou atualizada(s).';
    }

    private static function form_options(int $clientId = 0, int $projectId = 0): array {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $formsTable = $px . 'forms';
        $bindingsTable = $px . 'form_bindings';
        $rows = [];
        if ($projectId || $clientId) {
            $where = ['b.is_active=1'];
            $args = [];
            if ($projectId) { $where[] = '(b.project_id IS NULL OR b.project_id=0 OR b.project_id=%d)'; $args[] = $projectId; }
            if ($clientId) { $where[] = '(b.client_id IS NULL OR b.client_id=0 OR b.client_id=%d)'; $args[] = $clientId; }
            $sql = "SELECT DISTINCT f.id, f.title, f.status,
                           CONCAT('binding #', b.id) AS binding_label,
                           b.project_id, b.client_id, b.location_id, b.priority
                    FROM {$formsTable} f
                    INNER JOIN {$bindingsTable} b ON b.form_id=f.id
                    WHERE f.status='active' AND " . implode(' AND ', $where) . "
                    ORDER BY b.project_id DESC, b.location_id DESC, b.priority DESC, f.title ASC
                    LIMIT 500";
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
        }
        if (!$rows) {
            $rows = $wpdb->get_results("SELECT id,title,status,'' AS binding_label FROM {$formsTable} WHERE status='active' ORDER BY title ASC LIMIT 500", ARRAY_A) ?: [];
        }
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) continue;
            $seen[$id] = true;
            $out[] = $row;
        }
        return $out;
    }

    private static function resolve_form_id(string $title, int $clientId = 0, int $projectId = 0): int {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $title = trim($title);
        if ($title === '') return 0;
        if (is_numeric($title)) return absint($title);
        $like = $wpdb->esc_like($title);
        return (int)$wpdb->get_var($wpdb->prepare("SELECT f.id FROM {$px}forms f LEFT JOIN {$px}form_bindings b ON b.form_id=f.id WHERE f.status='active' AND f.title LIKE %s AND (%d=0 OR b.client_id=%d OR b.client_id IS NULL OR b.client_id=0) AND (%d=0 OR b.project_id=%d OR b.project_id IS NULL OR b.project_id=0) ORDER BY b.project_id DESC, b.priority DESC, f.id ASC LIMIT 1", $like, $clientId, $clientId, $projectId, $projectId));
    }

    private static function location_options(int $clientId = 0, int $projectId = 0): array {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        if ($projectId) {
            $sql = "SELECT DISTINCT l.id,l.name,l.external_ref,l.client_id
                    FROM {$px}locations l
                    INNER JOIN {$px}campaign_locations cl ON cl.location_id=l.id
                    WHERE cl.project_id=%d";
            $args = [$projectId];
            if ($clientId) { $sql .= " AND l.client_id=%d"; $args[] = $clientId; }
            $sql .= " ORDER BY l.name ASC LIMIT 2000";
            return $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
        }
        if ($clientId) {
            return $wpdb->get_results($wpdb->prepare("SELECT id,name,external_ref,client_id FROM {$px}locations WHERE client_id=%d ORDER BY name ASC LIMIT 2000", $clientId), ARRAY_A) ?: [];
        }
        return $wpdb->get_results("SELECT id,name,external_ref,client_id FROM {$px}locations ORDER BY name ASC LIMIT 2000", ARRAY_A) ?: [];
    }

    private static function campaign_location_id(int $projectId, int $locationId): int {
        if ($projectId <= 0 || $locationId <= 0) return 0;
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        return (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$px}campaign_locations WHERE project_id=%d AND location_id=%d LIMIT 1", $projectId, $locationId));
    }

    private static function resolve_location_id(int $clientId, int $projectId, string $externalRef, string $name): int {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $externalRef = trim($externalRef);
        if ($externalRef !== '') {
            $id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$px}locations WHERE external_ref=%s AND (%d=0 OR client_id=%d) LIMIT 1", $externalRef, $clientId, $clientId));
            if ($id) return $id;
        }
        $name = trim($name);
        if ($name !== '') {
            $like = $wpdb->esc_like($name);
            $id = (int)$wpdb->get_var($wpdb->prepare("SELECT l.id FROM {$px}locations l LEFT JOIN {$px}campaign_locations cl ON cl.location_id=l.id WHERE l.name LIKE %s AND (%d=0 OR l.client_id=%d) AND (%d=0 OR cl.project_id=%d) ORDER BY l.id ASC LIMIT 1", $like, $clientId, $clientId, $projectId, $projectId));
            if ($id) return $id;
        }
        return 0;
    }

    private static function parse_options($raw): array {
        if (is_array($raw)) return array_values(array_filter(array_map('sanitize_text_field', $raw)));
        $raw = str_replace(["\r\n", "\r", '|'], "\n", (string)$raw);
        return array_values(array_filter(array_map(static fn($v) => sanitize_text_field(trim($v)), explode("\n", $raw)), static fn($v) => $v !== ''));
    }

    private static function options_text(string $json): string {
        $d = json_decode($json, true);
        return is_array($d) ? implode("\n", array_map('strval', $d)) : '';
    }

    private static function truthy($value): bool {
        return in_array(strtolower(trim((string)$value)), ['1','yes','sim','true','ativo','active'], true);
    }

    public static function ensure_table(): void {
        global $wpdb;
        $table = ContextQuestionService::table();
        $charset = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            project_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            campaign_location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            form_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            context_type VARCHAR(64) NOT NULL DEFAULT 'custom',
            question_key VARCHAR(191) NOT NULL,
            question_label VARCHAR(255) NOT NULL,
            question_type VARCHAR(32) NOT NULL DEFAULT 'select',
            question_options_json LONGTEXT NULL,
            help_text TEXT NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 100,
            visibility_rules_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY scope_idx (client_id, project_id, location_id),
            KEY campaign_location_idx (campaign_location_id),
            KEY form_idx (form_id),
            KEY active_idx (is_active),
            KEY question_key_idx (question_key)
        ) {$charset}");
        // ensure form_id column
        $hasFormId = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'form_id'));
        if (!$hasFormId) {
            $wpdb->query('ALTER TABLE ' . $table . ' ADD COLUMN form_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER campaign_location_id');
            $wpdb->query('ALTER TABLE ' . $table . ' ADD INDEX form_idx (form_id)');
        }
    }
}
