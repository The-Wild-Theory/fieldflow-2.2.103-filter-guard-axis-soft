<?php
namespace RoutesPro\Support;

use RoutesPro\Repositories\SchemaRepository;
use RoutesPro\Repositories\SystemLogRepository;
use RoutesPro\Support\LicenseManager;

if (!defined('ABSPATH')) exit;

class SystemHealth {
    public static function status(): array {
        global $wp_version;
        $upload = wp_upload_dir();
        $opts = Config::all();
        $missing_tables = SchemaRepository::missingTables();
        $plugin_root = trailingslashit(ROUTESPRO_PATH);
        $backup_matches = [];
        $missing_runtime_files = class_exists('\RoutesPro\Support\Loader') ? Loader::missingFiles() : [];
        $release_files = ['readme.txt', 'uninstall.php', 'CHANGELOG.md', 'languages/routes-pro.pot', 'DOCUMENTACAO-UTILIZADOR.md', 'DOCUMENTACAO-TECNICA.md'];
        $missing_release_files = [];
        foreach ($release_files as $release_file) {
            if (!file_exists($plugin_root . $release_file)) {
                $missing_release_files[] = $release_file;
            }
        }
        foreach (['*.bak', '*.bak_*', '*.before*'] as $pattern) {
            $found = glob($plugin_root . 'src/Admin/' . $pattern) ?: [];
            foreach ($found as $file) {
                $backup_matches[] = wp_basename($file);
            }
        }

        $checks = [
            [
                'key' => 'php',
                'label' => 'PHP',
                'status' => version_compare(PHP_VERSION, '8.0', '>=') ? 'ok' : 'warning',
                'detail' => 'Versão atual: ' . PHP_VERSION . '. Recomendado 8.1 ou superior.',
            ],
            [
                'key' => 'wordpress',
                'label' => 'WordPress',
                'status' => version_compare((string) $wp_version, '6.4', '>=') ? 'ok' : 'warning',
                'detail' => 'Versão atual: ' . $wp_version . '. Recomendado 6.4 ou superior.',
            ],
            [
                'key' => 'uploads',
                'label' => 'Uploads',
                'status' => !empty($upload['basedir']) && wp_is_writable($upload['basedir']) ? 'ok' : 'critical',
                'detail' => !empty($upload['basedir']) ? 'Diretório: ' . $upload['basedir'] : 'Sem diretório de uploads resolvido.',
            ],
            [
                'key' => 'schema',
                'label' => 'Base de dados',
                'status' => empty($missing_tables) ? 'ok' : 'critical',
                'detail' => empty($missing_tables) ? 'Todas as tabelas nucleares existem.' : 'Tabelas em falta: ' . implode(', ', $missing_tables),
            ],
            [
                'key' => 'maps',
                'label' => 'Mapas',
                'status' => self::mapsStatus($opts),
                'detail' => 'Provider ativo: ' . ($opts['maps_provider'] ?? 'leaflet') . '.',
            ],
            [
                'key' => 'ai',
                'label' => 'IA',
                'status' => self::aiStatus($opts),
                'detail' => 'Provider ativo: ' . ($opts['ai_provider'] ?? 'none') . '.',
            ],
            [
                'key' => 'license',
                'label' => 'Licenciamento',
                'status' => LicenseManager::isRemoteMode() ? (LicenseManager::remoteReady() ? (LicenseManager::isActive() ? (LicenseManager::isBoundToCurrentDomain() ? 'ok' : 'critical') : 'warning') : 'critical') : (LicenseManager::isActive() ? (LicenseManager::isBoundToCurrentDomain() ? 'ok' : 'critical') : 'warning'),
                'detail' => 'Modo: ' . LicenseManager::mode() . '. Estado atual: ' . LicenseManager::statusLabel() . '. Domínio guardado: ' . (LicenseManager::get('domain', '') ?: 'por ligar') . '.',
            ],
            [
                'key' => 'logging',
                'label' => 'Logging técnico',
                'status' => SystemLogRepository::exists() ? 'ok' : 'warning',
                'detail' => SystemLogRepository::exists() ? 'Tabela de logs pronta para troubleshooting.' : 'Tabela de logs técnicos ainda não existe.',
            ],
            [
                'key' => 'distribution',
                'label' => 'Distribuição',
                'status' => empty($backup_matches) ? 'ok' : 'warning',
                'detail' => empty($backup_matches) ? 'Sem ficheiros temporários ou backups no pacote.' : 'Ficheiros a remover: ' . implode(', ', array_unique($backup_matches)),
            ],
            [
                'key' => 'runtime_files',
                'label' => 'Bootstrap interno',
                'status' => empty($missing_runtime_files) ? 'ok' : 'critical',
                'detail' => empty($missing_runtime_files) ? 'Todos os ficheiros obrigatórios do runtime existem.' : 'Ficheiros em falta: ' . implode(', ', $missing_runtime_files),
            ],
            [
                'key' => 'release_files',
                'label' => 'Pacote comercial',
                'status' => empty($missing_release_files) ? 'ok' : 'warning',
                'detail' => empty($missing_release_files) ? 'Documentação mínima de release presente.' : 'Faltam ficheiros: ' . implode(', ', $missing_release_files),
            ],
        ];

        $summary = 'ok';
        foreach ($checks as $check) {
            if ($check['status'] === 'critical') {
                $summary = 'critical';
                break;
            }
            if ($check['status'] === 'warning') {
                $summary = 'warning';
            }
        }

        return [
            'summary' => $summary,
            'checks' => $checks,
            'counts' => [
                'clients' => SchemaRepository::tableRowCount('routespro_clients'),
                'projects' => SchemaRepository::tableRowCount('routespro_projects'),
                'routes' => SchemaRepository::tableRowCount('routespro_routes'),
                'locations' => SchemaRepository::tableRowCount('routespro_locations'),
                'forms' => SchemaRepository::tableRowCount('routespro_forms'),
                'submissions' => SchemaRepository::tableRowCount('routespro_form_submissions'),
                'system_logs' => class_exists('\RoutesPro\Repositories\SystemLogRepository') ? SystemLogRepository::count() : 0,
                'missing_runtime_files' => count($missing_runtime_files),
                'missing_release_files' => count($missing_release_files),
                'license_active' => LicenseManager::isActive() ? 1 : 0,
                'license_domain_match' => LicenseManager::isBoundToCurrentDomain() ? 1 : 0,
            ],
            'constants' => self::constantSources(),
        ];
    }

    private static function mapsStatus(array $opts): string {
        $provider = (string) ($opts['maps_provider'] ?? 'leaflet');
        if ($provider === 'leaflet') return 'ok';
        if ($provider === 'google') return Config::providerReady('google_maps', $opts) ? 'ok' : 'warning';
        if ($provider === 'azure') return Config::providerReady('azure_maps', $opts) ? 'ok' : 'warning';
        return 'warning';
    }

    private static function aiStatus(array $opts): string {
        $provider = (string) ($opts['ai_provider'] ?? 'none');
        if ($provider === 'none') return 'warning';
        if ($provider === 'google') return Config::providerReady('google_ai', $opts) ? 'ok' : 'warning';
        if ($provider === 'azure') return Config::providerReady('azure_ai', $opts) ? 'ok' : 'warning';
        if ($provider === 'openai') return Config::providerReady('openai', $opts) ? 'ok' : 'warning';
        if ($provider === 'copilot') return Config::providerReady('copilot', $opts) ? 'ok' : 'warning';
        return 'warning';
    }

    private static function constantSources(): array {
        $out = [];
        foreach (Config::secretConstants() as $key => $constant) {
            $out[$key] = defined($constant) ? 'wp-config.php' : 'base de dados';
        }
        return $out;
    }
}
