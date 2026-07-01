<?php
namespace RoutesPro\Support;

if (!defined('ABSPATH')) exit;

class Loader {
    public static function requireAll(): void {
        foreach (self::files() as $relativePath) {
            self::requireFile($relativePath);
        }
    }

    public static function files(): array {
        return [
            'src/Activator.php',
            'src/Support/GeoPT.php',
            'src/Support/AssignmentMatrix.php',
            'src/Support/AssignmentResolver.php',
            'src/Support/Permissions.php',
            'src/Support/Config.php',
            'src/Support/Request.php',
            'src/Support/AdminPage.php',
            'src/Support/SystemHealth.php',
            'src/Support/LicenseManager.php',
            'src/Support/RemoteLicenseClient.php',
            'src/Support/Features.php',
            'src/Support/AdminNotices.php',
            'src/Support/Logger.php',
            'src/Support/TollEstimator.php',
            'src/Services/GoogleRoutes.php',
            'src/Services/RouteMetricsService.php',
            'src/Support/Migrations.php',
            'src/Support/Loader.php',
            'src/Support/Plugin.php',
            'src/PWA/Settings.php',
            'src/PWA/ManifestController.php',
            'src/PWA/ServiceWorkerController.php',
            'src/PWA/Router.php',
            'src/PWA/Assets.php',
            'src/PWA/InstallPrompt.php',
            'src/PWA/Push.php',
            'src/Services/Planning/RouteCalculator.php',
            'src/Services/Planning/VisitRuleResolver.php',
            'src/Services/Planning/PlanQualityScorer.php',
            'src/Admin/Branding.php',
            'src/Admin/PWASettingsPage.php',
            'src/Admin/ContextQuestions.php',
            'src/Admin/ProductCardex.php',
            'src/Admin/Menu.php',
            'src/Admin/Clients.php',
            'src/Admin/Projects.php',
            'src/Admin/Integrations.php',
            'src/Admin/Routes.php',
            'src/Admin/Assignments.php',
            'src/Admin/Settings.php',
            'src/Admin/SystemHealth.php',
            'src/Admin/SystemLogs.php',
            'src/Admin/ajax.php',
            'src/Admin/Emails.php',
            'src/Performance/Module.php',
            'src/Admin/Performance.php',
            'src/Admin/Appearance.php',
            'src/Admin/CampaignBranding.php',
            'src/Admin/Commercial.php',
            'src/Admin/Categories.php',
            'src/Admin/CampaignLocations.php',
            'src/Admin/Forms.php',
            'src/Admin/FormSubmissions.php',
            'src/Admin/FormBindings.php',
            'src/Admin/FormHistory.php',
            'src/Forms/SubmissionContext.php',
            'src/Forms/RecordService.php',
            'src/Forms/ContextQuestions.php',
            'src/Forms/ProductCardex.php',
            'src/Forms/FormRenderer.php',
            'src/Forms/BindingResolver.php',
            'src/Forms/Forms.php',
            'src/Import/CSVImporter.php',
            'src/Rest/RoutesController.php',
            'src/Rest/LocationsController.php',
            'src/Rest/EventsController.php',
            'src/Rest/OptimizeController.php',
            'src/Rest/StatsController.php',
            'src/Rest/IntegrationsController.php',
            'src/Rest/CategoriesController.php',
            'src/Rest/CommercialController.php',
            'src/Rest/PlacesController.php',
            'src/Services/Maps.php',
            'src/Services/AI.php',
            'src/Services/Notify.php',
            'src/Services/RouteSnapshotService.php',
            'src/Services/LocationDeduplicator.php',
            'src/Services/IntegrationPlatform.php',
            'src/Repositories/SchemaRepository.php',
            'src/Repositories/SystemLogRepository.php',
            'src/Repositories/RouteAccessRepository.php',
            'src/Repositories/CampaignLocationRepository.php',
            'src/Shortcodes.php',
            'src/Front/AppShortcodeRenderer.php',
            'src/Front/TeamInboxShortcodeRenderer.php',
            'src/Front/ClientPortalShortcodeRenderer.php',
            'src/Front/PerformanceShortcodeRenderer.php',
            'src/Elementor/Register.php',
        ];
    }

    public static function missingFiles(): array {
        $missing = [];
        foreach (self::files() as $relativePath) {
            $absolutePath = trailingslashit(ROUTESPRO_PATH) . ltrim($relativePath, '/');
            if (!file_exists($absolutePath)) {
                $missing[] = $relativePath;
            }
        }
        return $missing;
    }

    private static function requireFile(string $relativePath): void {
        $absolutePath = trailingslashit(ROUTESPRO_PATH) . ltrim($relativePath, '/');
        if (!file_exists($absolutePath)) {
            if (function_exists('__') && function_exists('error_log')) {
                error_log('[FieldFlow] Ficheiro obrigatório em falta: ' . $relativePath);
            }
            return;
        }
        require_once $absolutePath;
    }
}
