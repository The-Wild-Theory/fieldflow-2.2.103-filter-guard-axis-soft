<?php
/**
 * Plugin Name: FieldFlow
 * Description: Plataforma profissional de rotas, equipas de terreno e reporting para merchandisers, promotores, equipas comerciais e clientes.
 * Version: 2.2.103
 * Author: The Wild Theory
 * Text Domain: routes-pro
 */
if (!defined('ABSPATH')) exit;

define('FIELDFLOW_VERSION', '2.2.102');
define('FIELDFLOW_PATH', plugin_dir_path(__FILE__));
define('FIELDFLOW_URL', plugin_dir_url(__FILE__));

// Compatibilidade retroativa para instalações existentes e código legado.
if (!defined('ROUTESPRO_VERSION')) define('ROUTESPRO_VERSION', FIELDFLOW_VERSION);
if (!defined('ROUTESPRO_PATH')) define('ROUTESPRO_PATH', FIELDFLOW_PATH);
if (!defined('ROUTESPRO_URL')) define('ROUTESPRO_URL', FIELDFLOW_URL);

require_once ROUTESPRO_PATH . 'src/Support/Loader.php';
\RoutesPro\Support\Loader::requireAll();

register_activation_hook(__FILE__, ['RoutesPro\Activator','activate']);

\RoutesPro\Support\Plugin::bootstrapUtf8Runtime();
\RoutesPro\Support\Plugin::registerWordPressHooks();


add_action('admin_post_routespro_download_commercial_template', function(){
    if (!current_user_can('routespro_manage')) wp_die('Sem permissões.');
    $headers = ['name','address','district','county','city','parish','postal_code','country','category','subcategory','contact_person','phone','email','website','lat','lng','external_ref','place_id','source'];
    $sample = ['Exemplo PDV','Rua Exemplo 123','Lisboa','Lisboa','Lisboa','','1000-100','Portugal','Horeca','Restaurante','Joao Silva','910000000','geral@example.com','https://example.com','38.7223','-9.1393','REF-001','','csv'];
    $csv = implode(',', $headers) . "
" . implode(',', array_map(function($v){ return '"' . str_replace('"', '""', $v) . '"'; }, $sample)) . "
";
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="routespro-commercial-template.csv"');
    echo $csv;
    exit;
});


add_action('admin_post_routespro_export_commercial_existing', function(){
    if (!current_user_can('routespro_manage')) wp_die('Forbidden');
    global $wpdb; $px = $wpdb->prefix . 'routespro_';
    $client_id = absint($_GET['client_id'] ?? 0);
    $project_id = absint($_GET['project_id'] ?? 0);
    $sql = "SELECT DISTINCT l.*, c.name AS category_name, sc.name AS subcategory_name, cl.assigned_to AS owner_user_id, owner.display_name AS owner_name FROM {$px}locations l LEFT JOIN {$px}categories c ON c.id=l.category_id LEFT JOIN {$px}categories sc ON sc.id=l.subcategory_id LEFT JOIN {$px}campaign_locations cl ON cl.location_id=l.id LEFT JOIN {$wpdb->users} owner ON owner.ID=cl.assigned_to LEFT JOIN {$px}projects p ON p.id=cl.project_id WHERE 1=1";
    $args = [];
    if ($project_id) { $sql .= " AND cl.project_id=%d"; $args[] = $project_id; } elseif ($client_id) { $sql .= " AND p.client_id=%d"; $args[] = $client_id; }
    $sql .= " ORDER BY l.id DESC";
    $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : ($wpdb->get_results($sql, ARRAY_A) ?: []);
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="routespro-commercial-existing.csv"');
    $out = fopen('php://output', 'w');
    $headers = ['id','name','address','district','county','city','parish','postal_code','country','category','subcategory','contact_person','phone','email','website','lat','lng','external_ref','place_id','source','owner_user_id','owner_nome','is_active'];
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '', $r['name'] ?? '', $r['address'] ?? '', $r['district'] ?? '', $r['county'] ?? '', $r['city'] ?? '', $r['parish'] ?? '', $r['postal_code'] ?? '', $r['country'] ?? '',
            $r['category_name'] ?? '', $r['subcategory_name'] ?? '', $r['contact_person'] ?? '', $r['phone'] ?? '', $r['email'] ?? '', $r['website'] ?? '', $r['lat'] ?? '', $r['lng'] ?? '',
            $r['external_ref'] ?? '', $r['place_id'] ?? '', $r['source'] ?? '', $r['owner_user_id'] ?? '', $r['owner_name'] ?? '', $r['is_active'] ?? 1
        ]);
    }
    fclose($out); exit;
});


function fieldflow_public_search_permission() {
    $rate_key = 'fieldflow_public_search_' . md5((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $hits = (int) get_transient($rate_key);
    if ($hits >= 60) {
        return new \WP_Error('fieldflow_rate_limited', 'Demasiados pedidos. Tenta novamente dentro de momentos.', ['status' => 429]);
    }
    set_transient($rate_key, $hits + 1, MINUTE_IN_SECONDS);
    return true;
}

// FieldFlow autocomplete endpoint
add_action('rest_api_init', function () {
    register_rest_route('fieldflow/v1', '/pdvs-search', [
        'methods' => 'GET',
        'callback' => function($request){
            global $wpdb;
            $q = sanitize_text_field((string) $request->get_param('q'));
            $q = trim($q);
            if (function_exists('mb_strlen') ? mb_strlen($q) < 2 : strlen($q) < 2) {
                return new \WP_Error('fieldflow_query_too_short', 'Pesquisa demasiado curta.', ['status' => 400]);
            }

            $table = $wpdb->prefix . 'ff_pdvs';
            $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($table_exists !== $table) {
                return [];
            }

            $like = '%' . $wpdb->esc_like($q) . '%';
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, nome, cidade FROM {$table} WHERE nome LIKE %s ORDER BY nome ASC LIMIT 10",
                    $like
                ),
                ARRAY_A
            );

            return is_array($results) ? $results : [];
        },
        'permission_callback' => 'fieldflow_public_search_permission'
    ]);
});


// enqueue autocomplete
add_action('wp_enqueue_scripts', function(){
    wp_enqueue_script('ff-autocomplete', plugin_dir_url(__FILE__).'assets/ff-autocomplete.js', [], '1.0', true);
});
