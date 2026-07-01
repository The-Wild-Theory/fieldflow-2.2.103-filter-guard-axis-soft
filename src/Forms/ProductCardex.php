<?php
namespace RoutesPro\Forms;

if (!defined('ABSPATH')) exit;

class ProductCardex {
    public static function table_cardex(): string { global $wpdb; return $wpdb->prefix . 'routespro_product_cardex'; }
    public static function table_items(): string { global $wpdb; return $wpdb->prefix . 'routespro_product_cardex_items'; }
    public static function table_locations(): string { global $wpdb; return $wpdb->prefix . 'routespro_product_cardex_locations'; }

    public static function install(): void {
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php'; $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table_cardex() . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            insignia VARCHAR(191) NULL,
            client_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            project_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            meta_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY client_idx (client_id), KEY project_idx (project_id), KEY insignia_idx (insignia), KEY status_idx (status)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table_items() . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cardex_id BIGINT UNSIGNED NOT NULL,
            reference VARCHAR(191) NULL,
            product_name VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY cardex_idx (cardex_id), KEY reference_idx (reference), KEY active_idx (is_active)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table_locations() . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cardex_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cardex_location (cardex_id, location_id), KEY cardex_idx (cardex_id), KEY location_idx (location_id)
        ) $charset;");
    }

    public static function list_cardex(): array {
        global $wpdb; self::install();
        return $wpdb->get_results("SELECT * FROM " . self::table_cardex() . " ORDER BY name ASC, id DESC", ARRAY_A) ?: [];
    }

    public static function get_cardex(int $id): ?array {
        if (!$id) return null; global $wpdb; self::install();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table_cardex() . " WHERE id=%d", $id), ARRAY_A);
        return $row ?: null;
    }

    public static function get_items(int $cardex_id): array {
        if (!$cardex_id) return []; global $wpdb; self::install();
        return $wpdb->get_results($wpdb->prepare("SELECT reference AS ref, product_name AS name FROM " . self::table_items() . " WHERE cardex_id=%d AND is_active=1 ORDER BY sort_order ASC, id ASC", $cardex_id), ARRAY_A) ?: [];
    }

    public static function resolve_cardex_id_for_location(int $location_id, int $client_id = 0, int $project_id = 0): int {
        if (!$location_id) return 0; global $wpdb; self::install();
        $linked = (int)$wpdb->get_var($wpdb->prepare("SELECT cardex_id FROM " . self::table_locations() . " WHERE location_id=%d ORDER BY id DESC LIMIT 1", $location_id));
        if ($linked) return $linked;
        $loc = $wpdb->get_row($wpdb->prepare("SELECT name, tags, meta_json, client_id, project_id FROM {$wpdb->prefix}routespro_locations WHERE id=%d", $location_id), ARRAY_A);
        if (!$loc) return 0;
        $hay = strtolower(trim(wp_strip_all_tags(($loc['name'] ?? '') . ' ' . ($loc['tags'] ?? '') . ' ' . ($loc['meta_json'] ?? ''))));
        $rows = self::list_cardex();
        foreach ($rows as $r) {
            if (($r['status'] ?? '') !== 'active') continue;
            if (!empty($r['client_id']) && (int)$r['client_id'] !== (int)($loc['client_id'] ?? $client_id)) continue;
            if (!empty($r['project_id']) && (int)$r['project_id'] !== (int)($loc['project_id'] ?? $project_id)) continue;
            $needle = strtolower(trim((string)($r['insignia'] ?: $r['name'])));
            if ($needle !== '' && strpos($hay, $needle) !== false) return (int)$r['id'];
        }
        return 0;
    }

    public static function question_rows(array $q, array $context = [], $prefill = null): array {
        $source = sanitize_key((string)($q['product_source'] ?? 'manual'));
        $cardex_id = 0;
        if ($source === 'cardex_fixed') $cardex_id = absint($q['cardex_id'] ?? 0);
        if ($source === 'cardex_auto') $cardex_id = self::resolve_cardex_id_for_location(absint($context['location_id'] ?? 0), absint($context['client_id'] ?? 0), absint($context['project_id'] ?? 0));
        if (!$cardex_id && !empty($q['cardex_id'])) $cardex_id = absint($q['cardex_id']);

        $base = [];
        if ($cardex_id) {
            $items = self::get_items($cardex_id);
            foreach ($items as $r) $base[] = ['ref'=>(string)($r['ref'] ?? ''), 'name'=>(string)($r['name'] ?? ''), 'qty'=>''];
        }
        if (!$base) {
            foreach (($q['product_rows'] ?? []) as $r) {
                if (!is_array($r)) continue;
                $base[] = ['ref'=>(string)($r['ref'] ?? $r['reference'] ?? ''), 'name'=>(string)($r['name'] ?? $r['product'] ?? ''), 'qty'=>''];
            }
        }
        return self::merge_prefill_into_rows($base, $prefill);
    }

    private static function merge_prefill_into_rows(array $base, $prefill): array {
        $history = [];
        if (is_array($prefill)) {
            foreach ($prefill as $row) {
                if (!is_array($row)) continue;
                $ref = sanitize_text_field((string)($row['ref'] ?? $row['reference'] ?? ''));
                $name = sanitize_text_field((string)($row['name'] ?? $row['product'] ?? ''));
                $qty = $row['qty'] ?? ($row['after'] ?? ($row['quantity'] ?? ''));
                $before = $row['before'] ?? $qty;
                $after = $row['after'] ?? $qty;
                $identity = self::row_identity($ref, $name);
                if ($identity) $history[$identity] = ['ref'=>$ref, 'name'=>$name, 'qty'=>$qty, 'before'=>$before, 'after'=>$after, 'has_history'=>1];
            }
        }
        $out = [];
        foreach ($base as $row) {
            if (!is_array($row)) continue;
            $ref = sanitize_text_field((string)($row['ref'] ?? $row['reference'] ?? ''));
            $name = sanitize_text_field((string)($row['name'] ?? $row['product'] ?? ''));
            $identity = self::row_identity($ref, $name);
            if ($identity && isset($history[$identity])) {
                $hist = $history[$identity];
                $out[] = ['ref'=>$ref ?: $hist['ref'], 'name'=>$name ?: $hist['name'], 'qty'=>$hist['qty'], 'before'=>$hist['before'], 'after'=>$hist['after'], 'has_history'=>1];
                unset($history[$identity]);
            } else {
                $out[] = ['ref'=>$ref, 'name'=>$name, 'qty'=>'', 'before'=>'', 'after'=>'', 'has_history'=>0];
            }
        }
        foreach ($history as $hist) $out[] = $hist;
        return $out;
    }

    private static function row_identity(string $ref, string $name): string {
        $ref = strtolower(trim($ref));
        $name = strtolower(trim($name));
        if ($ref !== '') return 'ref:' . $ref;
        if ($name !== '') return 'name:' . $name;
        return '';
    }
}
