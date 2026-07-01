<?php
namespace RoutesPro\Support;

if (!defined('ABSPATH')) exit;

class Plugin {
    public static function bootstrapUtf8Runtime(): void {
        if (function_exists('mb_internal_encoding')) {
            @mb_internal_encoding('UTF-8');
            @mb_http_output('UTF-8');
        }
        if (function_exists('ini_set')) {
            @ini_set('default_charset', 'UTF-8');
        }
    }

    public static function maybeActivateOnVersionChange(): void {
        $installed = (string) get_option('routespro_version', '');
        if ($installed !== ROUTESPRO_VERSION) {
            \RoutesPro\Support\Logger::info('Atualização automática do schema iniciada.', ['context_key' => 'upgrade'], ['from' => $installed, 'to' => ROUTESPRO_VERSION]);
            \RoutesPro\Activator::activate();
            \RoutesPro\Support\Logger::info('Atualização automática do schema concluída.', ['context_key' => 'upgrade'], ['version' => ROUTESPRO_VERSION]);
        }
    }

    public static function registerRoutes(): void {
        (new \RoutesPro\Rest\LocationsController())->register_routes();
        (new \RoutesPro\Rest\RoutesController())->register_routes();
        (new \RoutesPro\Rest\EventsController())->register_routes();
        (new \RoutesPro\Rest\OptimizeController())->register_routes();
        (new \RoutesPro\Rest\StatsController())->register_routes();
        (new \RoutesPro\Rest\IntegrationsController())->register_routes();
        (new \RoutesPro\Rest\CategoriesController())->register_routes();
        (new \RoutesPro\Rest\CommercialController())->register_routes();
        (new \RoutesPro\Rest\PlacesController())->register_routes();
    }

    public static function registerWordPressHooks(): void {
        add_action('admin_menu', ['RoutesPro\\Admin\\Menu', 'register']);
        add_action('init', ['RoutesPro\\PWA\\Router', 'register']);
        add_action('init', ['RoutesPro\\PWA\\Assets', 'register']);
        add_action('init', ['RoutesPro\\PWA\\InstallPrompt', 'register']);
        add_action('init', ['RoutesPro\\PWA\\Push', 'register']);
        add_action('init', ['RoutesPro\\Shortcodes', 'register']);
        add_action('init', ['RoutesPro\\Forms\\Forms', 'boot']);
        add_action('admin_init', ['RoutesPro\\Admin\\Forms', 'register_hooks']);
        add_action('admin_init', ['RoutesPro\\Admin\\Routes', 'register_hooks']);
        add_action('admin_init', ['RoutesPro\\Admin\\FormSubmissions', 'register_hooks']);
        add_action('admin_init', ['RoutesPro\\Admin\\FormBindings', 'register_hooks']);
        add_action('admin_init', ['RoutesPro\\Admin\\ProductCardex', 'register_hooks']);
        add_action('admin_init', ['RoutesPro\\Admin\\Branding', 'enqueue_menu_branding']);
        add_action('admin_init', ['RoutesPro\\Support\\LicenseManager', 'maybeValidateOnBootstrap']);
        add_action('plugins_loaded', [self::class, 'maybeActivateOnVersionChange']);
        add_action('plugins_loaded', ['RoutesPro\\Performance\\Module', 'maybe_install']);
        add_action('init', ['RoutesPro\\Performance\\Module', 'register_hooks']);
        add_action('plugins_loaded', ['RoutesPro\\Support\\AdminNotices', 'register']);
        add_action('init', function () {
            load_plugin_textdomain('routes-pro', false, dirname(plugin_basename(FIELDFLOW_PATH . 'fieldflow.php')) . '/languages');
        });
        add_action('rest_api_init', [self::class, 'registerRoutes']);
        add_action('elementor/widgets/register', function($widgets_manager){
            if (class_exists('\\RoutesPro\\Elementor\\Register')) {
                \RoutesPro\Elementor\Register::register_widgets($widgets_manager);
            }
        });
    }
}
