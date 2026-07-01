<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Modo seguro, por omissão não remove dados de negócio.
// Para limpeza total, definir a opção abaixo antes da desinstalação.
if (!defined('FIELDFLOW_DELETE_DATA_ON_UNINSTALL') || !FIELDFLOW_DELETE_DATA_ON_UNINSTALL) {
    delete_option('routespro_version');
    return;
}

global $wpdb;
$tables = [
    'routespro_clients',
    'routespro_projects',
    'routespro_locations',
    'routespro_routes',
    'routespro_route_stops',
    'routespro_events',
    'routespro_assignments',
    'routespro_forms',
    'routespro_form_fields',
    'routespro_form_bindings',
    'routespro_form_submissions',
    'routespro_form_submission_items',
    'routespro_form_history',
    'routespro_categories',
    'routespro_campaign_locations',
    'routespro_email_logs',
    'routespro_route_snapshots',
];
foreach ($tables as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table);
}
$option_names = [
    'routespro_version',
    'routespro_settings',
    'routespro_integrations',
    'routespro_branding',
    'routespro_appearance',
    'routespro_emails',
    'routespro_forms_settings',
];
foreach ($option_names as $option_name) {
    delete_option($option_name);
}
