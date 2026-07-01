<?php
namespace RoutesPro\Support;

if (!defined('ABSPATH')) exit;

class LicenseManager {
    public const OPTION_KEY = 'routespro_license';
    public const VALIDATE_TRANSIENT = 'routespro_license_validate_lock';

    public static function defaults(): array {
        return [
            'key' => '',
            'status' => 'inactive',
            'plan' => 'starter',
            'expires_at' => '',
            'last_checked_at' => '',
            'source' => 'local',
            'customer' => '',
            'domain' => '',
            'domain_fingerprint' => '',
            'activated_at' => '',
            'max_activations' => 1,
            'notes' => '',
            'mode' => 'local',
            'remote_activation_id' => '',
            'remote_license_id' => '',
            'remote_customer_email' => '',
            'remote_last_error' => '',
            'features' => [],
        ];
    }

    public static function all(): array {
        $saved = get_option(self::OPTION_KEY, []);
        $data = wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
        if (!is_array($data['features'])) {
            $data['features'] = [];
        }
        $data['mode'] = self::mode();
        return $data;
    }

    public static function get(string $key = null, $default = null) {
        $data = self::all();
        if ($key === null) return $data;
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    public static function mode(): string {
        $mode = (string) Config::get('license_mode', 'local');
        return $mode === 'remote' ? 'remote' : 'local';
    }

    public static function isRemoteMode(): bool {
        return self::mode() === 'remote';
    }

    public static function remoteReady(): bool {
        return RemoteLicenseClient::isReady();
    }

    public static function normalizeKey(string $key): string {
        return preg_replace('/\s+/', '', strtoupper(trim($key)));
    }

    /**
     * Preserves the exact case of remote license keys and shared-secret-derived keys.
     * Remote licensing may use case-sensitive tokens, so never force upper/lower case here.
     */
    public static function normalizeRemoteKey(string $key): string {
        return preg_replace('/\s+/', '', trim($key));
    }

    public static function currentDomain(): string {
        $home = function_exists('home_url') ? home_url('/') : '';
        $host = (string) wp_parse_url($home, PHP_URL_HOST);
        $host = strtolower(trim($host));
        return preg_replace('/^www\./', '', $host);
    }

    public static function currentFingerprint(): string {
        $domain = self::currentDomain();
        return strtoupper(substr(hash('sha256', $domain . '|' . wp_salt('auth')), 0, 12));
    }

    private static function checksum(string $seed): string {
        return strtoupper(substr(hash('sha256', $seed), 0, 6));
    }

    private static function randomSeed(): string {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes(16));
            } catch (\Throwable $e) {
            }
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            try {
                $strong = false;
                $bytes = openssl_random_pseudo_bytes(16, $strong);
                if ($bytes !== false) {
                    return bin2hex($bytes);
                }
            } catch (\Throwable $e) {
            }
        }
        return uniqid('routespro_', true) . '|' . wp_rand();
    }

    public static function generateLocalKey(string $plan = 'pro', string $customer = '', int $maxActivations = 1): string {
        $plan = strtoupper(substr((string) (preg_replace('/[^A-Z0-9]/', '', $plan) ?: 'PRO'), 0, 8));
        $customer = strtoupper(substr((string) (preg_replace('/[^A-Z0-9]/', '', $customer) ?: 'LOCAL'), 0, 8));
        $nonce = strtoupper(substr(hash('sha256', $plan . '|' . $customer . '|' . microtime(true) . '|' . self::randomSeed()), 0, 10));
        $maxActivations = max(1, (int) $maxActivations);
        $payload = implode('-', ['FF', $plan, $customer, $nonce, str_pad((string) $maxActivations, 2, '0', STR_PAD_LEFT)]);
        return $payload . '-' . self::checksum($payload);
    }

    public static function parseKey(string $key): array {
        $key = self::normalizeKey($key);
        $parts = explode('-', $key);
        if (count($parts) !== 6 || $parts[0] !== 'FF') {
            return ['valid' => false, 'reason' => 'format'];
        }
        [$prefix, $plan, $customer, $nonce, $activationCount, $checksum] = $parts;
        $payload = implode('-', [$prefix, $plan, $customer, $nonce, $activationCount]);
        if (self::checksum($payload) !== $checksum) {
            return ['valid' => false, 'reason' => 'checksum'];
        }
        return [
            'valid' => true,
            'plan' => strtolower($plan),
            'customer' => $customer,
            'nonce' => $nonce,
            'max_activations' => max(1, (int) ltrim($activationCount, '0') ?: 1),
        ];
    }

    public static function update(array $payload): array {
        $current = self::all();
        $next = wp_parse_args($payload, $current);
        if (isset($next['key'])) {
            $next['key'] = self::isRemoteMode()
                ? self::normalizeRemoteKey((string) $next['key'])
                : self::normalizeKey((string) $next['key']);
        }
        if (isset($next['status'])) {
            $allowed = ['inactive', 'trial', 'active', 'expired', 'invalid', 'revoked'];
            $next['status'] = in_array($next['status'], $allowed, true) ? $next['status'] : 'inactive';
        }
        if (isset($next['max_activations'])) {
            $next['max_activations'] = max(1, (int) $next['max_activations']);
        }
        if (!isset($next['mode'])) {
            $next['mode'] = self::mode();
        }
        if (!isset($next['features']) || !is_array($next['features'])) {
            $next['features'] = [];
        }
        update_option(self::OPTION_KEY, $next);
        return $next;
    }

    public static function deactivate(): array {
        if (self::isRemoteMode() && self::isActive() && self::remoteReady()) {
            $remote = self::deactivateRemote();
            if (!empty($remote['success'])) {
                return $remote['license'];
            }
        }
        return self::update([
            'status' => 'inactive',
            'domain' => '',
            'domain_fingerprint' => '',
            'activated_at' => '',
            'remote_activation_id' => '',
            'last_checked_at' => current_time('mysql'),
            'notes' => 'Licença desativada manualmente.',
        ]);
    }

    public static function activate(string $licenseKey, string $plan = 'pro'): array {
        return self::isRemoteMode()
            ? self::activateRemote($licenseKey, $plan)
            : self::activateLocal($licenseKey, $plan);
    }

    public static function activateLocal(string $licenseKey, string $plan = 'pro'): array {
        $licenseKey = self::normalizeKey($licenseKey);
        if ($licenseKey === '') {
            return self::update([
                'key' => '',
                'status' => 'inactive',
                'plan' => $plan ?: 'starter',
                'last_checked_at' => current_time('mysql'),
                'notes' => 'Sem chave introduzida.',
            ]);
        }

        $parsed = self::parseKey($licenseKey);
        $domain = self::currentDomain();
        $fingerprint = self::currentFingerprint();

        if (!empty($parsed['valid'])) {
            return self::update([
                'key' => $licenseKey,
                'status' => 'active',
                'plan' => $parsed['plan'] ?: ($plan ?: 'pro'),
                'customer' => $parsed['customer'] ?? '',
                'max_activations' => $parsed['max_activations'] ?? 1,
                'domain' => $domain,
                'domain_fingerprint' => $fingerprint,
                'activated_at' => current_time('mysql'),
                'last_checked_at' => current_time('mysql'),
                'source' => 'local_generated',
                'features' => [],
                'remote_last_error' => '',
                'notes' => 'Licença local validada e ligada ao domínio atual.',
            ]);
        }

        return self::update([
            'key' => $licenseKey,
            'status' => 'active',
            'plan' => $plan ?: 'pro',
            'domain' => $domain,
            'domain_fingerprint' => $fingerprint,
            'activated_at' => current_time('mysql'),
            'last_checked_at' => current_time('mysql'),
            'source' => 'local_manual',
            'features' => [],
            'remote_last_error' => '',
            'notes' => 'Chave manual aceite em modo local. Recomendado migrar para chave gerada.',
        ]);
    }

    public static function activateRemote(string $licenseKey, string $plan = 'pro'): array {
        $licenseKey = self::normalizeRemoteKey($licenseKey);
        if ($licenseKey === '') {
            return self::update([
                'key' => '',
                'status' => 'inactive',
                'plan' => $plan ?: 'starter',
                'last_checked_at' => current_time('mysql'),
                'remote_last_error' => 'Sem chave introduzida.',
                'notes' => 'Sem chave introduzida.',
            ]);
        }
        if (!self::remoteReady()) {
            return self::activateByLocalFallback($licenseKey, $plan, 'API remota por configurar.', 'remote_activate_local_fallback');
        }

        $response = RemoteLicenseClient::post('/license/activate', array_merge(RemoteLicenseClient::siteContext(), [
            'license_key' => $licenseKey,
            'requested_plan' => $plan,
        ]));

        if (empty($response['success'])) {
            $message = (string) ($response['message'] ?? 'Ativação remota indisponível.');
            $httpCode = (int) ($response['http_code'] ?? 0);
            $softFailure = $httpCode >= 500
                || stripos($message, 'internal server error') !== false
                || stripos($message, 'resposta invalida') !== false
                || stripos($message, 'resposta inválida') !== false
                || stripos($message, 'unable to cast') !== false
                || stripos($message, 'invalidcastexception') !== false;
            if ($softFailure) {
                return self::activateByLocalFallback($licenseKey, $plan, $message, 'remote_activate_local_fallback');
            }
        }

        return self::hydrateFromRemote($response, $licenseKey, $plan, 'remote_activate');
    }

    public static function validateCurrent(bool $force = false): array {
        if (!self::isRemoteMode()) {
            return self::all();
        }
        $licenseKey = self::normalizeRemoteKey((string) self::get('key', ''));
        if ($licenseKey === '') {
            return self::all();
        }
        return self::activateByLocalFallback($licenseKey, (string) self::get('plan', 'fieldflow-pro'), '', 'remote_validate_disabled');
    }

    public static function maybeValidateOnBootstrap(): void {
        if (!self::isRemoteMode() || !self::remoteReady()) return;
        if (!self::isActive()) return;
        self::validateCurrent(false);
    }

    private static function activateByLocalFallback(string $licenseKey, string $plan = 'fieldflow-pro', string $remoteMessage = '', string $source = 'remote_local_fallback'): array {
        $current = self::all();
        $domain = self::currentDomain();
        $fingerprint = self::currentFingerprint();
        $activatedAt = (string) ($current['activated_at'] ?? '');
        if ($activatedAt === '') {
            $activatedAt = current_time('mysql');
        }
        return self::update([
            'key' => self::normalizeRemoteKey($licenseKey),
            'status' => 'active',
            'plan' => $plan ?: ((string) ($current['plan'] ?? 'fieldflow-pro') ?: 'fieldflow-pro'),
            'customer' => (string) ($current['customer'] ?? 'LOCAL'),
            'max_activations' => (int) ($current['max_activations'] ?? 1),
            'domain' => $domain,
            'domain_fingerprint' => $fingerprint,
            'activated_at' => $activatedAt,
            'last_checked_at' => current_time('mysql'),
            'source' => $source,
            'remote_last_error' => '',
            'features' => is_array($current['features'] ?? null) ? $current['features'] : [],
            'notes' => $remoteMessage !== ''
                ? 'Licença mantida ativa. Validação Azure temporariamente ignorada por erro remoto.'
                : 'Licença ativa por fallback local enquanto a validação Azure é corrigida.',
        ]);
    }

    public static function generateRequested(string $plan = 'pro', string $customer = '', int $maxActivations = 1, string $expiresAt = ''): array {
        if (self::isRemoteMode()) {
            return self::generateRemote($plan, $customer, $maxActivations, $expiresAt);
        }
        return ['success' => true, 'key' => self::generateLocalKey($plan, $customer, $maxActivations)];
    }

    public static function generateRemote(string $plan = 'pro', string $customer = '', int $maxActivations = 1, string $expiresAt = ''): array {
        if (!self::remoteReady()) {
            return ['success' => false, 'message' => 'API remota por configurar.'];
        }
        $customerCode = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $customer ?: 'LOCAL'), 0, 32));
        return RemoteLicenseClient::post('/license/generate', [
            'plan' => $plan,
            'customer' => $customerCode,
            'customer_code' => $customerCode,
            'max_activations' => max(1, $maxActivations),
            'expires_at' => $expiresAt,
            'site_context' => RemoteLicenseClient::siteContext(),
        ], true);
    }

    public static function deactivateRemote(): array {
        if (!self::remoteReady()) {
            return ['success' => false, 'message' => 'API remota por configurar.'];
        }
        $response = RemoteLicenseClient::post('/license/deactivate', array_merge(RemoteLicenseClient::siteContext(), [
            'license_key' => (string) self::get('key', ''),
            'activation_id' => (string) self::get('remote_activation_id', ''),
        ]));
        if (!empty($response['success'])) {
            return [
                'success' => true,
                'license' => self::update([
                    'status' => 'inactive',
                    'domain' => '',
                    'domain_fingerprint' => '',
                    'activated_at' => '',
                    'remote_activation_id' => '',
                    'last_checked_at' => current_time('mysql'),
                    'remote_last_error' => '',
                    'notes' => (string) ($response['message'] ?? 'Licença remota desativada.'),
                ]),
            ];
        }
        return ['success' => false, 'message' => (string) ($response['message'] ?? 'Falha ao desativar licença remota.')];
    }

    private static function shouldValidateNow(): bool {
        $intervalHours = max(1, (int) Config::get('license_remote_validate_interval', 12));
        $last = (string) self::get('last_checked_at', '');
        if ($last === '') return true;
        $ts = strtotime($last);
        if (!$ts) return true;
        return (time() - $ts) >= ($intervalHours * HOUR_IN_SECONDS);
    }

    private static function hydrateFromRemote(array $response, string $licenseKey, string $fallbackPlan, string $source): array {
        $remoteLicense = is_array($response['license'] ?? null) ? $response['license'] : [];
        $ok = !empty($response['success']);
        $httpCode = (int) ($response['http_code'] ?? 0);
        $message = (string) ($response['message'] ?? '');
        $current = self::all();
        $currentlyActive = ((string) ($current['status'] ?? 'inactive')) === 'active';
        $isValidateError = $source === 'remote_validate' && (!$ok) && ($httpCode >= 500 || stripos($message, 'internal server error') !== false || stripos($message, 'resposta invalida') !== false || stripos($message, 'resposta inválida') !== false);
        $isAlreadyActivated = (!$ok) && (stripos($message, 'limite de ativa') !== false || stripos($message, 'activation limit') !== false || stripos($message, 'already activated') !== false);

        if ($isAlreadyActivated && in_array($source, ['remote_activate', 'remote_validate'], true)) {
            $ok = true;
            if (empty($remoteLicense)) {
                $remoteLicense = [];
            }
            $remoteLicense['status'] = 'active';
            if ($message === '') {
                $message = 'Licença já ativada neste ambiente.';
            }
        }

        if ((!$ok) && ($currentlyActive || $source === 'remote_validate')) {
            $payload = [
                'key' => $licenseKey,
                'status' => 'active',
                'plan' => (string) ($current['plan'] ?? ($fallbackPlan ?: 'starter')),
                'customer' => (string) ($current['customer'] ?? ''),
                'max_activations' => (int) ($current['max_activations'] ?? 1),
                'domain' => (string) ($current['domain'] ?? self::currentDomain()),
                'domain_fingerprint' => (string) ($current['domain_fingerprint'] ?? self::currentFingerprint()),
                'activated_at' => (string) ($current['activated_at'] ?? ''),
                'expires_at' => (string) ($current['expires_at'] ?? ''),
                'last_checked_at' => current_time('mysql'),
                'source' => 'remote_validate_unavailable',
                'remote_license_id' => (string) ($current['remote_license_id'] ?? ''),
                'remote_activation_id' => (string) ($current['remote_activation_id'] ?? ''),
                'remote_customer_email' => (string) ($current['remote_customer_email'] ?? ''),
                'remote_last_error' => $message !== '' ? $message : 'Endpoint de validação indisponível.',
                'features' => is_array($current['features'] ?? null) ? $current['features'] : [],
                'notes' => 'Validação remota indisponível. Mantida última ativação válida.',
            ];
            $updated = self::update($payload);
            if (class_exists('\RoutesPro\Support\Logger')) {
                Logger::error('Validação remota indisponível. Licença ativa preservada.', ['context_key' => 'license'], ['message' => $payload['remote_last_error'], 'http_code' => (string) $httpCode]);
            }
            return $updated;
        }

        $status = strtolower((string) ($remoteLicense['status'] ?? ($ok ? 'active' : 'invalid')));
        $features = is_array($remoteLicense['features'] ?? null) ? $remoteLicense['features'] : [];

        $payload = [
            'key' => $licenseKey,
            'status' => $status,
            'plan' => (string) ($remoteLicense['plan'] ?? ($fallbackPlan ?: 'starter')),
            'customer' => (string) ($remoteLicense['customer'] ?? self::get('customer', '')),
            'max_activations' => (int) ($remoteLicense['max_activations'] ?? self::get('max_activations', 1)),
            'domain' => (string) ($remoteLicense['domain'] ?? self::currentDomain()),
            'domain_fingerprint' => (string) ($remoteLicense['fingerprint'] ?? self::currentFingerprint()),
            'activated_at' => (string) ($remoteLicense['activated_at'] ?? ($ok ? (self::get('activated_at', '') ?: current_time('mysql')) : '')),
            'expires_at' => (string) ($remoteLicense['expires_at'] ?? self::get('expires_at', '')),
            'last_checked_at' => current_time('mysql'),
            'source' => $source,
            'remote_license_id' => (string) ($remoteLicense['license_id'] ?? self::get('remote_license_id', '')),
            'remote_activation_id' => (string) ($remoteLicense['activation_id'] ?? self::get('remote_activation_id', '')),
            'remote_customer_email' => (string) ($remoteLicense['customer_email'] ?? self::get('remote_customer_email', '')),
            'remote_last_error' => $ok ? '' : ($message !== '' ? $message : 'Falha remota.'),
            'features' => $features,
            'notes' => $message !== '' ? $message : ($ok ? 'Licença remota validada.' : 'Falha na validação remota.'),
        ];

        $updated = self::update($payload);
        if (class_exists('\\RoutesPro\\Support\\Logger')) {
            if ($ok) {
                Logger::info('Licença remota sincronizada.', ['context_key' => 'license'], ['status' => $payload['status'], 'plan' => $payload['plan']]);
            } else {
                Logger::error('Licença remota falhou.', ['context_key' => 'license'], ['message' => $payload['remote_last_error'], 'http_code' => (string) ($response['http_code'] ?? '')]);
            }
        }
        return $updated;
    }

    public static function isActive(): bool {
        return self::get('status', 'inactive') === 'active';
    }

    public static function isBoundToCurrentDomain(): bool {
        if (!self::isActive()) return false;
        $savedDomain = (string) self::get('domain', '');
        $savedFingerprint = (string) self::get('domain_fingerprint', '');
        if ($savedDomain === '' && $savedFingerprint === '') return true;
        return $savedDomain === self::currentDomain() && $savedFingerprint === self::currentFingerprint();
    }

    public static function statusLabel(): string {
        $status = (string) self::get('status', 'inactive');
        switch ($status) {
            case 'active': return 'Ativa';
            case 'trial': return 'Trial';
            case 'expired': return 'Expirada';
            case 'invalid': return 'Inválida';
            case 'revoked': return 'Revogada';
            default: return 'Inativa';
        }
    }

    public static function maskedKey(): string {
        $key = (string) self::get('key', '');
        if ($key === '') return '';
        if (strlen($key) <= 8) return str_repeat('*', max(0, strlen($key) - 4)) . substr($key, -4);
        return substr($key, 0, 6) . str_repeat('*', max(0, strlen($key) - 10)) . substr($key, -4);
    }

    public static function summary(): array {
        return [
            'status_label' => self::statusLabel(),
            'domain' => self::currentDomain(),
            'fingerprint' => self::currentFingerprint(),
            'bound_ok' => self::isBoundToCurrentDomain(),
            'masked_key' => self::maskedKey(),
            'mode' => self::mode(),
            'remote_ready' => self::remoteReady(),
            'remote_last_error' => (string) self::get('remote_last_error', ''),
        ];
    }
}
