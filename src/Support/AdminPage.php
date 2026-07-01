<?php
namespace RoutesPro\Support;

use RoutesPro\Admin\Branding;

if (!defined('ABSPATH')) exit;

class AdminPage {
    public static function open(string $title, string $subtitle = '', array $context = []): void {
        echo '<div class="wrap">';
        Branding::render_header($title, $subtitle !== '' ? $subtitle : 'Operação FieldFlow.', $context);
    }

    public static function close(): void {
        echo '</div>';
    }

    public static function notice(string $message, string $type = 'updated'): void {
        echo '<div class="' . esc_attr($type) . ' notice"><p>' . esc_html($message) . '</p></div>';
    }
}
