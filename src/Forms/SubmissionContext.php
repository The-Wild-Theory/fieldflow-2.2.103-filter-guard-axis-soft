<?php
namespace RoutesPro\Forms;

use RoutesPro\Support\Permissions;
use WP_Error;

if (!defined('ABSPATH')) exit;

class SubmissionContext {
    public static function normalize_for_submission(array $input, int $form_id, int $binding_id = 0, ?int $user_id = null) {
        global $wpdb;
        $uid = $user_id ?: get_current_user_id();
        $px = $wpdb->prefix . 'routespro_';

        $ctx = [
            'client_id' => absint($input['client_id'] ?? 0),
            'project_id' => absint($input['project_id'] ?? 0),
            'route_id' => absint($input['route_id'] ?? 0),
            'route_stop_id' => absint($input['route_stop_id'] ?? 0),
            'location_id' => absint($input['location_id'] ?? 0),
        ];

        $binding = null;
        if ($binding_id > 0) {
            $binding = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . BindingResolver::table() . ' WHERE id = %d LIMIT 1',
                $binding_id
            ), ARRAY_A);
            if (!$binding || (int)($binding['form_id'] ?? 0) !== $form_id || (int)($binding['is_active'] ?? 0) !== 1) {
                return new WP_Error('binding_invalid', 'Binding inválido para este formulário.', ['status' => 400]);
            }
        }

        $route = null;
        $stop = null;
        $locationExists = false;

        if ($ctx['route_stop_id'] > 0) {
            $stop = $wpdb->get_row($wpdb->prepare(
                "SELECT rs.id, rs.route_id, rs.location_id, r.client_id, r.project_id, r.owner_user_id
                 FROM {$px}route_stops rs
                 LEFT JOIN {$px}routes r ON r.id = rs.route_id
                 WHERE rs.id = %d LIMIT 1",
                $ctx['route_stop_id']
            ), ARRAY_A);
            if (!$stop) {
                return new WP_Error('stop_invalid', 'Paragem inválida.', ['status' => 400]);
            }
            if ($ctx['route_id'] > 0 && $ctx['route_id'] !== (int)($stop['route_id'] ?? 0)) {
                return new WP_Error('route_stop_mismatch', 'A paragem não pertence à rota indicada.', ['status' => 400]);
            }
            if ($ctx['location_id'] > 0 && $ctx['location_id'] !== (int)($stop['location_id'] ?? 0)) {
                return new WP_Error('location_stop_mismatch', 'A paragem não pertence ao local indicado.', ['status' => 400]);
            }
            $ctx['route_id'] = (int)($stop['route_id'] ?? 0);
            $ctx['location_id'] = (int)($stop['location_id'] ?? 0);
            $ctx['client_id'] = (int)($stop['client_id'] ?? 0);
            $ctx['project_id'] = (int)($stop['project_id'] ?? 0);
        }

        if ($ctx['route_id'] > 0) {
            $route = $wpdb->get_row($wpdb->prepare(
                "SELECT id, client_id, project_id, owner_user_id
                 FROM {$px}routes
                 WHERE id = %d LIMIT 1",
                $ctx['route_id']
            ), ARRAY_A);
            if (!$route) {
                return new WP_Error('route_invalid', 'Rota inválida.', ['status' => 400]);
            }
            if ($ctx['client_id'] > 0 && $ctx['client_id'] !== (int)($route['client_id'] ?? 0)) {
                return new WP_Error('client_route_mismatch', 'O cliente não coincide com a rota indicada.', ['status' => 400]);
            }
            if ($ctx['project_id'] > 0 && (int)($route['project_id'] ?? 0) > 0 && $ctx['project_id'] !== (int)($route['project_id'] ?? 0)) {
                return new WP_Error('project_route_mismatch', 'A campanha não coincide com a rota indicada.', ['status' => 400]);
            }
            $ctx['client_id'] = (int)($route['client_id'] ?? 0);
            $ctx['project_id'] = (int)($route['project_id'] ?? 0);
        }

        if ($ctx['location_id'] > 0) {
            $locationExists = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$px}locations WHERE id = %d LIMIT 1",
                $ctx['location_id']
            ));
            if (!$locationExists) {
                return new WP_Error('location_invalid', 'Local inválido.', ['status' => 400]);
            }
        }

        if ($binding) {
            $map = [
                'client_id' => 'client_id',
                'project_id' => 'project_id',
                'route_id' => 'route_id',
                'stop_id' => 'route_stop_id',
                'location_id' => 'location_id',
            ];
            foreach ($map as $bindingField => $ctxField) {
                $expected = absint($binding[$bindingField] ?? 0);
                if ($expected <= 0) continue;
                if (!empty($ctx[$ctxField]) && (int)$ctx[$ctxField] !== $expected) {
                    return new WP_Error('binding_context_mismatch', 'O contexto da submissão não coincide com o binding ativo.', ['status' => 400]);
                }
                $ctx[$ctxField] = $expected;
            }
        }

        if ($ctx['route_stop_id'] > 0 && $ctx['route_id'] <= 0) {
            return new WP_Error('route_required', 'Falta a rota da paragem.', ['status' => 400]);
        }
        if ($ctx['route_id'] > 0 && !Permissions::can_access_route($ctx['route_id'], $uid)) {
            return new WP_Error('forbidden_route', 'Sem acesso à rota selecionada.', ['status' => 403]);
        }
        $scope = Permissions::assert_scope_or_error($ctx['client_id'], $ctx['project_id'], $uid);
        if (is_wp_error($scope)) return $scope;

        $owner_user_id = 0;
        if ($route) {
            $owner_user_id = (int)($route['owner_user_id'] ?? 0);
        } elseif ($stop) {
            $owner_user_id = (int)($stop['owner_user_id'] ?? 0);
        }

        return [
            'context' => $ctx,
            'binding' => $binding,
            'route' => $route,
            'stop' => $stop,
            'owner_user_id' => $owner_user_id,
        ];
    }
}
