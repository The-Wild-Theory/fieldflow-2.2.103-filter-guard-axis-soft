<?php
namespace RoutesPro\Support;

if (!defined('ABSPATH')) exit;

class Logger {
    public static function log(string $level, string $message, array $context = [], array $meta = []): void {
        if (!function_exists('sanitize_text_field')) {
            return;
        }
        $level = self::normalizeLevel($level);
        $message = wp_strip_all_tags($message);
        if ($message === '') {
            return;
        }

        if (class_exists('\\RoutesPro\\Repositories\\SystemLogRepository')) {
            \RoutesPro\Repositories\SystemLogRepository::insert([
                'level' => $level,
                'context_key' => sanitize_key((string)($context['context_key'] ?? 'system')),
                'message' => $message,
                'user_id' => isset($context['user_id']) ? absint($context['user_id']) : get_current_user_id(),
                'route_id' => isset($context['route_id']) ? absint($context['route_id']) : 0,
                'client_id' => isset($context['client_id']) ? absint($context['client_id']) : 0,
                'project_id' => isset($context['project_id']) ? absint($context['project_id']) : 0,
                'meta_json' => !empty($meta) ? wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]);
        }
    }

    public static function info(string $message, array $context = [], array $meta = []): void {
        self::log('info', $message, $context, $meta);
    }

    public static function warning(string $message, array $context = [], array $meta = []): void {
        self::log('warning', $message, $context, $meta);
    }

    public static function error(string $message, array $context = [], array $meta = []): void {
        self::log('error', $message, $context, $meta);
    }

    public static function captureThrowable(string $message, \Throwable $e, array $context = []): void {
        self::error($message, $context, [
            'exception' => get_class($e),
            'code' => (string) $e->getCode(),
            'error_message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }

    private static function normalizeLevel(string $level): string {
        $level = strtolower(trim($level));
        return in_array($level, ['debug','info','warning','error'], true) ? $level : 'info';
    }
}
