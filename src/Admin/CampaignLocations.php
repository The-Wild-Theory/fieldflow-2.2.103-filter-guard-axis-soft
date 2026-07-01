<?php
namespace RoutesPro\Admin;

use RoutesPro\Support\GeoPT;

use RoutesPro\Support\Permissions;
use RoutesPro\Services\LocationDeduplicator;
use RoutesPro\Services\MapsFactory;
use RoutesPro\Services\Planning\RouteCalculator;
use RoutesPro\Services\Planning\VisitRuleResolver;
use RoutesPro\Services\Planning\PlanQualityScorer;
use RoutesPro\Services\Planning\GeoPartitionService;
use RoutesPro\Services\Planning\RouteCandidateSelector;
use RoutesPro\Services\Planning\RoutePlanningPipeline;

if (!defined('ABSPATH')) exit;

class CampaignLocations {
    public static function render() {
        if (!current_user_can('routespro_manage')) return;
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $projects_tbl = $px . 'projects';
        $clients_tbl = $px . 'clients';
        $locations_tbl = $px . 'locations';
        $links_tbl = $px . 'campaign_locations';
        $cats_tbl = $px . 'categories';

        $project_id = absint($_REQUEST['project_id'] ?? 0);
        $q = sanitize_text_field($_REQUEST['q'] ?? '');
        $category_id = absint($_REQUEST['category_id'] ?? 0);
        $week_start = sanitize_text_field($_REQUEST['week_start'] ?? date('Y-m-d'));
        $selected_project = $project_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$projects_tbl} WHERE id=%d", $project_id), ARRAY_A) : null;
        $selected_client_id = (int)($selected_project['client_id'] ?? 0);
        $users = Permissions::get_associated_users($selected_client_id, $project_id, ['ID','display_name','user_login']);
        $settings = get_option('routespro_settings', []);
        $gmKey = trim((string)($settings['google_maps_key'] ?? ''));
        $mapsProvider = trim((string)($settings['maps_provider'] ?? 'leaflet'));
        $selected_owner_user_id = absint($_REQUEST['owner_user_id'] ?? ($_POST['owner_user_id'] ?? 0));
        $linked_page = max(1, absint($_REQUEST['linked_page'] ?? 1));
        $linked_per_page = absint($_REQUEST['linked_per_page'] ?? 20);
        if (!in_array($linked_per_page, [10,20,50,100], true)) $linked_per_page = 20;
        $linked_q = sanitize_text_field($_REQUEST['linked_q'] ?? ($_POST['linked_q'] ?? ''));
        $linked_category_id = absint($_REQUEST['linked_category_id'] ?? ($_POST['linked_category_id'] ?? 0));
        $linked_status = sanitize_text_field($_REQUEST['linked_status'] ?? ($_POST['linked_status'] ?? ''));
        if (!in_array($linked_status, ['', 'active', 'paused'], true)) $linked_status = '';
        $linked_active = sanitize_text_field($_REQUEST['linked_active'] ?? ($_POST['linked_active'] ?? ''));
        if (!in_array($linked_active, ['', '1', '0'], true)) $linked_active = '';
        $holiday_country = strtolower(sanitize_text_field($_REQUEST['holiday_country'] ?? ($_POST['holiday_country'] ?? 'pt')));
        if (!in_array($holiday_country, ['pt','es'], true)) $holiday_country = 'pt';

        // NOTE: Mantém-se normalize_plan_options() aqui, como tinhas.
        // A correção para "preservar options extra" é feita no build_period_plan() (bloco onde ele existir).
        $simulation_options = self::normalize_plan_options([
            'max_stops_per_day' => absint($_REQUEST['simulation_max_stops'] ?? ($_POST['simulation_max_stops'] ?? 12)),
            'target_stops_per_day' => absint($_REQUEST['simulation_target_stops'] ?? ($_POST['simulation_target_stops'] ?? 0)),
            'work_minutes' => absint($_REQUEST['simulation_work_minutes'] ?? ($_POST['simulation_work_minutes'] ?? 0)),
            'simulation_work_hours' => wp_unslash($_REQUEST['simulation_work_hours'] ?? ($_POST['simulation_work_hours'] ?? '8')),
            'lunch_minutes' => absint($_REQUEST['simulation_lunch_minutes'] ?? ($_POST['simulation_lunch_minutes'] ?? 60)),
            'allow_overtime' => !empty($_REQUEST['simulation_allow_overtime']) || !empty($_POST['simulation_allow_overtime']),
            'allow_extra_visits' => !empty($_REQUEST['simulation_allow_extra_visits']) || !empty($_POST['simulation_allow_extra_visits']),
            'overtime_extra_minutes' => absint($_REQUEST['simulation_overtime_extra_minutes'] ?? ($_POST['simulation_overtime_extra_minutes'] ?? 0)),
            'lock_start_point' => !empty($_REQUEST['simulation_lock_start_point']) || !empty($_POST['simulation_lock_start_point']),
            'lock_end_point' => !empty($_REQUEST['simulation_lock_end_point']) || !empty($_POST['simulation_lock_end_point']),
            'route_strategy' => sanitize_text_field($_REQUEST['simulation_route_strategy'] ?? ($_POST['simulation_route_strategy'] ?? 'complete_coverage')),
            'distance_sensitivity' => sanitize_text_field($_REQUEST['simulation_distance_sensitivity'] ?? ($_POST['simulation_distance_sensitivity'] ?? 'normal')),
        ]);

        $routeDefaultsAll = get_option('routespro_route_defaults', []);
        if (!is_array($routeDefaultsAll)) $routeDefaultsAll = [];
        $routeDefaultKey = $selected_client_id . '|' . $project_id . '|' . $selected_owner_user_id;
        $routeDefaults = $routeDefaultsAll[$routeDefaultKey] ?? $routeDefaultsAll[$selected_client_id . '|' . $project_id . '|0'] ?? [];
        $simulation_start = [
            'address' => sanitize_text_field($_REQUEST['simulation_start_address'] ?? ($_POST['simulation_start_address'] ?? ($routeDefaults['start_point']['address'] ?? ''))),
            'lat' => is_numeric($_REQUEST['simulation_start_lat'] ?? ($_POST['simulation_start_lat'] ?? ($routeDefaults['start_point']['lat'] ?? ''))) ? (float)($_REQUEST['simulation_start_lat'] ?? ($_POST['simulation_start_lat'] ?? ($routeDefaults['start_point']['lat'] ?? ''))) : null,
            'lng' => is_numeric($_REQUEST['simulation_start_lng'] ?? ($_POST['simulation_start_lng'] ?? ($routeDefaults['start_point']['lng'] ?? ''))) ? (float)($_REQUEST['simulation_start_lng'] ?? ($_POST['simulation_start_lng'] ?? ($routeDefaults['start_point']['lng'] ?? ''))) : null,
        ];
        $simulation_end = [
            'address' => sanitize_text_field($_REQUEST['simulation_end_address'] ?? ($_POST['simulation_end_address'] ?? ($routeDefaults['end_point']['address'] ?? ''))),
            'lat' => is_numeric($_REQUEST['simulation_end_lat'] ?? ($_POST['simulation_end_lat'] ?? ($routeDefaults['end_point']['lat'] ?? ''))) ? (float)($_REQUEST['simulation_end_lat'] ?? ($_POST['simulation_end_lat'] ?? ($routeDefaults['end_point']['lat'] ?? ''))) : null,
            'lng' => is_numeric($_REQUEST['simulation_end_lng'] ?? ($_POST['simulation_end_lng'] ?? ($routeDefaults['end_point']['lng'] ?? ''))) ? (float)($_REQUEST['simulation_end_lng'] ?? ($_POST['simulation_end_lng'] ?? ($routeDefaults['end_point']['lng'] ?? ''))) : null,
        ];
        $daily_overtime_dates = array_values(array_filter(array_map('sanitize_text_field', (array)($_REQUEST['simulation_overtime_dates'] ?? ($_POST['simulation_overtime_dates'] ?? [])))));
        $daily_overtime_hours_raw = (array)($_REQUEST['simulation_overtime_hours'] ?? ($_POST['simulation_overtime_hours'] ?? []));
        $daily_overtime_minutes = [];
        foreach ($daily_overtime_hours_raw as $date => $hoursRaw) {
            $date = sanitize_text_field((string)$date);
            if (!$date) continue;
            $mins = (int) round(max(0, min(2, (float)$hoursRaw)) * 60);
            if ($mins > 0) $daily_overtime_minutes[$date] = $mins;
        }
        foreach ($daily_overtime_dates as $date) {
            if (!isset($daily_overtime_minutes[$date])) $daily_overtime_minutes[$date] = (int)($simulation_options['overtime_extra_minutes'] ?? 60);
        }
        $simulation_options['daily_overtime_dates'] = $daily_overtime_dates;
        $simulation_options['daily_overtime_minutes'] = $daily_overtime_minutes;
        $simulation_options['start_point'] = $simulation_start;
        $simulation_options['end_point'] = $simulation_end;

        if (!empty($_POST['routespro_campaign_locations_nonce']) && wp_verify_nonce($_POST['routespro_campaign_locations_nonce'], 'routespro_campaign_locations')) {
            $action = sanitize_text_field($_POST['campaign_action'] ?? '');
            if ($action === 'add' && $project_id) {
                $ids = array_map('absint', (array)($_POST['location_ids'] ?? []));
                foreach ($ids as $location_id) {
                    if (!$location_id) continue;
                    $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$links_tbl} (project_id, location_id, status, is_active, visit_frequency, frequency_count, visit_duration_min) VALUES (%d,%d,'active',1,'weekly',1,45)", $project_id, $location_id));
                }
                echo '<div class="updated notice"><p>PDVs associados à campanha.</p></div>';
            }
            if ($action === 'remove' && $project_id) {
                $link_id = absint($_POST['link_id'] ?? 0);
                if ($link_id) {
                    $wpdb->delete($links_tbl, ['id' => $link_id]);
                    echo '<div class="updated notice"><p>PDV removido da campanha.</p></div>';
                }
            }
            if ($action === 'update_plan' && $project_id) {
                $link_id = absint($_POST['link_id'] ?? 0);
                if ($link_id) {
                    $updated = self::update_campaign_link_plan($link_id, $_POST);
                    if ($updated) {
                        echo '<div class="updated notice"><p>Planeamento do PDV atualizado.</p></div>';
                    }
                }
            }
            if ($action === 'bulk_update_plan' && $project_id) {
                $rows = isset($_POST['rows']) && is_array($_POST['rows']) ? wp_unslash($_POST['rows']) : [];
                $updated = 0;
                foreach ($rows as $link_id => $row) {
                    $link_id = absint($link_id);
                    if (!$link_id || !is_array($row)) continue;
                    $updated += self::update_campaign_link_plan($link_id, $row) ? 1 : 0;
                }
                echo '<div class="updated notice"><p>' . intval($updated) . ' PDVs atualizados de uma só vez.</p></div>';
            }
            if ($action === 'export_bulk_assignments_csv' && $project_id) {
                $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$projects_tbl} WHERE id=%d", $project_id), ARRAY_A);
                if ($project) {
                    $exportFilters = [
                        'q' => sanitize_text_field($_POST['linked_q'] ?? ''),
                        'category_id' => absint($_POST['linked_category_id'] ?? 0),
                        'status' => sanitize_text_field($_POST['linked_status'] ?? ''),
                        'active' => sanitize_text_field($_POST['linked_active'] ?? ''),
                        'owner_user_id' => absint($_POST['owner_user_id'] ?? 0),
                        'mode' => 'bulk_assignments',
                    ];
                    self::stream_linked_locations_csv($project, self::get_campaign_linked_rows($project_id, $exportFilters), $exportFilters);
                }
            }

            if ($action === 'export_bulk_assignments_template' && $project_id) {
                $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$projects_tbl} WHERE id=%d", $project_id), ARRAY_A);
                if ($project) {
                    self::stream_linked_locations_template_csv($project);
                }
            }
            if ($action === 'import_bulk_assignments_csv' && $project_id) {
                $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$projects_tbl} WHERE id=%d", $project_id), ARRAY_A);
                if ($project) {
                    $rows = self::parse_uploaded_csv_file('bulk_assignments_csv');
                    if ($rows === null) {
                        echo '<div class="notice notice-error"><p>Não foi possível ler o CSV de atribuição em lote.</p></div>';
                    } else {
                        $result = self::import_bulk_assignments_from_csv($project_id, $rows);
                        echo '<div class="updated notice"><p>Importação bulk concluída. ' . intval($result['updated']) . ' linhas atualizadas.</p></div>';
                    }
                }
            }
            if (in_array($action, ['export_linked_filtered','export_linked_all'], true) && $project_id) {
                $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$projects_tbl} WHERE id=%d", $project_id), ARRAY_A);
                if ($project) {
                    $exportFilters = [
                        'q' => sanitize_text_field($_POST['linked_q'] ?? ''),
                        'category_id' => absint($_POST['linked_category_id'] ?? 0),
                        'status' => sanitize_text_field($_POST['linked_status'] ?? ''),
                        'active' => sanitize_text_field($_POST['linked_active'] ?? ''),
                        'owner_user_id' => absint($_POST['owner_user_id'] ?? 0),
                    ];
                    if ($action === 'export_linked_all') {
                        $exportFilters = ['q' => '', 'category_id' => 0, 'status' => '', 'active' => '', 'owner_user_id' => 0];
                    }
                    self::stream_linked_locations_csv($project, self::get_campaign_linked_rows($project_id, $exportFilters), $exportFilters);
                }
            }
            if (in_array($action, ['preview_plan','accept_week','generate_auto_plan','export_plan','save_plan_suggestion','export_plan_template','import_plan_csv','manual_create_routes','manual_save_plan_suggestion'], true) && $project_id) {
                $owner_user_id = absint($_POST['owner_user_id'] ?? 0);
                $week_start = sanitize_text_field($_POST['week_start'] ?? date('Y-m-d'));
                $holiday_country = strtolower(sanitize_text_field($_POST['holiday_country'] ?? 'pt'));
                if (!in_array($holiday_country, ['pt','es'], true)) $holiday_country = 'pt';

                // NOTE: Também aqui mantém-se como tinhas.
                // A correção "preservar extras" é no build_period_plan().
                $simulation_options = self::normalize_plan_options([
                    'max_stops_per_day' => absint($_POST['simulation_max_stops'] ?? 12),
                    'target_stops_per_day' => absint($_POST['simulation_target_stops'] ?? 0),
                    'work_minutes' => absint($_POST['simulation_work_minutes'] ?? 0),
                    'simulation_work_hours' => wp_unslash($_POST['simulation_work_hours'] ?? '8'),
                    'lunch_minutes' => absint($_POST['simulation_lunch_minutes'] ?? 60),
                    'allow_overtime' => !empty($_POST['simulation_allow_overtime']),
                    'allow_extra_visits' => !empty($_POST['simulation_allow_extra_visits']),
                    'overtime_extra_minutes' => absint($_POST['simulation_overtime_extra_minutes'] ?? 0),
                    'lock_start_point' => !empty($_POST['simulation_lock_start_point']),
                    'lock_end_point' => !empty($_POST['simulation_lock_end_point']),
                    'route_strategy' => sanitize_text_field($_POST['simulation_route_strategy'] ?? 'complete_coverage'),
                    'distance_sensitivity' => sanitize_text_field($_POST['simulation_distance_sensitivity'] ?? 'normal'),
                    'daily_overtime_dates' => (array)($_POST['simulation_overtime_dates'] ?? []),
                    'daily_overtime_minutes' => (array)($_POST['simulation_overtime_minutes'] ?? []),
                    'start_point' => [
                        'address' => sanitize_text_field($_POST['simulation_start_address'] ?? ''),
                        'lat' => is_numeric($_POST['simulation_start_lat'] ?? null) ? (float)$_POST['simulation_start_lat'] : null,
                        'lng' => is_numeric($_POST['simulation_start_lng'] ?? null) ? (float)$_POST['simulation_start_lng'] : null,
                    ],
                    'end_point' => [
                        'address' => sanitize_text_field($_POST['simulation_end_address'] ?? ''),
                        'lat' => is_numeric($_POST['simulation_end_lat'] ?? null) ? (float)$_POST['simulation_end_lat'] : null,
                        'lng' => is_numeric($_POST['simulation_end_lng'] ?? null) ? (float)$_POST['simulation_end_lng'] : null,
                    ],
                ]);
                $plan_scope = sanitize_text_field($_POST['plan_scope'] ?? 'weekly');
                if (!in_array($plan_scope, ['weekly','monthly'], true)) $plan_scope = 'weekly';
                $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$projects_tbl} WHERE id=%d", $project_id), ARRAY_A);
                if ($project) {
                    $planFilters = [
                        'q' => sanitize_text_field($_POST['linked_q'] ?? ''),
                        'category_id' => absint($_POST['linked_category_id'] ?? 0),
                        'status' => sanitize_text_field($_POST['linked_status'] ?? ''),
                        'active' => sanitize_text_field($_POST['linked_active'] ?? ''),
                        'owner_user_id' => $owner_user_id,
                    ];
                    if (!in_array($planFilters['status'], ['', 'active', 'paused'], true)) $planFilters['status'] = '';
                    if (!in_array((string)$planFilters['active'], ['', '1', '0'], true)) $planFilters['active'] = '';
                    $linkedForPlan = self::get_campaign_linked_rows($project_id, $planFilters);
                    $plan = self::build_period_plan($linkedForPlan, $plan_scope, $week_start, $holiday_country, $simulation_options);
                    $saved_key = self::get_saved_plan_option_key($project_id, $owner_user_id, $plan_scope, $week_start, $holiday_country, $planFilters);
                    $edited_payload = wp_unslash($_POST['edited_plan_json'] ?? '');
                    if ($action === 'generate_auto_plan') {
                        self::clear_saved_plan_suggestion($saved_key);
                    }
                    if ($edited_payload) {
                        $edited_plan = self::decode_edited_plan_payload($edited_payload, $linkedForPlan, $plan, $simulation_options);
                        if (!empty($edited_plan['days'])) {
                            $plan = $edited_plan;
                        }
                    } elseif (!in_array($action, ['save_plan_suggestion','manual_save_plan_suggestion','manual_create_routes','generate_auto_plan'], true)) {
                        $saved_plan = self::load_saved_plan_suggestion($saved_key, $linkedForPlan, $plan, $simulation_options);
                        if (!empty($saved_plan['days'])) {
                            $plan = $saved_plan;
                        }
                    }
                    if (in_array($action, ['manual_save_plan_suggestion','manual_create_routes'], true)) {
                        $manual_base = self::make_manual_base_plan($linkedForPlan, $plan_scope, $week_start, $holiday_country, $simulation_options);
                        $manual_payload = wp_unslash($_POST['manual_plan_json'] ?? '');
                        $manual_plan = $manual_payload ? self::decode_edited_plan_payload($manual_payload, $linkedForPlan, $manual_base, $simulation_options) : $manual_base;
                        if (!empty($manual_plan['days'])) {
                            $plan = $manual_plan;
                        }
                    }
                    if ($action === 'preview_plan') {
                        echo '<div class="updated notice"><p>Sugestão calculada para pré-visualização. Nenhuma rota foi criada.</p></div>';
                    } elseif ($action === 'save_plan_suggestion' || $action === 'manual_save_plan_suggestion') {
                        $saved = self::save_plan_suggestion($saved_key, $plan, $simulation_options);
                        if ($saved) {
                            echo '<div class="updated notice"><p>Sugestão gravada com sucesso neste contexto da campanha. Esta versão passa a prevalecer neste mês até voltares a gerar a rota automática.</p></div>';
                        }
                    } elseif ($action === 'generate_auto_plan') {
                        echo '<div class="updated notice"><p>Rota automática regenerada para este contexto. A partir deste momento, esta versão automática volta a prevalecer neste mês até gravares uma sugestão editada ou manual.</p></div>';
                    } elseif ($action === 'export_plan') {
                        self::stream_plan_csv($project, $plan, $plan_scope, $week_start, $holiday_country, $simulation_options);
                    } elseif ($action === 'export_plan_template') {
                        self::stream_plan_template_csv($project, $plan_scope, $week_start, $holiday_country, $simulation_options);
                    } elseif ($action === 'import_plan_csv') {
                        $rows = self::parse_uploaded_csv_file('plan_csv');
                        if ($rows === null) {
                            echo '<div class="notice notice-error"><p>Não foi possível ler o CSV da sugestão de rotas.</p></div>';
                        } else {
                            $imported = self::import_plan_from_csv_rows($rows, $linkedForPlan, $plan, $simulation_options);
                            $imported_plan = is_array($imported['plan'] ?? null) ? $imported['plan'] : $plan;
                            $imported_options = is_array($imported['options'] ?? null) ? $imported['options'] : $simulation_options;
                            $saved = self::save_plan_suggestion($saved_key, $imported_plan, $imported_options);
                            if ($saved) {
                                echo '<div class="updated notice"><p>Sugestão importada por CSV e substituída na totalidade neste contexto, incluindo parâmetros globais de otimização.</p></div>';
                            }
                        }
                    } else {
                        if ($action === 'manual_create_routes' || ($action === 'accept_week' && $edited_payload)) {
                            self::save_plan_suggestion($saved_key, $plan, $simulation_options);
                        }
                        $created = self::create_routes_from_plan((int)($project['client_id'] ?? 0), $project_id, $owner_user_id, $week_start, $plan);
                        if ($action === 'manual_create_routes') {
                            $label = 'Planeamento manual gravado e definido como rota prevalecente neste mês.';
                        } elseif ($action === 'accept_week' && $edited_payload) {
                            $label = $plan_scope === 'monthly' ? 'Sugestão editada aplicada e definida como rota prevalecente deste mês.' : 'Sugestão editada aplicada e definida como rota prevalecente desta semana.';
                        } else {
                            $label = $plan_scope === 'monthly' ? 'Plano mensal aceite.' : 'Semana aceite.';
                        }
                        echo '<div class="updated notice"><p>' . esc_html($label) . ' ' . intval($created) . ' rotas criadas automaticamente.</p></div>';
                    }
                }
            }
        }

        $projects = $wpdb->get_results("SELECT p.id,p.name,c.name AS client_name FROM {$projects_tbl} p LEFT JOIN {$clients_tbl} c ON c.id=p.client_id ORDER BY c.name ASC, p.name ASC", ARRAY_A) ?: [];
        $categories = $wpdb->get_results("SELECT id,name FROM {$cats_tbl} WHERE parent_id IS NULL AND is_active=1 ORDER BY name ASC", ARRAY_A) ?: [];

        $where = ["(l.location_type IN ('pdv','') OR l.location_type IS NULL)", "l.is_active=1"];
        $args = [];
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where[] = '(l.name LIKE %s OR l.address LIKE %s OR l.city LIKE %s OR l.phone LIKE %s)';
            array_push($args, $like, $like, $like, $like);
        }
        if ($category_id) {
            $where[] = '(l.category_id=%d OR cat.parent_id=%d)';
            array_push($args, $category_id, $category_id);
        }
        if ($project_id) {
            $where[] = 'l.id NOT IN (SELECT location_id FROM ' . $links_tbl . ' WHERE project_id=%d)';
            $args[] = $project_id;
        }
        $sql = "SELECT l.*, c.name AS category_name, sc.name AS subcategory_name FROM {$locations_tbl} l LEFT JOIN {$cats_tbl} c ON c.id=l.category_id LEFT JOIN {$cats_tbl} sc ON sc.id=l.subcategory_id LEFT JOIN {$cats_tbl} cat ON cat.id=l.subcategory_id WHERE " . implode(' AND ', $where) . " ORDER BY l.updated_at DESC, l.id DESC LIMIT 200";
        $available = $args ? ($wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: []) : ($wpdb->get_results($sql, ARRAY_A) ?: []);
        $available = LocationDeduplicator::dedupe_rows($available);

        $linkedAll = $project_id ? self::get_campaign_linked_rows($project_id) : [];
        $linkedFiltered = $project_id ? self::get_campaign_linked_rows($project_id, [
            'q' => $linked_q,
            'category_id' => $linked_category_id,
            'status' => $linked_status,
            'active' => $linked_active,
            'owner_user_id' => $selected_owner_user_id,
        ]) : [];
        $linkedCount = count($linkedFiltered);
        $linkedOffset = ($linked_page - 1) * $linked_per_page;
        $linked = array_slice($linkedFiltered, $linkedOffset, $linked_per_page);
        $days5 = $linkedCount ? ceil($linkedCount / 5) : 0;
        $days6 = $linkedCount ? ceil($linkedCount / 6) : 0;
        $visitMinutes = array_reduce($linkedFiltered, function($sum, $row){ return $sum + max(0, (int)($row['visit_duration_min'] ?? 45)); }, 0);
        $plan_scope = sanitize_text_field($_REQUEST['plan_scope'] ?? 'weekly');
        if (!in_array($plan_scope, ['weekly','monthly'], true)) $plan_scope = 'weekly';
        $requestCampaignAction = sanitize_text_field($_REQUEST['campaign_action'] ?? '');
        $planBuildActions = ['preview_plan','accept_week','generate_auto_plan','export_plan','save_plan_suggestion','export_plan_template','import_plan_csv','manual_create_routes','manual_save_plan_suggestion'];
        $shouldBuildSuggestion = $project_id && (isset($_REQUEST['routespro_preview_plan']) || in_array($requestCampaignAction, $planBuildActions, true));
        // 2.2.103: o motor de rotas tem de respeitar os filtros visiveis do BO.
        // Antes, a sugestao usava apenas o merchandiser/owner e ignorava pesquisa, categoria, estado e ativo.
        $planFilters = [
            'q' => $linked_q,
            'category_id' => $linked_category_id,
            'status' => $linked_status,
            'active' => $linked_active,
            'owner_user_id' => $selected_owner_user_id,
        ];
        $linkedForSuggestion = $project_id ? self::get_campaign_linked_rows($project_id, $planFilters) : [];
        $suggested = [];
        if ($shouldBuildSuggestion) {
            // O modo mensal pode ser pesado porque avalia distribuição, periodicidade, distância, feriados e carga por dia.
            // Cache curto por contexto para evitar reconstruir o mesmo plano em cada refresh/filtro do backoffice.
            $suggestionCacheKey = 'routespro_plan_' . md5(wp_json_encode([
                'project_id' => $project_id,
                'owner_user_id' => $selected_owner_user_id,
                'scope' => $plan_scope,
                'week_start' => $week_start,
                'holiday_country' => $holiday_country,
                'options' => $simulation_options,
                'filters' => $planFilters,
                'links' => array_map(function($r){ return [(int)($r['link_id'] ?? $r['id'] ?? 0), (int)($r['location_id'] ?? 0), (string)($r['updated_at'] ?? '')]; }, $linkedForSuggestion),
            ]));
            $cachedSuggestion = get_transient($suggestionCacheKey);
            if (is_array($cachedSuggestion)) {
                $suggested = $cachedSuggestion;
            } else {
                $suggested = self::build_period_plan($linkedForSuggestion, $plan_scope, $week_start, $holiday_country, $simulation_options);
                set_transient($suggestionCacheKey, $suggested, 5 * MINUTE_IN_SECONDS);
            }
        }
        if ($shouldBuildSuggestion) {
            $savedSuggestionKey = self::get_saved_plan_option_key($project_id, $selected_owner_user_id, $plan_scope, $week_start, $holiday_country, $planFilters);
            $savedSuggestion = self::load_saved_plan_suggestion($savedSuggestionKey, $linkedForSuggestion, $suggested, $simulation_options);
            if (!empty($savedSuggestion['days'])) {
                $suggested = $savedSuggestion;
            }
        }
        $suggestedDistanceKm = $project_id ? self::estimate_plan_distance_km($suggested, $simulation_options) : 0.0;
        $suggestedTollCostEur = $project_id ? self::estimate_plan_toll_cost_eur($suggested, $simulation_options) : 0.0;
        $suggestedRouteDays = $project_id ? self::count_plan_days_with_stops($suggested) : 0;
        $requiredVisitStats = $project_id ? self::plan_required_visit_stats($linkedForSuggestion, $suggested) : ['required_visits'=>0,'planned_visits'=>0,'missing_visits'=>0,'coverage_pct'=>100.0,'required_visit_min'=>0];
        $createdRoutesSummary = $project_id ? self::get_created_routes_summary($project_id, $selected_owner_user_id, $week_start, $plan_scope) : [
            'routes' => [],
            'route_count' => 0,
            'stops_count' => 0,
            'distance_km' => 0.0,
            'toll_cost_eur' => 0.0,
            'date_from' => '',
            'date_to' => '',
        ];

        echo '<div class="wrap">';
        Branding::render_header('Campanhas PDVs', 'Liga a BD comercial global às campanhas sem duplicar lojas. Define periodicidade por campanha e gera uma semana sugerida de rotas.');
        echo '<style>
.routespro-campaign-page{max-width:1640px;width:100%;box-sizing:border-box}.routespro-campaign-toolbar{position:relative;overflow:hidden}.routespro-campaign-toolbar form,.routespro-plan-compact-form{display:grid!important;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px!important;align-items:end!important}.routespro-campaign-toolbar label,.routespro-plan-compact-form label{font-weight:700;color:#334155}.routespro-campaign-toolbar input,.routespro-campaign-toolbar select,.routespro-plan-compact-form input,.routespro-plan-compact-form select{max-width:100%}.routespro-campaign-toolbar .button,.routespro-plan-compact-form .button{min-height:36px}.routespro-plan-compact-form>#routespro-plan-preview{grid-column:1/-1;width:100%;min-width:0;display:block}.routespro-plan-compact-form>#routespro-edited-plan-json{display:none}.routespro-plan-actions{grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px}.routespro-campaign-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:14px}.routespro-campaign-kpis>div{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px}.routespro-campaign-kpis .rp-kpi-label{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.06em;font-weight:800}.routespro-campaign-kpis .rp-kpi-value{font-size:22px;font-weight:900;margin-top:4px;color:#0f172a}.routespro-compact-help{font-size:12px;color:#64748b;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:10px;line-height:1.45}.routespro-section-toggle{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:0 0 12px}.routespro-section-toggle h2{margin:0}.routespro-plan-compact-card{border-left:4px solid #0ea5e9}.routespro-over-max-badge{display:inline-flex;align-items:center;border-radius:999px;padding:2px 8px;background:#fef2f2;color:#991b1b;font-weight:800;font-size:11px}.routespro-campaign-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 0;padding:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px}.routespro-campaign-tab{border:1px solid transparent;background:transparent;border-radius:999px;padding:9px 14px;cursor:pointer;font-weight:800;color:#475569}.routespro-campaign-tab.is-active{background:#fff;border-color:#bfdbfe;color:#0369a1;box-shadow:0 6px 20px rgba(15,23,42,.08)}.routespro-campaign-tab-panel[hidden]{display:none!important}.routespro-table-scroll{width:100%;overflow:auto;border-radius:12px}.routespro-plan-editor-grid{display:grid;grid-template-columns:minmax(260px,340px) minmax(0,1fr);gap:14px;align-items:start}.routespro-plan-editor-days-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px}.routespro-card table.widefat{min-width:980px}
@media(max-width:782px){.routespro-campaign-toolbar form,.routespro-plan-compact-form{grid-template-columns:1fr!important}.routespro-campaign-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.routespro-campaign-kpis .rp-kpi-value{font-size:18px}.routespro-plan-editor-grid{grid-template-columns:1fr}.routespro-campaign-tab{flex:1;text-align:center}}
</style>';
        echo '<div class="routespro-campaign-page">';
        echo '<div class="routespro-card routespro-campaign-toolbar" style="margin-top:18px">';
        echo '<div class="routespro-section-toggle"><h2>Filtro e simulação</h2><span class="routespro-compact-help">Define o contexto. A geração pesada fica no bloco de sugestão abaixo.</span></div>';
        echo '<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">';
        echo '<input type="hidden" name="page" value="routespro-campaign-locations">';
        echo '<label>Campanha<br><select name="project_id" style="min-width:340px"><option value="">Selecionar campanha</option>';
        foreach ($projects as $p) {
            echo '<option value="'.intval($p['id']).'" '.selected($project_id, intval($p['id']), false).'>'.esc_html(($p['client_name'] ? $p['client_name'].' · ' : '').$p['name']).'</option>';
        }
        echo '</select></label>';
        echo '<label>Categoria<br><select name="category_id"><option value="">Todas</option>';
        foreach ($categories as $c) echo '<option value="'.intval($c['id']).'" '.selected($category_id, intval($c['id']), false).'>'.esc_html($c['name']).'</option>';
        echo '</select></label>';
        echo '<label>Pesquisar<br><input type="search" name="q" value="'.esc_attr($q).'" placeholder="Nome, morada, cidade"></label>';
        echo '<label>Data base<br><input type="date" name="week_start" value="'.esc_attr($week_start).'"></label>';
        echo '<label>Modo de sugestão<br><select name="plan_scope"><option value="weekly" '.selected($plan_scope, 'weekly', false).'>Semanal</option><option value="monthly" '.selected($plan_scope, 'monthly', false).'>Mensal</option></select></label>';
            echo '<label>Estratégia de geração<br><select name="simulation_route_strategy" style="min-width:250px"><option value="operational_balanced" '.selected(($simulation_options['route_strategy'] ?? 'operational_balanced'), 'operational_balanced', false).'>Operacional equilibrado</option><option value="complete_coverage" '.selected(($simulation_options['route_strategy'] ?? ''), 'complete_coverage', false).'>Cadência fixa, cobertura e periodicidade</option><option value="balanced_load" '.selected(($simulation_options['route_strategy'] ?? ''), 'balanced_load', false).'>Equilibrar carga</option><option value="minimize_km" '.selected(($simulation_options['route_strategy'] ?? ''), 'minimize_km', false).'>Minimizar kms</option><option value="cluster_district" '.selected(($simulation_options['route_strategy'] ?? ''), 'cluster_district', false).'>Agrupar por distrito/cidade</option><option value="route_corridor" '.selected(($simulation_options['route_strategy'] ?? ''), 'route_corridor', false).'>Corredor partida/chegada</option></select></label>';
        echo '<label>Feriados<br><select name="holiday_country"><option value="pt" '.selected($holiday_country, 'pt', false).'>Portugal</option><option value="es" '.selected($holiday_country, 'es', false).'>Espanha</option></select></label>';
        echo '<label>Owner da sugestão<br><select name="owner_user_id"><option value="0">Todos</option>';
        foreach ($users as $u) echo '<option value="'.intval($u->ID).'" '.selected($selected_owner_user_id, intval($u->ID), false).'>'.esc_html($u->display_name.' ['.$u->user_login.']').'</option>';
        echo '</select></label>';
        echo '<label>Máx. visitas/dia<br><input type="number" min="1" max="20" name="simulation_max_stops" value="'.intval($simulation_options['max_stops_per_day']).'" style="width:90px"></label>';
        echo self::render_target_stops_control($simulation_options);
        echo self::render_distance_sensitivity_control($simulation_options);
        echo '<label>Horas úteis<br><input type="number" min="1" max="12" step="0.5" name="simulation_work_hours" value="'.esc_attr(number_format($simulation_options['work_minutes'] / 60, 1, '.', '')).'" style="width:90px"></label>';
        echo '<label>Almoço<br><input type="number" min="0" max="180" step="15" name="simulation_lunch_minutes" value="'.intval($simulation_options['lunch_minutes']).'" style="width:90px"> min</label>';
        echo '<label style="display:flex;align-items:center;gap:6px;padding-bottom:6px"><input type="checkbox" name="simulation_allow_overtime" value="1" '.checked(!empty($simulation_options['allow_overtime']), true, false).'> Permitir fora do horário, geral</label>';
            echo '<label style="display:flex;align-items:center;gap:6px;padding-bottom:6px;max-width:260px"><input type="checkbox" name="simulation_allow_extra_visits" value="1" '.checked(!empty($simulation_options['allow_extra_visits']), true, false).'> Permitir visitas extra por cadência espelho</label>';
        echo '<label>Ponto de partida<br><input type="text" id="routespro-simulation-start-address" class="routespro-simulation-address" data-lat="#routespro-simulation-start-lat" data-lng="#routespro-simulation-start-lng" name="simulation_start_address" value="'.esc_attr((string)($simulation_options['start_point']['address'] ?? '')).'" placeholder="Morada inicial" style="min-width:240px"><input type="hidden" id="routespro-simulation-start-lat" name="simulation_start_lat" value="'.esc_attr((string)($simulation_options['start_point']['lat'] ?? '')).'"><input type="hidden" id="routespro-simulation-start-lng" name="simulation_start_lng" value="'.esc_attr((string)($simulation_options['start_point']['lng'] ?? '')).'"><span style="display:block;font-size:11px;color:#64748b;margin-top:4px">Autocomplete e preenchimento automático de coordenadas.</span></label>';
        echo '<label>Ponto de chegada<br><input type="text" id="routespro-simulation-end-address" class="routespro-simulation-address" data-lat="#routespro-simulation-end-lat" data-lng="#routespro-simulation-end-lng" name="simulation_end_address" value="'.esc_attr((string)($simulation_options['end_point']['address'] ?? '')).'" placeholder="Morada final" style="min-width:240px"><input type="hidden" id="routespro-simulation-end-lat" name="simulation_end_lat" value="'.esc_attr((string)($simulation_options['end_point']['lat'] ?? '')).'"><input type="hidden" id="routespro-simulation-end-lng" name="simulation_end_lng" value="'.esc_attr((string)($simulation_options['end_point']['lng'] ?? '')).'"><span style="display:block;font-size:11px;color:#64748b;margin-top:4px">Autocomplete e preenchimento automático de coordenadas.</span></label>';
        echo '<button class="button button-primary">Atualizar</button>';
        echo '</form>';
        if ($project_id) {
            echo '<div class="routespro-campaign-kpis">';
            foreach ([
                ['PDVs na campanha', $linkedCount],
                ['Dias alvo, 5 lojas/dia', $days5],
                ['Dias stretch, 6 lojas/dia', $days6],
                ['Tempo total em loja', ($visitMinutes ? floor($visitMinutes/60).'h '.($visitMinutes%60).'m' : '0m')],
                ['Visitas previstas/mês', (int)($requiredVisitStats['required_visits'] ?? 0)],
                ['Visitas alocadas sugestão', self::format_coverage_label($requiredVisitStats)],
                ['Kms estimados sugestão', self::format_km($suggestedDistanceKm)],
                ['Portagens sugestão', \RoutesPro\Support\TollEstimator::formatEuro($suggestedTollCostEur)],
                ['Dias com rota sugerida', $suggestedRouteDays],
                ['Rotas já criadas', (int)($createdRoutesSummary['route_count'] ?? 0)],
                ['Kms rotas criadas', self::format_km((float)($createdRoutesSummary['distance_km'] ?? 0))],
                ['Portagens rotas criadas', \RoutesPro\Support\TollEstimator::formatEuro((float)($createdRoutesSummary['toll_cost_eur'] ?? 0))],
            ] as $card) {
                echo '<div><div class="rp-kpi-label">'.esc_html($card[0]).'</div><div class="rp-kpi-value">'.esc_html($card[1]).'</div></div>';
            }
            echo '</div>';
        }
        echo '</div>';
        if (!$project_id) { echo '</div>'; }

        if ($project_id) {
            echo '<nav class="routespro-campaign-tabs" aria-label="Secções da campanha"><button type="button" class="routespro-campaign-tab is-active" data-campaign-tab="summary">Resumo</button><button type="button" class="routespro-campaign-tab" data-campaign-tab="pdvs">PDVs e regras</button><button type="button" class="routespro-campaign-tab" data-campaign-tab="planning">Planeamento</button></nav>';
            echo '<section class="routespro-campaign-tab-panel" data-campaign-tab-panel="summary">';
            echo self::render_created_routes_summary_html($createdRoutesSummary);
            echo '</section>';
            echo '<section class="routespro-campaign-tab-panel" data-campaign-tab-panel="pdvs" hidden>';
        }

        if ($project_id) {
            echo '<div class="routespro-card" style="margin-top:18px">';
            echo '<h2 style="margin-top:0">Adicionar lojas existentes à campanha</h2>';
            echo '<form method="post">';
            wp_nonce_field('routespro_campaign_locations', 'routespro_campaign_locations_nonce');
            echo '<input type="hidden" name="project_id" value="'.intval($project_id).'">';
            echo '<input type="hidden" name="owner_user_id" value="'.intval($selected_owner_user_id).'">';
            echo '<input type="hidden" name="linked_page" value="'.intval($linked_page).'">';
            echo '<input type="hidden" name="linked_per_page" value="'.intval($linked_per_page).'">';
            echo '<input type="hidden" name="simulation_max_stops" value="'.intval($simulation_options['max_stops_per_day']).'">';
            echo '<input type="hidden" name="simulation_target_stops" value="'.intval($simulation_options['target_stops_per_day'] ?? 0).'">';
            echo '<input type="hidden" name="simulation_distance_sensitivity" value="'.esc_attr((string)($simulation_options['distance_sensitivity'] ?? 'normal')).'"><input type="hidden" name="simulation_route_strategy" value="'.esc_attr((string)($simulation_options['route_strategy'] ?? 'complete_coverage')).'">';
            echo '<input type="hidden" name="simulation_work_minutes" value="'.intval($simulation_options['work_minutes']).'">';
            echo '<input type="hidden" name="simulation_lunch_minutes" value="'.intval($simulation_options['lunch_minutes']).'">';
            echo '<input type="hidden" name="simulation_start_address" value="'.esc_attr((string)($simulation_options['start_point']['address'] ?? '')).'">';
            echo '<input type="hidden" name="simulation_start_lat" value="'.esc_attr((string)($simulation_options['start_point']['lat'] ?? '')).'">';
            echo '<input type="hidden" name="simulation_start_lng" value="'.esc_attr((string)($simulation_options['start_point']['lng'] ?? '')).'">';
            echo '<input type="hidden" name="simulation_end_address" value="'.esc_attr((string)($simulation_options['end_point']['address'] ?? '')).'">';
            echo '<input type="hidden" name="simulation_end_lat" value="'.esc_attr((string)($simulation_options['end_point']['lat'] ?? '')).'">';
            echo '<input type="hidden" name="simulation_end_lng" value="'.esc_attr((string)($simulation_options['end_point']['lng'] ?? '')).'">';
            echo '<input type="hidden" name="simulation_overtime_extra_minutes" value="'.intval($simulation_options['overtime_extra_minutes'] ?? 0).'">';
            if (!empty($simulation_options['lock_start_point'])) echo '<input type="hidden" name="simulation_lock_start_point" value="1">';
            if (!empty($simulation_options['lock_end_point'])) echo '<input type="hidden" name="simulation_lock_end_point" value="1">';
            foreach ((array)($simulation_options['daily_overtime_dates'] ?? []) as $overtimeDate) echo '<input type="hidden" name="simulation_overtime_dates[]" value="'.esc_attr($overtimeDate).'">';
            foreach ((array)($simulation_options['daily_overtime_minutes'] ?? []) as $oDate => $oMin) echo '<input type="hidden" name="simulation_overtime_minutes['.esc_attr((string)$oDate).']" value="'.esc_attr((string)$oMin).'">';
            if (!empty($simulation_options['allow_overtime'])) echo '<input type="hidden" name="simulation_allow_overtime" value="1">';
            if (!empty($simulation_options['allow_extra_visits'])) echo '<input type="hidden" name="simulation_allow_extra_visits" value="1">';
            echo '<input type="hidden" name="campaign_action" value="add">';
            echo '<div class="routespro-table-scroll"><table class="widefat striped"><thead><tr><th></th><th>Nome</th><th>Morada</th><th>Cidade</th><th>Categoria</th><th>Telefone</th></tr></thead><tbody>';
            if (!$available) {
                echo '<tr><td colspan="6">Sem PDVs disponíveis com estes filtros.</td></tr>';
            } else {
                foreach ($available as $row) {
                    echo '<tr><td><input type="checkbox" name="location_ids[]" value="'.intval($row['id']).'"></td><td>'.esc_html($row['name']).'</td><td>'.esc_html($row['address']).'</td><td>'.esc_html($row['city']).'</td><td>'.esc_html($row['subcategory_name'] ?: $row['category_name']).'</td><td>'.esc_html($row['phone']).'</td></tr>';
                }
            }
            echo '</tbody></table></div><p><button class="button button-primary">Associar selecionados</button></p></form></div>';

            echo '<div class="routespro-card" style="margin-top:18px">';
            echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:start;flex-wrap:wrap"><div><h2 style="margin:0">PDVs já ligados à campanha</h2><p style="margin:6px 0 0;color:#64748b">Agora com exportação do resultado filtrado ou da campanha completa.</p></div>';
            echo '<form method="get" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">';
            echo '<input type="hidden" name="page" value="routespro-campaign-locations"><input type="hidden" name="project_id" value="'.intval($project_id).'"><input type="hidden" name="category_id" value="'.intval($category_id).'"><input type="hidden" name="q" value="'.esc_attr($q).'"><input type="hidden" name="week_start" value="'.esc_attr($week_start).'"><input type="hidden" name="plan_scope" value="'.esc_attr($plan_scope).'"><input type="hidden" name="holiday_country" value="'.esc_attr($holiday_country).'">';
            echo '<input type="hidden" name="simulation_max_stops" value="'.intval($simulation_options['max_stops_per_day']).'"><input type="hidden" name="simulation_target_stops" value="'.intval($simulation_options['target_stops_per_day'] ?? 0).'"><input type="hidden" name="simulation_distance_sensitivity" value="'.esc_attr((string)($simulation_options['distance_sensitivity'] ?? 'normal')).'"><input type="hidden" name="simulation_route_strategy" value="'.esc_attr((string)($simulation_options['route_strategy'] ?? 'complete_coverage')).'"><input type="hidden" name="simulation_work_minutes" value="'.intval($simulation_options['work_minutes']).'"><input type="hidden" name="simulation_lunch_minutes" value="'.intval($simulation_options['lunch_minutes']).'"><input type="hidden" name="simulation_start_address" value="'.esc_attr((string)($simulation_options['start_point']['address'] ?? '')).'"><input type="hidden" name="simulation_start_lat" value="'.esc_attr((string)($simulation_options['start_point']['lat'] ?? '')).'"><input type="hidden" name="simulation_start_lng" value="'.esc_attr((string)($simulation_options['start_point']['lng'] ?? '')).'"><input type="hidden" name="simulation_end_address" value="'.esc_attr((string)($simulation_options['end_point']['address'] ?? '')).'"><input type="hidden" name="simulation_end_lat" value="'.esc_attr((string)($simulation_options['end_point']['lat'] ?? '')).'"><input type="hidden" name="simulation_end_lng" value="'.esc_attr((string)($simulation_options['end_point']['lng'] ?? '')).'"><input type="hidden" name="simulation_overtime_extra_minutes" value="'.intval($simulation_options['overtime_extra_minutes'] ?? 0).'">'.(!empty($simulation_options['allow_overtime']) ? '<input type="hidden" name="simulation_allow_overtime" value="1">' : '').(!empty($simulation_options['allow_extra_visits']) ? '<input type="hidden" name="simulation_allow_extra_visits" value="1">' : '').(!empty($simulation_options['lock_start_point']) ? '<input type="hidden" name="simulation_lock_start_point" value="1">' : '').(!empty($simulation_options['lock_end_point']) ? '<input type="hidden" name="simulation_lock_end_point" value="1">' : '');
            echo '<label>Pesquisar<br><input type="search" name="linked_q" value="'.esc_attr($linked_q).'" placeholder="Nome, morada, cidade"></label>';
            echo '<label>Categoria<br><select name="linked_category_id"><option value="0">Todas</option>';
            foreach ($categories as $c) echo '<option value="'.intval($c['id']).'" '.selected($linked_category_id, intval($c['id']), false).'>'.esc_html($c['name']).'</option>';
            echo '</select></label>';
            echo '<label>Owner<br><select name="owner_user_id"><option value="0">Todos</option>';
            foreach ($users as $u) echo '<option value="'.intval($u->ID).'" '.selected($selected_owner_user_id, intval($u->ID), false).'>'.esc_html($u->display_name.' ['.$u->user_login.']').'</option>';
            echo '</select></label>';
            echo '<label>Estado<br><select name="linked_status"><option value="">Todos</option><option value="active" '.selected($linked_status, 'active', false).'>Ativo</option><option value="paused" '.selected($linked_status, 'paused', false).'>Pausado</option></select></label>';
            echo '<label>Ligação<br><select name="linked_active"><option value="">Todas</option><option value="1" '.selected($linked_active, '1', false).'>Ativas</option><option value="0" '.selected($linked_active, '0', false).'>Inativas</option></select></label>';
            echo '<label>Itens por página<br><select name="linked_per_page" onchange="this.form.submit()">';
            foreach ([10,20,50,100] as $pp) echo '<option value="'.intval($pp).'" '.selected($linked_per_page, $pp, false).'>'.intval($pp).'</option>';
            echo '</select></label><button class="button">Filtrar</button></form></div>';
            echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin:12px 0 14px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px">';
            echo '<div style="font-size:13px;color:#334155"><strong>'.intval($linkedCount).'</strong> PDVs no resultado atual, de um total de <strong>'.intval(count($linkedAll)).'</strong> na campanha.</div>';
            echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
            echo '<form method="post" style="margin:0">';
            wp_nonce_field('routespro_campaign_locations', 'routespro_campaign_locations_nonce');
            echo '<input type="hidden" name="project_id" value="'.intval($project_id).'"><input type="hidden" name="campaign_action" value="export_linked_filtered"><input type="hidden" name="owner_user_id" value="'.intval($selected_owner_user_id).'"><input type="hidden" name="linked_q" value="'.esc_attr($linked_q).'"><input type="hidden" name="linked_category_id" value="'.intval($linked_category_id).'"><input type="hidden" name="linked_status" value="'.esc_attr($linked_status).'"><input type="hidden" name="linked_active" value="'.esc_attr($linked_active).'">';
            echo '<button class="button">Exportar filtrados</button></form>';
            echo '<form method="post" style="margin:0">';
            wp_nonce_field('routespro_campaign_locations', 'routespro_campaign_locations_nonce');
            echo '<input type="hidden" name="project_id" value="'.intval($project_id).'"><input type="hidden" name="campaign_action" value="export_linked_all">';
            echo '<button class="button button-secondary">Exportar todos</button></form>';
            echo '</div></div>';
            echo '<form method="post" id="routespro-bulk-linked-form" enctype="multipart/form-data">';
            wp_nonce_field('routespro_campaign_locations', 'routespro_campaign_locations_nonce');
            echo '<input type="hidden" name="project_id" value="'.intval($project_id).'"><input type="hidden" name="owner_user_id" value="'.intval($selected_owner_user_id).'"><input type="hidden" name="holiday_country" value="'.esc_attr($holiday_country).'"><input type="hidden" name="linked_page" value="'.intval($linked_page).'"><input type="hidden" name="linked_per_page" value="'.intval($linked_per_page).'"><input type="hidden" name="linked_q" value="'.esc_attr($linked_q).'"><input type="hidden" name="linked_category_id" value="'.intval($linked_category_id).'"><input type="hidden" name="linked_status" value="'.esc_attr($linked_status).'"><input type="hidden" name="linked_active" value="'.esc_attr($linked_active).'"><input type="hidden" name="campaign_action" value="bulk_update_plan"><input type="hidden" name="link_id" value="0"><input type="hidden" name="simulation_max_stops" value="'.intval($simulation_options['max_stops_per_day']).'"><input type="hidden" name="simulation_target_stops" value="'.intval($simulation_options['target_stops_per_day'] ?? 0).'"><input type="hidden" name="simulation_distance_sensitivity" value="'.esc_attr((string)($simulation_options['distance_sensitivity'] ?? 'normal')).'"><input type="hidden" name="simulation_route_strategy" value="'.esc_attr((string)($simulation_options['route_strategy'] ?? 'complete_coverage')).'"><input type="hidden" name="simulation_work_minutes" value="'.intval($simulation_options['work_minutes']).'"><input type="hidden" name="simulation_lunch_minutes" value="'.intval($simulation_options['lunch_minutes']).'"><input type="hidden" name="simulation_start_address" value="'.esc_attr((string)($simulation_options['start_point']['address'] ?? '')).'"><input type="hidden" name="simulation_start_lat" value="'.esc_attr((string)($simulation_options['start_point']['lat'] ?? '')).'"><input type="hidden" name="simulation_start_lng" value="'.esc_attr((string)($simulation_options['start_point']['lng'] ?? '')).'"><input type="hidden" name="simulation_end_address" value="'.esc_attr((string)($simulation_options['end_point']['address'] ?? '')).'"><input type="hidden" name="simulation_end_lat" value="'.esc_attr((string)($simulation_options['end_point']['lat'] ?? '')).'"><input type="hidden" name="simulation_end_lng" value="'.esc_attr((string)($simulation_options['end_point']['lng'] ?? '')).'"><input type="hidden" name="simulation_overtime_extra_minutes" value="'.intval($simulation_options['overtime_extra_minutes'] ?? 0).'">'.(!empty($simulation_options['allow_overtime']) ? '<input type="hidden" name="simulation_allow_overtime" value="1">' : '').(!empty($simulation_options['allow_extra_visits']) ? '<input type="hidden" name="simulation_allow_extra_visits" value="1">' : '').(!empty($simulation_options['lock_start_point']) ? '<input type="hidden" name="simulation_lock_start_point" value="1">' : '').(!empty($simulation_options['lock_end_point']) ? '<input type="hidden" name="simulation_lock_end_point" value="1">' : '');
            echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px"><div><strong>Gravação em lote</strong><div style="font-size:12px;color:#64748b;margin-top:4px">Altera várias linhas, exporta os dados atuais, descarrega um template e volta a importar tudo por CSV.</div></div><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><span id="routespro-bulk-dirty" style="display:none;font-size:12px;color:#b45309;background:#fffbeb;border:1px solid #fcd34d;border-radius:999px;padding:4px 10px">Alterações por guardar</span><input type="file" name="bulk_assignments_csv" accept=".csv,text/csv" style="max-width:220px"><button class="button" type="submit" name="campaign_action" value="export_bulk_assignments_template">Template CSV bulk</button><button class="button" type="submit" name="campaign_action" value="export_bulk_assignments_csv">Exportar CSV atribuição</button><button class="button" type="submit" name="campaign_action" value="import_bulk_assignments_csv">Importar CSV bulk</button><button class="button button-primary" type="submit">Guardar tudo</button></div></div>';
            echo '<div class="routespro-table-scroll"><table class="widefat striped"><thead><tr><th>Nome</th><th>Cidade</th><th>Categoria</th><th>Owner</th><th>Periodicidade</th><th>Repetição</th><th>Visita</th><th>Prioridade</th><th>Regras</th><th>Ativo</th><th>Estado</th><th></th></tr></thead><tbody>';
            if (!$linked) {
                echo '<tr><td colspan="12">Sem PDVs associados à campanha.</td></tr>';
            } else {
                foreach ($linked as $row) {
                    $linkId = intval($row['link_id']);
                    echo '<tr data-linked-row="'.$linkId.'"><td>'.esc_html($row['name']).'<div style="font-size:11px;color:#64748b">'.esc_html($row['phone']).'</div></td><td>'.esc_html($row['city']).'</td><td>'.esc_html($row['subcategory_name'] ?: $row['category_name']).'</td><td>';
                    echo '<select name="rows['.$linkId.'][assigned_to]" style="min-width:180px" data-bulk-field="1"><option value="0">Sem owner</option>';
                    foreach ($users as $u) echo '<option value="'.intval($u->ID).'" '.selected((int)($row['assigned_to'] ?? 0), intval($u->ID), false).'>'.esc_html($u->display_name.' ['.$u->user_login.']').'</option>';
                    echo '</select></td>';
                    echo '<td><select name="rows['.$linkId.'][visit_frequency]" data-bulk-field="1"><option value="weekly" '.selected(($row['visit_frequency'] ?: 'weekly'),'weekly',false).'>Semanal</option><option value="monthly" '.selected(($row['visit_frequency'] ?: ''),'monthly',false).'>Mensal</option></select></td>';
                    echo '<td><input type="number" min="1" max="7" name="rows['.$linkId.'][frequency_count]" value="'.intval($row['frequency_count'] ?: 1).'" style="width:72px" data-bulk-field="1"></td>';
                    echo '<td><input type="number" min="0" max="360" name="rows['.$linkId.'][visit_duration_min]" value="'.intval($row['visit_duration_min'] ?: 45).'" style="width:82px" data-bulk-field="1"> min</td>';
                    echo '<td><input type="number" min="0" max="999" name="rows['.$linkId.'][priority]" value="'.intval($row['priority'] ?: 0).'" style="width:72px" data-bulk-field="1"></td>';
                    $hasAdvancedRule = !empty($row['min_gap_days']) || !empty($row['max_gap_days']) || !empty($row['preferred_weekdays']) || !empty($row['blocked_weekdays']) || !empty($row['time_window_start']) || !empty($row['time_window_end']) || empty($row['allow_auto_reschedule']) || !empty($row['allow_overtime']) || !empty($row['rule_notes']);
                    echo '<td><details style="min-width:240px"><summary style="cursor:pointer;color:#0369a1;font-weight:700">'.($hasAdvancedRule ? 'Avançada' : 'Padrão').'</summary><div style="margin-top:8px;display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px">';
                    echo '<label>Intervalo mín.<br><input type="number" min="0" max="31" name="rows['.$linkId.'][min_gap_days]" value="'.intval($row['min_gap_days'] ?? 0).'" style="width:76px" data-bulk-field="1"> dias</label>';
                    echo '<label>Intervalo máx.<br><input type="number" min="0" max="90" name="rows['.$linkId.'][max_gap_days]" value="'.intval($row['max_gap_days'] ?? 0).'" style="width:76px" data-bulk-field="1"> dias</label>';
                    echo '<label style="grid-column:1/-1">Dias preferenciais<br><input type="text" name="rows['.$linkId.'][preferred_weekdays]" value="'.esc_attr((string)($row['preferred_weekdays'] ?? '')).'" placeholder="1,2,3,4,5" style="width:100%" data-bulk-field="1"><span style="color:#64748b">1=Seg, 7=Dom</span></label>';
                    echo '<label style="grid-column:1/-1">Dias bloqueados<br><input type="text" name="rows['.$linkId.'][blocked_weekdays]" value="'.esc_attr((string)($row['blocked_weekdays'] ?? '')).'" placeholder="6,7" style="width:100%" data-bulk-field="1"></label>';
                    echo '<label>Janela início<br><input type="time" name="rows['.$linkId.'][time_window_start]" value="'.esc_attr((string)($row['time_window_start'] ?? '')).'" data-bulk-field="1"></label>';
                    echo '<label>Janela fim<br><input type="time" name="rows['.$linkId.'][time_window_end]" value="'.esc_attr((string)($row['time_window_end'] ?? '')).'" data-bulk-field="1"></label>';
                    echo '<label style="grid-column:1/-1"><input type="checkbox" name="rows['.$linkId.'][allow_auto_reschedule]" value="1" '.checked((int)($row['allow_auto_reschedule'] ?? 1), 1, false).' data-bulk-field="1"> permitir reagendamento automático</label>';
                    echo '<label style="grid-column:1/-1"><input type="checkbox" name="rows['.$linkId.'][allow_overtime]" value="1" '.checked(!empty($row['allow_overtime']), true, false).' data-bulk-field="1"> permitir horas extra para este PDV</label>';
                    echo '<label style="grid-column:1/-1">Notas da regra<br><textarea name="rows['.$linkId.'][rule_notes]" rows="2" style="width:100%" data-bulk-field="1">'.esc_textarea((string)($row['rule_notes'] ?? '')).'</textarea></label>';
                    echo '</div></details></td>';
                    echo '<td><label><input type="checkbox" name="rows['.$linkId.'][is_active]" value="1" '.checked(!empty($row['campaign_active']), true, false).' data-bulk-field="1"> ativo</label></td>';
                    echo '<td><select name="rows['.$linkId.'][status]" data-bulk-field="1"><option value="active" '.selected(($row['campaign_status'] ?: 'active'),'active',false).'>active</option><option value="paused" '.selected(($row['campaign_status'] ?: ''),'paused',false).'>paused</option></select></td>';
                    echo '<td><button class="button-link-delete" type="submit" name="campaign_action" value="remove" onclick="this.form.elements[\'link_id\'].value=\''.$linkId.'\'; return confirm(\'Remover da campanha?\')">Remover</button></td></tr>';
                }
            }
            echo '</tbody></table></div>';
            echo '<div style="display:flex;justify-content:flex-end;gap:8px;align-items:center;margin-top:12px"><button class="button button-primary" type="submit">Guardar tudo</button></div>';
            echo '</form>';
            echo self::render_linked_pagination($project_id, $category_id, $q, $week_start, $plan_scope, $holiday_country, $selected_owner_user_id, $linked_page, $linked_per_page, $linkedCount, $simulation_options, $linked_q, $linked_category_id, $linked_status, $linked_active);
            echo '<p style="color:#64748b;margin-top:10px">Semanal com repetição 2 ou 3 permite repetir o mesmo local mais do que uma vez na semana. Mensal distribui as visitas ao longo do mês selecionado e pode gerar rotas datadas para esse período.</p>';
            echo '<script>(function(){const form=document.getElementById("routespro-bulk-linked-form"); if(!form) return; const badge=document.getElementById("routespro-bulk-dirty"); let dirty=false; const markDirty=(el)=>{ dirty=true; if(badge) badge.style.display="inline-flex"; const row=el && el.closest("tr[data-linked-row]"); if(row){ row.style.background="#fff7ed"; } }; form.querySelectorAll("[data-bulk-field=\\"1\\"]").forEach(el=>{ const ev=(el.type==="checkbox"||el.tagName==="SELECT")?"change":"input"; el.addEventListener(ev,()=>markDirty(el)); }); window.addEventListener("beforeunload",function(e){ if(!dirty) return; e.preventDefault(); e.returnValue=""; }); form.addEventListener("submit",function(){ dirty=false; if(badge) badge.style.display="none"; });})();</script>';
            echo '</div>';
            echo '</section><section class="routespro-campaign-tab-panel" data-campaign-tab-panel="planning" hidden>';
            echo '<div class="routespro-card routespro-plan-compact-card" style="margin-top:18px">';
            echo '<div class="routespro-section-toggle"><h2>Sugestão automática de rotas</h2><span class="routespro-compact-help">Máx. visitas/dia agora é limite duro. Se não couber, fica por atribuir para decisão operacional.</span></div>';
            echo '<form method="post" id="routespro-plan-form" enctype="multipart/form-data" class="routespro-plan-compact-form" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:14px">';
            wp_nonce_field('routespro_campaign_locations', 'routespro_campaign_locations_nonce');
            echo '<input type="hidden" name="project_id" value="'.intval($project_id).'">';
            echo '<input type="hidden" name="linked_page" value="'.intval($linked_page).'">';
            echo '<input type="hidden" name="linked_per_page" value="'.intval($linked_per_page).'">';
            echo '<input type="hidden" name="campaign_action" value="preview_plan">';
            echo '<label>Data base<br><input type="date" name="week_start" value="'.esc_attr($week_start).'"></label>';
            echo '<label>Modo de sugestão<br><select name="plan_scope"><option value="weekly" '.selected($plan_scope, 'weekly', false).'>Semanal</option><option value="monthly" '.selected($plan_scope, 'monthly', false).'>Mensal</option></select></label>';
            echo '<label>Estratégia de geração<br><select name="simulation_route_strategy" style="min-width:250px"><option value="operational_balanced" '.selected(($simulation_options['route_strategy'] ?? 'operational_balanced'), 'operational_balanced', false).'>Operacional equilibrado</option><option value="complete_coverage" '.selected(($simulation_options['route_strategy'] ?? ''), 'complete_coverage', false).'>Cadência fixa, cobertura e periodicidade</option><option value="balanced_load" '.selected(($simulation_options['route_strategy'] ?? ''), 'balanced_load', false).'>Equilibrar carga</option><option value="minimize_km" '.selected(($simulation_options['route_strategy'] ?? ''), 'minimize_km', false).'>Minimizar kms</option><option value="cluster_district" '.selected(($simulation_options['route_strategy'] ?? ''), 'cluster_district', false).'>Agrupar por distrito/cidade</option><option value="route_corridor" '.selected(($simulation_options['route_strategy'] ?? ''), 'route_corridor', false).'>Corredor partida/chegada</option></select></label>';
            echo '<label>Feriados<br><select name="holiday_country"><option value="pt" '.selected($holiday_country, 'pt', false).'>Portugal</option><option value="es" '.selected($holiday_country, 'es', false).'>Espanha</option></select></label>';
            echo '<label>Owner das rotas<br><select name="owner_user_id" id="routespro-plan-owner"><option value="0">Sem owner</option>';
            foreach ($users as $u) echo '<option value="'.intval($u->ID).'" '.selected($selected_owner_user_id, intval($u->ID), false).'>'.esc_html($u->display_name.' ['.$u->user_login.']').'</option>';
            echo '</select></label>';
            echo '<label>Máx. visitas/dia<br><input type="number" min="1" max="20" name="simulation_max_stops" value="'.intval($simulation_options['max_stops_per_day']).'" style="width:90px"></label>';
            echo self::render_target_stops_control($simulation_options);
            echo self::render_distance_sensitivity_control($simulation_options);
            echo '<label>Horas úteis<br><input type="number" min="1" max="12" step="0.5" name="simulation_work_hours" value="'.esc_attr(number_format($simulation_options['work_minutes'] / 60, 1, '.', '')).'" style="width:90px"></label>';
            echo '<label>Almoço<br><input type="number" min="0" max="180" step="15" name="simulation_lunch_minutes" value="'.intval($simulation_options['lunch_minutes']).'" style="width:90px"> min</label>';
            echo '<label style="display:flex;align-items:center;gap:6px;padding-bottom:6px"><input type="checkbox" name="simulation_allow_overtime" value="1" '.checked(!empty($simulation_options['allow_overtime']), true, false).'> Permitir fora do horário, geral</label>';
            echo '<label style="display:flex;align-items:center;gap:6px;padding-bottom:6px;max-width:260px"><input type="checkbox" name="simulation_allow_extra_visits" value="1" '.checked(!empty($simulation_options['allow_extra_visits']), true, false).'> Permitir visitas extra por cadência espelho</label>';
            echo '<label>Horas adicionais, geral<br><input type="number" step="0.5" min="0" max="2.5" name="simulation_overtime_extra_hours" value="'.esc_attr(number_format(((int)($simulation_options['overtime_extra_minutes'] ?? 0))/60, 1, '.', '')).'" style="width:90px"><input type="hidden" name="simulation_overtime_extra_minutes" value="'.intval($simulation_options['overtime_extra_minutes'] ?? 0).'"></label>';
            echo '<label>Ponto de partida<br><input type="text" id="routespro-plan-start-address" class="routespro-simulation-address" data-lat="#routespro-plan-start-lat" data-lng="#routespro-plan-start-lng" name="simulation_start_address" value="'.esc_attr((string)($simulation_options['start_point']['address'] ?? '')).'" placeholder="Morada inicial" style="min-width:240px"><input type="hidden" id="routespro-plan-start-lat" name="simulation_start_lat" value="'.esc_attr((string)($simulation_options['start_point']['lat'] ?? '')).'"><input type="hidden" id="routespro-plan-start-lng" name="simulation_start_lng" value="'.esc_attr((string)($simulation_options['start_point']['lng'] ?? '')).'"><span style="display:block;font-size:12px;color:#475569;margin-top:4px"><label><input type="checkbox" name="simulation_lock_start_point" value="1" '.checked(!empty($simulation_options['lock_start_point']), true, false).'> Bloquear ponto de partida</label></span></label>';
            echo '<label>Ponto de chegada<br><input type="text" id="routespro-plan-end-address" class="routespro-simulation-address" data-lat="#routespro-plan-end-lat" data-lng="#routespro-plan-end-lng" name="simulation_end_address" value="'.esc_attr((string)($simulation_options['end_point']['address'] ?? '')).'" placeholder="Morada final" style="min-width:240px"><input type="hidden" id="routespro-plan-end-lat" name="simulation_end_lat" value="'.esc_attr((string)($simulation_options['end_point']['lat'] ?? '')).'"><input type="hidden" id="routespro-plan-end-lng" name="simulation_end_lng" value="'.esc_attr((string)($simulation_options['end_point']['lng'] ?? '')).'"><span style="display:block;font-size:12px;color:#475569;margin-top:4px"><label><input type="checkbox" name="simulation_lock_end_point" value="1" '.checked(!empty($simulation_options['lock_end_point']), true, false).'> Bloquear ponto de chegada</label></span></label>';
            echo '<div style="font-size:12px;color:#64748b;max-width:460px">Por defeito a simulação tenta preencher 8h úteis e 1h de almoço. A periodicidade é prioritária e o motor equilibra distância e carga. As horas extra nunca podem ultrapassar 2h30m por dia. Quando isso não chega, a pré-visualização recomenda reforço de equipa e indica a zona mais lógica para dividir a rota.</div>';
            echo '<div id="routespro-plan-preview">';
            if ($shouldBuildSuggestion) {
                echo self::render_plan_preview_html($suggested, $plan_scope, $selected_owner_user_id, $users, $holiday_country, $simulation_options, $linkedForSuggestion);
            } else {
                echo '<div style="padding:16px;border:1px dashed #cbd5e1;border-radius:16px;background:#f8fafc;color:#475569"><strong>Sugestão ainda não calculada.</strong><br>Escolhe os parâmetros e clica em <strong>Pré-visualizar sugestão</strong>. Assim o filtro de campanha e a lista de PDVs abrem rápido, sem tentar calcular o mês inteiro em cada refresh.</div>';
            }
            echo '</div>';
            echo '<input type="hidden" name="edited_plan_json" id="routespro-edited-plan-json" value="">';
            echo '<div class="routespro-plan-actions"><input type="file" name="plan_csv" accept=".csv,text/csv" style="max-width:220px"><button class="button button-secondary" type="submit" name="campaign_action" value="preview_plan">Pré-visualizar sugestão</button><button class="button button-primary" type="submit" name="campaign_action" value="accept_week">Aplicar sugestão e criar rotas</button><button class="button" type="submit" name="campaign_action" value="generate_auto_plan">Gerar rota automática</button><button class="button button-secondary" type="submit" name="campaign_action" value="save_plan_suggestion">Gravar sugestão alterada</button><button class="button" type="submit" name="campaign_action" value="export_plan_template">Template CSV sugestão</button><button class="button" type="submit" name="campaign_action" value="export_plan">Exportar sugestão</button><button class="button" type="submit" name="campaign_action" value="import_plan_csv">Importar CSV sugestão</button><span style="font-size:12px;color:#64748b">Ao gravar, importar ou criar a partir de uma sugestão editada, essa versão passa a prevalecer neste contexto até voltares a gerar a rota automática.</span></div>';
            echo '</form>';
            if ($shouldBuildSuggestion) {
                echo self::render_manual_planner_html(self::make_manual_base_plan($linkedForSuggestion, $plan_scope, $week_start, $holiday_country, $simulation_options), $plan_scope, $simulation_options, $linkedForSuggestion);
            }
            echo <<<'HTML'
<script>
(function(){
  const form=document.getElementById("routespro-plan-form");
  if(!form) return;
  const preview=document.getElementById("routespro-plan-preview");
  const syncReadonly=()=>{
    const lockStart=form.querySelector('input[name="simulation_lock_start_point"]');
    const lockEnd=form.querySelector('input[name="simulation_lock_end_point"]');
    const s=form.querySelector('input[name="simulation_start_address"]');
    const e=form.querySelector('input[name="simulation_end_address"]');
    if(s&&lockStart) s.readOnly=!!lockStart.checked;
    if(e&&lockEnd) e.readOnly=!!lockEnd.checked;
  };
  const syncExtra=()=>{
    const extra=form.querySelector('input[name="simulation_overtime_extra_hours"]');
    const hidden=form.querySelector('input[name="simulation_overtime_extra_minutes"]');
    if(extra&&hidden){
      const v=parseFloat(String(extra.value).replace(',','.'))||0;
      hidden.value=String(Math.round(Math.max(0,Math.min(2,v))*60));
    }
    form.querySelectorAll('input[name^="simulation_overtime_minutes["]').forEach(function(el){
      const v=parseFloat(String(el.value).replace(',','.'))||0;
      el.dataset.minutes=String(Math.round(Math.max(0,Math.min(2,v))*60));
    });
  };
  window.routesproInitPlanEditor=function(previewEl, formEl){
    if(!previewEl||!formEl) return;
    const root=previewEl.querySelector('#routespro-plan-editor-root');
    if(!root||root.dataset.bound==='1') return;
    root.dataset.bound='1';
    const hidden=formEl.querySelector('#routespro-edited-plan-json');
    const sourceHidden=formEl.querySelector('#routespro-current-plan-source');
    const daysWrap=root.querySelector('#routespro-plan-editor-days');
    const availableWrap=root.querySelector('#routespro-available-list');
    const availableCount=root.querySelector('#routespro-available-count');
    const availableSearch=root.querySelector('#routespro-available-search');
    const mapEl=root.querySelector('#routespro-plan-map');
    const liveAssigned=root.querySelector('#routespro-live-assigned-stores');
    const liveFree=root.querySelector('#routespro-live-free-stores');
    const liveFreePeriodicities=root.querySelector('#routespro-live-free-periodicities');
    const liveDistance=root.querySelector('#routespro-live-distance-km');
    const liveToll=root.querySelector('#routespro-live-toll-cost');
    const liveStatus=root.querySelector('#routespro-live-status');
    const summaryText=document.getElementById('routespro-summary-text');
    let map=null, polyline=null, markers=[];
    let dirty=false;
    let focusDay=0;
    const overrideState=new Map();
    const parse=(name,fallback)=>{ try{return JSON.parse(root.dataset[name]||JSON.stringify(fallback));}catch(e){return fallback;} };
    const clone=(obj)=>JSON.parse(JSON.stringify(obj));
    const normalizeItem=(it)=>({
      ...it,
      uid:String(it.uid||(`${Number(it.id||0)}__${Number(it.copy_index||1)}`)),
      id:Number(it.id||0),
      copy_index:Number(it.copy_index||1),
      frequency_count:Number(it.frequency_count||1),
      visit_duration_min:Number(it.visit_duration_min||45),
      lat:it.lat!==null&&it.lat!==''?Number(it.lat):null,
      lng:it.lng!==null&&it.lng!==''?Number(it.lng):null
    });
    const allItems=(parse('items',[])||[]).map(normalizeItem);
    const plan=parse('plan',{days:[]});
    if(!Array.isArray(plan.days)) plan.days=[];
    const itemMap=new Map(allItems.map(it=>[String(it.uid), clone(it)]));
    plan.days=plan.days.map((day,idx)=>({label:day.label||'',date:day.date||'',override_rules: !!(day && day.override_rules), items:(day.items||[]).map(it=>itemMap.get(String((it&&it.uid)||(`${Number((it&&it.id)||0)}__${Number((it&&it.copy_index)||1)}`)))||normalizeItem(it))}));
    plan.days.forEach((day,idx)=>{ overrideState.set(String((day.date||'sem-data')+'|'+idx), !!day.override_rules); });
    const escapeHtml=(v)=>String(v||'').replace(/[&<>"']/g, function(ch){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch] || ch; });
    const hav=(a,b,c,d)=>{ const R=6371, toRad=x=>x*Math.PI/180; const dLat=toRad(c-a), dLng=toRad(d-b); const q=Math.sin(dLat/2)**2+Math.cos(toRad(a))*Math.cos(toRad(c))*Math.sin(dLng/2)**2; return 2*R*Math.asin(Math.sqrt(q)); };
    const pointDistance=(a,b)=>{ if(!a||!b||!isFinite(a.lat)||!isFinite(a.lng)||!isFinite(b.lat)||!isFinite(b.lng)) return Number.MAX_SAFE_INTEGER; return hav(Number(a.lat),Number(a.lng),Number(b.lat),Number(b.lng)); };
    const startPoint=()=>({ lat:parseFloat(formEl.querySelector('input[name="simulation_start_lat"]')?.value||''), lng:parseFloat(formEl.querySelector('input[name="simulation_start_lng"]')?.value||'') });
    const endPoint=()=>({ lat:parseFloat(formEl.querySelector('input[name="simulation_end_lat"]')?.value||''), lng:parseFloat(formEl.querySelector('input[name="simulation_end_lng"]')?.value||'') });
    const segmentDistance=(a,b)=>{ if(!a||!b||!isFinite(a.lat)||!isFinite(a.lng)||!isFinite(b.lat)||!isFinite(b.lng)) return 0; return hav(Number(a.lat),Number(a.lng),Number(b.lat),Number(b.lng)); };
    const routeDistance=(items)=>{ items=(items||[]).filter(Boolean); if(!items.length) return 0; let total=0; let prev=startPoint(); if(!isFinite(prev.lat)||!isFinite(prev.lng)) prev=items[0]||prev; items.forEach(item=>{ total+=segmentDistance(prev,item); prev=item; }); const end=endPoint(); if(isFinite(end.lat)&&isFinite(end.lng)&&items.length) total+=segmentDistance(prev,end); return total; };
    const routeTravelMinutes=(items)=>routeDistance(items)*1.6;
    const estimateToll=(km)=>{ km=Number(km||0)||0; return Math.round((km*0.58*0.075)/0.05)*0.05; };
    const formatEuro=(v)=>`${(Number(v||0)||0).toLocaleString('pt-PT',{minimumFractionDigits:2,maximumFractionDigits:2})} €`;
    const maxStopsPerDay=()=>Math.max(1, Number(formEl.querySelector('input[name="simulation_max_stops"]')?.value||12));
    const targetStopsPerDay=()=>{ const raw=Number(formEl.querySelector('select[name="simulation_target_stops"],input[name="simulation_target_stops"]')?.value||0); return raw>0 ? Math.min(maxStopsPerDay(), raw) : 0; };
    const lunchMinutes=()=>Math.max(0, Number(formEl.querySelector('input[name="simulation_lunch_minutes"]')?.value||60));
    const maxWorkMinutes=()=>{
      const baseHours=parseFloat(formEl.querySelector('input[name="simulation_work_hours"]')?.value||'8')||8;
      const overtimeAllowed=!!formEl.querySelector('input[name="simulation_allow_overtime"]')?.checked;
      let overtime=0;
      if(overtimeAllowed){
        overtime=Number(formEl.querySelector('input[name="simulation_overtime_extra_minutes"]')?.value||0);
      }
      return Math.max(60, Math.round(baseHours*60) + Math.max(0, overtime));
    };

    const weekStartKey=(dateStr)=>{
      if(!dateStr) return '';
      const d=new Date(String(dateStr)+'T00:00:00');
      if(Number.isNaN(d.getTime())) return '';
      let wd=d.getDay();
      if(wd===0) wd=7;
      d.setDate(d.getDate()-(wd-1));
      const y=d.getFullYear();
      const m=String(d.getMonth()+1).padStart(2,'0');
      const day=String(d.getDate()).padStart(2,'0');
      return `${y}-${m}-${day}`;
    };
    const weekIndexMap=()=>{
      const map=new Map();
      (plan.days||[]).forEach(day=>{
        const key=weekStartKey(day.date||'');
        if(key && !map.has(key)) map.set(key, map.size+1);
      });
      return map;
    };
    const dayWeekIndex=(day)=>{
      const key=weekStartKey((day&&day.date)||'');
      return weekIndexMap().get(key) || 0;
    };
    const assignedEntriesForStore=(storeId, exceptUid)=>{
      const rows=[];
      const skipUid=String(exceptUid||'');
      (plan.days||[]).forEach((day,dayIndex)=>{
        (day.items||[]).forEach(it=>{
          const uid=String(it.uid||(`${Number(it.id||0)}__${Number(it.copy_index||1)}`));
          if(skipUid && uid===skipUid) return;
          if(String(Number(it.id||0))===String(Number(storeId||0))){
            rows.push({dayIndex, day, item:it, weekIndex:dayWeekIndex(day)});
          }
        });
      });
      return rows;
    };
    const evaluatePlacementRules=(item, dayIndex, exceptUid)=>{
      const day=plan.days[dayIndex];
      if(!day) return {ok:false, reason:'Dia inválido'};
      const freq=Math.max(1, Number(item.frequency_count||1));
      const assigned=assignedEntriesForStore(item.id, exceptUid);
      if(assigned.length >= freq){
        return {ok:false, reason:'Periodicidade mensal cumprida'};
      }
      const targetWeek=dayWeekIndex(day);
      if(!targetWeek){
        return {ok:false, reason:'Semana inválida'};
      }
      if(item.target_date && day.date && String(item.target_date)!==String(day.date)){
        return {ok:false, reason:'Cadência fixa: visita pertence a '+String(item.target_date)};
      }
      if(item.target_week_key){
        const desiredWeek=weekStartKey(String(item.target_date||day.date||''));
        const dayWeek=weekStartKey(String(day.date||''));
        if(desiredWeek && dayWeek && desiredWeek!==dayWeek){
          return {ok:false, reason:'Cadência fixa: semana diferente'};
        }
      }
      const assignedWeeks=[...new Set(assigned.map(r=>Number(r.weekIndex||0)).filter(Boolean))].sort((a,b)=>a-b);
      if(assignedWeeks.includes(targetWeek)){
        return {ok:false, reason:'Já existe visita nesta semana'};
      }
      const weeks=[...assignedWeeks, targetWeek].sort((a,b)=>a-b);
      if(freq===2){
        if(weeks.length>2) return {ok:false, reason:'Periodicidade mensal cumprida'};
        if(weeks.length===2 && Math.abs(weeks[1]-weeks[0])===1){
          return {ok:false, reason:'P2 não permite semanas seguidas'};
        }
      } else if(freq===3){
        if(weeks.length>3) return {ok:false, reason:'Periodicidade mensal cumprida'};
        if(weeks.length===2){
          const diff=Math.abs(weeks[1]-weeks[0]);
          if(diff<1 || diff>2){
            return {ok:false, reason:'P3 exige semanas próximas e válidas'};
          }
        }
        if(weeks.length===3){
          const diffs=[weeks[1]-weeks[0], weeks[2]-weeks[1]].sort((a,b)=>a-b);
          if(!(diffs[0]===1 && diffs[1]===2)){
            return {ok:false, reason:'P3 exige padrão válido'};
          }
        }
      } else {
        if(weeks.length>freq){
          return {ok:false, reason:'Periodicidade mensal cumprida'};
        }
      }
      return {ok:true};
    };
    const dayOverrideKey=(dayIndex)=>{
      const idx=Number(dayIndex||0);
      const day=(plan.days||[])[idx]||{};
      return String((day.date||'sem-data')+'|'+idx);
    };
    const dayOverrideEnabled=(dayIndex)=>{
      const idx=Number(dayIndex||0);
      const day=(plan.days||[])[idx];
      const key=dayOverrideKey(idx);
      return !!(overrideState.has(key) ? overrideState.get(key) : (day && day.override_rules));
    };
    const setDayOverride=(dayIndex,enabled)=>{
      const idx=Number(dayIndex||0);
      if(!plan.days[idx]) return;
      const value=!!enabled;
      plan.days[idx].override_rules=value;
      overrideState.set(dayOverrideKey(idx), value);
      const live=root.querySelector('input[data-override-day="'+String(idx)+'"]');
      if(live) live.checked=value;
    };
    root.addEventListener('change',function(ev){
      const target=ev.target && ev.target.closest ? ev.target.closest('input[data-override-day]') : null;
      if(!target) return;
      const idx=Number(target.dataset.overrideDay||0);
      setDayOverride(idx, !!target.checked);
      dirty=true;
      save();
      renderDays(idx);
      renderAvailable();
      updateLiveWidgets();
    });
    const dayOverrideMeta=(dayIndex, item)=>{
      const reasons=[];
      const day=(plan.days||[])[Number(dayIndex||0)]||{items:[]};
      const rule=evaluatePlacementRules(item, Number(dayIndex||0));
      if(!rule.ok) reasons.push(rule.reason||'Regra de periodicidade');
      const candidate=(day.items||[]).map(clone);
      candidate.push(clone(item));
      const metrics=estimateDayMetrics(candidate);
      if(metrics.stops > maxStopsPerDay()) reasons.push('Excede Máx. visitas/dia');
      if(metrics.workMin > maxWorkMinutes()) reasons.push('Excede Horas úteis');
      return {enabled:dayOverrideEnabled(dayIndex), reasons, metrics};
    };
    const estimateDayMetrics=(items)=>{
      const ordered=twoOptRoute(buildGreedyRoute((items||[]).map(clone)));
      const visitMin=ordered.reduce((sum,it)=>sum + Number(it.visit_duration_min||45),0);
      const travelMin=routeTravelMinutes(ordered);
      const workMin=visitMin + travelMin + lunchMinutes();
      return {ordered, visitMin, travelMin, workMin, stops:ordered.length};
    };
    const normGeo=(v)=>String(v||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,' ').trim();
    const itemZone=(it)=>normGeo(it.city||it.district||it.address||'');
    const itemMacro=(it)=>normGeo(it.district||it.city||'');
    const itemAxis=(it)=>{ const sp=startPoint(); if(!isFinite(Number(sp.lat))||!isFinite(Number(sp.lng))||!isFinite(Number(it.lat))||!isFinite(Number(it.lng))) return itemMacro(it); const dLat=Number(it.lat)-Number(sp.lat), dLng=Number(it.lng)-Number(sp.lng); const ns=Math.abs(dLat)<0.035?'eixo':(dLat>0?'norte':'sul'); const ew=Math.abs(dLng)<0.045?'eixo':(dLng>0?'interior':'litoral'); return ns+'|'+ew; };
    const axisOpposition=(a,b)=>{ a=String(a||''); b=String(b||''); let p=0; if((a.includes('norte')&&b.includes('sul'))||(a.includes('sul')&&b.includes('norte'))) p+=620; if((a.includes('interior')&&b.includes('litoral'))||(a.includes('litoral')&&b.includes('interior'))) p+=320; return p; };
    const dayDominant=(day,fn)=>{ const m=new Map(); (day.items||[]).forEach(it=>{ const k=fn(it); if(k) m.set(k,(m.get(k)||0)+1); }); let best='', n=0; m.forEach((v,k)=>{ if(v>n){best=k;n=v;} }); return {key:best,count:n,map:m}; };
    const geoFitPenalty=(day,item)=>{
      const current=(day.items||[]);
      if(!current.length) return pointDistance(startPoint(), item)*1.4;
      let score=0;
      const z=itemZone(item), mz=itemMacro(item), ax=itemAxis(item);
      const dz=dayDominant(day,itemZone), dm=dayDominant(day,itemMacro), da=dayDominant(day,itemAxis);
      if(z){ if(dz.map.has(z)) score-=650+Math.min(350,dz.map.get(z)*110); else if(dz.key&&dz.key!==z) score+=520+current.length*65; }
      if(mz){ if(dm.map.has(mz)) score-=180; else if(dm.key&&dm.key!==mz) score+=300; }
      if(ax){ if(da.map.has(ax)) score-=520+Math.min(420,da.map.get(ax)*140); else if(da.key&&da.key!==ax) score+=360+axisOpposition(da.key,ax)+(current.length*120); }
      let nearest=Number.MAX_SAFE_INTEGER;
      current.forEach(it=>{ nearest=Math.min(nearest, pointDistance(it,item)); });
      if(isFinite(nearest)){ score+=Math.min(780, nearest*14); if(nearest<=6) score-=220; if(nearest>24) score+=Math.min(420,(nearest-24)*20); }
      let lat=0,lng=0,c=0;
      current.forEach(it=>{ if(isFinite(Number(it.lat))&&isFinite(Number(it.lng))){ lat+=Number(it.lat); lng+=Number(it.lng); c++; } });
      if(c>0 && isFinite(Number(item.lat))&&isFinite(Number(item.lng))){ const center={lat:lat/c,lng:lng/c}; const km=pointDistance(center,item); score+=Math.min(900,km*16); if(km>28) score+=Math.min(500,(km-28)*18); }
      return score;
    };
    const findBestDayForItem=(item)=>{
      let best=null;
      let firstInvalidReason='';
      let overrideBest=null;
      const softTarget=targetStopsPerDay();
      const totalStops=(plan.days||[]).reduce((sum,d)=>sum+((d.items||[]).length||0),0)+1;
      const avgStops=(plan.days||[]).length ? totalStops/(plan.days||[]).length : 0;
      plan.days.forEach((day,idx)=>{
        const rule=evaluatePlacementRules(item, idx);
        if(!rule.ok){
          if(!firstInvalidReason) firstInvalidReason=rule.reason||'Sem dia válido por periodicidade';
          return;
        }
        const candidate=(day.items||[]).map(clone);
        candidate.push(clone(item));
        const metrics=estimateDayMetrics(candidate);
        const exceedsStops=metrics.stops > maxStopsPerDay();
        const exceedsWork=metrics.workMin > maxWorkMinutes();
        const override=dayOverrideEnabled(idx);
        if((exceedsStops || exceedsWork) && !override){
          if(!firstInvalidReason) firstInvalidReason=(exceedsStops ? 'Excede Máx. visitas/dia' : 'Excede Horas úteis');
          return;
        }
        const currentStops=(day.items||[]).length;
        const targetPenalty=softTarget>0 ? (Math.max(0, metrics.stops-softTarget)**2*55 + Math.abs(metrics.stops-softTarget)*4) : Math.abs(metrics.stops-avgStops)*10;
        const balancePenalty=Math.max(0,currentStops-Math.floor(avgStops))*18;
        const geoPenalty=geoFitPenalty(day,item);
        const score=((exceedsStops || exceedsWork) && override ? 500000 : 0) + metrics.travelMin + (metrics.stops*3) + targetPenalty + balancePenalty + geoPenalty;
        const payload={dayIndex:idx, label:day.label||('Dia '+(idx+1)), score, exceedsStops, exceedsWork, metrics, overrideApplied: !!((exceedsStops || exceedsWork) && override), reason: (exceedsStops ? 'Excede Máx. visitas/dia' : (exceedsWork ? 'Excede Horas úteis' : ''))};
        if((exceedsStops || exceedsWork) && override){
          if(!overrideBest || score < overrideBest.score) overrideBest=payload;
          return;
        }
        if(!best || score < best.score){
          best=payload;
        }
      });
      return best || overrideBest || {invalid:true, reason:firstInvalidReason || 'Sem sugestão válida'};
    };
    const buildGreedyRoute=(items)=>{
      const pool=(items||[]).map(clone);
      if(pool.length<=1) return pool;
      const route=[];
      let current=startPoint();
      if(!isFinite(current.lat)||!isFinite(current.lng)) current=pool[0];
      while(pool.length){
        let bestIdx=0, bestDist=Number.MAX_SAFE_INTEGER;
        pool.forEach((item,idx)=>{ const dist=pointDistance(current,item); if(dist<bestDist){ bestDist=dist; bestIdx=idx; } });
        const next=pool.splice(bestIdx,1)[0];
        route.push(next);
        current=next;
      }
      return route;
    };
    const twoOptRoute=(items)=>{
      const route=items.slice();
      if(route.length<4) return route;
      let improved=true, guard=0;
      while(improved && guard<6){
        improved=false; guard++;
        for(let i=0;i<route.length-2;i++){
          for(let j=i+1;j<route.length-1;j++){
            const candidate=route.slice();
            const segment=candidate.slice(i,j+1).reverse();
            candidate.splice(i,j-i+1,...segment);
            if(routeDistance(candidate)+0.0001<routeDistance(route)){
              route.splice(0,route.length,...candidate);
              improved=true;
            }
          }
        }
      }
      return route;
    };
    const optimizeDay=(day)=>twoOptRoute(buildGreedyRoute((day&&day.items)||[]));
    const normalizeDayOrder=(dayIndex)=>{
      if(!plan.days[dayIndex]) return;
      plan.days[dayIndex].items=optimizeDay(plan.days[dayIndex]).map(clone);
    };
    const assignedIds=()=>{
      const ids=new Set();
      plan.days.forEach(day=>(day.items||[]).forEach(it=>ids.add(String(it.uid||(`${Number(it.id||0)}__${Number(it.copy_index||1)}`)))));
      return ids;
    };
    const assignedCountsByStore=()=>{
      const counts=new Map();
      plan.days.forEach(day=>(day.items||[]).forEach(it=>{
        const key=String(Number(it.id||0));
        counts.set(key, (counts.get(key)||0) + 1);
      }));
      return counts;
    };
    const availableItemsByRemainingNeed=()=>{
      const q=(availableSearch?.value||'').toLowerCase().trim();
      const assignedSet=assignedIds();
      const assignedCounts=assignedCountsByStore();
      const grouped=new Map();
      allItems.forEach(it=>{
        const key=String(Number(it.id||0));
        if(!grouped.has(key)) grouped.set(key, []);
        grouped.get(key).push(it);
      });
      const free=[];
      grouped.forEach(group=>{
        if(!group.length) return;
        const required=Math.max(1, Number(group[0].frequency_count||1));
        const assigned=Math.max(0, Number(assignedCounts.get(String(Number(group[0].id||0)))||0));
        const remaining=Math.max(0, required - assigned);
        if(remaining<=0) return;
        const candidates=group.filter(it=>!assignedSet.has(String(it.uid)));
        candidates.sort((a,b)=>Number(a.copy_index||1) - Number(b.copy_index||1));
        let added=0;
        candidates.forEach(it=>{
          if(added>=remaining) return;
          const hay=`${it.name||''} ${it.city||''} ${it.copy_label||''}`.toLowerCase();
          if(q && !hay.includes(q)) return;
          free.push(it);
          added++;
        });
      });
      return free;
    };
    const computeLiveSummary=()=>{
      const assignedSet=assignedIds();
      const freeItems=availableItemsByRemainingNeed();
      return {
        assignedStores: assignedSet.size,
        freeStores: freeItems.length,
        freePeriodicities: freeItems.length,
        totalStops: plan.days.reduce((sum,day)=>sum + ((day.items||[]).length||0),0),
        totalVisitMin: plan.days.reduce((sum,day)=>sum + (day.items||[]).reduce((s,it)=>s + Number(it.visit_duration_min||45),0),0),
        totalDistanceKm: plan.days.reduce((sum,day)=>sum + routeDistance(optimizeDay(day||{items:[]})),0)
      };
    };
    const updateLiveWidgets=()=>{
      const info=computeLiveSummary();
      if(liveAssigned) liveAssigned.textContent=String(info.totalStops);
      if(liveFree) liveFree.textContent=String(info.freeStores);
      if(liveFreePeriodicities){ const total=info.totalStops + info.freeStores; const pct=total>0 ? ((info.totalStops/total)*100).toFixed(1).replace('.',',') : '100,0'; liveFreePeriodicities.textContent=String(info.totalStops)+'/'+String(total)+' · '+pct+'%'; }
      if(liveDistance) liveDistance.textContent=info.totalDistanceKm.toFixed(1) + ' km';
      if(liveToll) liveToll.textContent=formatEuro(estimateToll(info.totalDistanceKm));
      if(liveStatus) liveStatus.textContent=dirty ? 'Alterações por gravar, a ordem final será otimizada por distância ao submeter' : 'Sem alterações guardadas';
      if(summaryText) summaryText.textContent=info.totalStops + ' visitas atualmente planeadas, ' + info.totalDistanceKm.toFixed(1) + ' km estimados, ' + info.totalVisitMin + ' min em loja, ' + info.freeStores + ' visitas ainda por alocar. Ao submeter, cada dia fica ordenado automaticamente do ponto de partida ao de chegada.';
    };
    const save=()=>{
      if(hidden){
        hidden.value=JSON.stringify({
          days: plan.days.map((day,idx)=>({label:day.label,date:day.date,override_rules: dayOverrideEnabled(idx), items:(day.items||[]).map(it=>({uid:String(it.uid||(`${Number(it.id||0)}__${Number(it.copy_index||1)}`)),id:it.id,copy_index:it.copy_index,target_date:it.target_date||'',target_week_key:it.target_week_key||'',preferred_weekday:it.preferred_weekday||0,cadence_label:it.cadence_label||''}))}))
        });
      }
      if(sourceHidden){ sourceHidden.value = dirty ? 'manual' : 'automatic'; }
      updateLiveWidgets();
    };
    const removeFromAll=(uid)=>{
      plan.days.forEach(day=>{ day.items=(day.items||[]).filter(it=>String(it.uid||(`${Number(it.id||0)}__${Number(it.copy_index||1)}`))!==String(uid)); });
    };
    const addToDay=(dayIndex,uid)=>{
      const base=itemMap.get(String(uid));
      if(!base||!plan.days[dayIndex]) return;
      const override=dayOverrideEnabled(dayIndex);
      const rule=evaluatePlacementRules(base, Number(dayIndex||0), String(base.uid||uid));
      if(!rule.ok){
        window.alert(rule.reason||'Esta loja não pode ser alocada neste dia sem quebrar periodicidade/cadência.');
        return;
      }
      const candidate=(plan.days[dayIndex].items||[]).map(clone);
      candidate.push(clone(base));
      const metrics=estimateDayMetrics(candidate);
      if(metrics.stops > maxStopsPerDay() && !override){
        window.alert('Excede Máx. visitas/dia.');
        return;
      }
      if(metrics.workMin > maxWorkMinutes() && !override){
        window.alert('Excede Horas úteis.');
        return;
      }
      removeFromAll(uid);
      plan.days[dayIndex].items.push(clone(base));
      normalizeDayOrder(dayIndex);
      dirty=true;
      renderDays(dayIndex);
    };
    const removeFromDay=(dayIndex,uid)=>{
      if(!plan.days[dayIndex]) return;
      plan.days[dayIndex].items=(plan.days[dayIndex].items||[]).filter(it=>String(it.uid||(`${Number(it.id||0)}__${Number(it.copy_index||1)}`))!==String(uid));
      normalizeDayOrder(dayIndex);
      dirty=true;
      renderDays(dayIndex);
    };
    const renderMap=(dayIndex)=>{
      focusDay=Number(dayIndex||0);
      if(!mapEl) return;
      const day=plan.days[focusDay]||plan.days[0];
      const points=[];
      const startLocked=formEl.querySelector('input[name="simulation_lock_start_point"]')?.checked;
      const endLocked=formEl.querySelector('input[name="simulation_lock_end_point"]')?.checked;
      const spLat=parseFloat(formEl.querySelector('input[name="simulation_start_lat"]')?.value||'');
      const spLng=parseFloat(formEl.querySelector('input[name="simulation_start_lng"]')?.value||'');
      const epLat=parseFloat(formEl.querySelector('input[name="simulation_end_lat"]')?.value||'');
      const epLng=parseFloat(formEl.querySelector('input[name="simulation_end_lng"]')?.value||'');
      if(startLocked && !Number.isNaN(spLat) && !Number.isNaN(spLng)) points.push({lat:spLat,lng:spLng,label:'Início'});
      optimizeDay(day||{items:[]}).forEach(it=>{ if(it.lat!==null && it.lng!==null){ points.push({lat:Number(it.lat),lng:Number(it.lng),label:it.name}); } });
      if(endLocked && !Number.isNaN(epLat) && !Number.isNaN(epLng)) points.push({lat:epLat,lng:epLng,label:'Fim'});
      if(!(window.google&&google.maps) || !points.length){ mapEl.innerHTML='<div>Seleciona um dia com coordenadas válidas para ver o trajeto no mapa.</div>'; return; }
      if(!map){ mapEl.innerHTML=''; map=new google.maps.Map(mapEl,{zoom:10,center:points[0]}); }
      markers.forEach(m=>m.setMap(null)); markers=[]; if(polyline) polyline.setMap(null);
      const bounds=new google.maps.LatLngBounds();
      points.forEach((p,idx)=>{ const marker=new google.maps.Marker({position:p,map:map,label:String(idx+1),title:p.label}); markers.push(marker); bounds.extend(p); });
      polyline=new google.maps.Polyline({path:points,map:map,strokeColor:'#2563eb',strokeOpacity:0.9,strokeWeight:4});
      if(points.length===1){ map.setCenter(points[0]); map.setZoom(12); } else { map.fitBounds(bounds,{top:40,right:40,bottom:40,left:40}); }
    };
    const bindDraggables=()=>{
      root.querySelectorAll('.routespro-editor-item[draggable="true"]').forEach(el=>{
        if(el.dataset.dragBound==='1') return;
        el.dataset.dragBound='1';
        el.addEventListener('dragstart',e=>{ e.dataTransfer.setData('text/plain', el.dataset.itemUid||''); e.dataTransfer.effectAllowed='move'; });
      });
      if(availableWrap && availableWrap.dataset.dropBound!=='1'){
        availableWrap.dataset.dropBound='1';
        availableWrap.addEventListener('dragover',e=>e.preventDefault());
        availableWrap.addEventListener('drop',e=>{ e.preventDefault(); const uid=e.dataTransfer.getData('text/plain'); if(uid){ removeFromAll(uid); dirty=true; renderDays(); } });
      }
    };
    const renderAvailable=()=>{
      const q=(availableSearch?.value||'').trim().toLowerCase();
      const items=availableItemsByRemainingNeed().filter(it=>!q || `${it.name} ${it.city} ${it.address} ${it.copy_label||''}`.toLowerCase().includes(q));
      if(availableCount) availableCount.textContent=`${items.length} visitas livres`;
      if(!availableWrap) return;
      availableWrap.innerHTML=items.length ? items.map(it=>{ const best=findBestDayForItem(it); const isValid=!!(best && !best.invalid); const suggestion=isValid ? `${best.label}${best.overrideApplied ? ' · exceção' : ''} · encaixe recomendado` : (best && best.reason ? best.reason : 'Sem sugestão válida'); return `<div class="routespro-editor-item" draggable="true" data-item-uid="${escapeHtml(it.uid)}" style="padding:10px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;cursor:grab"><div style="display:flex;justify-content:space-between;gap:8px;align-items:start"><div><strong>${escapeHtml(it.name)}</strong><div style="font-size:12px;color:#64748b">${escapeHtml(it.city)} · ${escapeHtml(it.periodicity_label||it.visit_frequency)}${it.copy_label ? ' · '+escapeHtml(it.copy_label) : ''} · ${it.visit_duration_min||45} min</div><div style="margin-top:6px;font-size:11px;color:${isValid ? '#065f46' : '#92400e'}">Melhor dia: ${escapeHtml(suggestion)}</div></div><div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end"><button type="button" class="button button-secondary button-small" data-suggest-day="${escapeHtml(it.uid)}" title="Sugerir melhor dia" ${isValid ? '' : 'disabled'}>Sugerir melhor dia</button>${plan.days.map((d,idx)=>{ const dayRule=evaluatePlacementRules(it, idx); const override=dayOverrideEnabled(idx); const canAdd=!!dayRule.ok; const titleText=canAdd ? ('Adicionar a '+(d.label||('Dia '+(idx+1))) + (override ? ' · exceção só para carga/horas' : '')) : (dayRule.reason||'Bloqueado por periodicidade'); return `<button type="button" class="button button-small" data-add-to-day="${escapeHtml(it.uid)}" data-day-index="${idx}" title="${escapeHtml(titleText)}" ${canAdd ? '' : 'disabled'}>${override ? '±' : '+'}</button>`; }).join('')}</div></div></div>`; }).join('') : '<div style="padding:12px;border:1px dashed #cbd5e1;border-radius:12px;color:#64748b;background:#f8fafc">Sem lojas livres com este filtro.</div>';
      availableWrap.querySelectorAll('[data-add-to-day]').forEach(btn=>btn.addEventListener('click',()=>addToDay(Number(btn.dataset.dayIndex), btn.dataset.addToDay)));
      availableWrap.querySelectorAll('[data-suggest-day]').forEach(btn=>btn.addEventListener('click',()=>{ const uid=String(btn.dataset.suggestDay||''); const item=itemMap.get(uid); if(!item) return; const best=findBestDayForItem(item); if(best && !best.invalid) addToDay(Number(best.dayIndex||0), uid); else if(best && best.reason){ window.alert(best.reason); } }));
      bindDraggables();
    };
    const renderDays=(focusIndex=focusDay)=>{
      if(!daysWrap) return;
      daysWrap.innerHTML=plan.days.map((day,dayIndex)=>{ const stopCount=(day.items||[]).length; const visitMinutes=(day.items||[]).reduce((sum,it)=>sum + Number(it.visit_duration_min||45),0); const optimizedOrder=optimizeDay(day); const dayKm=routeDistance(optimizedOrder); const optimizedNames=optimizedOrder.map(it=>it.name).join(' → '); return `<div class="routespro-day-card" data-day-index="${dayIndex}" style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:14px"><div style="display:flex;justify-content:space-between;gap:8px;align-items:start"><div><div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.06em">${escapeHtml(day.label||('Dia '+(dayIndex+1)))}</div><div style="margin-top:4px;color:#475569;font-weight:700">${escapeHtml(day.date||'')}</div><div style="margin-top:6px;font-size:12px;color:#0369a1"><span>${stopCount} lojas</span> · <span>${dayKm.toFixed(1)} km</span> · <span>${visitMinutes} min em loja</span>${dayOverrideEnabled(dayIndex) ? ' · <span style="color:#b45309;font-weight:700">Exceção ativa</span>' : ''}</div><label style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;font-size:12px;color:${dayOverrideEnabled(dayIndex) ? '#92400e' : '#475569'};background:${dayOverrideEnabled(dayIndex) ? '#fffbeb' : 'transparent'};border:${dayOverrideEnabled(dayIndex) ? '1px solid #f59e0b' : '1px solid transparent'};border-radius:999px;padding:5px 8px"><input type="checkbox" data-override-day="${dayIndex}" ${dayOverrideEnabled(dayIndex) ? 'checked' : ''}> ${dayOverrideEnabled(dayIndex) ? 'Exceção ativa neste dia' : 'Permitir exceções neste dia'}</label></div><div style="display:flex;gap:8px;align-items:center"><button type="button" class="button button-small" data-optimize-day="${dayIndex}">Otimizar ordem</button><button type="button" class="button-link" data-focus-day="${dayIndex}">Ver no mapa</button></div></div><div class="routespro-day-dropzone" data-day-index="${dayIndex}" style="min-height:120px;margin-top:12px;padding:10px;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc;display:flex;flex-direction:column;gap:8px">${(day.items||[]).length ? (day.items||[]).map((it,itemIndex)=>`<div class="routespro-editor-item" draggable="true" data-item-uid="${escapeHtml(it.uid||(`${Number(it.id||0)}__${Number(it.copy_index||1)}`))}" style="padding:10px;border:1px solid #dbeafe;border-radius:12px;background:#fff;cursor:grab"><div style="display:flex;justify-content:space-between;gap:8px;align-items:start"><div><div style="font-size:11px;color:#0369a1;font-weight:700;margin-bottom:4px">#${itemIndex+1}</div><strong>${escapeHtml(it.name)}</strong><div style="font-size:12px;color:#64748b">${escapeHtml(it.city)} · ${escapeHtml(it.periodicity_label||it.visit_frequency)}${it.copy_label ? ' · '+escapeHtml(it.copy_label) : ''} · ${it.visit_duration_min||45} min</div></div><div style="display:flex;gap:6px;flex-direction:column"><button type="button" class="button button-small" data-remove-item="${escapeHtml(it.uid||(`${Number(it.id||0)}__${Number(it.copy_index||1)}`))}" data-day-index="${dayIndex}" title="Retirar">-</button></div></div></div>`).join('') : '<div style="padding:12px;border-radius:10px;color:#64748b;background:#fff">Arrasta lojas para aqui ou usa o mais na lista lateral.</div>'}</div><div style="margin-top:10px;font-size:12px;color:#64748b">Ordem otimizada ao submeter: ${optimizedNames ? escapeHtml(optimizedNames) : 'Sem visitas neste dia.'}</div></div>`; }).join('');
      daysWrap.querySelectorAll('[data-remove-item]').forEach(btn=>btn.addEventListener('click',()=>removeFromDay(Number(btn.dataset.dayIndex), btn.dataset.removeItem)));
      daysWrap.querySelectorAll('[data-optimize-day]').forEach(btn=>btn.addEventListener('click',()=>{ const idx=Number(btn.dataset.optimizeDay); normalizeDayOrder(idx); dirty=true; renderDays(idx); }));
      daysWrap.querySelectorAll('[data-override-day]').forEach(chk=>chk.addEventListener('change',()=>{ const idx=Number(chk.dataset.overrideDay); if(!plan.days[idx]) return; setDayOverride(idx, !!chk.checked); dirty=true; save(); renderDays(idx); renderAvailable(); updateLiveWidgets(); }));
      daysWrap.querySelectorAll('[data-focus-day]').forEach(btn=>btn.addEventListener('click',()=>renderMap(Number(btn.dataset.focusDay))));
      daysWrap.querySelectorAll('.routespro-day-dropzone').forEach(zone=>{
        zone.addEventListener('dragover',e=>{ e.preventDefault(); zone.style.borderColor='#2563eb'; zone.style.background='#eff6ff'; });
        zone.addEventListener('dragleave',()=>{ zone.style.borderColor='#cbd5e1'; zone.style.background='#f8fafc'; });
        zone.addEventListener('drop',e=>{ e.preventDefault(); zone.style.borderColor='#cbd5e1'; zone.style.background='#f8fafc'; const uid=e.dataTransfer.getData('text/plain'); if(uid) addToDay(Number(zone.dataset.dayIndex), uid); });
      });
      bindDraggables();
      renderAvailable();
      save();
      renderMap(focusIndex);
    };
    if(availableSearch) availableSearch.addEventListener('input',renderAvailable);
    renderDays(0);
    save();
    updateLiveWidgets();
    formEl.addEventListener('submit',save);
  };
  let t=null;
  const schedule=()=>{ clearTimeout(t); t=setTimeout(run,180); };
  const run=()=>{
    syncReadonly();
    syncExtra();
    if(!preview) return;
    const fd=new FormData();
    fd.append('action','routespro_campaign_plan_preview');
    fd.append('routespro_campaign_locations_nonce',form.querySelector('input[name="routespro_campaign_locations_nonce"]')?.value||'');
    fd.append('project_id',form.querySelector('input[name="project_id"]')?.value||'0');
    fd.append('owner_user_id',form.querySelector('select[name="owner_user_id"]')?.value||'0');
    fd.append('plan_scope',form.querySelector('select[name="plan_scope"]')?.value||'weekly');
    fd.append('holiday_country',form.querySelector('select[name="holiday_country"]')?.value||'pt');
    fd.append('simulation_route_strategy',form.querySelector('select[name="simulation_route_strategy"]')?.value||'complete_coverage');
    fd.append('week_start',form.querySelector('input[name="week_start"]')?.value||'');
    fd.append('simulation_max_stops',form.querySelector('input[name="simulation_max_stops"]')?.value||'12');
    fd.append('simulation_target_stops',form.querySelector('select[name="simulation_target_stops"],input[name="simulation_target_stops"]')?.value||'0');
    fd.append('simulation_distance_sensitivity',form.querySelector('select[name="simulation_distance_sensitivity"],input[name="simulation_distance_sensitivity"]')?.value||'normal');
    const workHours=parseFloat(form.querySelector('input[name="simulation_work_hours"]')?.value||'8')||8;
    fd.append('simulation_work_minutes',String(Math.round(workHours*60)));
    fd.append('simulation_lunch_minutes',form.querySelector('input[name="simulation_lunch_minutes"]')?.value||'60');
    fd.append('simulation_overtime_extra_minutes',form.querySelector('input[name="simulation_overtime_extra_minutes"]')?.value||'0');
    fd.append('simulation_start_address',form.querySelector('input[name="simulation_start_address"]')?.value||'');
    fd.append('simulation_start_lat',form.querySelector('input[name="simulation_start_lat"]')?.value||'');
    fd.append('simulation_start_lng',form.querySelector('input[name="simulation_start_lng"]')?.value||'');
    fd.append('simulation_end_address',form.querySelector('input[name="simulation_end_address"]')?.value||'');
    fd.append('simulation_end_lat',form.querySelector('input[name="simulation_end_lat"]')?.value||'');
    fd.append('simulation_end_lng',form.querySelector('input[name="simulation_end_lng"]')?.value||'');
    if(form.querySelector('input[name="simulation_allow_overtime"]')?.checked) fd.append('simulation_allow_overtime','1');
    if(form.querySelector('input[name="simulation_allow_extra_visits"]')?.checked) fd.append('simulation_allow_extra_visits','1');
    if(form.querySelector('input[name="simulation_lock_start_point"]')?.checked) fd.append('simulation_lock_start_point','1');
    if(form.querySelector('input[name="simulation_lock_end_point"]')?.checked) fd.append('simulation_lock_end_point','1');
    form.querySelectorAll('input[name="simulation_overtime_dates[]"]:checked').forEach(el=>fd.append('simulation_overtime_dates[]',el.value));
    form.querySelectorAll('input[name^="simulation_overtime_minutes["]').forEach(el=>{ const m=el.dataset.minutes||'0'; if(m!=='0') fd.append(el.name,m); });
    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd})
      .then(r=>r.json())
      .then(resp=>{ if(resp&&resp.success&&resp.data&&resp.data.html!==undefined){ preview.innerHTML=resp.data.html; if(window.routesproInitPlanEditor) window.routesproInitPlanEditor(preview, form); } })
      .catch(()=>{});
  };
  form.addEventListener('change',function(e){ if(!e.target) return; if(e.target.matches('[data-override-day],[data-manual-override-day]')){ e.stopPropagation(); return; } if(e.target.closest('#routespro-plan-editor-root')) return; schedule(); });
  form.addEventListener('input',function(e){ if(!e.target) return; if(e.target.closest('#routespro-plan-editor-root')) return; if(e.target.name==='simulation_overtime_extra_hours' || e.target.name.indexOf('simulation_overtime_minutes[')===0 || e.target.name==='simulation_work_hours') schedule(); });
  syncReadonly();
  syncExtra();
  if(window.routesproInitPlanEditor) window.routesproInitPlanEditor(preview, form);
})();
</script>
HTML;
            echo '<script>(function(){const root=document.querySelector(".routespro-campaign-page"); if(!root) return; const tabs=root.querySelectorAll("[data-campaign-tab]"); const panels=root.querySelectorAll("[data-campaign-tab-panel]"); function show(id){ tabs.forEach(function(t){t.classList.toggle("is-active",t.dataset.campaignTab===id);}); panels.forEach(function(p){p.hidden=p.dataset.campaignTabPanel!==id;}); if(window.location.hash!=="#"+id){ history.replaceState(null,"",window.location.pathname+window.location.search+"#"+id); } } tabs.forEach(function(t){t.addEventListener("click",function(){show(t.dataset.campaignTab);});}); const initial=(window.location.hash||"").replace("#",""); if(initial&&root.querySelector("[data-campaign-tab=\""+initial+"\"]")){show(initial);} })();</script>';
            echo '<script>(function(){const mapsProvider=' . wp_json_encode($mapsProvider) . '; const mapsKey=' . wp_json_encode($gmKey) . '; function loadGoogle(cb){ if(mapsProvider!="google"||!mapsKey){ cb(false); return; } if(window.google&&google.maps&&google.maps.places){ cb(true); return; } const existing=document.querySelector("script[data-routespro-google=\\"1\\"]"); if(existing){ existing.addEventListener("load",function(){ cb(true); },{once:true}); return; } const s=document.createElement("script"); s.src="https://maps.googleapis.com/maps/api/js?key="+encodeURIComponent(mapsKey)+"&libraries=places"; s.async=true; s.defer=true; s.dataset.routesproGoogle="1"; s.onload=function(){ cb(true); }; s.onerror=function(){ cb(false); }; document.head.appendChild(s); } function refs(input){ return { lat: document.querySelector(input.dataset.lat||""), lng: document.querySelector(input.dataset.lng||"") }; } function trigger(input){ if(input) input.dispatchEvent(new Event("change",{bubbles:true})); } function applyPlace(input, place){ if(!input||!place) return; const r=refs(input); input.value=place.formatted_address||input.value||""; if(place.geometry&&r.lat&&r.lng){ r.lat.value=String(place.geometry.location.lat()); r.lng.value=String(place.geometry.location.lng()); trigger(r.lat); trigger(r.lng); } trigger(input); } function bindOne(input){ if(!input||input.dataset.routesproAcBound==="1"||!(window.google&&google.maps&&google.maps.places&&google.maps.places.Autocomplete)) return; input.dataset.routesproAcBound="1"; const ac=new google.maps.places.Autocomplete(input,{componentRestrictions:{country:["pt","es"]},fields:["formatted_address","geometry","name"]}); ac.addListener("place_changed",function(){ applyPlace(input, ac.getPlace()); }); input.addEventListener("input",function(){ const r=refs(input); if(r.lat) r.lat.value=""; if(r.lng) r.lng.value=""; }); input.addEventListener("blur",function(){ const r=refs(input); if(!input.value.trim()||!r.lat||!r.lng||r.lat.value||r.lng.value||!(window.google&&google.maps&&google.maps.Geocoder)) return; const geocoder=new google.maps.Geocoder(); geocoder.geocode({address:input.value.trim()}, function(results,status){ if(status==="OK"&&results&&results[0]) applyPlace(input, results[0]); }); }); } loadGoogle(function(ok){ if(!ok) return; document.querySelectorAll(".routespro-simulation-address").forEach(bindOne); }); })();</script>';
            echo '</div>';
            echo '</section>';
            echo '</div>';
        }
        if (!$project_id) { echo '</div>'; }
        echo '</div>';
    }




    private static function format_km(float $km): string {
        return number_format(max(0.0, $km), 1, ',', ' ') . ' km';
    }

    private static function count_plan_days_with_stops(array $plan): int {
        $days = !empty($plan['preview_days']) && is_array($plan['preview_days']) ? (array)$plan['preview_days'] : (array)($plan['days'] ?? []);
        $count = 0;
        foreach ($days as $day) {
            if (count((array)($day['items'] ?? [])) > 0) $count++;
        }
        return $count;
    }

    private static function plan_required_visit_stats(array $linked_rows, array $plan = []): array {
        $pdvs = 0;
        $configuredVisits = 0;
        $configuredVisitMin = 0;
        foreach ($linked_rows as $row) {
            if (empty($row['campaign_active']) || ($row['campaign_status'] ?? 'active') !== 'active') continue;
            $pdvs++;
            $freq = max(1, (int)($row['frequency_count'] ?? 1));
            $dur = max(0, (int)($row['visit_duration_min'] ?? 45));
            $configuredVisits += $freq;
            $configuredVisitMin += ($freq * $dur);
        }
        $plannedVisits = 0;
        $plannedVisitMin = 0;
        $days = !empty($plan['preview_days']) && is_array($plan['preview_days']) ? (array)$plan['preview_days'] : (array)($plan['days'] ?? []);
        foreach ($days as $day) {
            foreach ((array)($day['items'] ?? []) as $item) {
                $plannedVisits++;
                $plannedVisitMin += max(0, (int)($item['visit_duration_min'] ?? 45));
            }
        }
        // A meta operacional e comercial é sempre a periodicidade configurada.
        // Não usamos max(configurado, planeado), porque isso escondia sobre-planeamento
        // em meses com 5 ocorrências do mesmo dia da semana.
        $requiredVisits = $configuredVisits;
        $requiredVisitMin = $configuredVisitMin;
        $missingVisits = max(0, $requiredVisits - $plannedVisits);
        $overplannedVisits = max(0, $plannedVisits - $requiredVisits);
        return [
            'pdvs' => $pdvs,
            'configured_visits' => $configuredVisits,
            'configured_visit_min' => $configuredVisitMin,
            'planned_visits' => $plannedVisits,
            'planned_visit_min' => $plannedVisitMin,
            'required_visits' => $requiredVisits,
            'required_visit_min' => $requiredVisitMin,
            'missing_visits' => $missingVisits,
            'overplanned_visits' => $overplannedVisits,
            'coverage_pct' => $requiredVisits > 0 ? round((min($plannedVisits, $requiredVisits) / $requiredVisits) * 100, 1) : 100.0,
        ];
    }

    private static function format_coverage_label(array $stats): string {
        $planned = (int)($stats['planned_visits'] ?? 0);
        $required = max(0, (int)($stats['required_visits'] ?? 0));
        $pct = (float)($stats['coverage_pct'] ?? 0);
        $extra = max(0, (int)($stats['overplanned_visits'] ?? 0));
        $suffix = $extra > 0 ? (' · +' . $extra . ' extra') : '';
        return $required > 0 ? ($planned . '/' . $required . $suffix . ' · ' . number_format($pct, 1, ',', ' ') . '%') : '0/0 · 100,0%';
    }

    private static function estimate_plan_distance_km(array $plan, array $simulation_options = []): float {
        $days = !empty($plan['preview_days']) && is_array($plan['preview_days']) ? (array)$plan['preview_days'] : (array)($plan['days'] ?? []);
        $total = 0.0;
        foreach ($days as $day) {
            $total += self::estimate_plan_day_distance_km((array)$day, $simulation_options);
        }
        return round($total, 2);
    }

    private static function estimate_plan_toll_cost_eur(array $plan, array $simulation_options = []): float {
        $days = !empty($plan['preview_days']) && is_array($plan['preview_days']) ? (array)$plan['preview_days'] : (array)($plan['days'] ?? []);
        $total = 0.0;
        foreach ($days as $day) {
            $dayKm = self::estimate_plan_day_distance_km((array)$day, $simulation_options);
            $total += \RoutesPro\Support\TollEstimator::costFromKm($dayKm, 'route');
        }
        return round($total, 2);
    }

    private static function estimate_plan_day_distance_km(array $day, array $simulation_options = []): float {
        $items = array_values((array)($day['items'] ?? []));
        if (!$items) return 0.0;
        $options = self::normalize_plan_options($simulation_options);
        if (!empty($day['start_point']) && is_array($day['start_point'])) $options['start_point'] = (array)$day['start_point'];
        if (!empty($day['end_point']) && is_array($day['end_point'])) $options['end_point'] = (array)$day['end_point'];
        $ordered = self::optimize_day_items($items, $options);
        $start = is_array($options['start_point'] ?? null) ? (array)$options['start_point'] : [];
        $end = is_array($options['end_point'] ?? null) ? (array)$options['end_point'] : [];
        $total = 0.0;
        $prev = self::point_has_coordinates($start) ? $start : [];
        if (!$prev && $ordered) $prev = (array)$ordered[0];
        foreach ($ordered as $item) {
            $item = (array)$item;
            $total += self::safe_haversine_between_points($prev, $item);
            $prev = $item;
        }
        if (self::point_has_coordinates($end)) {
            $total += self::safe_haversine_between_points($prev, $end);
        }
        return round($total, 2);
    }

    private static function point_has_coordinates(array $point): bool {
        return is_numeric($point['lat'] ?? null) && is_numeric($point['lng'] ?? null);
    }

    private static function safe_haversine_between_points(array $from, array $to): float {
        if (!self::point_has_coordinates($from) || !self::point_has_coordinates($to)) return 0.0;
        return (float)self::haversine_km((float)$from['lat'], (float)$from['lng'], (float)$to['lat'], (float)$to['lng']);
    }

    private static function get_period_date_range(string $base_date, string $scope): array {
        $ts = strtotime($base_date ?: date('Y-m-d'));
        if (!$ts) $ts = current_time('timestamp');
        if ($scope === 'monthly') {
            // Em planeamento mensal, a data base é o arranque real do período operacional.
            // Ex.: base 06.07.2026 deve planear de 06.07 até ao fim do mês, não desde 01.07.
            return [date('Y-m-d', $ts), date('Y-m-t', $ts)];
        }
        $monday = strtotime('monday this week', $ts);
        if (!$monday) $monday = $ts;
        return [date('Y-m-d', $monday), date('Y-m-d', strtotime('+6 days', $monday))];
    }

    private static function get_created_routes_summary(int $project_id, int $owner_user_id, string $base_date, string $scope): array {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        [$dateFrom, $dateTo] = self::get_period_date_range($base_date, $scope);
        $where = ['r.project_id=%d', 'r.date BETWEEN %s AND %s'];
        $args = [$project_id, $dateFrom, $dateTo];
        if ($owner_user_id > 0) {
            $where[] = 'r.owner_user_id=%d';
            $args[] = $owner_user_id;
        }
        $sql = "SELECT r.*, owner.display_name AS owner_name, owner.user_login AS owner_login, (SELECT COUNT(*) FROM {$px}route_stops rs_count WHERE rs_count.route_id=r.id) AS stops_count
                FROM {$px}routes r
                LEFT JOIN {$wpdb->users} owner ON owner.ID=r.owner_user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY r.date ASC, r.id ASC";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
        $routes = [];
        $totalKm = 0.0;
        $totalToll = 0.0;
        $totalStops = 0;
        foreach ($rows as $row) {
            $routeId = (int)($row['id'] ?? 0);
            $meta = !empty($row['meta_json']) ? json_decode((string)$row['meta_json'], true) : [];
            if (!is_array($meta)) $meta = [];
            $km = self::extract_route_distance_km($meta);
            if ($km <= 0 && $routeId > 0) $km = self::estimate_stored_route_distance_km($routeId, $meta);
            $toll = self::extract_route_toll_cost_eur($meta);
            if ($toll <= 0 && $km > 0) $toll = \RoutesPro\Support\TollEstimator::costFromKm($km, 'route');
            $stops = (int)($row['stops_count'] ?? 0);
            $totalKm += $km;
            $totalToll += $toll;
            $totalStops += $stops;
            $exportBase = admin_url('admin-post.php?action=routespro_export_routes&route_id=' . $routeId);
            $routes[] = [
                'id' => $routeId,
                'date' => (string)($row['date'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'owner_name' => (string)($row['owner_name'] ?: ($row['owner_login'] ?? '')),
                'stops_count' => $stops,
                'distance_km' => round($km, 2),
                'toll_cost_eur' => round($toll, 2),
                'edit_url' => admin_url('admin.php?page=routespro-routes&edit=' . $routeId . '&client_id=' . (int)($row['client_id'] ?? 0)),
                'csv_url' => wp_nonce_url(add_query_arg(['format' => 'csv'], $exportBase), 'routespro_export_routes'),
                'xls_url' => wp_nonce_url(add_query_arg(['format' => 'xls'], $exportBase), 'routespro_export_routes'),
                'pdf_url' => wp_nonce_url(add_query_arg(['format' => 'pdf'], $exportBase), 'routespro_export_routes'),
            ];
        }
        return [
            'routes' => $routes,
            'route_count' => count($routes),
            'stops_count' => $totalStops,
            'distance_km' => round($totalKm, 2),
            'toll_cost_eur' => round($totalToll, 2),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    private static function extract_route_distance_km(array $meta): float {
        foreach ([['route_metrics','distance_km'], ['plan_summary','distance_km'], ['metrics','distance_km']] as $path) {
            $bucket = $meta[$path[0]] ?? null;
            if (is_array($bucket) && isset($bucket[$path[1]]) && is_numeric($bucket[$path[1]])) return (float)$bucket[$path[1]];
        }
        if (isset($meta['distance_km']) && is_numeric($meta['distance_km'])) return (float)$meta['distance_km'];
        return 0.0;
    }

    private static function extract_route_toll_cost_eur(array $meta): float {
        foreach ([['route_metrics','toll_cost_eur'], ['route_metrics','toll_estimated_eur'], ['plan_summary','toll_cost_eur'], ['plan_summary','toll_estimated_eur'], ['metrics','toll_cost_eur']] as $path) {
            $bucket = $meta[$path[0]] ?? null;
            if (is_array($bucket) && isset($bucket[$path[1]]) && is_numeric($bucket[$path[1]])) return (float)$bucket[$path[1]];
        }
        if (isset($meta['toll_cost_eur']) && is_numeric($meta['toll_cost_eur'])) return (float)$meta['toll_cost_eur'];
        return 0.0;
    }

    private static function estimate_stored_route_distance_km(int $route_id, array $route_meta = []): float {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $stops = $wpdb->get_results($wpdb->prepare("SELECT meta_json FROM {$px}route_stops WHERE route_id=%d ORDER BY seq ASC, id ASC", $route_id), ARRAY_A) ?: [];
        $km = 0.0;
        foreach ($stops as $stop) {
            $meta = !empty($stop['meta_json']) ? json_decode((string)$stop['meta_json'], true) : [];
            if (!is_array($meta)) $meta = [];
            if (isset($meta['distance_from_prev_km']) && is_numeric($meta['distance_from_prev_km'])) $km += (float)$meta['distance_from_prev_km'];
        }
        $metrics = is_array($route_meta['route_metrics'] ?? null) ? (array)$route_meta['route_metrics'] : (is_array($route_meta['plan_summary'] ?? null) ? (array)$route_meta['plan_summary'] : []);
        if (isset($metrics['end_leg_distance_km']) && is_numeric($metrics['end_leg_distance_km'])) $km += (float)$metrics['end_leg_distance_km'];
        return round($km, 2);
    }

    private static function render_created_routes_summary_html(array $summary): string {
        $routes = (array)($summary['routes'] ?? []);
        ob_start();
        echo '<div class="routespro-card" style="margin-top:18px">';
        echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:start;flex-wrap:wrap"><div><h2 style="margin:0">Rotas criadas no período</h2><p style="margin:6px 0 0;color:#64748b">Mostra as rotas já atribuídas para a campanha, owner e período selecionados na data base.</p></div><div style="font-size:12px;color:#64748b">Período: '.esc_html(date_i18n('d/m/Y', strtotime((string)($summary['date_from'] ?? '')))).' a '.esc_html(date_i18n('d/m/Y', strtotime((string)($summary['date_to'] ?? '')))).'</div></div>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:14px 0">';
        echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Rotas atribuídas</div><div style="font-size:24px;font-weight:800;color:#0f172a">'.intval($summary['route_count'] ?? 0).'</div></div>';
        echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">PDVs em rotas</div><div style="font-size:24px;font-weight:800;color:#0f172a">'.intval($summary['stops_count'] ?? 0).'</div></div>';
        echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Kms estimados criados</div><div style="font-size:24px;font-weight:800;color:#0f172a">'.esc_html(self::format_km((float)($summary['distance_km'] ?? 0))).'</div></div>';
        echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Portagens estimadas</div><div style="font-size:24px;font-weight:800;color:#0f172a">'.esc_html(\RoutesPro\Support\TollEstimator::formatEuro((float)($summary['toll_cost_eur'] ?? 0))).'</div></div>';
        echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Média km/rota</div><div style="font-size:24px;font-weight:800;color:#0f172a">'.esc_html(self::format_km(!empty($summary['route_count']) ? ((float)($summary['distance_km'] ?? 0) / max(1, (int)$summary['route_count'])) : 0)).'</div></div>';
        echo '</div>';
        if (!$routes) {
            echo '<p style="margin:0;color:#64748b">Ainda não existem rotas criadas para este filtro. Quando aplicares a sugestão ou gravares o planeamento manual, elas aparecem aqui.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Owner</th><th>PDVs</th><th>Kms estimados</th><th>Portagens estimadas</th><th>Estado</th><th>Ações</th></tr></thead><tbody>';
            foreach ($routes as $route) {
                echo '<tr><td>'.esc_html(date_i18n('d/m/Y', strtotime((string)($route['date'] ?? '')))).'</td><td>'.esc_html((string)($route['owner_name'] ?? '')).'</td><td>'.intval($route['stops_count'] ?? 0).'</td><td>'.esc_html(self::format_km((float)($route['distance_km'] ?? 0))).'</td><td>'.esc_html(\RoutesPro\Support\TollEstimator::formatEuro((float)($route['toll_cost_eur'] ?? 0))).'</td><td>'.esc_html((string)($route['status'] ?? '')).'</td><td><a class="button button-small" href="'.esc_url((string)$route['edit_url']).'">Editar</a> <a class="button button-small" href="'.esc_url((string)$route['csv_url']).'">CSV</a> <a class="button button-small" href="'.esc_url((string)$route['xls_url']).'">Excel</a> <a class="button button-small" href="'.esc_url((string)$route['pdf_url']).'">PDF</a></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        return (string)ob_get_clean();
    }

    private static function update_campaign_link_plan(int $link_id, array $data): bool {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        if ($link_id <= 0) return false;
        $visit_frequency = sanitize_text_field($data['visit_frequency'] ?? 'weekly');
        if (!in_array($visit_frequency, ['weekly','monthly'], true)) $visit_frequency = 'weekly';
        $frequency_count = max(1, min(7, absint($data['frequency_count'] ?? 1)));
        $visit_duration_min = max(0, min(360, absint($data['visit_duration_min'] ?? 45)));
        $priority = max(0, min(999, absint($data['priority'] ?? 0)));
        $status = sanitize_text_field($data['status'] ?? 'active');
        if (!in_array($status, ['active','paused'], true)) $status = 'active';
        $is_active = empty($data['is_active']) ? 0 : 1;
        $assigned_to = absint($data['assigned_to'] ?? 0);
        $advancedRule = VisitRuleResolver::sanitizeForStorage($data);
        $payload = array_merge([
            'visit_frequency' => $visit_frequency,
            'frequency_count' => $frequency_count,
            'visit_duration_min' => $visit_duration_min,
            'priority' => $priority,
            'status' => $status,
            'is_active' => $is_active,
            'assigned_to' => $assigned_to ?: null,
        ], $advancedRule);
        $updated = $wpdb->update($px . 'campaign_locations', $payload, ['id' => $link_id], ['%s','%d','%d','%d','%s','%d','%d','%d','%d','%s','%s','%s','%s','%d','%d','%s'], ['%d']);
        return $updated !== false;
    }

    public static function ajax_plan_preview(): void {
        if (!current_user_can('routespro_manage')) wp_send_json_error(['message' => 'forbidden'], 403);
        check_ajax_referer('routespro_campaign_locations', 'routespro_campaign_locations_nonce');
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $project_id = absint($_POST['project_id'] ?? 0);
        $owner_user_id = absint($_POST['owner_user_id'] ?? 0);
        $plan_scope = sanitize_text_field($_POST['plan_scope'] ?? 'weekly');
        if (!in_array($plan_scope, ['weekly','monthly'], true)) $plan_scope = 'weekly';
        $week_start = sanitize_text_field($_POST['week_start'] ?? date('Y-m-d'));
        $holiday_country = strtolower(sanitize_text_field($_POST['holiday_country'] ?? 'pt'));
        if (!in_array($holiday_country, ['pt','es'], true)) $holiday_country = 'pt';
        $simulation_options = self::normalize_plan_options([
            'max_stops_per_day' => absint($_POST['simulation_max_stops'] ?? 12),
            'target_stops_per_day' => absint($_POST['simulation_target_stops'] ?? 0),
            'work_minutes' => absint($_POST['simulation_work_minutes'] ?? 0),
            'simulation_work_hours' => wp_unslash($_POST['simulation_work_hours'] ?? '8'),
            'lunch_minutes' => absint($_POST['simulation_lunch_minutes'] ?? 60),
            'allow_overtime' => !empty($_POST['simulation_allow_overtime']),
            'allow_extra_visits' => !empty($_POST['simulation_allow_extra_visits']),
            'overtime_extra_minutes' => absint($_POST['simulation_overtime_extra_minutes'] ?? 0),
            'lock_start_point' => !empty($_POST['simulation_lock_start_point']),
            'lock_end_point' => !empty($_POST['simulation_lock_end_point']),
            'route_strategy' => sanitize_text_field($_POST['simulation_route_strategy'] ?? 'complete_coverage'),
                    'distance_sensitivity' => sanitize_text_field($_POST['simulation_distance_sensitivity'] ?? 'normal'),
            'daily_overtime_dates' => (array)($_POST['simulation_overtime_dates'] ?? []),
            'daily_overtime_minutes' => (array)($_POST['simulation_overtime_minutes'] ?? []),
            'start_point' => [
                'address' => sanitize_text_field($_POST['simulation_start_address'] ?? ''),
                'lat' => is_numeric($_POST['simulation_start_lat'] ?? null) ? (float)$_POST['simulation_start_lat'] : null,
                'lng' => is_numeric($_POST['simulation_start_lng'] ?? null) ? (float)$_POST['simulation_start_lng'] : null,
            ],
            'end_point' => [
                'address' => sanitize_text_field($_POST['simulation_end_address'] ?? ''),
                'lat' => is_numeric($_POST['simulation_end_lat'] ?? null) ? (float)$_POST['simulation_end_lat'] : null,
                'lng' => is_numeric($_POST['simulation_end_lng'] ?? null) ? (float)$_POST['simulation_end_lng'] : null,
            ],
        ]);
        if (!$project_id) wp_send_json_success(['html' => '<p>Seleciona uma campanha.</p>']);
        $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$px}projects WHERE id=%d", $project_id), ARRAY_A);
        $users = Permissions::get_associated_users((int)($project['client_id'] ?? 0), $project_id, ['ID','display_name','user_login']);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT cl.id AS link_id, cl.status AS campaign_status, cl.priority, cl.visit_frequency, cl.frequency_count, cl.visit_duration_min, cl.min_gap_days, cl.max_gap_days, cl.preferred_weekdays, cl.blocked_weekdays, cl.time_window_start, cl.time_window_end, cl.allow_auto_reschedule, cl.allow_overtime, cl.rule_notes, cl.assigned_to, cl.is_active AS campaign_active, l.*, c.name AS category_name, sc.name AS subcategory_name FROM {$px}campaign_locations cl INNER JOIN {$px}locations l ON l.id=cl.location_id LEFT JOIN {$px}categories c ON c.id=l.category_id LEFT JOIN {$px}categories sc ON sc.id=l.subcategory_id WHERE cl.project_id=%d ORDER BY cl.priority DESC, l.city ASC, l.name ASC", $project_id), ARRAY_A) ?: [];
        $rows = self::filter_linked_by_owner($rows, $owner_user_id);
        $plan = self::build_period_plan($rows, $plan_scope, $week_start, $holiday_country, $simulation_options);
        wp_send_json_success(['html' => self::render_plan_preview_html($plan, $plan_scope, $owner_user_id, $users, $holiday_country, $simulation_options, $rows)]);}
    

    private static function filter_linked_by_owner(array $linked, int $owner_user_id): array {
        if ($owner_user_id <= 0) return $linked;
        return array_values(array_filter($linked, function($row) use ($owner_user_id){
            return (int)($row['assigned_to'] ?? 0) === $owner_user_id;
        }));
    }

private static function render_plan_preview_html(array $suggested, string $plan_scope, int $owner_user_id = 0, array $users = [], string $holiday_country = 'pt', array $simulation_options = [], array $linked_rows = []): string {
    ob_start();
    $ownerLabel = 'Todos os owners';
    if ($owner_user_id > 0) {
        foreach ($users as $u) {
            if ((int)$u->ID === $owner_user_id) { $ownerLabel = $u->display_name . ' [' . $u->user_login . ']'; break; }
        }
    }
    $holidayLabel = strtoupper($holiday_country) === 'ES' ? 'Espanha' : 'Portugal';
    $simulation_options = self::normalize_plan_options($simulation_options ?: (array)($suggested['options'] ?? []));
    $excludedDays = (array)($suggested['excluded_days'] ?? []);
    $excludedDates = array_keys($excludedDays);
    $editorItems = [];
    $plannedCopiesByLocation = [];
    $plannedMetaByUid = [];
    $plannedSourceDays = !empty($suggested['preview_days']) && is_array($suggested['preview_days']) ? (array)$suggested['preview_days'] : (array)($suggested['days'] ?? []);
    foreach ($plannedSourceDays as $plannedDay) {
        foreach ((array)($plannedDay['items'] ?? []) as $plannedItem) {
            $plannedId = (int)($plannedItem['id'] ?? 0);
            if ($plannedId <= 0) continue;
            $plannedCopy = max(1, (int)($plannedItem['copy_index'] ?? 1));
            $plannedCopiesByLocation[$plannedId] = max((int)($plannedCopiesByLocation[$plannedId] ?? 0), $plannedCopy);
            $plannedUid = $plannedId . '__' . $plannedCopy;
            $plannedMetaByUid[$plannedUid] = [
                'target_date' => sanitize_text_field((string)($plannedItem['target_date'] ?? $plannedDay['date'] ?? '')),
                'target_week_key' => sanitize_text_field((string)($plannedItem['target_week_key'] ?? '')),
                'preferred_weekday' => max(0, min(7, (int)($plannedItem['preferred_weekday'] ?? 0))),
                'cadence_label' => sanitize_text_field((string)($plannedItem['cadence_label'] ?? $plannedItem['assignment_pattern_label'] ?? '')),
            ];
        }
    }
    foreach ($linked_rows as $row) {
        $resolved = self::resolve_route_geo_point([
            'id' => (int)($row['location_id'] ?? $row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'address' => (string)($row['address'] ?? ''),
            'district' => (string)($row['district'] ?? ''),
            'county' => (string)($row['county'] ?? ''),
            'city' => (string)($row['city'] ?? ''),
            'place_id' => (string)($row['place_id'] ?? ''),
            'visit_duration_min' => (int)($row['visit_duration_min'] ?? 45),
            'visit_frequency' => (string)($row['visit_frequency'] ?? 'weekly'),
            'priority' => (int)($row['priority'] ?? 0),
            'lat' => $row['lat'] ?? null,
            'lng' => $row['lng'] ?? null,
        ], (int)($row['location_id'] ?? $row['id'] ?? 0));
        $lid = (int)($resolved['id'] ?? 0);
        if ($lid <= 0) continue;
        $frequencyCount = max(1, (int)($resolved['frequency_count'] ?? $row['frequency_count'] ?? 1));
        $frequencyCount = max($frequencyCount, (int)($plannedCopiesByLocation[$lid] ?? 0));
        $periodicityLabel = ((string)($resolved['visit_frequency'] ?? 'weekly') === 'monthly' ? 'Mensal · ' . $frequencyCount . ' visita(s)/mês' : 'Semanal');
        for ($copyIndex = 1; $copyIndex <= $frequencyCount; $copyIndex++) {
            $editorUid = $lid . '__' . $copyIndex;
            $editorMeta = (array)($plannedMetaByUid[$editorUid] ?? []);
            $editorItems[] = [
                'uid' => $editorUid,
                'id' => $lid,
                'copy_index' => $copyIndex,
                'copy_label' => $frequencyCount > 1 ? ('Visita ' . $copyIndex . '/' . $frequencyCount) : '',
                'name' => (string)($resolved['name'] ?? ''),
                'city' => (string)($resolved['city'] ?? ''),
                'district' => (string)($resolved['district'] ?? ''),
                'county' => (string)($resolved['county'] ?? ''),
                'address' => (string)($resolved['address'] ?? ''),
                'lat' => is_numeric($resolved['lat'] ?? null) ? (float)$resolved['lat'] : null,
                'lng' => is_numeric($resolved['lng'] ?? null) ? (float)$resolved['lng'] : null,
                'visit_duration_min' => (int)($resolved['visit_duration_min'] ?? 45),
                'visit_frequency' => (string)($resolved['visit_frequency'] ?? 'weekly'),
                'frequency_count' => $frequencyCount,
                'periodicity_label' => $periodicityLabel,
                'priority' => (int)($resolved['priority'] ?? 0),
                'target_date' => sanitize_text_field((string)($editorMeta['target_date'] ?? '')),
                'target_week_key' => sanitize_text_field((string)($editorMeta['target_week_key'] ?? '')),
                'preferred_weekday' => max(0, min(7, (int)($editorMeta['preferred_weekday'] ?? 0))),
                'cadence_label' => sanitize_text_field((string)($editorMeta['cadence_label'] ?? '')),
            ];
        }
    }
    $editorPlan = [
        'days' => [],
        'summary' => (array)($suggested['summary'] ?? []),
        'options' => $simulation_options,
    ];

    $strategyLabels = [
        'operational_balanced' => 'Operacional equilibrado',
        'complete_coverage' => 'Cadência fixa, cobertura e periodicidade',
        'balanced_load' => 'Equilibrar carga',
        'minimize_km' => 'Minimizar kms',
        'cluster_district' => 'Agrupar por distrito/cidade',
        'route_corridor' => 'Corredor partida/chegada',
    ];
    $strategyKey = self::normalize_route_strategy((string)($simulation_options['route_strategy'] ?? 'operational_balanced'));
    echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:10px;color:#475569"><div><strong>Filtro owner:</strong> ' . esc_html($ownerLabel) . '</div><div><strong>Estratégia:</strong> ' . esc_html($strategyLabels[$strategyKey] ?? $strategyLabels['operational_balanced']) . '</div><div><strong>Feriados:</strong> ' . esc_html($holidayLabel) . '</div><div><strong>Fim de semana:</strong> Sábado e domingo</div><div><strong>Máx. visitas/dia:</strong> ' . intval($simulation_options['max_stops_per_day']) . '</div><div><strong>Média alvo/dia:</strong> ' . esc_html(self::target_stops_label($simulation_options)) . '</div><div><strong>Sensibilidade distância:</strong> ' . esc_html(ucfirst(self::normalize_distance_sensitivity((string)($simulation_options['distance_sensitivity'] ?? 'normal')))) . '</div><div><strong>Horas úteis:</strong> ' . esc_html(self::human_minutes((int)$simulation_options['work_minutes'])) . '</div><div><strong>Almoço:</strong> ' . esc_html(self::human_minutes((int)$simulation_options['lunch_minutes'])) . '</div><div><strong>Fora do horário, geral:</strong> ' . (!empty($simulation_options['allow_overtime']) ? 'Permitido' : 'Não') . '</div><div><strong>Visitas extra:</strong> ' . (!empty($simulation_options['allow_extra_visits']) ? 'Permitidas' : 'Bloqueadas') . '</div><div><strong>Horas adicionais, geral:</strong> ' . esc_html(self::human_minutes((int)($simulation_options['overtime_extra_minutes'] ?? 0))) . '</div><div><strong>Partida:</strong> ' . esc_html((string)($simulation_options['start_point']['address'] ?? 'Sem ponto definido')) . (!empty($simulation_options['lock_start_point']) ? ' · bloqueado' : '') . '</div><div><strong>Chegada:</strong> ' . esc_html((string)($simulation_options['end_point']['address'] ?? 'Sem ponto definido')) . (!empty($simulation_options['lock_end_point']) ? ' · bloqueado' : '') . '</div></div>';
    if (isset($suggested['quality_score'])) {
        echo '<div style="margin:10px 0 12px;padding:12px 14px;border:1px solid #bfdbfe;border-radius:16px;background:#eff6ff;color:#1e3a8a;font-size:12px"><strong>Score do plano:</strong> '.intval($suggested['quality_score']).'/100 · <strong>Cobertura:</strong> '.esc_html((string)($suggested['coverage_rate'] ?? 100)).'%'.(!empty($suggested['hard_errors']) ? ' · <strong>Erros:</strong> '.intval(count((array)$suggested['hard_errors'])) : '').(!empty($suggested['warnings']) ? ' · <strong>Avisos:</strong> '.intval(count((array)$suggested['warnings'])) : '').'</div>';
    }
    $diagnostics = (array)($suggested['diagnostics'] ?? []);
    if ($diagnostics) {
        echo '<div style="margin:10px 0 14px;padding:14px;border:1px solid #c7d2fe;border-radius:16px;background:#eef2ff;color:#312e81">';
        echo '<div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start"><div><strong>Diagnóstico do plano</strong><div style="margin-top:4px;font-size:12px;color:#4338ca">Day Balancer v2 ativo · alvo '.intval($diagnostics['target_stops'] ?? 0).' visita(s)/dia · ideal mínimo '.intval($diagnostics['ideal_min_stops'] ?? 0).' · máximo '.intval($diagnostics['max_stops'] ?? 0).'</div></div>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;font-size:12px">';
        echo '<span style="background:#fff;border:1px solid #c7d2fe;border-radius:999px;padding:5px 8px">Dias leves: '.intval($diagnostics['underused_days'] ?? 0).'</span>';
        echo '<span style="background:#fff;border:1px solid #c7d2fe;border-radius:999px;padding:5px 8px">Dias longos: '.intval($diagnostics['long_route_days'] ?? 0).'</span>';
        echo '<span style="background:#fff;border:1px solid #c7d2fe;border-radius:999px;padding:5px 8px">Mistura zonas: '.intval($diagnostics['mixed_zone_days'] ?? 0).'</span>';
        echo '<span style="background:#fff;border:1px solid #c7d2fe;border-radius:999px;padding:5px 8px">Por encaixar: '.intval(count((array)($suggested['unassigned'] ?? []))).'</span>';
        echo '</div></div>';
        $messages = array_slice((array)($diagnostics['messages'] ?? []), 0, 4);
        if ($messages) echo '<ul style="margin:10px 0 0 18px;font-size:12px;color:#3730a3"><li>'.implode('</li><li>', array_map('esc_html', $messages)).'</li></ul>';
        $topZones = (array)($diagnostics['top_zones'] ?? []);
        if ($topZones) {
            $zoneBits = [];
            foreach (array_slice($topZones, 0, 4, true) as $zk => $zn) $zoneBits[] = self::human_zone_label((string)$zk).' ('.intval($zn).')';
            echo '<div style="margin-top:8px;font-size:12px;color:#4338ca"><strong>Zonas dominantes:</strong> '.esc_html(implode(' · ', $zoneBits)).'</div>';
        }
        echo '</div>';
    }

    if ($excludedDates) {
        $parts = [];
        foreach ($excludedDays as $d => $reason) $parts[] = date_i18n('d/m/Y', strtotime((string)$d)) . ' (' . $reason . ')';
        echo '<div style="margin:-2px 0 10px;color:#64748b;font-size:12px">Datas excluídas da sugestão automática: ' . esc_html(implode(', ', $parts)) . '</div>';
    }

    $broken = (array)($suggested['broken_periodicity'] ?? []);
    if ($broken) {
        $count = count($broken);
        $sample = array_slice($broken, 0, 10);
        $lines = [];
        foreach ($sample as $b) {
            $lines[] =
                (string)($b['name'] ?? '') .
                ((string)($b['city'] ?? '') ? ' · ' . (string)($b['city'] ?? '') : '') .
                ((string)($b['to_date'] ?? '') ? ' · ' . date_i18n('d/m/Y', strtotime((string)$b['to_date'])) : '') .
                ((string)($b['from_week'] ?? '') && (string)($b['to_week'] ?? '') ? ' · movida de ' . (string)($b['from_week'] ?? '') . ' para ' . (string)($b['to_week'] ?? '') : '');
        }
        echo '<div style="margin:10px 0 12px;padding:14px;border:1px solid #a7f3d0;border-radius:16px;background:#ecfdf5;color:#065f46">';
        echo '<strong>Periodicidade monitorizada:</strong> ' . intval($count) . ' visita(s) foram sinalizadas para revisão. Na estratégia de cadência fixa o motor não deve deslocar visitas entre semanas.';
        echo '<div style="margin-top:8px;font-size:12px;color:#047857">Exemplos: ' . esc_html(implode(' | ', $lines)) . ($count > count($sample) ? ' …' : '') . '</div>';
        echo '</div>';
    }

    $unassignedNow = (array)($suggested['unassigned'] ?? []);
    $summaryNow = (array)($suggested['summary'] ?? []);
    if (empty($unassignedNow) && !empty($summaryNow)) {
        echo '<div style="margin:10px 0 12px;padding:12px 14px;border:1px solid #bbf7d0;border-radius:16px;background:#f0fdf4;color:#166534;font-size:12px"><strong>Cobertura completa:</strong> todas as visitas necessárias estão planeadas. Se algum dia exceder carga ou horas, fica marcado como exceção operacional para decisão consciente.</div>';
    }

    $daysToRender = [];
    if (!empty($suggested['preview_days']) && is_array($suggested['preview_days'])) {
        $daysToRender = $suggested['preview_days'];
    } elseif (!empty($suggested['days']) && is_array($suggested['days'])) {
        $daysToRender = $suggested['days'];
    }

    if (!$daysToRender) {
        echo '<p>Sem dados suficientes para gerar a sugestão. Garante PDVs ativos, com coordenadas válidas e, se aplicável, atribuídos ao owner selecionado.</p>';
        return (string) ob_get_clean();
    }

    $planDistanceKm = self::estimate_plan_distance_km(['days' => $daysToRender], $simulation_options);
    $planTollCostEur = self::estimate_plan_toll_cost_eur(['days' => $daysToRender], $simulation_options);
    $planRouteDays = self::count_plan_days_with_stops(['days' => $daysToRender]);
    $previewVisitStats = self::plan_required_visit_stats($linked_rows, ['days' => $daysToRender]);
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:0 0 14px 0">';
    echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Visitas previstas/mês</div><div style="font-size:24px;font-weight:800;color:#0f172a">'.intval($previewVisitStats['required_visits'] ?? 0).'</div></div>';
    echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Cobertura da sugestão</div><div style="font-size:24px;font-weight:800;color:#0f172a">'.esc_html(self::format_coverage_label($previewVisitStats)).'</div></div>';
    echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Kms estimados total</div><div style="font-size:24px;font-weight:800;color:#0f172a">'.esc_html(self::format_km($planDistanceKm)).'</div></div>';
    echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Portagens estimadas total</div><div style="font-size:24px;font-weight:800;color:#0f172a">'.esc_html(\RoutesPro\Support\TollEstimator::formatEuro($planTollCostEur)).'</div></div>';
    echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Dias com rota</div><div style="font-size:24px;font-weight:800;color:#0f172a">'.intval($planRouteDays).'</div></div>';
    echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Média km/dia</div><div style="font-size:24px;font-weight:800;color:#0f172a">'.esc_html(self::format_km($planRouteDays ? ($planDistanceKm / $planRouteDays) : 0)).'</div></div>';
    echo '</div>';

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px">';
    $editorAssignedCopyTracker = [];
    foreach ($daysToRender as $day) {
        $dayDistanceKm = self::estimate_plan_day_distance_km((array)$day, $simulation_options);
        $dayItems = [];
        foreach ((array)($day['items'] ?? []) as $item) {
            $itemId = (int)($item['id'] ?? 0);
            if ($itemId <= 0) continue;
            $rawUid = (string)($item['uid'] ?? '');
            $rawCopyIndex = (int)($item['copy_index'] ?? 0);
            if ($rawCopyIndex <= 0) {
                $editorAssignedCopyTracker[$itemId] = (int)($editorAssignedCopyTracker[$itemId] ?? 0) + 1;
                $resolvedCopyIndex = $editorAssignedCopyTracker[$itemId];
            } else {
                $resolvedCopyIndex = max(1, $rawCopyIndex);
                $editorAssignedCopyTracker[$itemId] = max((int)($editorAssignedCopyTracker[$itemId] ?? 0), $resolvedCopyIndex);
            }
            $resolvedUid = ($rawUid !== '' && strpos($rawUid, '__') !== false) ? $rawUid : ($itemId . '__' . $resolvedCopyIndex);
            $dayItems[] = [
                'uid' => $resolvedUid,
                'id' => $itemId,
                'copy_index' => $resolvedCopyIndex,
                'copy_label' => (string)($item['copy_label'] ?? (max(1, (int)($item['frequency_count'] ?? 1)) > 1 ? ('Visita ' . $resolvedCopyIndex . '/' . max(1, (int)($item['frequency_count'] ?? 1))) : '')),
                'name' => (string)($item['name'] ?? ''),
                'city' => (string)($item['city'] ?? ''),
                'address' => (string)($item['address'] ?? ''),
                'lat' => is_numeric($item['lat'] ?? null) ? (float)$item['lat'] : null,
                'lng' => is_numeric($item['lng'] ?? null) ? (float)$item['lng'] : null,
                'visit_duration_min' => (int)($item['visit_duration_min'] ?? 45),
                'visit_frequency' => (string)($item['visit_frequency'] ?? 'weekly'),
                'frequency_count' => max(1, (int)($item['frequency_count'] ?? 1)),
                'periodicity_label' => ((string)($item['visit_frequency'] ?? 'weekly') === 'monthly' ? 'Mensal · ' . max(1, (int)($item['frequency_count'] ?? 1)) . ' visita(s)/mês' : 'Semanal'),
                'priority' => (int)($item['priority'] ?? 0),
                'target_date' => sanitize_text_field((string)($item['target_date'] ?? $day['date'] ?? '')),
                'target_week_key' => sanitize_text_field((string)($item['target_week_key'] ?? '')),
                'preferred_weekday' => max(0, min(7, (int)($item['preferred_weekday'] ?? 0))),
                'cadence_label' => sanitize_text_field((string)($item['cadence_label'] ?? $item['assignment_pattern_label'] ?? '')),
            ];
        }
        $dayDiagnostic = self::operational_day_diagnostic((array)$day, $simulation_options, $dayDistanceKm);
        $editorPlan['days'][] = [
            'label' => (string)($day['label'] ?? ''),
            'date' => (string)($day['date'] ?? ''),
            'override_rules' => !empty($day['override_rules']),
            'diagnostic' => $dayDiagnostic,
            'items' => $dayItems,
        ];
        $diagBorder = ($dayDiagnostic['level'] ?? '') === 'Crítico' ? '#fecaca' : ((($dayDiagnostic['level'] ?? '') === 'Pesado') ? '#fed7aa' : ((($dayDiagnostic['level'] ?? '') === 'Subaproveitado') ? '#bfdbfe' : '#e2e8f0'));
        echo '<div style="background:#fff;border:1px solid '.esc_attr($diagBorder).';border-radius:16px;padding:16px">';
        echo '<div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.06em">'.esc_html($day['label']).'</div>';
        if (!empty($day['date'])) echo '<div style="margin-top:4px;color:#475569;font-weight:700">'.esc_html(date_i18n('d/m/Y', strtotime((string)$day['date']))).'</div>';
        echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-top:6px;flex-wrap:wrap">';
        echo '<div style="font-size:26px;font-weight:800">'.intval($day['stops']).' lojas</div>';
        if (!empty($day['date'])) {
            echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
            echo '<label style="font-size:12px;color:#334155;display:flex;align-items:center;gap:6px"><input type="checkbox" name="simulation_overtime_dates[]" value="'.esc_attr((string)$day['date']).'" '.checked(!empty($day['allow_overtime']), true, false).'> Permitir horas adicionais, máximo 2h</label>';
            echo '<label style="font-size:12px;color:#334155">Horas extra<br><input type="number" step="0.5" min="0" max="2" name="simulation_overtime_minutes['.esc_attr((string)$day['date']).']" value="'.esc_attr(number_format(((int)($day['extra_minutes'] ?? 0))/60, 1, '.', '')).'" style="width:78px"></label>';
            echo '</div>';
        }
        echo '</div>';
        echo '<div style="margin-top:8px;color:#475569">Km estimados: '.esc_html(self::format_km($dayDistanceKm)).' · Viagem: '.esc_html($day['travel_human']).' · Visita: '.esc_html($day['visit_human']).' · Trabalho: '.esc_html($day['work_human'] ?? $day['total_human']).' · Almoço: '.esc_html($day['lunch_human'] ?? '0m') . ' · Total: '.esc_html($day['total_human']).(!empty($day['overtime_human']) && $day['overtime_human'] !== '0m' ? ' · Fora do horário: '.esc_html($day['overtime_human']) : '').(!empty($day['overflow_count']) ? ' · Overflow: '.intval($day['overflow_count']).' visita(s)' : '').(!empty($day['can_add_store']) ? ' · Ainda cabe mais uma loja' : '').'</div>';
        echo '<div style="margin-top:8px;padding:8px 10px;border-radius:12px;background:#f8fafc;color:#334155;font-size:12px"><strong>Diagnóstico:</strong> '.esc_html((string)($dayDiagnostic['level'] ?? '')).' · Score '.intval($dayDiagnostic['score'] ?? 0).'/100 · Target distância '.intval($dayDiagnostic['target_stops'] ?? 0).' loja(s)<div style="margin-top:4px;color:#64748b">'.esc_html(implode(' | ', (array)($dayDiagnostic['warnings'] ?? []))).'</div></div>';
        echo '<ol style="margin:12px 0 0 18px">';
        foreach ((array)($day['items'] ?? []) as $item) {
            $adjusted = !empty($item['periodicity_broken']);
            $forcedOverflow = !empty($item['overflow_forced']);
            $conflictOverflow = !empty($item['overflow_conflict']);
            $assignmentReason = self::describe_assigned_visit_reason((array)$item, (array)$day, $simulation_options);
            echo '<li><strong>'.esc_html($item['name']).'</strong>' . ($adjusted ? ' <span style="display:inline-block;margin-left:6px;font-size:11px;color:#065f46;background:#d1fae5;border:1px solid #6ee7b7;border-radius:999px;padding:2px 8px">ajustada</span>' : '') . ($forcedOverflow ? ' <span style="display:inline-block;margin-left:6px;font-size:11px;color:#9a3412;background:#ffedd5;border:1px solid #fdba74;border-radius:999px;padding:2px 8px">overflow</span>' : '') . ($conflictOverflow ? ' <span style="display:inline-block;margin-left:6px;font-size:11px;color:#991b1b;background:#fee2e2;border:1px solid #fca5a5;border-radius:999px;padding:2px 8px">conflito</span>' : '') . '<br><span style="color:#64748b">'.esc_html($item['city']).' · '.esc_html($item['visit_duration_min']).' min · '.esc_html($item['periodicity_label'] ?? (((string)($item['visit_frequency'] ?? '') === 'monthly') ? ('Mensal · '.max(1, (int)($item['frequency_count'] ?? 1)).' visita(s)/mês') : ((string)($item['visit_frequency'] ?? 'weekly') === 'weekly' ? 'Semanal' : (string)($item['visit_frequency'] ?? '')))).($item['copy_index']>1 ? ' #'.intval($item['copy_index']) : '').'</span>' . ($assignmentReason !== '' ? '<div style="margin-top:4px;font-size:11px;color:#0369a1">'.esc_html($assignmentReason).'</div>' : '') . '</li>';
        }
        echo '</ol></div>';
    }
    echo '</div>';

    $assignedUids = [];
    foreach ((array)($editorPlan['days'] ?? []) as $editorDay) {
        foreach ((array)($editorDay['items'] ?? []) as $editorItem) {
            $assignedUids[(string)($editorItem['uid'] ?? ((int)($editorItem['id'] ?? 0) . '__' . max(1, (int)($editorItem['copy_index'] ?? 1))))] = true;
        }
    }
    echo '<div id="routespro-plan-editor-root" data-plan="' . esc_attr(wp_json_encode($editorPlan)) . '" data-items="' . esc_attr(wp_json_encode($editorItems)) . '" style="margin-top:16px">';
    echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px"><div><strong>Editor direto da sugestão</strong><div style="font-size:12px;color:#64748b;margin-top:4px">Podes arrastar lojas entre dias, retirar com menos e voltar a acrescentar com mais. O motor cria uma cadência fixa: o mesmo PDV tende a ficar sempre no mesmo dia da semana, e a periodicidade replica essa lógica ao longo do mês.</div></div><div style="font-size:12px;color:#475569;background:#eff6ff;border:1px solid #bfdbfe;border-radius:999px;padding:6px 10px">Arrastar e largar, edição manual antes de criar as rotas</div></div>';
    echo '<div style="margin:0 0 12px 0;padding:12px 14px;border:1px solid #fde68a;background:#fffbeb;border-radius:14px;color:#78350f;font-size:12px;line-height:1.45"><strong>Regra atual do motor:</strong> a prioridade é cumprir todas as lojas, periodicidade e cadência fixa, sem criar visitas extra, salvo quando a checkbox de visitas extra estiver ativa. As lojas com 4 visitas/mês são a âncora comercial semanal. Depois o motor encaixa P1/P2/P3 no mesmo dia e semana das âncoras próximas por zona/distância, mas respeita a harmonia de carga para evitar dias com 12/13 visitas ao lado de dias vazios. As semanas seguem o calendário real: se o mês começa a meio da semana, a Semana 1 continua a semana anterior, por exemplo 1-3, 1-4 e 1-5. Num mês com 5 ocorrências do mesmo dia, uma loja de cadência 4 mantém 4 visitas, não passa a 5. Se a carga não couber nas horas ou no máximo de visitas, o sistema mantém a visita e marca exceção operacional em vez de deixar a loja fora. A opção <strong>Permitir exceções neste dia</strong> força manualmente esse dia a aceitar encaixes fora das regras normais.</div>';
    $editorVisitStats = self::plan_required_visit_stats($linked_rows, ['days' => $daysToRender]);
    echo '<div id="routespro-editor-live-summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:0 0 14px 0">';
    echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Visitas previstas/mês</div><div id="routespro-live-required-visits" style="font-size:24px;font-weight:800;color:#0f172a">'.intval($editorVisitStats['required_visits'] ?? 0).'</div></div>';
    echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Visitas planeadas</div><div id="routespro-live-assigned-stores" style="font-size:24px;font-weight:800;color:#0f172a">0</div></div>';
    echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Visitas livres</div><div id="routespro-live-free-stores" style="font-size:24px;font-weight:800;color:#0f172a">0</div></div>';
    echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Cobertura edição</div><div id="routespro-live-free-periodicities" style="font-size:24px;font-weight:800;color:#0f172a">0</div></div>';
    echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Kms estimados edição</div><div id="routespro-live-distance-km" style="font-size:24px;font-weight:800;color:#0f172a">0 km</div></div>';
    echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Estado da edição</div><div id="routespro-live-status" style="font-size:14px;font-weight:700;color:#0369a1">Sem alterações guardadas</div></div>';
    echo '</div>';
    echo '<div class="routespro-plan-editor-grid">';
    echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:14px;position:sticky;top:16px">';
    echo '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center"><strong>Lojas ainda não planeadas</strong><span id="routespro-available-count" style="font-size:12px;color:#64748b"></span></div>';
    echo '<input type="search" id="routespro-available-search" placeholder="Filtrar por nome ou cidade" style="width:100%;margin-top:10px">';
    echo '<div id="routespro-available-list" style="display:flex;flex-direction:column;gap:8px;margin-top:12px;max-height:560px;overflow:auto">';
    foreach ($editorItems as $item) {
        $itemUid = (string)($item['uid'] ?? ((int)($item['id'] ?? 0) . '__' . max(1, (int)($item['copy_index'] ?? 1))));
        if (!empty($assignedUids[$itemUid])) continue;
        echo '<div style="padding:10px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc"><strong>'.esc_html($item['name']).'</strong><div style="font-size:12px;color:#64748b">'.esc_html($item['city']).' · '.intval($item['visit_duration_min']).' min · '.esc_html($item['periodicity_label'] ?? $item['visit_frequency']).'</div></div>';
    }
    echo '</div></div>';
    echo '<div><div id="routespro-plan-editor-days" class="routespro-plan-editor-days-grid"></div><div id="routespro-plan-map" style="margin-top:14px;height:360px;border:1px solid #dbeafe;border-radius:16px;background:#f8fafc;display:flex;align-items:center;justify-content:center;color:#64748b;padding:16px;text-align:center">Seleciona um dia no editor para visualizar o trajeto geográfico.</div></div>';
    echo '</div>';
    echo '</div>';

    echo '<div id="routespro-summary-box" style="margin-top:14px;padding:14px;border:1px dashed #cbd5e1;border-radius:16px;background:#f8fafc;color:#334155">Resumo '.($plan_scope === 'monthly' ? 'mensal' : 'semanal').': <span id="routespro-summary-text">'.intval($suggested['summary']['stops'] ?? 0).' visitas planeadas, '.esc_html(self::format_km($planDistanceKm)).' estimados, '.esc_html($suggested['summary']['travel_human'] ?? '0m').' de viagem estimada, '.esc_html($suggested['summary']['visit_human'] ?? '0m').' em loja, '.esc_html($suggested['summary']['lunch_human'] ?? '0m').' de almoço, trabalho total '.esc_html($suggested['summary']['work_human'] ?? '0m').', dia total '.esc_html($suggested['summary']['total_human'] ?? '0m').(!empty($suggested['summary']['overtime_human']) && $suggested['summary']['overtime_human'] !== '0m' ? ', fora do horário '.esc_html($suggested['summary']['overtime_human']) : '').'.</span></div>';

    if (!empty($suggested['reinforcement']['recommended'])) {
        echo '<div style="margin-top:14px;padding:16px;border:1px solid #f59e0b;border-radius:16px;background:#fffbeb;color:#78350f"><strong>Reforço operacional sugerido.</strong> O plano ultrapassa o objetivo normal de 8h de trabalho por dia. O sistema só admite até <strong>' . esc_html((string)($suggested['reinforcement']['max_overtime_per_day_human'] ?? '2h 30m')) . '</strong> adicionais por dia, para um máximo operacional de <strong>10h 30m de trabalho</strong>. Horas extra totais estimadas: <strong>' . esc_html((string)($suggested['reinforcement']['overtime_human'] ?? '0m')) . '</strong>'.(!empty($suggested['reinforcement']['zone']) ? ' · Zona prioritária para dividir a rota: <strong>' . esc_html((string)$suggested['reinforcement']['zone']) . '</strong>' : '').'. Carga sugerida para um 2.º membro: <strong>' . esc_html((string)($suggested['reinforcement']['second_member_share_human'] ?? '0m')) . '</strong>'.(!empty($suggested['reinforcement']['unassigned_count']) ? ' Existem <strong>' . intval($suggested['reinforcement']['unassigned_count']) . '</strong> visitas que já não cabem dentro do período sem reforço.' : '').'</div>';
    }
    if (!empty($suggested['unassigned'])) {
        $unassignedSuggestions = self::build_unassigned_day_suggestions((array)$suggested['unassigned'], (array)($editorPlan['days'] ?? []), $simulation_options);
        echo '<div style="margin-top:12px;padding:14px;border:1px solid #fecaca;border-radius:16px;background:#fef2f2;color:#991b1b"><strong>Visitas por encaixar no período:</strong> ' . intval(count((array)$suggested['unassigned'])) . '. Para manter a periodicidade sem ultrapassar 2h30m de horas extra por dia, estas visitas devem ser absorvidas por reforço de equipa ou redistribuição operacional.</div>';
        echo '<div style="margin-top:12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px">';
        foreach ((array)$suggested['unassigned'] as $left) {
            $leftUid = (string)($left['uid'] ?? ((int)($left['id'] ?? 0) . '__' . max(1, (int)($left['copy_index'] ?? 1))));
            $leftReason = self::describe_unassigned_visit_reason((array)$left, $simulation_options);
            $leftSuggestions = (array)($unassignedSuggestions[$leftUid] ?? []);
            $chips = [];
            foreach (array_slice($leftSuggestions, 0, 3) as $sg) {
                $chips[] = '<span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#fff;border:1px solid #fecaca;color:#7f1d1d">' . esc_html((string)($sg['label'] ?? '')) . '</span>';
            }
            echo '<div style="background:#fff7f7;border:1px solid #fecaca;border-radius:16px;padding:12px">';
            echo '<div style="display:flex;justify-content:space-between;gap:8px;align-items:start"><div><strong>' . esc_html((string)($left['name'] ?? 'PDV')) . '</strong><div style="font-size:12px;color:#7f1d1d;margin-top:4px">' . esc_html((string)($left['city'] ?? '')) . ' · ' . esc_html((string)($left['periodicity_label'] ?? (((string)($left['visit_frequency'] ?? '') === 'monthly') ? ('Mensal · '.max(1, (int)($left['frequency_count'] ?? 1)).' visita(s)/mês') : ((string)($left['visit_frequency'] ?? 'weekly') === 'weekly' ? 'Semanal' : (string)($left['visit_frequency'] ?? ''))))) . '</div></div><span style="font-size:11px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:999px;padding:3px 8px">por encaixar</span></div>';
            if ($leftReason !== '') echo '<div style="margin-top:8px;font-size:12px;color:#7f1d1d"><strong>Motivo:</strong> ' . esc_html($leftReason) . '</div>';
            echo '<div style="margin-top:8px;font-size:12px;color:#7f1d1d"><strong>Melhores dias para encaixe manual:</strong> ' . ($chips ? implode(' ', $chips) : 'Sem sugestão viável com os limites atuais.') . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    
return (string) ob_get_clean();
}


    private static function make_manual_base_plan(array $linked_rows, string $scope, string $base_date, string $holiday_country = 'pt', array $simulation_options = []): array {
        $base = self::build_period_plan($linked_rows, $scope, $base_date, $holiday_country, $simulation_options);
        $base['days'] = array_map(function($day){
            return [
                'label' => (string)($day['label'] ?? ''),
                'date' => (string)($day['date'] ?? ''),
                'items' => [],
                'travel_min' => 0,
                'visit_min' => 0,
                'stops' => 0,
                'work_min' => 0,
                'total_min' => 0,
                'lunch_min' => (int)($day['lunch_min'] ?? 0),
            ];
        }, (array)($base['days'] ?? []));
        $base['summary'] = ['stops'=>0,'travel_min'=>0,'visit_min'=>0,'work_min'=>0,'total_min'=>0,'lunch_min'=>(int)($simulation_options['lunch_minutes'] ?? 0)];
        $base['preview_days'] = [];
        $base['unassigned'] = [];
        return $base;
    }

    private static function render_manual_planner_html(array $basePlan, string $plan_scope, array $simulation_options = [], array $linked_rows = []): string {
        ob_start();
        $editorItems = [];
        foreach ($linked_rows as $row) {
            $resolved = self::resolve_route_geo_point([
                'id' => (int)($row['location_id'] ?? $row['id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'address' => (string)($row['address'] ?? ''),
                'district' => (string)($row['district'] ?? ''),
                'county' => (string)($row['county'] ?? ''),
                'city' => (string)($row['city'] ?? ''),
                'place_id' => (string)($row['place_id'] ?? ''),
                'visit_duration_min' => (int)($row['visit_duration_min'] ?? 45),
                'visit_frequency' => (string)($row['visit_frequency'] ?? 'weekly'),
                'priority' => (int)($row['priority'] ?? 0),
                'lat' => $row['lat'] ?? null,
                'lng' => $row['lng'] ?? null,
                'category' => (string)($row['category_name'] ?? ''),
                'subcategory' => (string)($row['subcategory_name'] ?? ''),
            ], (int)($row['location_id'] ?? $row['id'] ?? 0));
            $baseId = (int)($resolved['id'] ?? 0);
            $frequencyCount = max(1, (int)($resolved['frequency_count'] ?? $row['frequency_count'] ?? 1));
            $periodicityLabel = ((string)($resolved['visit_frequency'] ?? 'weekly') === 'monthly' ? 'Mensal · ' . $frequencyCount . ' visita(s)/mês' : 'Semanal');
            for ($copyIndex = 1; $copyIndex <= $frequencyCount; $copyIndex++) {
                $editorItems[] = [
                    'uid' => $baseId . '__' . $copyIndex,
                    'id' => $baseId,
                    'name' => (string)($resolved['name'] ?? ''),
                    'city' => (string)($resolved['city'] ?? ''),
                    'address' => (string)($resolved['address'] ?? ''),
                    'district' => (string)($resolved['district'] ?? ''),
                    'county' => (string)($resolved['county'] ?? ''),
                    'lat' => $resolved['lat'],
                    'lng' => $resolved['lng'],
                    'place_id' => (string)($resolved['place_id'] ?? ''),
                    'visit_duration_min' => (int)($resolved['visit_duration_min'] ?? 45),
                    'visit_frequency' => (string)($resolved['visit_frequency'] ?? 'weekly'),
                    'frequency_count' => $frequencyCount,
                    'periodicity_label' => $periodicityLabel,
                    'priority' => (int)($resolved['priority'] ?? 0),
                    'category' => (string)($row['category_name'] ?? ''),
                    'subcategory' => (string)($row['subcategory_name'] ?? ''),
                    'copy_index' => $copyIndex,
                    'copy_label' => $frequencyCount > 1 ? ('Visita ' . $copyIndex . '/' . $frequencyCount) : '',
                ];
            }
        }
        echo '<div class="routespro-card" style="margin-top:18px">';
        echo '<h2 style="margin-top:0">Planeador manual integrado</h2>';
        echo '<p style="margin-top:0;color:#475569;max-width:1000px">Cria rotas do zero com base nos PDVs já ligados à campanha. Vais acrescentando lojas por dia e o sistema recalcula kms estimados, tempo de viagem, tempo em loja e hora final em tempo real, tendo em conta início, fim, almoço e regras do período.</p>';
        echo '<form method="post" id="routespro-manual-plan-form" style="display:block">';
        wp_nonce_field('routespro_campaign_locations', 'routespro_campaign_locations_nonce');
        echo '<input type="hidden" name="project_id" value="'.intval(absint($_REQUEST['project_id'] ?? 0)).'">';
        echo '<input type="hidden" name="owner_user_id" value="'.intval(absint($_REQUEST['owner_user_id'] ?? ($_POST['owner_user_id'] ?? 0))).'">';
        echo '<input type="hidden" name="week_start" value="'.esc_attr(sanitize_text_field($_REQUEST['week_start'] ?? ($_POST['week_start'] ?? date('Y-m-d')))).'">';
        echo '<input type="hidden" name="plan_scope" value="'.esc_attr($plan_scope).'">';
        echo '<input type="hidden" name="holiday_country" value="'.esc_attr(strtolower(sanitize_text_field($_REQUEST['holiday_country'] ?? ($_POST['holiday_country'] ?? 'pt')))).'">';
        echo '<input type="hidden" name="simulation_max_stops" value="'.intval($simulation_options['max_stops_per_day'] ?? 12).'">';
        echo '<input type="hidden" name="simulation_target_stops" value="'.intval($simulation_options['target_stops_per_day'] ?? 0).'">';
        echo '<input type="hidden" name="simulation_distance_sensitivity" value="'.esc_attr((string)($simulation_options['distance_sensitivity'] ?? 'normal')).'"><input type="hidden" name="simulation_route_strategy" value="'.esc_attr((string)($simulation_options['route_strategy'] ?? 'complete_coverage')).'">';
        echo '<input type="hidden" name="simulation_work_hours" value="'.esc_attr(number_format(($simulation_options['work_minutes'] ?? 480)/60,1,'.','')).'">';
        echo '<input type="hidden" name="simulation_lunch_minutes" value="'.intval($simulation_options['lunch_minutes'] ?? 60).'">';
        echo '<input type="hidden" name="simulation_overtime_extra_minutes" value="'.intval($simulation_options['overtime_extra_minutes'] ?? 0).'">';
        if (!empty($simulation_options['allow_overtime'])) echo '<input type="hidden" name="simulation_allow_overtime" value="1">';
        if (!empty($simulation_options['allow_extra_visits'])) echo '<input type="hidden" name="simulation_allow_extra_visits" value="1">';
        if (!empty($simulation_options['lock_start_point'])) echo '<input type="hidden" name="simulation_lock_start_point" value="1">';
        if (!empty($simulation_options['lock_end_point'])) echo '<input type="hidden" name="simulation_lock_end_point" value="1">';
        echo '<input type="hidden" name="simulation_start_address" value="'.esc_attr((string)($simulation_options['start_point']['address'] ?? '')).'">';
        echo '<input type="hidden" name="simulation_start_lat" value="'.esc_attr((string)($simulation_options['start_point']['lat'] ?? '')).'">';
        echo '<input type="hidden" name="simulation_start_lng" value="'.esc_attr((string)($simulation_options['start_point']['lng'] ?? '')).'">';
        echo '<input type="hidden" name="simulation_end_address" value="'.esc_attr((string)($simulation_options['end_point']['address'] ?? '')).'">';
        echo '<input type="hidden" name="simulation_end_lat" value="'.esc_attr((string)($simulation_options['end_point']['lat'] ?? '')).'">';
        echo '<input type="hidden" name="simulation_end_lng" value="'.esc_attr((string)($simulation_options['end_point']['lng'] ?? '')).'">';
        echo '<input type="hidden" name="manual_plan_json" id="routespro-manual-plan-json" value="">';
        $manualVisitStats = self::plan_required_visit_stats($linked_rows, $basePlan);
        echo '<div id="routespro-manual-planner-root" data-plan="'.esc_attr(wp_json_encode($basePlan)).'" data-items="'.esc_attr(wp_json_encode($editorItems)).'">';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:0 0 14px 0">';
        echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Visitas previstas/mês</div><div id="routespro-manual-live-required" style="font-size:24px;font-weight:800;color:#0f172a">'.intval($manualVisitStats['required_visits'] ?? count($editorItems)).'</div></div>';
        echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Visitas planeadas</div><div id="routespro-manual-live-assigned" style="font-size:24px;font-weight:800;color:#0f172a">0</div></div>';
        echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Visitas livres</div><div id="routespro-manual-live-free" style="font-size:24px;font-weight:800;color:#0f172a">0</div></div>';
        echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Kms estimados total</div><div id="routespro-manual-live-distance" style="font-size:24px;font-weight:800;color:#0f172a">0 km</div></div>';
        echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px"><div style="font-size:12px;color:#64748b">Tempo viagem</div><div id="routespro-manual-live-travel" style="font-size:24px;font-weight:800;color:#0f172a">0 min</div></div>';
        echo '</div>';
        echo '<div style="display:grid;grid-template-columns:minmax(240px,320px) 1fr;gap:14px;align-items:start">';
        echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:14px;position:sticky;top:16px">';
        echo '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center"><strong>PDVs disponíveis</strong><span id="routespro-manual-available-count" style="font-size:12px;color:#64748b"></span></div>';
        echo '<input type="search" id="routespro-manual-available-search" placeholder="Filtrar por nome ou cidade" style="width:100%;margin-top:10px">';
        echo '<div id="routespro-manual-available-list" style="display:flex;flex-direction:column;gap:8px;margin-top:12px;max-height:560px;overflow:auto"></div>';
        echo '</div>';
        echo '<div><div id="routespro-manual-days" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px"></div><div id="routespro-manual-map" style="margin-top:14px;height:340px;border:1px solid #dbeafe;border-radius:16px;background:#f8fafc;display:flex;align-items:center;justify-content:center;color:#64748b;padding:16px;text-align:center">Seleciona um dia do planeador manual para visualizar o trajeto.</div></div>';
        echo '</div>';
        
$hasOverflow = !empty($editorPlan['summary']['overflow_count'] ?? 0);

echo '<div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">';

if ($hasOverflow) {
    echo '<div style="color:#9a3412;background:#fff7ed;border:1px solid #fdba74;padding:10px;border-radius:10px;width:100%">
    Existem visitas em overflow. O sistema já bloqueia duplicações no mesmo dia e na mesma semana real visível. As horas extra ficam limitadas a 2h por dia e qualquer conflito impossível fica por rever manualmente.
    </div>';
}

echo '<button type="submit" name="routespro_create_routes" value="1" data-routespro-create-routes="1"
style="background:'.($hasOverflow ? '#f59e0b' : '#16a34a').';color:#fff;border:0;border-radius:10px;padding:10px 16px;font-weight:600;cursor:pointer"
onclick="return '.($hasOverflow ? "confirm('Existem visitas em overflow. Queres mesmo criar as rotas?')" : "true").';">
Criar rotas automaticamente
</button>';

echo '</div>';

echo '</div>';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px"><button class="button button-secondary" type="submit" name="campaign_action" value="manual_save_plan_suggestion">Gravar como sugestão manual</button><button class="button button-primary" type="submit" name="campaign_action" value="manual_create_routes">Gravar diretamente como rota</button><span id="routespro-manual-status" style="font-size:12px;color:#0369a1">Sem alterações guardadas. Quando gravares aqui, esta versão passa a prevalecer neste mês até voltares a gerar a rota automática.</span></div>';
        echo '</form></div>';
        echo <<<'HTML'
<script>
(function(){
  const root=document.getElementById('routespro-manual-planner-root');
  const form=document.getElementById('routespro-manual-plan-form');
  if(!root||!form||root.dataset.bound==='1') return; root.dataset.bound='1';
  const hidden=document.getElementById('routespro-manual-plan-json');
  const daysWrap=document.getElementById('routespro-manual-days');
  const availableWrap=document.getElementById('routespro-manual-available-list');
  const availableCount=document.getElementById('routespro-manual-available-count');
  const availableSearch=document.getElementById('routespro-manual-available-search');
  const mapEl=document.getElementById('routespro-manual-map');
  const liveAssigned=document.getElementById('routespro-manual-live-assigned');
  const liveFree=document.getElementById('routespro-manual-live-free');
  const liveDistance=document.getElementById('routespro-manual-live-distance');
  const liveToll=document.getElementById('routespro-manual-live-toll');
  const liveTravel=document.getElementById('routespro-manual-live-travel');
  const liveStatus=document.getElementById('routespro-manual-status');
  let map=null, polyline=null, markers=[], focusDay=0, dirty=false;
  const plan=JSON.parse(root.dataset.plan||'{"days":[]}');
  const items=(JSON.parse(root.dataset.items||'[]')||[]).map(it=>({ ...it, uid:String(it.uid||(`${Number(it.id||0)}__${Number(it.copy_index||1)}`)), id:Number(it.id||0), copy_index:Number(it.copy_index||1), frequency_count:Number(it.frequency_count||1), visit_duration_min:Number(it.visit_duration_min||45), lat:it.lat!==null&&it.lat!==''?Number(it.lat):null, lng:it.lng!==null&&it.lng!==''?Number(it.lng):null }));
  const itemMap=new Map(items.map(it=>[String(it.uid),it]));
  plan.days=(plan.days||[]).map(day=>({label:day.label||'',date:day.date||'',override_rules:!!day.override_rules,items:(day.items||[]).map(it=>itemMap.get(String((it&&it.uid)||`${Number((it&&it.id)||0)}__${Number((it&&it.copy_index)||1)}`))||it)}));
  function esc(s){return String(s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
  function human(min){min=Math.round(Number(min)||0);const h=Math.floor(min/60),m=min%60;return h>0?`${h}h ${m}m`:`${m} min`;}
  function hav(a,b,c,d){const R=6371, toRad=x=>x*Math.PI/180;const dLat=toRad(c-a), dLng=toRad(d-b);const q=Math.sin(dLat/2)**2+Math.cos(toRad(a))*Math.cos(toRad(c))*Math.sin(dLng/2)**2;return 2*R*Math.asin(Math.sqrt(q));}
  function startPoint(){return {lat:parseFloat(form.querySelector('[name="simulation_start_lat"]').value||''),lng:parseFloat(form.querySelector('[name="simulation_start_lng"]').value||''),address:form.querySelector('[name="simulation_start_address"]').value||''};}
  function endPoint(){return {lat:parseFloat(form.querySelector('[name="simulation_end_lat"]').value||''),lng:parseFloat(form.querySelector('[name="simulation_end_lng"]').value||''),address:form.querySelector('[name="simulation_end_address"]').value||''};}
  function leg(from,to){ if(!isFinite(from.lat)||!isFinite(from.lng)||!isFinite(to.lat)||!isFinite(to.lng)) return {km:0,min:0}; const km=hav(from.lat,from.lng,to.lat,to.lng); return {km:km,min:km*1.6}; }
  function routeDistance(items){ items=(items||[]).filter(Boolean); if(!items.length) return 0; let total=0; let prev=startPoint(); if(!isFinite(prev.lat)||!isFinite(prev.lng)) prev=items[0]||prev; items.forEach(it=>{ const lg=leg(prev,it); total+=lg.km; prev=it; }); const end=endPoint(); const back=leg(prev,end); return total+back.km; }
  function maxStopsPerDay(){ return Math.max(1, Number(form.querySelector('[name="simulation_max_stops"]').value||12)); }
  function targetStopsPerDay(){ const raw=Number(form.querySelector('[name="simulation_target_stops"]')?.value||0); return raw>0 ? Math.min(maxStopsPerDay(), raw) : 0; }
  function lunchMinutes(){ return Math.max(0, Number(form.querySelector('[name="simulation_lunch_minutes"]').value||60)); }
  function maxWorkMinutes(){ const hours=parseFloat(form.querySelector('[name="simulation_work_hours"]').value||'8')||8; const overtimeAllowed=!!form.querySelector('[name="simulation_allow_overtime"]')?.checked; let overtime=0; if(overtimeAllowed){ overtime=Number(form.querySelector('[name="simulation_overtime_extra_minutes"]').value||0); } return Math.max(60, Math.round(hours*60)+Math.max(0,overtime)); }
  function dayOverrideEnabled(i){ return !!(plan.days[Number(i||0)] && plan.days[Number(i||0)].override_rules); }
  function setDayOverride(i,val){ i=Number(i||0); if(!plan.days[i]) return; plan.days[i].override_rules=!!val; const live=root.querySelector('input[data-manual-override-day="'+String(i)+'"]'); if(live) live.checked=!!val; markDirty(); refresh(); }
  root.addEventListener('change', function(ev){ const target=ev.target && ev.target.closest ? ev.target.closest('input[data-manual-override-day]') : null; if(!target) return; setDayOverride(Number(target.dataset.manualOverrideDay||0), !!target.checked); });
  function greedyOrder(items){ const source=(items||[]).slice(); if(source.length<=1) return source; const pool=source.slice(); const ordered=[]; let current=startPoint(); if(!isFinite(current.lat)||!isFinite(current.lng)) current=pool[0]; while(pool.length){ let bestIdx=0,bestDist=Number.MAX_SAFE_INTEGER; pool.forEach((it,idx)=>{ const d=(isFinite(current.lat)&&isFinite(current.lng)&&isFinite(it.lat)&&isFinite(it.lng)) ? hav(current.lat,current.lng,it.lat,it.lng) : Number.MAX_SAFE_INTEGER; if(d<bestDist){ bestDist=d; bestIdx=idx; } }); const next=pool.splice(bestIdx,1)[0]; ordered.push(next); current=next; } return ordered; }
  function twoOpt(items){ const route=items.slice(); if(route.length<4) return route; let improved=true, guard=0; while(improved && guard<6){ improved=false; guard++; for(let i=0;i<route.length-2;i++){ for(let j=i+1;j<route.length-1;j++){ const candidate=route.slice(); const segment=candidate.slice(i,j+1).reverse(); candidate.splice(i,j-i+1,...segment); if(routeDistance(candidate)+0.0001<routeDistance(route)){ route.splice(0,route.length,...candidate); improved=true; } } } } return route; }
  function optimizeDayItems(day){ return twoOpt(greedyOrder((day&&day.items?day.items:[]).slice())); }
  function normalizeDay(dayIndex){ if(!plan.days[dayIndex]) return; plan.days[dayIndex].items=optimizeDayItems(plan.days[dayIndex]); }
  function computeDay(day){ const ordered=optimizeDayItems(day); let prev=startPoint(), km=0,min=0,visit=0; ordered.forEach(it=>{ const lg=leg(prev,it); km+=lg.km; min+=lg.min; visit+=Number(it.visit_duration_min||45); prev=it; }); const end=endPoint(); const back=leg(prev,end); km+=back.km; min+=back.min; return {km,min,visit,total:min+visit+lunchMinutes(), ordered}; }
  const normGeo2=(v)=>String(v||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,' ').trim();
  const itemZone2=(it)=>normGeo2(it.city||it.district||it.address||'');
  const itemMacro2=(it)=>normGeo2(it.district||it.city||'');
  function itemAxis2(it){ const sp=startPoint(); if(!isFinite(Number(sp.lat))||!isFinite(Number(sp.lng))||!isFinite(Number(it.lat))||!isFinite(Number(it.lng))) return itemMacro2(it); const dLat=Number(it.lat)-Number(sp.lat), dLng=Number(it.lng)-Number(sp.lng); const ns=Math.abs(dLat)<0.035?'eixo':(dLat>0?'norte':'sul'); const ew=Math.abs(dLng)<0.045?'eixo':(dLng>0?'interior':'litoral'); return ns+'|'+ew; }
  function axisOpposition2(a,b){ a=String(a||''); b=String(b||''); let p=0; if((a.includes('norte')&&b.includes('sul'))||(a.includes('sul')&&b.includes('norte'))) p+=620; if((a.includes('interior')&&b.includes('litoral'))||(a.includes('litoral')&&b.includes('interior'))) p+=320; return p; }
  function dayDominant2(day,fn){ const m=new Map(); (day.items||[]).forEach(it=>{ const k=fn(it); if(k) m.set(k,(m.get(k)||0)+1); }); let key='',count=0; m.forEach((v,k)=>{ if(v>count){key=k;count=v;} }); return {key,count,map:m}; }
  function geoFitPenalty2(day,it){ const current=(day.items||[]); if(!current.length) return pointDistance(startPoint(), it)*1.4; let score=0; const z=itemZone2(it), mz=itemMacro2(it), ax=itemAxis2(it); const dz=dayDominant2(day,itemZone2), dm=dayDominant2(day,itemMacro2), da=dayDominant2(day,itemAxis2); if(z){ if(dz.map.has(z)) score-=650+Math.min(350,dz.map.get(z)*110); else if(dz.key&&dz.key!==z) score+=520+current.length*65; } if(mz){ if(dm.map.has(mz)) score-=180; else if(dm.key&&dm.key!==mz) score+=300; } if(ax){ if(da.map.has(ax)) score-=520+Math.min(420,da.map.get(ax)*140); else if(da.key&&da.key!==ax) score+=360+axisOpposition2(da.key,ax)+(current.length*120); } let nearest=Number.MAX_SAFE_INTEGER; current.forEach(x=>{ nearest=Math.min(nearest, pointDistance(x,it)); }); if(isFinite(nearest)){ score+=Math.min(780, nearest*14); if(nearest<=6) score-=220; if(nearest>24) score+=Math.min(420,(nearest-24)*20); } return score; }
  function findBestDayForItem(it){ let best=null; const softTarget=targetStopsPerDay(); (plan.days||[]).forEach((day,idx)=>{ const currentStops=(day.items||[]).length; const candidate={...day, items:(day.items||[]).slice().concat([it])}; const m=computeDay(candidate); const projectedStops=currentStops+1; const exceedsStops=projectedStops > maxStopsPerDay(); const exceedsWork=m.total > maxWorkMinutes(); const override=dayOverrideEnabled(idx); const overloadPenalty=override ? ((exceedsStops||exceedsWork)?500:0) : ((exceedsStops?100000:0)+(exceedsWork?100000:0)); const targetPenalty=softTarget>0 ? (Math.max(0, projectedStops-softTarget)**2*55 + Math.abs(projectedStops-softTarget)*4) : 0; const score=overloadPenalty + m.min + (m.km*2) + (currentStops*3) + targetPenalty + geoFitPenalty2(day,it); if(!best || score<best.score){ best={dayIndex:idx,label:day.label||('Dia '+(idx+1)),score,exceedsStops,exceedsWork,overrideApplied:override&&(exceedsStops||exceedsWork)}; } }); return best; }
  function serialize(){ hidden.value=JSON.stringify({days:plan.days.map(day=>{ const ordered=optimizeDayItems(day); return {label:day.label,date:day.date,override_rules:!!day.override_rules,items:ordered.map(it=>({uid:String(it.uid||(`${Number(it.id||0)}__${Number(it.copy_index||1)}`)),id:it.id,copy_index:it.copy_index}))}; })}); }
  function assignedIds(){ const s=new Set(); (plan.days||[]).forEach(day=>(day.items||[]).forEach(it=>s.add(String(it.uid||(`${Number(it.id||0)}__${Number(it.copy_index||1)}`))))); return s; }
  function assignedCountsByStore(){ const counts=new Map(); (plan.days||[]).forEach(day=>(day.items||[]).forEach(it=>{ const key=String(Number(it.id||0)); counts.set(key, (counts.get(key)||0)+1); })); return counts; }
  function availableItemsByRemainingNeed(){ const q=(availableSearch.value||'').toLowerCase().trim(); const used=assignedIds(); const counts=assignedCountsByStore(); const grouped=new Map(); items.forEach(it=>{ const key=String(Number(it.id||0)); if(!grouped.has(key)) grouped.set(key, []); grouped.get(key).push(it); }); const free=[]; grouped.forEach(group=>{ if(!group.length) return; const required=Math.max(1, Number(group[0].frequency_count||1)); const assigned=Math.max(0, Number(counts.get(String(Number(group[0].id||0)))||0)); const remaining=Math.max(0, required-assigned); if(remaining<=0) return; const candidates=group.filter(it=>!used.has(String(it.uid))).sort((a,b)=>Number(a.copy_index||1)-Number(b.copy_index||1)); let added=0; candidates.forEach(it=>{ if(added>=remaining) return; const hay=`${it.name||''} ${it.city||''} ${it.copy_label||''}`.toLowerCase(); if(q && !hay.includes(q)) return; free.push(it); added++; }); }); return free; }
  function markDirty(){ dirty=true; liveStatus.textContent='Alterações por guardar. Ao submeter, cada dia fica ordenado automaticamente por distância.'; serialize(); }
  function renderAvailable(){ const free=availableItemsByRemainingNeed(); availableCount.textContent=`${free.length} livres`; availableWrap.innerHTML=free.map(it=>{ const best=findBestDayForItem(it); const suggestion=best ? `${best.label}${best.exceedsStops||best.exceedsWork ? ' · fora de limite' : ' · encaixe recomendado'}` : 'Sem sugestão'; return `<div draggable="true" data-item-uid="${esc(it.uid)}" class="rp-manual-av" style="padding:10px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;cursor:grab"><div style="display:flex;justify-content:space-between;gap:8px"><div><strong>${esc(it.name)}</strong><div style="font-size:12px;color:#64748b">${esc(it.city)} · ${it.visit_duration_min} min · ${esc(it.periodicity_label || (((it.visit_frequency||'')==='monthly') ? ('Mensal · '+String(it.frequency_count||1)+' visita(s)/mês') : (it.visit_frequency||'Semanal')))}${it.copy_label ? ' · '+esc(it.copy_label) : ''}</div><div style="margin-top:6px;font-size:11px;color:${best && !(best.exceedsStops||best.exceedsWork) ? '#065f46' : '#92400e'}">Melhor dia: ${esc(suggestion)}</div></div><div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end"><button type="button" class="button button-secondary button-small" data-suggest-item="${esc(it.uid)}">Sugerir melhor dia</button><button type="button" class="button button-small" data-add-item="${esc(it.uid)}">+</button></div></div></div>`; }).join('') || '<div style="padding:12px;border-radius:10px;color:#64748b;background:#fff">Sem lojas livres com este filtro.</div>'; availableWrap.querySelectorAll('[data-add-item]').forEach(btn=>btn.onclick=()=>addItem(String(btn.dataset.addItem||''), focusDay)); availableWrap.querySelectorAll('[data-suggest-item]').forEach(btn=>btn.onclick=()=>{ const uid=String(btn.dataset.suggestItem||''); const it=itemMap.get(uid); if(!it) return; const best=findBestDayForItem(it); if(best) addItem(uid, Number(best.dayIndex||0)); }); bindDrag(); }
  function renderDays(){ daysWrap.innerHTML=plan.days.map((day,i)=>{ const m=computeDay(day); return `<div class="routespro-day-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:14px"><div style="display:flex;justify-content:space-between;gap:8px;align-items:start"><div><div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.06em">${esc(day.label||('Dia '+(i+1)))}</div><div style="margin-top:4px;color:#475569;font-weight:700">${esc(day.date||'')}</div><div style="margin-top:6px;font-size:12px;color:#0369a1">${day.items.length} lojas · ${human(m.min)} viagem · ${m.km.toFixed(1)} km${dayOverrideEnabled(i) ? ' · <span style="color:#b45309;font-weight:700">Exceção ativa</span>' : ''}</div><label style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;font-size:12px;color:${dayOverrideEnabled(i) ? '#92400e' : '#475569'};background:${dayOverrideEnabled(i) ? '#fffbeb' : 'transparent'};border:${dayOverrideEnabled(i) ? '1px solid #f59e0b' : '1px solid transparent'};border-radius:999px;padding:5px 8px"><input type="checkbox" data-manual-override-day="${i}" ${dayOverrideEnabled(i) ? 'checked' : ''}> ${dayOverrideEnabled(i) ? 'Exceção ativa neste dia' : 'Permitir exceções neste dia'}</label><div style="margin-top:6px;font-size:12px;color:#64748b">Ordem otimizada atual: ${m.ordered.length ? esc(m.ordered.map(it=>it.name).join(' → ')) : 'Sem visitas neste dia.'}</div></div><div style="display:flex;gap:8px;align-items:center"><button type="button" class="button button-small" data-optimize-day="${i}">Otimizar ordem</button><button type="button" class="button-link" data-focus-day="${i}">Ver no mapa</button></div></div><div class="routespro-manual-dropzone" data-day-index="${i}" style="min-height:120px;margin-top:12px;padding:10px;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc;display:flex;flex-direction:column;gap:8px">${day.items.length?day.items.map((it,idx)=>`<div draggable="true" data-item-uid="${esc(it.uid||`${Number(it.id||0)}__${Number(it.copy_index||1)}`)}" data-from-day="${i}" style="padding:10px;border:1px solid #dbeafe;border-radius:12px;background:#fff;cursor:grab"><div style="display:flex;justify-content:space-between;gap:8px;align-items:start"><div><div style="font-size:11px;color:#0369a1;font-weight:700;margin-bottom:4px">#${idx+1}</div><strong>${esc(it.name)}</strong><div style="font-size:12px;color:#64748b">${esc(it.city)} · ${it.visit_duration_min} min · ${esc(it.periodicity_label || (((it.visit_frequency||'')==='monthly') ? ('Mensal · '+String(it.frequency_count||1)+' visita(s)/mês') : (it.visit_frequency==='weekly' ? 'Semanal' : (it.visit_frequency||''))))}${it.copy_label ? ' · '+esc(it.copy_label) : ''}</div></div><div style="display:flex;gap:6px;flex-direction:column"><button type="button" class="button button-small" data-remove-item="${esc(it.uid||`${Number(it.id||0)}__${Number(it.copy_index||1)}`)}" data-day-index="${i}">-</button></div></div></div>`).join(''):'<div style="padding:12px;border-radius:10px;color:#64748b;background:#fff">Arrasta lojas para aqui ou usa o mais na lista lateral.</div>'}</div></div>`; }).join(''); daysWrap.querySelectorAll('[data-remove-item]').forEach(btn=>btn.onclick=()=>removeItem(String(btn.dataset.removeItem||''), Number(btn.dataset.dayIndex))); daysWrap.querySelectorAll('[data-manual-override-day]').forEach(chk=>chk.onchange=(ev)=>{ ev.stopPropagation(); setDayOverride(Number(chk.dataset.manualOverrideDay||0), !!chk.checked); }); daysWrap.querySelectorAll('[data-focus-day]').forEach(btn=>btn.onclick=()=>{focusDay=Number(btn.dataset.focusDay||0); renderMap();}); bindDrag(); }
  function addItem(uid, dayIndex){ const it=itemMap.get(String(uid||'')); if(!it||!plan.days[dayIndex]) return; const used=assignedIds(); if(used.has(String(it.uid))) return; const counts=assignedCountsByStore(); const required=Math.max(1, Number(it.frequency_count||1)); const assigned=Math.max(0, Number(counts.get(String(Number(it.id||0)))||0)); if(assigned>=required) return; plan.days[dayIndex].items.push(it); normalizeDay(dayIndex); markDirty(); refresh(); }
  function removeItem(uid, dayIndex){ if(!plan.days[dayIndex]) return; plan.days[dayIndex].items=plan.days[dayIndex].items.filter(it=>String(it.uid||(`${Number(it.id||0)}__${Number(it.copy_index||1)}`))!==String(uid||'')); normalizeDay(dayIndex); markDirty(); refresh(); }
  function moveItem(uid, fromDay, toDay){ if(fromDay===toDay||!plan.days[fromDay]||!plan.days[toDay]) return; const idx=plan.days[fromDay].items.findIndex(it=>String(it.uid||(`${Number(it.id||0)}__${Number(it.copy_index||1)}`))===String(uid||'')); if(idx<0) return; const [it]=plan.days[fromDay].items.splice(idx,1); normalizeDay(fromDay); plan.days[toDay].items.push(it); normalizeDay(toDay); markDirty(); refresh(); }
  function bindDrag(){ root.querySelectorAll('[draggable="true"]').forEach(el=>{ el.ondragstart=e=>{ e.dataTransfer.setData('text/plain', JSON.stringify({uid:String(el.dataset.itemUid||''), fromDay:el.dataset.fromDay!==undefined?Number(el.dataset.fromDay):null})); }; }); root.querySelectorAll('.routespro-manual-dropzone').forEach(zone=>{ zone.ondragover=e=>e.preventDefault(); zone.ondrop=e=>{ e.preventDefault(); try{ const data=JSON.parse(e.dataTransfer.getData('text/plain')||'{}'); if(data.fromDay===null||isNaN(data.fromDay)) addItem(String(data.uid||''), Number(zone.dataset.dayIndex)); else moveItem(String(data.uid||''), Number(data.fromDay), Number(zone.dataset.dayIndex)); }catch(err){} }; }); daysWrap.querySelectorAll('[data-optimize-day]').forEach(btn=>btn.onclick=()=>{ const idx=Number(btn.dataset.optimizeDay||0); normalizeDay(idx); markDirty(); refresh(); }); }
  function renderMap(){ const day=plan.days[focusDay]||plan.days[0]; if(!day){ mapEl.textContent='Sem dias para mostrar.'; return; } const pts=[]; const s=startPoint(), e=endPoint(); if(isFinite(s.lat)&&isFinite(s.lng)) pts.push({lat:s.lat,lng:s.lng,label:'Início'}); optimizeDayItems(day).forEach(it=>{ if(isFinite(it.lat)&&isFinite(it.lng)) pts.push({lat:it.lat,lng:it.lng,label:it.name}); }); if(isFinite(e.lat)&&isFinite(e.lng)) pts.push({lat:e.lat,lng:e.lng,label:'Fim'}); if(typeof google!=='undefined' && google.maps){ if(!map){ map=new google.maps.Map(mapEl,{zoom:9,center:{lat:pts[0]?.lat||39.5,lng:pts[0]?.lng||-8}}); polyline=new google.maps.Polyline({map:map,path:[]}); } markers.forEach(m=>m.setMap(null)); markers=[]; const bounds=new google.maps.LatLngBounds(); pts.forEach(p=>{ const m=new google.maps.Marker({position:{lat:p.lat,lng:p.lng},map:map,title:p.label}); markers.push(m); bounds.extend(m.getPosition()); }); polyline.setPath(pts.map(p=>({lat:p.lat,lng:p.lng}))); if(pts.length) map.fitBounds(bounds); return; } mapEl.innerHTML=`<div style="padding:16px">${pts.length?pts.map(p=>esc(p.label)).join(' → '):'Sem coordenadas suficientes para mapa.'}</div>`; }
  function estimateToll(km){ km=Number(km||0)||0; return Math.round((km*0.58*0.075)/0.05)*0.05; }
  function formatEuro(v){ return `${(Number(v||0)||0).toLocaleString('pt-PT',{minimumFractionDigits:2,maximumFractionDigits:2})} €`; }
  function refresh(){ renderDays(); renderAvailable(); const used=assignedIds(); let km=0,min=0; plan.days.forEach(day=>{ const m=computeDay(day); km+=m.km; min+=m.min; }); liveAssigned.textContent=String(used.size); liveFree.textContent=String(Math.max(0, items.length-used.size)); liveDistance.textContent=`${km.toFixed(1)} km`; if(liveToll) liveToll.textContent=formatEuro(estimateToll(km)); liveTravel.textContent=human(min); serialize(); renderMap(); }
  availableSearch.addEventListener('input', renderAvailable); form.addEventListener('submit', serialize); refresh();
})();
</script>
HTML;
        return (string)ob_get_clean();
    }


    private static function get_saved_plan_option_key(int $project_id, int $owner_user_id, string $plan_scope, string $week_start, string $holiday_country, array $filters = []): string {
        $engineVersion = 'routing_intelligence_v11_filter_guard_axis_soft';
        $filterSignature = [
            'q' => sanitize_text_field((string)($filters['q'] ?? '')),
            'category_id' => absint($filters['category_id'] ?? 0),
            'status' => sanitize_text_field((string)($filters['status'] ?? '')),
            'active' => sanitize_text_field((string)($filters['active'] ?? '')),
            'owner_user_id' => absint($filters['owner_user_id'] ?? $owner_user_id),
        ];
        return 'routespro_saved_plan_' . md5(implode('|', [$engineVersion, $project_id, $owner_user_id, $plan_scope, $week_start, $holiday_country, wp_json_encode($filterSignature)]));
    }

    private static function save_plan_suggestion(string $option_key, array $plan, array $simulation_options = []): bool {
        $payload = [
            'saved_at' => current_time('mysql'),
            'plan' => $plan,
            'options' => self::normalize_plan_options($simulation_options),
        ];
        return (bool) update_option($option_key, wp_json_encode($payload), false);
    }

    private static function clear_saved_plan_suggestion(string $option_key): bool {
        return (bool) delete_option($option_key);
    }

    private static function load_saved_plan_suggestion(string $option_key, array $linked_rows, array $fallback_plan, array $simulation_options = []): array {
        $raw = get_option($option_key, '');
        if (!is_string($raw) || $raw === '') return $fallback_plan;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return $fallback_plan;
        $plan = is_array($decoded['plan'] ?? null) ? $decoded['plan'] : [];
        if (empty($plan['days']) || !is_array($plan['days'])) return $fallback_plan;
        $payload = wp_json_encode([
            'days' => array_map(function($day){
                return [
                    'label' => (string)($day['label'] ?? ''),
                    'date' => (string)($day['date'] ?? ''),
                    'override_rules' => !empty($day['override_rules']),
                    'items' => array_map(function($item){
                        return [
                            'uid' => sanitize_text_field((string)($item['uid'] ?? ((int)($item['id'] ?? 0) . '__' . max(1, (int)($item['copy_index'] ?? 1))))),
                            'id' => (int)($item['id'] ?? 0),
                            'copy_index' => max(1, (int)($item['copy_index'] ?? 1)),
                        ];
                    }, (array)($day['items'] ?? [])),
                ];
            }, (array)$plan['days'])
        ]);
        return self::decode_edited_plan_payload((string)$payload, $linked_rows, $fallback_plan, $simulation_options);
    }

    private static function optimize_day_items(array $items, array $simulation_options = []): array {
        if (count($items) <= 1) return array_values($items);
        $calculator = new RouteCalculator();
        $startPoint = is_array($simulation_options['start_point'] ?? null) ? (array)$simulation_options['start_point'] : [];
        $endPoint = is_array($simulation_options['end_point'] ?? null) ? (array)$simulation_options['end_point'] : [];
        return array_values($calculator->reorderStops(array_values($items), $startPoint, $endPoint));
    }

    private static function rebuild_plan_day(array $dayInput, array $fallbackDay, array $items, array $simulation_options = [], int $index = 0): array {
        $items = self::optimize_day_items($items, $simulation_options);
        $travelMin = self::estimate_day_travel_minutes($items, $simulation_options);
        $visitMin = 0;
        foreach ($items as $item) $visitMin += (int)($item['visit_duration_min'] ?? 45);
        $workMin = $travelMin + $visitMin;
        $lunchMin = (int)($simulation_options['lunch_minutes'] ?? 0);
        return [
            'label' => sanitize_text_field((string)($dayInput['label'] ?? ($fallbackDay['label'] ?? ('Dia ' . ($index + 1))))),
            'date' => sanitize_text_field((string)($dayInput['date'] ?? ($fallbackDay['date'] ?? ''))),
            'override_rules' => !empty($dayInput['override_rules']) || !empty($fallbackDay['override_rules']),
            'items' => $items,
            'travel_min' => $travelMin,
            'visit_min' => $visitMin,
            'stops' => count($items),
            'allow_overtime' => !empty($dayInput['allow_overtime']) || !empty($fallbackDay['allow_overtime']),
            'extra_minutes' => isset($dayInput['extra_minutes']) ? max(0, (int)$dayInput['extra_minutes']) : (int)($fallbackDay['extra_minutes'] ?? 0),
            'hard_limit_minutes' => (int)($simulation_options['work_minutes'] ?? 480) + (isset($dayInput['extra_minutes']) ? max(0, (int)$dayInput['extra_minutes']) : (int)($fallbackDay['extra_minutes'] ?? 0)),
            'work_min' => $workMin,
            'lunch_min' => $lunchMin,
            'total_min' => $workMin + $lunchMin,
            'overtime_min' => max(0, $workMin - (int)($simulation_options['work_minutes'] ?? 480)),
        ];
    }

    private static function optimize_plan_days(array $days, array $simulation_options = []): array {
        $optimized = [];
        foreach (array_values($days) as $index => $day) {
            if (!is_array($day)) continue;
            $items = array_values((array)($day['items'] ?? []));
            $optimized[] = self::rebuild_plan_day($day, $day, $items, $simulation_options, $index);
        }
        return $optimized;
    }

    private static function decode_edited_plan_payload(string $payload, array $linked_rows, array $fallback_plan, array $simulation_options = []): array {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) return $fallback_plan;
        $daysInput = is_array($decoded['days'] ?? null) ? $decoded['days'] : [];
        if (!$daysInput) return $fallback_plan;

        $catalog = [];
        foreach ($linked_rows as $row) {
            $resolved = self::resolve_route_geo_point([
                'id' => (int)($row['location_id'] ?? $row['id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'address' => (string)($row['address'] ?? ''),
                'district' => (string)($row['district'] ?? ''),
                'county' => (string)($row['county'] ?? ''),
                'city' => (string)($row['city'] ?? ''),
                'place_id' => (string)($row['place_id'] ?? ''),
                'visit_duration_min' => (int)($row['visit_duration_min'] ?? 45),
                'visit_frequency' => (string)($row['visit_frequency'] ?? 'weekly'),
                'priority' => (int)($row['priority'] ?? 0),
                'lat' => $row['lat'] ?? null,
                'lng' => $row['lng'] ?? null,
            ], (int)($row['location_id'] ?? $row['id'] ?? 0));
            $id = (int)($resolved['id'] ?? 0);
            if ($id <= 0) continue;
            $frequencyCount = max(1, (int)($resolved['frequency_count'] ?? $row['frequency_count'] ?? 1));
            $baseItem = [
                'id' => $id,
                'name' => sanitize_text_field((string)($resolved['name'] ?? '')),
                'city' => sanitize_text_field((string)($resolved['city'] ?? '')),
                'district' => sanitize_text_field((string)($resolved['district'] ?? '')),
                'county' => sanitize_text_field((string)($resolved['county'] ?? '')),
                'address' => sanitize_text_field((string)($resolved['address'] ?? '')),
                'lat' => is_numeric($resolved['lat'] ?? null) ? (float)$resolved['lat'] : null,
                'lng' => is_numeric($resolved['lng'] ?? null) ? (float)$resolved['lng'] : null,
                'place_id' => sanitize_text_field((string)($resolved['place_id'] ?? '')),
                'visit_duration_min' => max(0, (int)($resolved['visit_duration_min'] ?? 45)),
                'visit_frequency' => sanitize_text_field((string)($resolved['visit_frequency'] ?? 'weekly')),
                'frequency_count' => $frequencyCount,
                'periodicity_label' => (sanitize_text_field((string)($resolved['visit_frequency'] ?? 'weekly')) === 'monthly' ? 'Mensal · ' . $frequencyCount . ' visita(s)/mês' : 'Semanal'),
                'priority' => (int)($resolved['priority'] ?? 0),
            ];
            for ($copyIndex = 1; $copyIndex <= $frequencyCount; $copyIndex++) {
                $copy = $baseItem;
                $copy['uid'] = $id . '__' . $copyIndex;
                $copy['copy_index'] = $copyIndex;
                $copy['copy_label'] = $frequencyCount > 1 ? ('Visita ' . $copyIndex . '/' . $frequencyCount) : '';
                $catalog[$copy['uid']] = $copy;
            }
        }

        $fallbackByDate = [];
        foreach ((array)($fallback_plan['days'] ?? []) as $day) {
            if (!empty($day['date'])) $fallbackByDate[(string)$day['date']] = $day;
        }

        $days = [];
        foreach ($daysInput as $index => $dayInput) {
            if (!is_array($dayInput)) continue;
            $date = sanitize_text_field((string)($dayInput['date'] ?? ''));
            if ($date === '') continue;
            $fallbackDay = $fallbackByDate[$date] ?? [];
            $items = [];
            $seen = [];
            foreach ((array)($dayInput['items'] ?? []) as $itemInput) {
                $uid = '';
                if (is_array($itemInput)) {
                    $uid = sanitize_text_field((string)($itemInput['uid'] ?? ''));
                    if ($uid === '') {
                        $id = absint($itemInput['id'] ?? 0);
                        $copyIndex = max(1, absint($itemInput['copy_index'] ?? 1));
                        if ($id > 0) $uid = $id . '__' . $copyIndex;
                    }
                } else {
                    $id = absint($itemInput);
                    if ($id > 0) $uid = $id . '__1';
                }
                if ($uid === '' || isset($seen[$uid]) || empty($catalog[$uid])) continue;
                $seen[$uid] = true;
                $items[] = $catalog[$uid];
            }
            $days[] = self::rebuild_plan_day($dayInput, $fallbackDay, $items, $simulation_options, $index);
        }

        $fallback_plan['days'] = $days;
        $fallback_plan['summary'] = self::summarize_days($days, [], count($days));
        $fallback_plan['preview_days'] = self::build_preview_days($days, array_values(array_filter(array_map(function($d){ return (string)($d['date'] ?? ''); }, $days))), 'Dia');
        return $fallback_plan;
    }

    private static function estimate_day_travel_minutes(array $items, array $simulation_options = []): int {
        if (count($items) <= 0) return 0;
        $points = [];
        $start = is_array($simulation_options['start_point'] ?? null) ? $simulation_options['start_point'] : [];
        $end = is_array($simulation_options['end_point'] ?? null) ? $simulation_options['end_point'] : [];
        if (!empty($simulation_options['lock_start_point']) && is_numeric($start['lat'] ?? null) && is_numeric($start['lng'] ?? null)) {
            $points[] = ['lat' => (float)$start['lat'], 'lng' => (float)$start['lng']];
        }
        foreach ($items as $item) {
            if (is_numeric($item['lat'] ?? null) && is_numeric($item['lng'] ?? null)) {
                $points[] = ['lat' => (float)$item['lat'], 'lng' => (float)$item['lng']];
            }
        }
        if (!empty($simulation_options['lock_end_point']) && is_numeric($end['lat'] ?? null) && is_numeric($end['lng'] ?? null)) {
            $points[] = ['lat' => (float)$end['lat'], 'lng' => (float)$end['lng']];
        }
        if (count($points) <= 1) return max(0, (count($items) - 1) * 18);
        $minutes = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $km = self::haversine_km((float)$points[$i - 1]['lat'], (float)$points[$i - 1]['lng'], (float)$points[$i]['lat'], (float)$points[$i]['lng']);
            $minutes += max(6.0, $km * 1.7);
        }
        return (int)round($minutes);
    }
    private static function get_campaign_linked_rows(int $project_id, array $filters = []): array {
        return \RoutesPro\Repositories\CampaignLocationRepository::findLinkedRows($project_id, $filters);
    }
    private static function stream_linked_locations_csv(array $project, array $rows, array $filters = []): void {
        if (ob_get_length()) @ob_end_clean();
        nocache_headers();
        $mode = !empty($filters['q']) || !empty($filters['category_id']) || !empty($filters['status']) || (string)($filters['active'] ?? '') !== '' || !empty($filters['owner_user_id']) ? 'filtrado' : 'todos';
        $filename = 'routespro-campanha-' . sanitize_title($project['name'] ?? 'campanha') . '-' . $mode . '-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $out = fopen('php://output', 'w');
        fwrite($out, "ï»¿");
        fputcsv($out, [get_bloginfo('name')]);
        fputcsv($out, ['Campanha', (string)($project['name'] ?? '')]);
        fputcsv($out, ['Modo', $mode === 'filtrado' ? 'Resultado filtrado' : 'Todos os PDVs da campanha']);
        fputcsv($out, ['Pesquisa', (string)($filters['q'] ?? '')]);
        fputcsv($out, ['Categoria ID', (int)($filters['category_id'] ?? 0)]);
        fputcsv($out, ['Owner ID', (int)($filters['owner_user_id'] ?? 0)]);
        if (!empty($filters['owner_user_id'])) {
            $owner = get_userdata((int)$filters['owner_user_id']);
            fputcsv($out, ['Owner nome', $owner ? (string)$owner->display_name : '']);
        }
        fputcsv($out, ['Estado campanha', (string)($filters['status'] ?? '')]);
        $activeLabel = (string)($filters['active'] ?? '') === '1' ? 'Ativas' : ((string)($filters['active'] ?? '') === '0' ? 'Inativas' : 'Todas');
        fputcsv($out, ['Ligação ativa', $activeLabel]);
        fputcsv($out, ['Total de linhas', count($rows)]);
        fputcsv($out, []);
        fputcsv($out, ['link_id','location_id','campanha','nome','morada','codigo_postal','cidade','distrito','telefone','email','categoria','subcategoria','owner_user_id','owner_nome','visit_frequency','frequency_count','visit_duration_min','min_gap_days','max_gap_days','preferred_weekdays','blocked_weekdays','time_window_start','time_window_end','allow_auto_reschedule','allow_overtime','rule_notes','priority','campaign_status','campaign_active','lat','lng','updated_at']);
        foreach ($rows as $row) {
            fputcsv($out, [
                (int)($row['link_id'] ?? 0),
                (int)($row['id'] ?? 0),
                (string)($project['name'] ?? ''),
                (string)($row['name'] ?? ''),
                (string)($row['address'] ?? ''),
                (string)($row['postal_code'] ?? ''),
                (string)($row['city'] ?? ''),
                (string)($row['district'] ?? ''),
                (string)($row['phone'] ?? ''),
                (string)($row['email'] ?? ''),
                (string)($row['category_name'] ?? ''),
                (string)($row['subcategory_name'] ?? ''),
                (int)($row['assigned_to'] ?? 0),
                (string)($row['assigned_to_name'] ?? ''),
                (string)($row['visit_frequency'] ?? ''),
                (int)($row['frequency_count'] ?? 0),
                (int)($row['visit_duration_min'] ?? 0),
                (int)($row['min_gap_days'] ?? 0),
                (int)($row['max_gap_days'] ?? 0),
                (string)($row['preferred_weekdays'] ?? ''),
                (string)($row['blocked_weekdays'] ?? ''),
                (string)($row['time_window_start'] ?? ''),
                (string)($row['time_window_end'] ?? ''),
                !empty($row['allow_auto_reschedule']) ? '1' : '0',
                !empty($row['allow_overtime']) ? '1' : '0',
                (string)($row['rule_notes'] ?? ''),
                (int)($row['priority'] ?? 0),
                (string)($row['campaign_status'] ?? ''),
                !empty($row['campaign_active']) ? '1' : '0',
                (string)($row['lat'] ?? ''),
                (string)($row['lng'] ?? ''),
                (string)($row['updated_at'] ?? ''),
            ]);
        }
        fclose($out);
        exit;
    }


    private static function parse_uploaded_csv_file(string $field): ?array {
        if (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) return null;
        $fh = fopen($_FILES[$field]['tmp_name'], 'r');
        if (!$fh) return null;
        $rows = [];
        while (($row = fgetcsv($fh, 0, ',')) !== false) {
            if ($row === [null] || $row === false) continue;
            $rows[] = array_map(function($value){
                $value = is_string($value) ? trim($value) : $value;
                return is_string($value) ? preg_replace('/^ï»¿/', '', $value) : $value;
            }, $row);
        }
        fclose($fh);
        return $rows;
    }

    private static function find_csv_header_index(array $rows, array $required): int {
        foreach ($rows as $idx => $row) {
            $normalized = array_map(function($v){ return strtolower(trim((string)$v)); }, (array)$row);
            $ok = true;
            foreach ($required as $field) {
                if (!in_array(strtolower($field), $normalized, true)) { $ok = false; break; }
            }
            if ($ok) return (int)$idx;
        }
        return -1;
    }

    private static function csv_rows_to_assoc(array $rows, int $headerIndex): array {
        if ($headerIndex < 0 || empty($rows[$headerIndex])) return [];
        $headers = array_map(function($v){ return strtolower(trim((string)$v)); }, $rows[$headerIndex]);
        $out = [];
        for ($i = $headerIndex + 1; $i < count($rows); $i++) {
            $row = (array)$rows[$i];
            if (!array_filter($row, function($v){ return trim((string)$v) !== ''; })) continue;
            $assoc = [];
            foreach ($headers as $idx => $header) {
                if ($header === '') continue;
                $assoc[$header] = isset($row[$idx]) ? trim((string)$row[$idx]) : '';
            }
            $out[] = $assoc;
        }
        return $out;
    }

    private static function parse_boolish(string $value): int {
        $value = strtolower(trim($value));
        return in_array($value, ['1','sim','yes','true','ativo','ativa'], true) ? 1 : 0;
    }

    private static function stream_linked_locations_template_csv(array $project): void {
        if (ob_get_length()) @ob_end_clean();
        nocache_headers();
        $filename = 'routespro-campanha-' . sanitize_title($project['name'] ?? 'campanha') . '-template-bulk-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $out = fopen('php://output', 'w');
        fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, ['link_id','location_id','nome','cidade','owner_user_id','owner_nome','visit_frequency','frequency_count','visit_duration_min','min_gap_days','max_gap_days','preferred_weekdays','blocked_weekdays','time_window_start','time_window_end','allow_auto_reschedule','allow_overtime','rule_notes','priority','campaign_status','campaign_active']);
        fputcsv($out, ['123','456','Nome da loja','Lisboa','0','Merchandiser Lisboa','weekly','1','45','0','0','1,3','','09:00','18:00','1','0','','0','active','1']);
        fclose($out);
        exit;
    }

    private static function import_bulk_assignments_from_csv(int $project_id, array $rows): array {
        $headerIndex = self::find_csv_header_index($rows, ['link_id','location_id']);
        if ($headerIndex < 0) return ['updated' => 0];
        $entries = self::csv_rows_to_assoc($rows, $headerIndex);
        $current = self::get_campaign_linked_rows($project_id);
        $byLink = [];
        $byLocation = [];
        foreach ($current as $row) {
            $byLink[(int)($row['link_id'] ?? 0)] = $row;
            $byLocation[(int)($row['id'] ?? 0)] = $row;
        }
        $updated = 0;
        foreach ($entries as $entry) {
            $linkId = absint($entry['link_id'] ?? 0);
            $locationId = absint($entry['location_id'] ?? 0);
            $base = $linkId && !empty($byLink[$linkId]) ? $byLink[$linkId] : ($locationId && !empty($byLocation[$locationId]) ? $byLocation[$locationId] : null);
            if (!$base) continue;
            $payload = [
                'visit_frequency' => sanitize_text_field($entry['visit_frequency'] ?? ($base['visit_frequency'] ?? 'weekly')),
                'frequency_count' => absint($entry['frequency_count'] ?? ($base['frequency_count'] ?? 1)),
                'visit_duration_min' => absint($entry['visit_duration_min'] ?? ($base['visit_duration_min'] ?? 45)),
                'min_gap_days' => absint($entry['min_gap_days'] ?? ($base['min_gap_days'] ?? 0)),
                'max_gap_days' => absint($entry['max_gap_days'] ?? ($base['max_gap_days'] ?? 0)),
                'preferred_weekdays' => sanitize_text_field($entry['preferred_weekdays'] ?? ($base['preferred_weekdays'] ?? '')),
                'blocked_weekdays' => sanitize_text_field($entry['blocked_weekdays'] ?? ($base['blocked_weekdays'] ?? '')),
                'time_window_start' => sanitize_text_field($entry['time_window_start'] ?? ($base['time_window_start'] ?? '')),
                'time_window_end' => sanitize_text_field($entry['time_window_end'] ?? ($base['time_window_end'] ?? '')),
                'allow_auto_reschedule' => self::parse_boolish((string)($entry['allow_auto_reschedule'] ?? (!empty($base['allow_auto_reschedule']) ? '1' : '0'))),
                'allow_overtime' => self::parse_boolish((string)($entry['allow_overtime'] ?? (!empty($base['allow_overtime']) ? '1' : '0'))),
                'rule_notes' => sanitize_textarea_field($entry['rule_notes'] ?? ($base['rule_notes'] ?? '')),
                'priority' => absint($entry['priority'] ?? ($base['priority'] ?? 0)),
                'status' => sanitize_text_field($entry['campaign_status'] ?? ($base['campaign_status'] ?? 'active')),
                'is_active' => self::parse_boolish((string)($entry['campaign_active'] ?? (!empty($base['campaign_active']) ? '1' : '0'))),
                'assigned_to' => absint($entry['owner_user_id'] ?? ($base['assigned_to'] ?? 0)),
            ];
            if (self::update_campaign_link_plan((int)$base['link_id'], $payload)) $updated++;
        }
        return ['updated' => $updated];
    }

    private static function stream_plan_template_csv(array $project, string $scope, string $base_date, string $holiday_country = 'pt', array $simulation_options = []): void {
        $simulation_options = self::normalize_plan_options($simulation_options);
        $filename = 'routespro-plan-template-' . sanitize_title($project['name'] ?? 'campanha') . '-' . $scope . '-' . date('Ymd', strtotime($base_date ?: date('Y-m-d'))) . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $out = fopen('php://output', 'w');
        fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
        foreach (self::plan_configuration_rows($scope, $base_date, $holiday_country, $simulation_options) as $row) {
            fputcsv($out, $row);
        }
        fputcsv($out, []);
        fputcsv($out, ['plano','data','bloco','link_id','location_id','owner_user_id','owner_nome','nome','morada','cidade','distrito','categoria','subcategoria','periodicidade','repeticao','duracao_visita_min','prioridade','lat','lng','fora_de_horas_no_dia','stops_no_bloco','max_visitas_dia','horas_uteis_min','almoco_bloco_min','almoco_geral_min','total_bloco_min','travel_min_bloco','ponto_partida','partida_lat','partida_lng','bloquear_partida','ponto_chegada','chegada_lat','chegada_lng','bloquear_chegada']);
        fputcsv($out, [$scope, $base_date, 'Dia 1', '123', '456', '0', 'Merchandiser Lisboa', 'Nome da loja', 'Morada da loja', 'Lisboa', 'Lisboa', 'Categoria', 'Subcategoria', 'weekly', '1', '45', '0', '38.7223', '-9.1393', 'Não', '1', (int)$simulation_options['max_stops_per_day'], (int)$simulation_options['work_minutes'], (int)$simulation_options['lunch_minutes'], (int)$simulation_options['lunch_minutes'], '120', '30', (string)($simulation_options['start_point']['address'] ?? ''), (string)($simulation_options['start_point']['lat'] ?? ''), (string)($simulation_options['start_point']['lng'] ?? ''), !empty($simulation_options['lock_start_point']) ? '1' : '0', (string)($simulation_options['end_point']['address'] ?? ''), (string)($simulation_options['end_point']['lat'] ?? ''), (string)($simulation_options['end_point']['lng'] ?? ''), !empty($simulation_options['lock_end_point']) ? '1' : '0']);
        fclose($out);
        exit;
    }

    private static function import_plan_from_csv_rows(array $rows, array $linked_rows, array $fallback_plan, array $simulation_options = []): array {
        $simulation_options = self::normalize_plan_options($simulation_options);
        $config = self::parse_plan_csv_configuration($rows, $simulation_options);
        $import_options = self::normalize_plan_options(array_merge($simulation_options, $config));
        $headerIndex = self::find_csv_header_index($rows, ['data','bloco']);
        if ($headerIndex < 0) return ['plan' => $fallback_plan, 'options' => $import_options];
        $entries = self::csv_rows_to_assoc($rows, $headerIndex);
        $catalogByLink = [];
        $catalogByLocation = [];
        $catalogByNameCity = [];
        foreach ($linked_rows as $row) {
            $item = self::plan_catalog_from_rows([$row]);
            if (!$item) continue;
            $item = $item[0];
            $catalogByLink[(int)($item['link_id'] ?? 0)] = $item;
            $catalogByLocation[(int)($item['id'] ?? 0)] = $item;
            $catalogByNameCity[strtolower(trim((string)($item['name'] ?? ''))) . '|' . strtolower(trim((string)($item['city'] ?? '')))] = $item;
        }
        $groups = [];
        foreach ($entries as $entry) {
            $date = sanitize_text_field((string)($entry['data'] ?? ''));
            if ($date === '') continue;
            $item = null;
            $linkId = absint($entry['link_id'] ?? 0);
            $locationId = absint($entry['location_id'] ?? 0);
            if ($linkId && !empty($catalogByLink[$linkId])) $item = $catalogByLink[$linkId];
            if (!$item && $locationId && !empty($catalogByLocation[$locationId])) $item = $catalogByLocation[$locationId];
            if (!$item) {
                $key = strtolower(trim((string)($entry['nome'] ?? ''))) . '|' . strtolower(trim((string)($entry['cidade'] ?? '')));
                if (!empty($catalogByNameCity[$key])) $item = $catalogByNameCity[$key];
            }
            if (!$item) continue;
            if (isset($entry['duracao_visita_min']) && $entry['duracao_visita_min'] !== '') $item['visit_duration_min'] = max(0, absint($entry['duracao_visita_min']));
            if (isset($entry['prioridade']) && $entry['prioridade'] !== '') $item['priority'] = absint($entry['prioridade']);
            if (!empty($entry['periodicidade'])) $item['visit_frequency'] = sanitize_text_field((string)$entry['periodicidade']);
            if (!empty($entry['repeticao'])) $item['copy_index'] = max(1, absint($entry['repeticao']));
            if (isset($entry['lat']) && is_numeric($entry['lat'])) $item['lat'] = (float)$entry['lat'];
            if (isset($entry['lng']) && is_numeric($entry['lng'])) $item['lng'] = (float)$entry['lng'];
            if (!empty($entry['categoria'])) $item['category_name'] = sanitize_text_field((string)$entry['categoria']);
            if (!empty($entry['subcategoria'])) $item['subcategory_name'] = sanitize_text_field((string)$entry['subcategoria']);
            if (!empty($entry['morada'])) $item['address'] = sanitize_text_field((string)$entry['morada']);
            if (!empty($entry['distrito'])) $item['district'] = sanitize_text_field((string)$entry['distrito']);
            if (empty($groups[$date])) {
                $groups[$date] = ['label' => sanitize_text_field((string)($entry['bloco'] ?? $date)), 'date' => $date, 'items' => [], 'entry' => $entry];
            }
            $groups[$date]['items'][] = $item;
        }
        if (!$groups) return ['plan' => $fallback_plan, 'options' => $import_options];
        $days = [];
        foreach ($groups as $date => $group) {
            $items = $group['items'];
            $entry = (array)($group['entry'] ?? []);
            $travelMin = isset($entry['travel_min_bloco']) && $entry['travel_min_bloco'] !== '' ? absint($entry['travel_min_bloco']) : self::estimate_day_travel_minutes($items, $import_options);
            $visitMin = 0;
            foreach ($items as $item) $visitMin += (int)($item['visit_duration_min'] ?? 45);
            $lunchMin = isset($entry['almoco_bloco_min']) && $entry['almoco_bloco_min'] !== '' ? absint($entry['almoco_bloco_min']) : (int)($import_options['lunch_minutes'] ?? 0);
            $workMin = $travelMin + $visitMin;
            $allowOvertime = self::parse_boolish((string)($entry['fora_de_horas_no_dia'] ?? '0'));
            $hardLimit = (int)($import_options['work_minutes'] ?? 480);
            $extraMinutes = 0;
            if ($allowOvertime) {
                $extraMinutes = (int)($import_options['daily_overtime_minutes'][$date] ?? ($import_options['overtime_extra_minutes'] ?? 0));
                $hardLimit += $extraMinutes;
            }
            $days[] = [
                'label' => $group['label'],
                'date' => $date,
                'items' => $items,
                'travel_min' => $travelMin,
                'visit_min' => $visitMin,
                'stops' => isset($entry['stops_no_bloco']) && $entry['stops_no_bloco'] !== '' ? absint($entry['stops_no_bloco']) : count($items),
                'allow_overtime' => $allowOvertime,
                'extra_minutes' => $extraMinutes,
                'hard_limit_minutes' => $hardLimit,
                'work_min' => $workMin,
                'lunch_min' => $lunchMin,
                'total_min' => isset($entry['total_bloco_min']) && $entry['total_bloco_min'] !== '' ? absint($entry['total_bloco_min']) : ($workMin + $lunchMin),
                'overtime_min' => max(0, $workMin - (int)($import_options['work_minutes'] ?? 480)),
            ];
        }
        usort($days, function($a, $b){ return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')); });
        $fallback_plan['days'] = $days;
        $fallback_plan['preview_days'] = self::build_preview_days($days, array_values(array_filter(array_map(function($d){ return (string)($d['date'] ?? ''); }, $days))), 'Dia');
        $fallback_plan['options'] = $import_options;
        return ['plan' => $fallback_plan, 'options' => $import_options];
    }


    private static function plan_configuration_rows(string $scope, string $base_date, string $holiday_country, array $simulation_options = []): array {
        $simulation_options = self::normalize_plan_options($simulation_options);
        $dayExtras = [];
        foreach ((array)($simulation_options['daily_overtime_minutes'] ?? []) as $date => $mins) {
            $dayExtras[] = $date . ':' . (int)$mins;
        }
        return [
            ['config_key','config_value'],
            ['plano', $scope],
            ['data_base', $base_date],
            ['feriados', $holiday_country],
            ['max_visitas_dia', (int)$simulation_options['max_stops_per_day']],
            ['horas_uteis_min', (int)$simulation_options['work_minutes']],
            ['almoco_min', (int)$simulation_options['lunch_minutes']],
            ['permitir_fora_horario_geral', !empty($simulation_options['allow_overtime']) ? '1' : '0'],
            ['permitir_visitas_extra', !empty($simulation_options['allow_extra_visits']) ? '1' : '0'],
            ['horas_adicionais_geral_min', (int)($simulation_options['overtime_extra_minutes'] ?? 0)],
            ['datas_fora_horario', implode('|', (array)($simulation_options['daily_overtime_dates'] ?? []))],
            ['horas_adicionais_por_dia', implode('|', $dayExtras)],
            ['ponto_partida', (string)($simulation_options['start_point']['address'] ?? '')],
            ['partida_lat', (string)($simulation_options['start_point']['lat'] ?? '')],
            ['partida_lng', (string)($simulation_options['start_point']['lng'] ?? '')],
            ['bloquear_partida', !empty($simulation_options['lock_start_point']) ? '1' : '0'],
            ['ponto_chegada', (string)($simulation_options['end_point']['address'] ?? '')],
            ['chegada_lat', (string)($simulation_options['end_point']['lat'] ?? '')],
            ['chegada_lng', (string)($simulation_options['end_point']['lng'] ?? '')],
            ['bloquear_chegada', !empty($simulation_options['lock_end_point']) ? '1' : '0'],
        ];
    }

    private static function parse_plan_csv_configuration(array $rows, array $fallback_options = []): array {
        $options = self::normalize_plan_options($fallback_options);
        foreach ((array)$rows as $row) {
            if (!is_array($row) || count($row) < 2) continue;
            $key = sanitize_key((string)($row[0] ?? ''));
            $value = is_scalar($row[1] ?? null) ? trim((string)$row[1]) : '';
            if ($key === '' || in_array($key, ['config_key','plano','data','bloco'], true)) continue;
            switch ($key) {
                case 'feriados': $options['holiday_country'] = in_array(strtolower($value), ['pt','es'], true) ? strtolower($value) : ($options['holiday_country'] ?? 'pt'); break;
                case 'max_visitas_dia': $options['max_stops_per_day'] = absint($value); break;
                case 'horas_uteis_min': $options['work_minutes'] = absint($value); break;
                case 'almoco_min': $options['lunch_minutes'] = absint($value); break;
                case 'permitir_fora_horario_geral': $options['allow_overtime'] = self::parse_boolish($value); break;
                case 'permitir_visitas_extra': $options['allow_extra_visits'] = self::parse_boolish($value); break;
                case 'horas_adicionais_geral_min': $options['overtime_extra_minutes'] = absint($value); break;
                case 'datas_fora_horario':
                    $options['daily_overtime_dates'] = array_values(array_filter(array_map('sanitize_text_field', explode('|', $value))));
                    break;
                case 'horas_adicionais_por_dia':
                    $parsed = [];
                    foreach (array_filter(explode('|', $value)) as $pair) {
                        $parts = array_map('trim', explode(':', $pair, 2));
                        if (count($parts) === 2 && $parts[0] !== '') $parsed[sanitize_text_field($parts[0])] = absint($parts[1]);
                    }
                    $options['daily_overtime_minutes'] = $parsed;
                    break;
                case 'ponto_partida': $options['start_point']['address'] = sanitize_text_field($value); break;
                case 'partida_lat': $options['start_point']['lat'] = is_numeric($value) ? (float)$value : null; break;
                case 'partida_lng': $options['start_point']['lng'] = is_numeric($value) ? (float)$value : null; break;
                case 'bloquear_partida': $options['lock_start_point'] = self::parse_boolish($value); break;
                case 'ponto_chegada': $options['end_point']['address'] = sanitize_text_field($value); break;
                case 'chegada_lat': $options['end_point']['lat'] = is_numeric($value) ? (float)$value : null; break;
                case 'chegada_lng': $options['end_point']['lng'] = is_numeric($value) ? (float)$value : null; break;
                case 'bloquear_chegada': $options['lock_end_point'] = self::parse_boolish($value); break;
            }
        }
        return $options;
    }

    private static function render_linked_pagination(int $project_id, int $category_id, string $q, string $week_start, string $plan_scope, string $holiday_country, int $owner_user_id, int $linked_page, int $linked_per_page, int $linked_count, array $simulation_options = [], string $linked_q = '', int $linked_category_id = 0, string $linked_status = '', string $linked_active = ''): string {
        $total_pages = max(1, (int) ceil($linked_count / max(1, $linked_per_page)));
        if ($total_pages <= 1) return '';
        $base = add_query_arg([
            'page' => 'routespro-campaign-locations',
            'project_id' => $project_id,
            'category_id' => $category_id,
            'q' => $q,
            'week_start' => $week_start,
            'plan_scope' => $plan_scope,
            'holiday_country' => $holiday_country,
            'owner_user_id' => $owner_user_id,
            'linked_per_page' => $linked_per_page,
            'linked_q' => $linked_q,
            'linked_category_id' => $linked_category_id,
            'linked_status' => $linked_status,
            'linked_active' => $linked_active,
            'simulation_max_stops' => (int)($simulation_options['max_stops_per_day'] ?? 12),
            'simulation_target_stops' => (int)($simulation_options['target_stops_per_day'] ?? 0),
            'simulation_work_minutes' => (int)($simulation_options['work_minutes'] ?? 480),
            'simulation_lunch_minutes' => (int)($simulation_options['lunch_minutes'] ?? 60),
            'simulation_allow_overtime' => !empty($simulation_options['allow_overtime']) ? 1 : 0,
            'simulation_allow_extra_visits' => !empty($simulation_options['allow_extra_visits']) ? 1 : 0,
            'simulation_route_strategy' => (string)($simulation_options['route_strategy'] ?? 'complete_coverage'),
            'simulation_distance_sensitivity' => (string)($simulation_options['distance_sensitivity'] ?? 'normal'),
        ], admin_url('admin.php'));
        $html = '<div style="display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-top:12px">';
        $html .= '<div style="color:#64748b">Página ' . intval($linked_page) . ' de ' . intval($total_pages) . ', ' . intval($linked_count) . ' PDVs</div>';
        $html .= '<div style="display:flex;gap:6px;align-items:center">';
        if ($linked_page > 1) $html .= '<a class="button" href="' . esc_url(add_query_arg('linked_page', $linked_page - 1, $base)) . '">Anterior</a>';
        if ($linked_page < $total_pages) $html .= '<a class="button" href="' . esc_url(add_query_arg('linked_page', $linked_page + 1, $base)) . '">Seguinte</a>';
        $html .= '</div></div>';
        return $html;
    }

    private static function normalize_route_strategy(string $strategy): string {
        $strategy = sanitize_key($strategy ?: 'operational_balanced');
        $allowed = ['operational_balanced', 'complete_coverage', 'balanced_load', 'minimize_km', 'cluster_district', 'route_corridor'];
        return in_array($strategy, $allowed, true) ? $strategy : 'operational_balanced';
    }

    private static function normalize_distance_sensitivity(string $value): string {
        $value = sanitize_key($value ?: 'normal');
        $allowed = ['low', 'normal', 'high'];
        return in_array($value, $allowed, true) ? $value : 'normal';
    }

    private static function distance_sensitivity_multiplier(array $options): float {
        $mode = self::normalize_distance_sensitivity((string)($options['distance_sensitivity'] ?? 'normal'));
        if ($mode === 'low') return 0.65;
        if ($mode === 'high') return 1.45;
        return 1.0;
    }

    private static function render_distance_sensitivity_control(array $simulation_options): string {
        $value = self::normalize_distance_sensitivity((string)($simulation_options['distance_sensitivity'] ?? 'normal'));
        $html = '<label>Sensibilidade à distância<br><select name="simulation_distance_sensitivity" style="width:150px">';
        $html .= '<option value="low" ' . selected($value, 'low', false) . '>Baixa</option>';
        $html .= '<option value="normal" ' . selected($value, 'normal', false) . '>Normal</option>';
        $html .= '<option value="high" ' . selected($value, 'high', false) . '>Alta</option>';
        $html .= '</select><span style="display:block;font-size:11px;color:#64748b;margin-top:4px">Rotas longe da base levam menos lojas.</span></label>';
        return $html;
    }


    private static function default_geo_cluster_radius_km(array $locations): float {
        $points = [];
        foreach ($locations as $location) {
            if (self::point_has_coordinates((array)$location)) $points[] = (array)$location;
        }
        if (count($points) < 2) return 8.0;
        $sample = array_slice($points, 0, 60);
        $nearest = [];
        foreach ($sample as $i => $a) {
            $best = PHP_FLOAT_MAX;
            foreach ($sample as $j => $b) {
                if ($i === $j) continue;
                $km = self::safe_haversine_between_points($a, $b);
                if ($km > 0 && $km < $best) $best = $km;
            }
            if ($best < PHP_FLOAT_MAX) $nearest[] = $best;
        }
        if (!$nearest) return 8.0;
        sort($nearest);
        $median = $nearest[(int)floor(count($nearest) / 2)];
        if ($median <= 3.0) return 5.0;      // urbano
        if ($median <= 8.0) return 10.0;     // misto
        return 15.0;                         // rural/disperso
    }

    private static function build_geo_clusters(array $locations, float $radius_km = 8.0, array $startPoint = [], array $endPoint = []): array {
        $radius = max(1.0, min(35.0, $radius_km));
        // 2.2.101 Geo Routing Brain: evita mega-clusters por efeito corrente.
        // Uma loja pode estar perto da ultima loja visitada, mas se abrir demasiado o raio real do cluster,
        // fica para outro bloco geografico. Isto melhora muito rotas em zonas mistas litoral/interior.
        $maxClusterRadius = min(55.0, max(12.0, $radius * 2.05));
        $candidates = [];
        foreach (array_values($locations) as $idx => $location) {
            $location = (array)$location;
            if (!self::point_has_coordinates($location)) continue;
            if (!isset($location['_ff_geo_index'])) $location['_ff_geo_index'] = $idx;
            $candidates[] = $location;
        }
        $clusters = [];
        $used = [];
        $clusterId = 1;
        foreach ($candidates as $i => $anchor) {
            if (isset($used[$i])) continue;
            $queue = [$i];
            $used[$i] = true;
            $members = [];
            while ($queue) {
                $currentIndex = array_shift($queue);
                $current = $candidates[$currentIndex];
                $members[] = $current;
                foreach ($candidates as $j => $candidate) {
                    if (isset($used[$j])) continue;
                    $km = self::safe_haversine_between_points($current, $candidate);
                    if ($km <= $radius) {
                        $candidateMembers = $members;
                        $candidateMembers[] = $candidate;
                        $candidateSummary = self::build_geo_cluster_summary($candidateMembers, 0, $startPoint, $endPoint);
                        if ((float)($candidateSummary['radius_km'] ?? 0.0) <= $maxClusterRadius) {
                            $used[$j] = true;
                            $queue[] = $j;
                        }
                    }
                }
            }
            $cluster = self::build_geo_cluster_summary($members, $clusterId, $startPoint, $endPoint);
            $clusters[] = $cluster;
            $clusterId++;
        }
        usort($clusters, function($a, $b) {
            $ca = count((array)($a['locations'] ?? []));
            $cb = count((array)($b['locations'] ?? []));
            if ($ca !== $cb) return $cb <=> $ca;
            return ((float)($b['density_score'] ?? 0)) <=> ((float)($a['density_score'] ?? 0));
        });
        return $clusters;
    }

    private static function build_geo_cluster_summary(array $locations, int $clusterId, array $startPoint = [], array $endPoint = []): array {
        $lat = 0.0;
        $lng = 0.0;
        $count = 0;
        $cities = [];
        $districts = [];
        $periodicity = [];
        foreach ($locations as $location) {
            $location = (array)$location;
            if (!self::point_has_coordinates($location)) continue;
            $lat += (float)$location['lat'];
            $lng += (float)$location['lng'];
            $count++;
            $city = trim((string)($location['city'] ?? ''));
            $district = trim((string)($location['district'] ?? ''));
            if ($city !== '') $cities[$city] = ($cities[$city] ?? 0) + 1;
            if ($district !== '') $districts[$district] = ($districts[$district] ?? 0) + 1;
            $freq = max(1, (int)($location['frequency_count'] ?? 1));
            $periodicity['P' . $freq] = ($periodicity['P' . $freq] ?? 0) + 1;
        }
        if ($count <= 0) {
            return [
                'cluster_id' => $clusterId,
                'locations' => [],
                'center_lat' => null,
                'center_lng' => null,
                'distance_from_base' => 0.0,
                'return_distance' => 0.0,
                'avg_internal_distance' => 0.0,
                'density_score' => 0.0,
                'dispersion_score' => 0.0,
                'dominant_city' => '',
                'dominant_district' => '',
                'periodicity_mix' => [],
            ];
        }
        $center = ['lat' => $lat / $count, 'lng' => $lng / $count];
        $sumCenter = 0.0;
        $maxCenter = 0.0;
        foreach ($locations as $location) {
            $location = (array)$location;
            if (!self::point_has_coordinates($location)) continue;
            $km = self::safe_haversine_between_points($center, $location);
            $sumCenter += $km;
            $maxCenter = max($maxCenter, $km);
        }
        arsort($cities);
        arsort($districts);
        $avgInternal = $count > 0 ? $sumCenter / $count : 0.0;
        $density = self::calculate_cluster_density([
            'locations' => $locations,
            'avg_internal_distance' => $avgInternal,
        ]);
        return [
            'cluster_id' => $clusterId,
            'locations' => array_values($locations),
            'center_lat' => $center['lat'],
            'center_lng' => $center['lng'],
            'distance_from_base' => self::point_has_coordinates($startPoint) ? round(self::safe_haversine_between_points($startPoint, $center), 2) : 0.0,
            'return_distance' => self::point_has_coordinates($endPoint) ? round(self::safe_haversine_between_points($center, $endPoint), 2) : 0.0,
            'avg_internal_distance' => round($avgInternal, 2),
            'radius_km' => round($maxCenter, 2),
            'density_score' => round($density, 3),
            'dispersion_score' => round($avgInternal + ($maxCenter * 0.65), 2),
            'dominant_city' => $cities ? (string)array_key_first($cities) : '',
            'dominant_district' => $districts ? (string)array_key_first($districts) : '',
            'periodicity_mix' => $periodicity,
        ];
    }

    private static function calculate_cluster_density(array $cluster): float {
        $locations = array_values((array)($cluster['locations'] ?? []));
        $count = count($locations);
        if ($count <= 0) return 0.0;
        $avgInternal = (float)($cluster['avg_internal_distance'] ?? 0.0);
        return $count / max(1.0, $avgInternal);
    }

    private static function calculate_dynamic_capacity(int $base_target, float $distance_from_base, float $density_score, float $dispersion_score, string $distance_sensitivity, int $max_stops): int {
        $base = $base_target > 0 ? $base_target : min($max_stops, 6);
        $mult = self::distance_sensitivity_multiplier(['distance_sensitivity' => $distance_sensitivity]);
        $distancePenalty = 0;
        if ($distance_from_base >= 180.0) $distancePenalty = 3;
        elseif ($distance_from_base >= 120.0) $distancePenalty = 2;
        elseif ($distance_from_base >= 75.0) $distancePenalty = 1;
        $distancePenalty = (int)round($distancePenalty * $mult);
        $densityBonus = 0;
        if ($density_score >= 1.2) $densityBonus = 3;
        elseif ($density_score >= 0.55) $densityBonus = 2;
        elseif ($density_score >= 0.25) $densityBonus = 1;
        $dispersionPenalty = 0;
        if ($dispersion_score >= 45.0) $dispersionPenalty = 3;
        elseif ($dispersion_score >= 28.0) $dispersionPenalty = 2;
        elseif ($dispersion_score >= 16.0) $dispersionPenalty = 1;
        return max(1, min($max_stops, $base - $distancePenalty + $densityBonus - $dispersionPenalty));
    }

    private static function geo_route_metrics_for_items(array $items, array $startPoint = [], array $endPoint = []): array {
        $points = [];
        foreach ($items as $item) {
            $item = (array)$item;
            if (self::point_has_coordinates($item)) $points[] = $item;
        }
        $count = count($points);
        if ($count <= 0) {
            return [
                'access_km' => 0.0,
                'local_km' => 0.0,
                'return_km' => 0.0,
                'total_km' => 0.0,
                'avg_internal_distance' => 0.0,
                'density_score' => 0.0,
                'dispersion_score' => 0.0,
                'radius_km' => 0.0,
            ];
        }
        $lat = array_sum(array_map(function($p){ return (float)$p['lat']; }, $points)) / $count;
        $lng = array_sum(array_map(function($p){ return (float)$p['lng']; }, $points)) / $count;
        $center = ['lat' => $lat, 'lng' => $lng];
        $sumCenter = 0.0;
        $maxCenter = 0.0;
        $pairSum = 0.0;
        $pairCount = 0;
        for ($i = 0; $i < $count; $i++) {
            $kmCenter = self::safe_haversine_between_points($center, $points[$i]);
            $sumCenter += $kmCenter;
            $maxCenter = max($maxCenter, $kmCenter);
            for ($j = $i + 1; $j < $count; $j++) {
                $pairSum += self::safe_haversine_between_points($points[$i], $points[$j]);
                $pairCount++;
            }
        }
        $avgInternal = $count > 0 ? $sumCenter / $count : 0.0;
        $avgPair = $pairCount > 0 ? $pairSum / $pairCount : $avgInternal;
        $localKm = $count > 1 ? $avgPair * max(1, $count - 1) : 0.0;
        $access = self::point_has_coordinates($startPoint) ? self::safe_haversine_between_points($startPoint, $center) : 0.0;
        $return = self::point_has_coordinates($endPoint) ? self::safe_haversine_between_points($center, $endPoint) : 0.0;
        $density = $count / max(1.0, $avgInternal);
        $dispersion = $avgInternal + ($maxCenter * 0.65);
        return [
            'access_km' => round($access, 2),
            'local_km' => round($localKm, 2),
            'return_km' => round($return, 2),
            'total_km' => round($access + $localKm + $return, 2),
            'avg_internal_distance' => round($avgInternal, 2),
            'density_score' => round($density, 3),
            'dispersion_score' => round($dispersion, 2),
            'radius_km' => round($maxCenter, 2),
        ];
    }

    private static function base_distance_km_for_item(array $item, array $startPoint = [], array $endPoint = []): float {
        if (!self::point_has_coordinates($item)) return 0.0;
        $km = 0.0;
        $legs = 0;
        if (self::point_has_coordinates($startPoint)) { $km += self::safe_haversine_between_points($startPoint, $item); $legs++; }
        if (self::point_has_coordinates($endPoint)) { $km += self::safe_haversine_between_points($item, $endPoint); $legs++; }
        return $legs > 0 ? $km / $legs : 0.0;
    }

    private static function dynamic_target_stops_for_distance(int $baseTarget, int $maxStops, float $routeKm, array $options = []): int {
        $maxStops = max(1, min(20, $maxStops));
        $baseTarget = $baseTarget > 0 ? max(1, min($maxStops, $baseTarget)) : max(1, min($maxStops, 6));
        $mult = self::distance_sensitivity_multiplier($options);
        $adjust = 0;
        if ($routeKm >= 180) $adjust = -3;
        elseif ($routeKm >= 120) $adjust = -2;
        elseif ($routeKm >= 75) $adjust = -1;
        elseif ($routeKm <= 25) $adjust = 2;
        elseif ($routeKm <= 45) $adjust = 1;
        $adjust = (int)round($adjust * $mult);
        return max(1, min($maxStops, $baseTarget + $adjust));
    }

    private static function distance_capacity_stops_for_route(int $baseTarget, int $maxStops, float $routeKm, array $options = [], bool $allowProductiveMinimum = true): int {
        // Capacidade operacional por distância. Esta é a regra dominante:
        // perto permite mais lojas, longe permite menos lojas. O mínimo produtivo só sobe até 2
        // para evitar dias improdutivos, nunca para justificar enchimento de rotas longas.
        $dynamicTarget = self::dynamic_target_stops_for_distance($baseTarget, $maxStops, $routeKm, $options);
        if (!$allowProductiveMinimum) return $dynamicTarget;
        $minProductive = self::minimum_productive_stops_per_day($options);
        return max($dynamicTarget, min($maxStops, $minProductive));
    }

    private static function cluster_density_bonus_for_items(array $items): int {
        $points = [];
        foreach ($items as $it) {
            if (self::point_has_coordinates((array)$it)) {
                $points[] = ['lat' => (float)$it['lat'], 'lng' => (float)$it['lng']];
            }
        }
        $count = count($points);
        if ($count < 2) return 0;
        $lat = array_sum(array_column($points, 'lat')) / $count;
        $lng = array_sum(array_column($points, 'lng')) / $count;
        $maxRadius = 0.0;
        $pairSum = 0.0;
        $pairCount = 0;
        for ($i = 0; $i < $count; $i++) {
            $maxRadius = max($maxRadius, self::haversine_km($lat, $lng, $points[$i]['lat'], $points[$i]['lng']));
            for ($j = $i + 1; $j < $count; $j++) {
                $pairSum += self::haversine_km($points[$i]['lat'], $points[$i]['lng'], $points[$j]['lat'], $points[$j]['lng']);
                $pairCount++;
            }
        }
        $avgPair = $pairCount > 0 ? ($pairSum / $pairCount) : 999.0;
        if ($maxRadius <= 6.0 || $avgPair <= 8.0) return 3;
        if ($maxRadius <= 12.0 || $avgPair <= 16.0) return 2;
        if ($maxRadius <= 22.0 || $avgPair <= 28.0) return 1;
        return 0;
    }

    private static function distance_capacity_stops_for_day(array $day, int $baseTarget, int $maxStops, array $options = [], bool $allowProductiveMinimum = true): int {
        $dayOptions = array_merge($options, [
            'start_point' => (array)($day['start_point'] ?? ($options['start_point'] ?? [])),
            'end_point' => (array)($day['end_point'] ?? ($options['end_point'] ?? [])),
            'lock_start_point' => true,
            'lock_end_point' => true,
        ]);
        $items = array_values((array)($day['items'] ?? []));
        $routeKm = self::estimate_plan_day_distance_km($day, $dayOptions);
        $metrics = self::geo_route_metrics_for_items($items, (array)($dayOptions['start_point'] ?? []), (array)($dayOptions['end_point'] ?? []));
        $distanceBase = max((float)$routeKm, (float)($metrics['access_km'] ?? 0) + (float)($metrics['return_km'] ?? 0));
        $capacity = self::calculate_dynamic_capacity(
            $baseTarget,
            $distanceBase,
            (float)($metrics['density_score'] ?? 0),
            (float)($metrics['dispersion_score'] ?? 0),
            self::normalize_distance_sensitivity((string)($options['distance_sensitivity'] ?? 'normal')),
            $maxStops
        );
        if ($allowProductiveMinimum) {
            $capacity = max($capacity, min($maxStops, self::minimum_productive_stops_per_day($options)));
        }
        // Guardrail: rotas longe e dispersas não ganham capacidade por terem muitos kms.
        if ($distanceBase >= 120.0 && (float)($metrics['density_score'] ?? 0) < 0.22 && (float)($metrics['dispersion_score'] ?? 0) >= 18.0) {
            $capacity = min($capacity, max(1, $baseTarget - 2));
        }
        // Exceção positiva: acesso longo, miolo curto e denso. Aqui faz sentido levar mais lojas.
        if ($distanceBase >= 90.0 && (float)($metrics['density_score'] ?? 0) >= 0.45 && (float)($metrics['local_km'] ?? 0) <= 35.0) {
            $capacity = min($maxStops, $capacity + 1);
        }
        return max(1, min($maxStops, $capacity));
    }

    private static function resolve_target_stops_per_day(array $options, int $maxStops): int {
        $maxStops = max(1, min(20, $maxStops));
        $target = (int)($options['target_stops_per_day'] ?? ($options['simulation_target_stops'] ?? 0));
        if ($target <= 0) return 0;
        return max(1, min($maxStops, $target));
    }

    private static function target_stops_label(array $options): string {
        $maxStops = max(1, min(20, (int)($options['max_stops_per_day'] ?? 12)));
        $target = self::resolve_target_stops_per_day($options, $maxStops);
        return $target > 0 ? ($target . ' visitas/dia') : 'Automática';
    }

    private static function render_target_stops_control(array $simulation_options): string {
        $maxStops = max(1, min(20, (int)($simulation_options['max_stops_per_day'] ?? 12)));
        $target = self::resolve_target_stops_per_day($simulation_options, $maxStops);
        $start = $maxStops <= 3 ? 1 : 3;
        $html = '<label>Média alvo/dia<br><select name="simulation_target_stops" style="width:130px">';
        $html .= '<option value="0" ' . selected($target, 0, false) . '>Auto</option>';
        for ($i = $start; $i <= $maxStops; $i++) {
            $html .= '<option value="' . intval($i) . '" ' . selected($target, $i, false) . '>' . intval($i) . ' visitas</option>';
        }
        $html .= '</select><span style="display:block;font-size:11px;color:#64748b;margin-top:4px">Meta suave; o máximo continua como limite.</span></label>';
        return $html;
    }

    private static function normalize_plan_options(array $raw = []): array {
        $work_minutes = (int)($raw['work_minutes'] ?? 480);
        if ($work_minutes <= 0 && !empty($raw['simulation_work_hours'])) $work_minutes = (int) round(((float)$raw['simulation_work_hours']) * 60);
        $maxStops = max(1, min(20, (int)($raw['max_stops_per_day'] ?? ($raw['simulation_max_stops'] ?? 12))));
        $targetStops = self::resolve_target_stops_per_day($raw, $maxStops);
        $dailyOvertime = array_values(array_filter(array_map('sanitize_text_field', (array)($raw['daily_overtime_dates'] ?? []))));
        $dailyOvertimeMinutes = [];
        foreach ((array)($raw['daily_overtime_minutes'] ?? []) as $date => $minsRaw) {
            $date = sanitize_text_field((string)$date);
            if (!$date) continue;
            if (!is_numeric($minsRaw)) continue;
            $mins = (int) round((float)$minsRaw);
            if ($mins <= 8) $mins = (int) round($mins * 60);
            $mins = max(0, min(150, $mins));
            if ($mins > 0) $dailyOvertimeMinutes[$date] = $mins;
        }
        $startPoint = is_array($raw['start_point'] ?? null) ? $raw['start_point'] : [];
        $endPoint = is_array($raw['end_point'] ?? null) ? $raw['end_point'] : [];
        return [
            'max_stops_per_day' => $maxStops,
            'target_stops_per_day' => $targetStops,
            'work_minutes' => max(60, min(720, $work_minutes ?: 480)),
            'lunch_minutes' => max(0, min(180, (int)($raw['lunch_minutes'] ?? 60))),
            'allow_overtime' => array_key_exists('allow_overtime', $raw) ? !empty($raw['allow_overtime']) : false,
            'allow_extra_visits' => array_key_exists('allow_extra_visits', $raw) ? !empty($raw['allow_extra_visits']) : (!empty($raw['simulation_allow_extra_visits'])),
            'overtime_extra_minutes' => max(0, min(150, (int)($raw['overtime_extra_minutes'] ?? 0))),
            'daily_overtime_dates' => $dailyOvertime,
            'daily_overtime_minutes' => $dailyOvertimeMinutes,
            'lock_start_point' => !empty($raw['lock_start_point']),
            'lock_end_point' => !empty($raw['lock_end_point']),
            'route_strategy' => self::normalize_route_strategy((string)($raw['route_strategy'] ?? ($raw['simulation_route_strategy'] ?? 'operational_balanced'))),
            'distance_sensitivity' => self::normalize_distance_sensitivity((string)($raw['distance_sensitivity'] ?? ($raw['simulation_distance_sensitivity'] ?? 'normal'))),
            'start_point' => [
                'address' => sanitize_text_field((string)($startPoint['address'] ?? '')),
                'lat' => is_numeric($startPoint['lat'] ?? null) ? (float)$startPoint['lat'] : null,
                'lng' => is_numeric($startPoint['lng'] ?? null) ? (float)$startPoint['lng'] : null,
            ],
            'end_point' => [
                'address' => sanitize_text_field((string)($endPoint['address'] ?? '')),
                'lat' => is_numeric($endPoint['lat'] ?? null) ? (float)$endPoint['lat'] : null,
                'lng' => is_numeric($endPoint['lng'] ?? null) ? (float)$endPoint['lng'] : null,
            ],
        ];
    }

private static function build_period_plan(array $linked, string $scope, string $base_date, string $holiday_country = 'pt', array $options = []): array {
    $rawOptions = is_array($options) ? $options : [];
    $norm = self::normalize_plan_options($rawOptions);

    // Preserva chaves extra (ex: min_open_days) e garante defaults normalizados
    $options = array_merge($rawOptions, $norm);

    // Delegate to the explicit geographic pipeline (Option A).
    // Phase 1+2 (candidate selection + geo pre-partition) are handled by
    // RoutePlanningPipeline before the internal scheduling methods are called.
    return RoutePlanningPipeline::run($linked, $scope, $base_date, $holiday_country, $options);
}

/**
 * Internal entry point called by RoutePlanningPipeline after Phase 1+2.
 *
 * Receives geo-annotated candidates (with cluster_id, corridor_key, etc.)
 * and runs the scheduling phases (3-6): scoring, sequencing, guard rails,
 * rebalancing.
 *
 * @internal Used by RoutePlanningPipeline only.
 */
public static function _run_pipeline_internal(array $linked, string $scope, string $base_date, string $holiday_country = 'pt', array $options = []): array {
    return $scope === 'monthly'
        ? self::build_month_plan($linked, $base_date, $holiday_country, $options)
        : self::build_week_plan($linked, $base_date, $holiday_country, $options);
}

private static function build_month_plan(array $linked, string $base_date, string $holiday_country = 'pt', array $options = []): array {
    $rawOptions = is_array($options) ? $options : [];
    $norm = self::normalize_plan_options($rawOptions);
    $options = array_merge($rawOptions, $norm);

    $calendar = self::get_month_business_calendar($base_date, $holiday_country);
    $routeStrategy = self::normalize_route_strategy((string)($options['route_strategy'] ?? 'complete_coverage'));
    $allowCrossWeekBalance = false; // periodicidade é regra dura: nunca mover visitas entre semanas
    $weekBuckets = (array)($calendar['weeks'] ?? []);
    $excludedDays = (array)($calendar['excluded_days'] ?? []);
    $allWeekdays = (array)($calendar['all_weekdays'] ?? []);

    if (!$weekBuckets) {
        return [
            'days' => [],
            'preview_days' => [],
            'summary' => [],
            'scope' => 'monthly',
            'period_label' => (string)($calendar['period_label'] ?? ''),
            'excluded_holidays' => array_keys(array_filter($excludedDays, function($reason){ return $reason !== 'Fim de semana'; })),
            'excluded_days' => $excludedDays,
            'options' => $options,
            'unassigned' => [],
            'reinforcement' => ['recommended' => false],
            'broken_periodicity' => [],
        ];
    }
    

    $tasksByWeek = self::build_tasks_by_week_for_calendar($linked, $weekBuckets, $options);
    if (!$tasksByWeek) {
        return [
            'days' => [],
            'preview_days' => [],
            'summary' => [],
            'scope' => 'monthly',
            'period_label' => (string)($calendar['period_label'] ?? ''),
            'excluded_holidays' => array_keys(array_filter($excludedDays, function($reason){ return $reason !== 'Fim de semana'; })),
            'excluded_days' => $excludedDays,
            'options' => $options,
            'unassigned' => [],
            'reinforcement' => ['recommended' => false],
            'broken_periodicity' => [],
        ];
    }

    // Todas as estratégias mensais usam o motor de cadência fixa.
    // A diferença entre estratégias passa a estar nos pesos de carga, zona e distância,
    // sem quebrar a regra-base: cobertura total e periodicidade exata.
    if (in_array($routeStrategy, ['operational_balanced', 'complete_coverage', 'balanced_load', 'minimize_km', 'cluster_district', 'route_corridor'], true)) {
        $strict = self::build_strict_fixed_cadence_month_plan($tasksByWeek, $weekBuckets, $allWeekdays, $excludedDays, $calendar, $options);
        $strict['period_label'] = (string)($calendar['period_label'] ?? '');
        $strict['excluded_holidays'] = array_keys(array_filter($excludedDays, function($reason){ return $reason !== 'Fim de semana'; }));
        $strict['excluded_days'] = $excludedDays;
        $strict['options'] = $options;
        $strict['broken_periodicity'] = [];
        return PlanQualityScorer::attach($strict);
    }

    // ----- helpers -----
    $dayHasStore = function(array $day, array $task): bool {
        $id = (int)($task['id'] ?? 0);
        if ($id <= 0) return false;
        foreach ((array)($day['items'] ?? []) as $it) {
            if ((int)($it['id'] ?? 0) === $id) return true;
        }
        return false;
    };

    $dayRefPoint = function(array $day, array $weekOptions): ?array {
        $items = (array)($day['items'] ?? []);
        if ($items) {
            $last = end($items);
            if (is_array($last) && is_numeric($last['lat'] ?? null) && is_numeric($last['lng'] ?? null)) return $last;
        }
        $sp = is_array($weekOptions['start_point'] ?? null) ? $weekOptions['start_point'] : [];
        if (is_numeric($sp['lat'] ?? null) && is_numeric($sp['lng'] ?? null)) return $sp;
        return null;
    };

    // Selecionar o "melhor dia" APENAS por carga/folga (primeiro 0 lojas, depois menos stops, depois menor work_min)
    $pickBestDayIndex = function(array $weekPreview): ?int {
        if (!$weekPreview) return null;
        $bestIdx = null;
        $bestTuple = null; // [stops, work_min]
        foreach ($weekPreview as $idx => $d) {
            $stops = (int)($d['stops'] ?? 0);
            $work = (int)($d['work_min'] ?? 0);
            $tuple = [$stops, $work];
            if ($bestTuple === null || $tuple < $bestTuple) {
                $bestTuple = $tuple;
                $bestIdx = $idx;
            }
        }
        return $bestIdx;
    };

    // Selecionar task para um dia: prioridade (alto), monthly (prefer), depois zona/distância (só aqui entra distância)
    $pickBestTaskIndexForDay = function(array $day, array $weekOptions, string $weekKey, array $pool, bool $allowPullBack = false) use ($dayHasStore, $dayRefPoint): ?int {
        if (!$pool) return null;

        $ref = $dayRefPoint($day, $weekOptions);
        $bestI = null;
        $bestScore = PHP_FLOAT_MAX;

        foreach ($pool as $i => $task) {
            $fromWeek = (string)($task['target_week_key'] ?? '');

            // Por defeito só adiantar (não puxar para trás). Se não houver alternativas, podemos permitir pull-back.
            if (!$allowPullBack && $fromWeek !== '' && $fromWeek < $weekKey) continue;

            // não duplicar loja no mesmo dia
            if ($dayHasStore($day, $task)) continue;

            // score: menor é melhor
            $score = 0.0;

            // prioridade domina
            $score -= ((int)($task['priority'] ?? 0)) * 100.0;

            // monthly preferido
            if (($task['visit_frequency'] ?? 'weekly') === 'monthly') $score -= 40.0;

            // zona (cidade/distrito)
            $last = null;
            $items = (array)($day['items'] ?? []);
            if ($items) $last = end($items);
            $sameZone = self::same_zone_score(is_array($last) ? $last : null, $task);
            $score += $sameZone ? -50.0 : 10.0;

            // distância (apenas desempate dentro do dia)
            if ($ref && is_numeric($task['lat'] ?? null) && is_numeric($task['lng'] ?? null)) {
                $km = self::haversine_km((float)$ref['lat'], (float)$ref['lng'], (float)$task['lat'], (float)$task['lng']);
                $score += ($km * 2.8);
            }

            // pequena penalização por mover entre semanas
            if ($fromWeek !== '' && $fromWeek !== $weekKey) $score += 6.0;

            if ($score < $bestScore) { $bestScore = $score; $bestI = $i; }
        }

        return $bestI;
    };

    // Pool global (todas as tasks do mês)
    $globalPool = [];
    foreach ($tasksByWeek as $wk => $tasks) {
        foreach ((array)$tasks as $t) $globalPool[] = $t;
    }

    // Ordenação base do pool (para estabilidade)
    usort($globalPool, function($a, $b){
        $pa = (int)($a['priority'] ?? 0);
        $pb = (int)($b['priority'] ?? 0);
        if ($pa !== $pb) return $pb <=> $pa;
        $fa = (($a['visit_frequency'] ?? 'weekly') === 'monthly') ? 0 : 1;
        $fb = (($b['visit_frequency'] ?? 'weekly') === 'monthly') ? 0 : 1;
        if ($fa !== $fb) return $fa <=> $fb;
        return strcmp(strtolower((string)($a['name'] ?? '')), strtolower((string)($b['name'] ?? '')));
    });

    // Helper para remover tasks "devidas" (não duplicar)
    $removeMatchingFromPool = function(array $task) use (&$globalPool): void {
        foreach ($globalPool as $i => $t) {
            if (
                (int)($t['id'] ?? 0) === (int)($task['id'] ?? 0) &&
                (int)($t['copy_index'] ?? 0) === (int)($task['copy_index'] ?? 0) &&
                (string)($t['visit_frequency'] ?? '') === (string)($task['visit_frequency'] ?? '') &&
                (string)($t['target_week_key'] ?? '') === (string)($task['target_week_key'] ?? '')
            ) {
                unset($globalPool[$i]);
                $globalPool = array_values($globalPool);
                return;
            }
        }
    };

    $days = [];
    $previewDays = [];
    $allUnassigned = [];
    $broken = [];

    foreach ($weekBuckets as $weekKey => $bucket) {
        $bucketDates = array_values((array)($bucket['dates'] ?? []));
        $bucketLabel = (string)($bucket['label'] ?? 'Semana');

        $weekTasks = (array)($tasksByWeek[$weekKey] ?? []);
        foreach ($weekTasks as $t) $removeMatchingFromPool($t);

        $weekOptions = $options;
        if ($bucketDates) {
            $weekOptions['min_open_days'] = min(count($bucketDates), max(0, (int)($weekOptions['min_open_days'] ?? 0)));
        }

        // Planeamento base
        $weekOptions = $options;
        $weekOptions['min_open_days'] = min(count($bucketDates), max(0, (int)($weekOptions['min_open_days'] ?? 0)));

        $planned = self::plan_tasks_into_dates(
    (array)($tasksByWeek[$weekKey] ?? []),
    $bucketDates,
    (string)($bucket['label'] ?? 'Semana'),
    $weekOptions
);
        $weekDays = (array)($planned['days'] ?? []);
        $weekPreview = (array) self::build_preview_days($weekDays, $bucketDates, $bucketLabel);
        foreach ($weekPreview as &$d) $d['week_key'] = $weekKey;
        unset($d);

        $maxStops = (int)($weekOptions['max_stops_per_day'] ?? 12);

        // Preencher até não existirem dias 0 (e depois equilibrar um pouco).
        // Só a estratégia "Equilibrar carga" pode puxar visitas entre semanas.
        // Nas restantes estratégias, periodicidade semanal/mensal é regra dura.
        $safety = 0;
        while ($allowCrossWeekBalance && $safety < 500) {
            $safety++;

            if (!$globalPool) break;

            // escolher o dia mais vazio/folgado (sem olhar distância)
            $dayIndex = $pickBestDayIndex($weekPreview);
            if ($dayIndex === null) break;

            $day = $weekPreview[$dayIndex];

            // se já está cheio, parar
            if ((int)($day['stops'] ?? 0) >= $maxStops) {
                // se o melhor dia está cheio, então todos estão no limite -> parar
                break;
            }

            // condição de paragem: se não há dias vazios e já está "equilibrado o suficiente"
            $hasEmpty = false;
            $minStops = PHP_INT_MAX;
            $maxStopsSeen = 0;
            foreach ($weekPreview as $d) {
                $st = (int)($d['stops'] ?? 0);
                if ($st === 0) $hasEmpty = true;
                $minStops = min($minStops, $st);
                $maxStopsSeen = max($maxStopsSeen, $st);
            }

            // Se não há vazios e diferença <= 1, não mexer mais (evita over-fill)
            if (!$hasEmpty && ($maxStopsSeen - $minStops) <= 1) break;

            // escolher task para ESTE dia (distância só aqui)
            $bestI = $pickBestTaskIndexForDay($day, $weekOptions, (string)$weekKey, $globalPool, false);

            // Se não encontrou (por ex. só há tasks "para trás"), permitir pull-back como fallback
            if ($bestI === null) {
                $bestI = $pickBestTaskIndexForDay($day, $weekOptions, (string)$weekKey, $globalPool, true);
            }

            if ($bestI === null) break;

            $picked = $globalPool[$bestI];
            unset($globalPool[$bestI]);
            $globalPool = array_values($globalPool);

            // Marcar quebra de periodicidade
            $picked['periodicity_broken'] = true;
            $picked['moved_from_week'] = (string)($picked['target_week_key'] ?? '');
            $picked['moved_to_week'] = (string)$weekKey;
            $picked['target_week_key_original'] = (string)($picked['target_week_key'] ?? '');
            $picked['target_week_key'] = (string)$weekKey;

            $broken[] = [
                'name' => (string)($picked['name'] ?? ''),
                'city' => (string)($picked['city'] ?? ''),
                'visit_frequency' => (string)($picked['visit_frequency'] ?? ''),
                'copy_index' => (int)($picked['copy_index'] ?? 1),
                'from_week' => (string)($picked['moved_from_week'] ?? ''),
                'to_week' => (string)($picked['moved_to_week'] ?? ''),
                'to_date' => (string)($day['date'] ?? ''),
            ];

            // Inserir (garantia anti-duplicado no mesmo dia)
            if (empty($weekPreview[$dayIndex]['items']) || !is_array($weekPreview[$dayIndex]['items'])) {
                $weekPreview[$dayIndex]['items'] = [];
            }

            if (!$dayHasStore($weekPreview[$dayIndex], $picked)) {
                $weekPreview[$dayIndex]['items'][] = $picked;
                $weekPreview[$dayIndex]['stops'] = (int)($weekPreview[$dayIndex]['stops'] ?? 0) + 1;
                $weekPreview[$dayIndex]['visit_min'] = (int)($weekPreview[$dayIndex]['visit_min'] ?? 0) + (int)($picked['visit_duration_min'] ?? 45);

                // Recalcular métricas do dia (travel_min fica aproximado)
                self::finalize_planned_day($weekPreview[$dayIndex], (int)$weekOptions['work_minutes'], (int)$weekOptions['lunch_minutes']);
            }
        }
        $weekOptions['_debug_scope'] = 'monthly';

        // Replanear semana inteira para obter uma rota “coerente” (sequência e travel)
        $finalTasks = [];
        foreach ($weekPreview as $d) {
            foreach ((array)($d['items'] ?? []) as $it) $finalTasks[] = $it;
        }

        $finalPlanned = self::plan_tasks_into_dates($finalTasks, $bucketDates, $bucketLabel, $weekOptions);

        foreach ((array)($finalPlanned['days'] ?? []) as $day) $days[] = $day;
        foreach ((array) self::build_preview_days((array)($finalPlanned['days'] ?? []), $bucketDates, $bucketLabel) as $day) $previewDays[] = $day;
        foreach ((array)($finalPlanned['unassigned'] ?? []) as $left) $allUnassigned[] = $left;
    }

    usort($days, function($a, $b){ return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')); });
    usort($previewDays, function($a, $b){ return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')); });

    $summary = self::summarize_days($days, $allUnassigned, count($allWeekdays));
    $reinforcement = self::build_reinforcement_summary($days, $allUnassigned, (int)$options['work_minutes'], count($allWeekdays));

    return [
        'days' => $days,
        'preview_days' => $previewDays,
        'summary' => $summary,
        'scope' => 'monthly',
        'period_label' => (string)($calendar['period_label'] ?? ''),
        'excluded_holidays' => array_keys(array_filter($excludedDays, function($reason){ return $reason !== 'Fim de semana'; })),
        'excluded_days' => $excludedDays,
        'options' => $options,
        'unassigned' => $allUnassigned,
        'reinforcement' => $reinforcement,
        'broken_periodicity' => $broken,
    ];
}

private static function build_week_plan(array $linked, string $base_date = '', string $holiday_country = 'pt', array $options = []): array {
    // Preservar opções extra (ex.: min_open_days) — normalize_plan_options() descarta chaves desconhecidas
    $rawOptions = is_array($options) ? $options : [];
    $normOptions = self::normalize_plan_options($rawOptions);
    $options = array_merge($rawOptions, $normOptions);
    

    // 1) Determinar semana (2ª a 6ª) que contém o base_date
    $baseTs = strtotime($base_date ?: date('Y-m-d'));
    if (!$baseTs) $baseTs = current_time('timestamp');

    // Garante que é mesmo "a semana do base_date" e não +1 semana por causa de parsing
    $weekStartTs = strtotime('monday this week', $baseTs);
    if (!$weekStartTs) $weekStartTs = $baseTs;
    $weekEndTs = strtotime('+4 day', $weekStartTs);

    // 2) Feriados só dentro do intervalo desta semana (não procurar 21 dias nem compensar)
    $holidayMap = self::get_holiday_map($holiday_country, [(int)date('Y', $weekStartTs), (int)date('Y', $weekEndTs)]);

    $dates = [];
    $excludedDays = [];
    for ($ts = $weekStartTs; $ts <= $weekEndTs; $ts = strtotime('+1 day', $ts)) {
        $date = date('Y-m-d', $ts);
        $dow = (int) date('N', $ts);

        if ($dow >= 6) { // redundante (já só percorremos 2ª-6ª), mas mantém consistência
            $excludedDays[$date] = 'Fim de semana';
            continue;
        }
        if (isset($holidayMap[$date])) {
            $excludedDays[$date] = $holidayMap[$date];
            continue;
        }
        $dates[] = $date;
    }

    if (!$dates) {
        return [
            'days' => [],
            'preview_days' => [],
            'summary' => [],
            'scope' => 'weekly',
            'excluded_holidays' => array_keys(array_filter($excludedDays, fn($reason) => $reason !== 'Fim de semana')),
            'excluded_days' => $excludedDays,
            'options' => $options,
            'unassigned' => [],
            'reinforcement' => ['recommended' => false],
        ];
    }

    // Forçar que o planner "abre" (pelo menos) todos os dias úteis desta semana.
    // (Se tiveres um spread real dentro do plan_tasks_into_dates, isto ajuda a não colapsar tudo no dia 1.)
    $options['min_open_days'] = max((int)($options['min_open_days'] ?? 0), count($dates));

    // NOVO: distância mínima entre visitas à mesma loja (gap em dias)
    // Gap = 2 => nunca visita a mesma loja em dias seguidos.
    $options['min_days_between_same_store'] = 2;

    // 3) Construir tasks (visitas) a partir dos linked
    $tasks = [];
    foreach ($linked as $row) {
        if (empty($row['campaign_active']) || ($row['campaign_status'] ?? 'active') !== 'active') continue;

        $lat = isset($row['lat']) ? (float)$row['lat'] : null;
        $lng = isset($row['lng']) ? (float)$row['lng'] : null;
        if (!is_finite($lat) || !is_finite($lng)) continue;

        $freq = ($row['visit_frequency'] ?: 'weekly');
        $count = max(1, (int)($row['frequency_count'] ?? 1));

        // Semanal: até 7 visitas nessa semana
        // Mensal: entra como 1 visita na semana (a distribuição mensal acontece no build_month_plan)
        $visits = $freq === 'weekly' ? min(7, $count) : 1;

        for ($i = 1; $i <= $visits; $i++) {
            $copy = $row;
            $copy['copy_index'] = $i;
            $copy['visit_frequency'] = $freq;
            $copy['visit_duration_min'] = max(0, min(360, (int)($row['visit_duration_min'] ?? 45)));
            $tasks[] = $copy;
        }
    }

    if (!$tasks) {
        return [
            'days' => [],
            'preview_days' => [],
            'summary' => [],
            'scope' => 'weekly',
            'excluded_holidays' => array_keys(array_filter($excludedDays, fn($reason) => $reason !== 'Fim de semana')),
            'excluded_days' => $excludedDays,
            'options' => $options,
            'unassigned' => [],
            'reinforcement' => ['recommended' => false],
        ];
    }
$options['_debug_scope'] = 'weekly';
    // 4) Planear tasks para os dias úteis disponíveis (2ª-6ª sem feriados)
    $planned = self::plan_tasks_into_dates($tasks, $dates, 'Dia', $options);

    $days = (array)($planned['days'] ?? []);
    $unassigned = (array)($planned['unassigned'] ?? []);
    $summary = self::summarize_days($days, $unassigned, count($dates));
    $reinforcement = (array)($planned['reinforcement'] ?? []);

    // Preview_days: garante que todos os dias do período aparecem na UI, mesmo sem lojas
    $previewDays = (array) self::build_preview_days($days, $dates, 'Dia');

    return [
        'days' => $days,
        'preview_days' => $previewDays,
        'summary' => $summary,
        'scope' => 'weekly',
        'period_label' => date_i18n('d/m/Y', $weekStartTs) . ' - ' . date_i18n('d/m/Y', $weekEndTs),
        'week_start' => date('Y-m-d', $weekStartTs),
        'week_end' => date('Y-m-d', $weekEndTs),
        'excluded_holidays' => array_keys(array_filter($excludedDays, fn($reason) => $reason !== 'Fim de semana')),
        'excluded_days' => $excludedDays,
        'options' => $options,
        'unassigned' => $unassigned,
        'reinforcement' => $reinforcement,
    ];
}

    private static function build_strict_fixed_cadence_month_plan(array $tasksByWeek, array $weekBuckets, array $allWeekdays, array $excludedDays, array $calendar, array $options = []): array {
        $rawOptions = is_array($options) ? $options : [];
        $norm = self::normalize_plan_options($rawOptions);
        $options = array_merge($rawOptions, $norm);
        $targetWorkMin = max(60, (int)($options['work_minutes'] ?? 480));
        $lunchMin = max(0, (int)($options['lunch_minutes'] ?? 60));
        $maxStops = max(1, (int)($options['max_stops_per_day'] ?? 12));
        $startPoint = is_array($options['start_point'] ?? null) ? (array)$options['start_point'] : [];
        $endPoint = is_array($options['end_point'] ?? null) ? (array)$options['end_point'] : [];
        $globalAllowOvertime = !empty($options['allow_overtime']);
        $defaultExtraMin = max(0, min(150, (int)($options['overtime_extra_minutes'] ?? 0)));
        $dailyOvertimeDates = [];
        foreach ((array)($options['daily_overtime_dates'] ?? []) as $d) {
            $d = sanitize_text_field((string)$d);
            if ($d !== '') $dailyOvertimeDates[$d] = true;
        }
        $dailyOvertimeMinutes = [];
        foreach ((array)($options['daily_overtime_minutes'] ?? []) as $d => $m) {
            $d = sanitize_text_field((string)$d);
            if ($d !== '') $dailyOvertimeMinutes[$d] = max(0, min(150, (int)$m));
        }

        $days = [];
        $dateIndex = [];
        foreach ($weekBuckets as $weekKey => $bucket) {
            $bucketLabel = (string)($bucket['label'] ?? 'Semana');
            foreach (array_values((array)($bucket['dates'] ?? [])) as $date) {
                $date = (string)$date;
                if ($date === '') continue;
                $ts = strtotime($date);
                $weekday = $ts ? (int)date('N', $ts) : (count($days) + 1);
                $hasDayOverride = isset($dailyOvertimeDates[$date]) || isset($dailyOvertimeMinutes[$date]);
                $dayExtraMin = isset($dailyOvertimeMinutes[$date]) ? min(150, (int)$dailyOvertimeMinutes[$date]) : ($hasDayOverride ? ($defaultExtraMin > 0 ? $defaultExtraMin : 150) : 0);
                $allowOvertime = ($hasDayOverride || $globalAllowOvertime) && $dayExtraMin > 0;
                if (!$allowOvertime) $dayExtraMin = 0;
                $days[] = [
                    'label' => $bucketLabel . '-' . $weekday,
                    'date' => $date,
                    'week_key' => (string)$weekKey,
                    'items' => [],
                    'travel_min' => 0.0,
                    'visit_min' => 0,
                    'stops' => 0,
                    'start_point' => $startPoint,
                    'end_point' => $endPoint,
                    'allow_overtime' => $allowOvertime,
                    'extra_minutes' => $dayExtraMin,
                    'hard_limit_minutes' => min($targetWorkMin + $dayExtraMin, 600),
                ];
                $dateIndex[$date] = count($days) - 1;
            }
        }

        $unassigned = [];
        foreach ($tasksByWeek as $weekKey => $tasks) {
            foreach ((array)$tasks as $task) {
                if (!is_array($task)) continue;
                $targetDate = sanitize_text_field((string)($task['target_date'] ?? ''));
                if ($targetDate === '' && isset($weekBuckets[$weekKey])) {
                    $targetDate = self::date_for_preferred_weekday((array)$weekBuckets[$weekKey], (int)($task['preferred_weekday'] ?? 1), true);
                }
                if ($targetDate === '' || !isset($dateIndex[$targetDate])) {
                    $task['overflow_conflict'] = true;
                    $task['route_exception_reason'] = 'Sem dia útil disponível para a cadência fixa no período';
                    $unassigned[] = $task;
                    continue;
                }
                $days[$dateIndex[$targetDate]]['items'][] = $task;
            }
        }

        // Harmonização operacional dentro de cada semana: mantém a semana espelho/cadência,
        // mas permite deslocar visitas para outro dia útil da mesma semana quando a distância/carga rebenta o target.
        $days = self::rebalance_fixed_cadence_days_by_effort($days, $options, $maxStops, $targetWorkMin, $lunchMin);
        $days = self::move_soft_visits_from_heavy_to_light_days($days, $unassigned, $options, $maxStops, $targetWorkMin, $lunchMin);
        $days = self::recover_unassigned_visits_to_best_days($days, $unassigned, $options, $maxStops, $targetWorkMin, $lunchMin);
        $days = self::rebalance_weekly_even_stop_distribution_v2($days, $unassigned, $options, $maxStops, $targetWorkMin, $lunchMin);
        $days = self::rebalance_to_even_stop_distribution($days, $unassigned, $options, $maxStops, $targetWorkMin, $lunchMin);
        $days = self::enforce_hard_daily_stop_cap($days, $unassigned, $options, $maxStops, $targetWorkMin, $lunchMin);

        foreach ($days as &$day) {
            $items = array_values((array)($day['items'] ?? []));
            $items = self::optimize_day_items($items, array_merge($options, [
                'start_point' => $startPoint,
                'end_point' => $endPoint,
            ]));
            $visitMin = 0;
            foreach ($items as $it) $visitMin += max(0, (int)($it['visit_duration_min'] ?? 45));
            $travelMin = self::estimate_day_travel_minutes($items, array_merge($options, [
                'lock_start_point' => true,
                'lock_end_point' => true,
                'start_point' => $startPoint,
                'end_point' => $endPoint,
            ]));
            $hardLimit = (int)($day['hard_limit_minutes'] ?? $targetWorkMin);
            $isOverflow = count($items) > $maxStops || ($visitMin + $travelMin + ($items ? $lunchMin : 0)) > ($hardLimit + 0.5);
            if ($isOverflow) {
                foreach ($items as &$it) {
                    $it['overflow_forced'] = true;
                    $it['route_exception_reason'] = 'Exceção operacional: cobertura e periodicidade mantidas apesar de exceder carga/horas';
                }
                unset($it);
            }
            $day['items'] = $items;
            $day['stops'] = count($items);
            $day['visit_min'] = $visitMin;
            $day['travel_min'] = $travelMin;
            self::finalize_planned_day($day, $targetWorkMin, $lunchMin);
            unset($day['hard_limit_minutes']);
        }
        unset($day);

        $summary = self::summarize_days($days, $unassigned, count($allWeekdays));
        $reinforcement = self::build_reinforcement_summary($days, $unassigned, $targetWorkMin, count($allWeekdays));
        $diagnostics = self::build_plan_diagnostics($days, $unassigned, $options, $maxStops, $targetWorkMin, $lunchMin);
        return [
            'days' => $days,
            'preview_days' => self::build_preview_days($days, $allWeekdays, 'Semana'),
            'summary' => $summary,
            'scope' => 'monthly',
            'month_start' => (string)($calendar['month_start'] ?? ''),
            'month_end' => (string)($calendar['month_end'] ?? ''),
            'unassigned' => $unassigned,
            'reinforcement' => $reinforcement,
            'diagnostics' => $diagnostics,
            'fixed_cadence' => true,
        ];
    }

    private static function get_month_business_calendar(string $base_date, string $holiday_country = 'pt'): array {
        $baseTs = strtotime($base_date ?: date('Y-m-d'));
        if (!$baseTs) $baseTs = current_time('timestamp');

        $monthStart = date('Y-m-01', $baseTs);
        $monthEnd = date('Y-m-t', $baseTs);
        $periodStart = date('Y-m-d', $baseTs);
        $holidayMap = self::get_holiday_map($holiday_country, [(int)date('Y', strtotime($monthStart)), (int)date('Y', strtotime($monthEnd))]);
        $weeks = [];
        $excludedDays = [];
        $allWeekdays = [];
        $selectedWeekKey = date('Y-m-d', strtotime('monday this week', $baseTs));

        // A sugestão mensal é sempre do dia base para a frente. Dias anteriores do mesmo mês
        // ficam apenas como excluídos informativos para não contaminar a cadência nem abrir rotas antigas.
        for ($ts = strtotime($monthStart); $ts < strtotime($periodStart); $ts = strtotime('+1 day', $ts)) {
            $date = date('Y-m-d', $ts);
            $dow = (int) date('N', $ts);
            $excludedDays[$date] = $dow >= 6 ? 'Fim de semana' : 'Antes da data base';
        }

        for ($ts = strtotime($periodStart); $ts <= strtotime($monthEnd); $ts = strtotime('+1 day', $ts)) {
            $date = date('Y-m-d', $ts);
            $dow = (int) date('N', $ts);
            if ($dow >= 6) { $excludedDays[$date] = 'Fim de semana'; continue; }
            if (isset($holidayMap[$date])) { $excludedDays[$date] = $holidayMap[$date]; continue; }
            $weekKey = date('Y-m-d', strtotime('monday this week', $ts));
            if (!isset($weeks[$weekKey])) {
                $weeks[$weekKey] = [
                    'key' => $weekKey,
                    'index' => count($weeks) + 1,
                    'label' => 'Semana ' . (count($weeks) + 1),
                    'dates' => [],
                    'week_start' => $weekKey,
                    'week_end' => $weekKey,
                ];
            }
            $weeks[$weekKey]['dates'][] = $date;
            $weeks[$weekKey]['week_end'] = $date;
            $allWeekdays[] = $date;
        }

        foreach ($weeks as $wkKey => &$wkBucket) {
            $wkStartTs = strtotime((string)$wkKey);
            $wkFridayTs = $wkStartTs ? strtotime('+4 days', $wkStartTs) : false;
            $startsBeforePeriod = $wkStartTs && $wkStartTs < strtotime($periodStart);
            $startsBeforeMonth = $wkStartTs && $wkStartTs < strtotime($monthStart);
            $endsAfterMonth = $wkFridayTs && $wkFridayTs > strtotime($monthEnd);
            $wkBucket['business_day_count'] = count((array)($wkBucket['dates'] ?? []));
            // Parcial também quando a data base entra a meio de uma semana, mesmo que seja o mesmo mês.
            $wkBucket['is_partial_week'] = (bool)($startsBeforePeriod || $startsBeforeMonth || $endsAfterMonth || $wkBucket['business_day_count'] < 5);
            $wkBucket['is_full_week'] = !$wkBucket['is_partial_week'];
        }
        unset($wkBucket);

        return [
            'month_start' => $periodStart,
            'calendar_month_start' => $monthStart,
            'month_end' => $monthEnd,
            'period_label' => date_i18n('d/m/Y', strtotime($periodStart)) . ' - ' . date_i18n('d/m/Y', strtotime($monthEnd)),
            'weeks' => $weeks,
            'all_weekdays' => $allWeekdays,
            'excluded_days' => $excludedDays,
            'selected_week_key' => $selectedWeekKey,
        ];
    }


    private static function build_mirror_week_matrix(array $weekBuckets): array {
        $weekKeys = array_values(array_keys($weekBuckets));
        $fullKeys = [];
        foreach ($weekKeys as $wk) {
            $bucket = (array)($weekBuckets[$wk] ?? []);
            if (!empty($bucket['is_full_week']) || count((array)($bucket['dates'] ?? [])) >= 5) $fullKeys[] = (string)$wk;
        }
        $firstKey = (string)($weekKeys[0] ?? '');
        $lastKey = (string)($weekKeys[count($weekKeys) - 1] ?? '');
        $firstPartial = $firstKey !== '' && !empty($weekBuckets[$firstKey]['is_partial_week']);
        $lastPartial = $lastKey !== '' && !empty($weekBuckets[$lastKey]['is_partial_week']);
        $preferredPair = [1, 3];
        if (count($weekKeys) >= 4) {
            $preferredPair = $firstPartial ? [2, 4] : ($lastPartial ? [1, 3] : [2, 4]);
        }
        $indexToKey = [];
        $keyToIndex = [];
        foreach ($weekKeys as $idx => $wk) {
            $indexToKey[$idx + 1] = (string)$wk;
            $keyToIndex[(string)$wk] = $idx + 1;
        }
        return [
            'week_keys' => $weekKeys,
            'full_week_keys' => $fullKeys,
            'index_to_key' => $indexToKey,
            'key_to_index' => $keyToIndex,
            'preferred_pair' => $preferredPair,
            'mirror_pairs' => [1 => 3, 2 => 4, 3 => 1, 4 => 2, 5 => 3],
            'week_5_is_full' => !empty($indexToKey[5]) && in_array((string)$indexToKey[5], $fullKeys, true),
            'partial_start_week' => $firstPartial,
            'partial_end_week' => $lastPartial,
        ];
    }

    private static function week_key_by_matrix_index(array $matrix, int $index): string {
        return (string)($matrix['index_to_key'][$index] ?? '');
    }

    private static function assign_periodicity_by_mirror_matrix(int $count, array $weekBuckets, array $weekLoad = [], string $seedKey = '', int $preferredWeekday = 1, bool $allowExtraVisits = false, array $options = []): array {
        $matrix = self::build_mirror_week_matrix($weekBuckets);
        $weekKeys = array_values((array)($matrix['week_keys'] ?? []));
        if (!$weekKeys) return [];
        $validIndexes = [];
        foreach ((array)($matrix['index_to_key'] ?? []) as $idx => $wk) {
            $date = self::date_for_preferred_weekday((array)($weekBuckets[$wk] ?? []), $preferredWeekday, true);
            if ($date !== '') $validIndexes[] = (int)$idx;
        }
        if (!$validIndexes) return [];
        $count = max(1, (int)$count);
        $n = count($validIndexes);
        $pickIndexes = [];
        $preferredPair = array_values((array)($matrix['preferred_pair'] ?? [2, 4]));
        $oppositePair = $preferredPair === [2, 4] ? [1, 3] : [2, 4];
        $baseTarget = self::resolve_target_stops_per_day($options, max(1, (int)($options['max_stops_per_day'] ?? 12)));
        if ($baseTarget <= 0) $baseTarget = max(1, min(8, (int)($options['max_stops_per_day'] ?? 12)));
        $weekCapacityScore = function(array $indexes, float $extraLoadPerWeek = 1.0, float $cadencePenalty = 0.0) use ($matrix, $weekBuckets, $weekLoad, $baseTarget): float {
            $score = $cadencePenalty;
            foreach ($indexes as $idx) {
                $wk = self::week_key_by_matrix_index($matrix, (int)$idx);
                if ($wk === '' || empty($weekBuckets[$wk])) { $score += 100000.0; continue; }
                $days = max(1, count((array)($weekBuckets[$wk]['dates'] ?? [])));
                $capacity = max(1.0, $days * max(1.0, (float)$baseTarget));
                $current = (float)($weekLoad[$wk] ?? 0);
                $projected = $current + $extraLoadPerWeek;
                $ratio = $projected / $capacity;
                $score += $current * 8.0;
                if (!empty($weekBuckets[$wk]['is_partial_week'])) $score += 18.0;
                if ($ratio > 0.85) $score += pow(($ratio - 0.85) * 100.0, 2) * 1.4;
                if ($ratio > 1.00) $score += pow(($ratio - 1.00) * 100.0, 2) * 5.5;
            }
            return $score;
        };
        $filterValid = static function(array $indexes) use ($validIndexes): array {
            $out = [];
            foreach ($indexes as $idx) if (in_array((int)$idx, $validIndexes, true)) $out[] = (int)$idx;
            return array_values(array_unique($out));
        };
        $sortCandidateSets = function(array $sets) use ($weekCapacityScore, $filterValid, $seedKey): array {
            $valid = [];
            foreach ($sets as $set) {
                $set = $filterValid((array)$set);
                if (!$set) continue;
                $valid[] = $set;
            }
            usort($valid, function($a, $b) use ($weekCapacityScore, $seedKey) {
                $sa = $weekCapacityScore((array)$a) + ((abs(crc32($seedKey . '|A|' . implode('-', (array)$a))) % 17) * 0.001);
                $sb = $weekCapacityScore((array)$b) + ((abs(crc32($seedKey . '|B|' . implode('-', (array)$b))) % 17) * 0.001);
                return $sa <=> $sb;
            });
            return $valid;
        };
        $preferredValidPair = $filterValid($preferredPair);
        $preferPartialRule = (!empty($matrix['partial_start_week']) || !empty($matrix['partial_end_week'])) && count($preferredValidPair) >= min(2, $count);
        $preferredPairScore = $preferPartialRule ? $weekCapacityScore($preferredValidPair) : PHP_FLOAT_MAX;

        if ($count >= 4) {
            // P4 é âncora semanal: 4 visitas sem criar extra, 5 apenas quando a checkbox permite.
            $pickIndexes = $allowExtraVisits && !empty($matrix['week_5_is_full']) ? [1, 2, 3, 4, 5] : [1, 2, 3, 4];
            $pickIndexes = $filterValid($pickIndexes);
        } elseif ($count === 3) {
            // P3 = par espelho saudável + terceira visita na semana livre mais leve.
            $candidateSets = [];
            foreach ([$preferredPair, $oppositePair, [3, 5], [1, 4], [2, 5]] as $base) {
                $base = $filterValid($base);
                if (count($base) < 2) continue;
                foreach ([1, 2, 3, 4, 5] as $extra) {
                    if (in_array($extra, $base, true)) continue;
                    $set = $filterValid(array_merge($base, [$extra]));
                    sort($set);
                    if (count($set) === 3) $candidateSets[] = $set;
                }
            }
            $candidateSets = $sortCandidateSets($candidateSets);
            if ($preferPartialRule) {
                $preferredSets = array_values(array_filter($candidateSets, function($set) use ($preferredValidPair) {
                    return count(array_intersect((array)$set, (array)$preferredValidPair)) >= count($preferredValidPair);
                }));
                if ($preferredSets) {
                    $bestAny = $candidateSets[0] ?? [];
                    $bestPreferred = $preferredSets[0];
                    // Mantém a regra das semanas parciais, só foge se o padrão preferido estiver claramente saturado.
                    if ($weekCapacityScore($bestPreferred) <= $weekCapacityScore($bestAny) + 18000.0) $candidateSets = array_merge([$bestPreferred], $candidateSets);
                }
            }
            $pickIndexes = $candidateSets[0] ?? $filterValid(array_merge($preferredPair, [1]));
        } elseif ($count === 2) {
            // P2 prefere o par espelho, mas pode cair no segundo melhor par se 2+4 estiver saturado.
            $candidateSets = [$preferredPair, $oppositePair, [3, 5], [1, 4], [2, 5]];
            $candidateSets = $sortCandidateSets($candidateSets);
            if ($preferPartialRule && count($preferredValidPair) >= 2) {
                $bestAny = $candidateSets[0] ?? [];
                // Repor a regra anterior: se o mês começa parcial, 2+4 é o padrão natural;
                // se acaba parcial, 1+3 é o padrão natural. A capacidade só pode quebrar isto em saturação grave.
                if (!$bestAny || $preferredPairScore <= $weekCapacityScore($bestAny) + 12000.0) {
                    $candidateSets = array_merge([$preferredValidPair], $candidateSets);
                }
            }
            $pickIndexes = $candidateSets[0] ?? $filterValid($preferredPair);
        } else {
            // P1 é flexível: escolhe semana útil com capacidade semanal saudável.
            $candidates = $validIndexes;
            usort($candidates, function($a, $b) use ($matrix, $weekBuckets, $weekLoad, $seedKey, $weekCapacityScore) {
                $sa = $weekCapacityScore([(int)$a]);
                $sb = $weekCapacityScore([(int)$b]);
                if ($sa == $sb) return (abs(crc32($seedKey . '|' . $a)) % 97) <=> (abs(crc32($seedKey . '|' . $b)) % 97);
                return $sa <=> $sb;
            });
            $pickIndexes = [reset($candidates) ?: 1];
        }
        $keys = [];
        foreach ($pickIndexes as $idx) {
            if (!in_array((int)$idx, $validIndexes, true)) continue;
            $wk = self::week_key_by_matrix_index($matrix, (int)$idx);
            if ($wk !== '' && isset($weekBuckets[$wk])) $keys[] = $wk;
        }
        $keys = array_values(array_unique($keys));
        if (!$keys) return self::allocate_monthly_week_assignments($count, $weekBuckets, $weekLoad, $seedKey, $preferredWeekday, $allowExtraVisits);
        return $keys;
    }

 private static function build_tasks_by_week_for_calendar(array $linked, array $weekBuckets, array $options = []): array {
    if (!$linked || !$weekBuckets) return [];

    $weekKeys = array_values(array_keys($weekBuckets));
    $weekLoad = array_fill_keys($weekKeys, 0);
    $dateLoad = [];
    $weekdayLoad = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    $weekdayZoneLoad = [];
    $dateZoneLoad = [];
    $dateItems = [];
    foreach ($weekBuckets as $bucket) {
        foreach ((array)($bucket['dates'] ?? []) as $d) {
            $d = (string)$d;
            if ($d === '') continue;
            if (!isset($dateLoad[$d])) $dateLoad[$d] = 0;
            if (!isset($dateZoneLoad[$d])) $dateZoneLoad[$d] = [];
            if (!isset($dateItems[$d])) $dateItems[$d] = [];
        }
    }
    $tasksByWeek = [];

    usort($linked, function($a, $b){
        $ca = max(1, (int)($a['frequency_count'] ?? 1));
        $cb = max(1, (int)($b['frequency_count'] ?? 1));
        // Phase 4G: P4 cria a âncora. P1 entra logo a seguir por prioridade geográfica,
        // para que a distribuição de P2/P3 já conte com essa carga real no espelho.
        $ra = $ca >= 4 ? 1 : ($ca === 1 ? 2 : ($ca === 2 ? 3 : 4));
        $rb = $cb >= 4 ? 1 : ($cb === 1 ? 2 : ($cb === 2 ? 3 : 4));
        if ($ra !== $rb) return $ra <=> $rb;
        if ($ca !== $cb) return $cb <=> $ca;
        // Pipeline Phase 2: use pre-computed geo_cluster_id to group candidates
        // from the same cluster together within each cadence group.
        // This ensures that P4 anchors from the same area are placed consecutively,
        // and P1/P2/P3 visits from the same cluster follow their anchor.
        $clA = (int)($a['geo_cluster_id'] ?? 0);
        $clB = (int)($b['geo_cluster_id'] ?? 0);
        if ($clA !== $clB && $clA > 0 && $clB > 0) return $clA <=> $clB;
        $za = self::task_zone_key((array)$a);
        $zb = self::task_zone_key((array)$b);
        if ($za !== $zb) return strcmp($za, $zb);
        $pa = (int)($a['priority'] ?? 0);
        $pb = (int)($b['priority'] ?? 0);
        if ($pa !== $pb) return $pb <=> $pa;
        return strcmp(strtolower((string)($a['name'] ?? '')), strtolower((string)($b['name'] ?? '')));
    });

    foreach ($linked as $row) {
        if (empty($row['campaign_active']) || ($row['campaign_status'] ?? 'active') !== 'active') continue;

        $lat = isset($row['lat']) ? (float)$row['lat'] : null;
        $lng = isset($row['lng']) ? (float)$row['lng'] : null;
        if (!is_finite($lat) || !is_finite($lng)) continue;

        $row = VisitRuleResolver::applyToRow((array)$row);
        $configuredCount = max(1, (int)($row['frequency_count'] ?? 1));
        $seedKey = (string)((int)($row['id'] ?? 0)) . '|' . (string)($row['name'] ?? '');

        $plan = self::pick_fixed_cadence_weekday_plan((array)$row, $configuredCount, $weekBuckets, $weekLoad, $dateLoad, $weekdayLoad, $weekdayZoneLoad, $seedKey, $dateZoneLoad, $dateItems, $options);
        $preferredWeekday = (int)($plan['weekday'] ?? 1);
        $assignedWeekKeys = array_values((array)($plan['weeks'] ?? []));
        if (!$assignedWeekKeys) continue;

        $actualCount = count($assignedWeekKeys);
        foreach ($assignedWeekKeys as $i => $weekKey) {
            if (empty($weekBuckets[$weekKey])) continue;
            $targetDate = self::date_for_preferred_weekday((array)$weekBuckets[$weekKey], $preferredWeekday, true);
            if ($targetDate === '') continue;

            $copy = $row;
            $copy['copy_index'] = $i + 1;
            $copy['visit_frequency'] = 'monthly';
            // Mantém a periodicidade configurada para o editor e para a validação de cobertura.
            // O número real de visitas planeadas neste mês fica separado, para não transformar
            // uma cadência 4 numa cadência 5 quando o calendário tem cinco ocorrências do dia.
            $copy['frequency_count'] = $configuredCount;
            $copy['configured_frequency_count'] = $configuredCount;
            $copy['actual_route_visit_count'] = $actualCount;
            $copy['visit_duration_min'] = max(0, min(360, (int)($row['visit_duration_min'] ?? 45)));
            $copy['target_week_key'] = $weekKey;
            $copy['target_date'] = $targetDate;
            $copy['preferred_weekday'] = $preferredWeekday;
            $copy['min_gap_days'] = (int)($row['min_gap_days'] ?? 0);
            $copy['max_gap_days'] = (int)($row['max_gap_days'] ?? 0);
            $copy['preferred_weekdays'] = (array)($row['preferred_weekdays'] ?? []);
            $copy['blocked_weekdays'] = (array)($row['blocked_weekdays'] ?? []);
            $copy['time_window_start'] = (string)($row['time_window_start'] ?? '');
            $copy['time_window_end'] = (string)($row['time_window_end'] ?? '');
            $copy['allow_auto_reschedule'] = !empty($row['allow_auto_reschedule']) ? 1 : 0;
            $copy['allow_overtime'] = !empty($row['allow_overtime']) ? 1 : 0;
            $copy['rule_source'] = (string)($row['rule_source'] ?? 'campaign_location');
            if ((int)$copy['min_gap_days'] > 0 && $i > 0) {
                $prevWeekKey = (string)($assignedWeekKeys[$i - 1] ?? '');
                $prevDate = $prevWeekKey !== '' && isset($weekBuckets[$prevWeekKey]) ? self::date_for_preferred_weekday((array)$weekBuckets[$prevWeekKey], $preferredWeekday, true) : '';
                if ($prevDate !== '' && abs((strtotime($targetDate) ?: 0) - (strtotime($prevDate) ?: 0)) / DAY_IN_SECONDS < (int)$copy['min_gap_days']) {
                    $copy['periodicity_warning'] = 'Intervalo mínimo entre visitas pode não estar cumprido.';
                }
            }
            $copy['uid'] = (string)((int)($row['id'] ?? 0)) . '__' . (string)($i + 1);
            $copy['assignment_pattern_label'] = self::describe_monthly_week_pattern($actualCount, $assignedWeekKeys, $weekKey, count($weekKeys));
            $copy['cadence_label'] = self::describe_fixed_cadence_label($preferredWeekday, $copy['assignment_pattern_label']);
            $copy['periodicity_label'] = 'Cadência fixa · ' . $configuredCount . ' visita(s)/mês';
            $copy['route_corridor_key'] = self::task_route_corridor_key((array)$copy, (array)($options['start_point'] ?? []), (array)($options['end_point'] ?? []));
            $copy['route_corridor_position'] = self::task_route_corridor_position((array)$copy, (array)($options['start_point'] ?? []), (array)($options['end_point'] ?? []));

            $tasksByWeek[$weekKey][] = $copy;
            $weekLoad[$weekKey] = ($weekLoad[$weekKey] ?? 0) + 1;
            $dateLoad[$targetDate] = ($dateLoad[$targetDate] ?? 0) + 1;
            $weekdayLoad[$preferredWeekday] = ($weekdayLoad[$preferredWeekday] ?? 0) + 1;
            $zone = self::task_zone_key((array)$row);
            if ($zone !== '') {
                $weekdayZoneLoad[$preferredWeekday][$zone] = (int)($weekdayZoneLoad[$preferredWeekday][$zone] ?? 0) + 1;
                $dateZoneLoad[$targetDate][$zone] = (int)($dateZoneLoad[$targetDate][$zone] ?? 0) + 1;
            }
            $dateItems[$targetDate][] = $copy;
        }
    }

    return $tasksByWeek;
}

private static function pick_fixed_cadence_weekday_plan(array $row, int $configuredCount, array $weekBuckets, array $weekLoad, array $dateLoad, array $weekdayLoad, array $weekdayZoneLoad, string $seedKey = '', array $dateZoneLoad = [], array $dateItems = [], array $options = []): array {
    $zone = self::task_zone_key($row);
    $macroZone = self::task_macro_zone_key($row);
    $routeCorridor = self::task_route_corridor_key($row, (array)($options['start_point'] ?? []), (array)($options['end_point'] ?? []));
    $best = ['weekday' => 1, 'weeks' => []];
    $bestScore = PHP_FLOAT_MAX;
    $maxStops = max(1, (int)($options['max_stops_per_day'] ?? 12));
    $targetStops = self::resolve_target_stops_per_day($options, $maxStops);
    $routeStrategy = self::normalize_route_strategy((string)($options['route_strategy'] ?? 'complete_coverage'));
    $targetWorkMin = max(60, (int)($options['work_minutes'] ?? 480));
    $visitMin = max(0, (int)($row['visit_duration_min'] ?? 45));
    $weekCapacityRatio = static function(string $wk, float $extra = 1.0) use ($weekBuckets, $weekLoad, $targetStops, $maxStops): float {
        $days = max(1, count((array)($weekBuckets[$wk]['dates'] ?? [])));
        $base = $targetStops > 0 ? (float)$targetStops : max(1.0, min(8.0, (float)$maxStops));
        $capacity = max(1.0, $days * $base);
        return ((float)($weekLoad[$wk] ?? 0) + $extra) / $capacity;
    };
    $isAnchor = $configuredCount >= 4;
    $loadWeight = 54.0;
    $maxStopWeight = 1200.0;
    $zoneBonusWeight = 280.0;
    $adjacentZonePenalty = 190.0;
    $nearKmWeight = 5.5;
    if ($routeStrategy === 'balanced_load') {
        $loadWeight = 92.0;
        $maxStopWeight = 2600.0;
        $zoneBonusWeight = 180.0;
        $adjacentZonePenalty = 130.0;
        $nearKmWeight = 3.5;
    } elseif ($routeStrategy === 'minimize_km') {
        $loadWeight = 48.0;
        $maxStopWeight = 1400.0;
        $zoneBonusWeight = 340.0;
        $adjacentZonePenalty = 230.0;
        $nearKmWeight = 8.5;
    } elseif ($routeStrategy === 'cluster_district') {
        $loadWeight = 58.0;
        $maxStopWeight = 1700.0;
        $zoneBonusWeight = 420.0;
        $adjacentZonePenalty = 260.0;
        $nearKmWeight = 4.5;
    } elseif ($routeStrategy === 'route_corridor') {
        $loadWeight = 56.0;
        $maxStopWeight = 1900.0;
        $zoneBonusWeight = 520.0;
        $adjacentZonePenalty = 340.0;
        $nearKmWeight = 9.0;
    }
    // A cadência 4 é âncora. Para as restantes, consolidamos com a âncora,
    // mas nunca a ponto de criar dias de 12/13 lojas se houver dias próximos vazios.
    if (!$isAnchor) {
        $loadWeight *= 1.15;
        $maxStopWeight *= 1.2;
    }
    // P1 é a camada de oportunidade geográfica. Deve colar-se a uma âncora P4,
    // a um corredor de ida/volta, ou à macro-zona mais coerente antes de abrir carga solta.
    if ($configuredCount === 1) {
        $zoneBonusWeight *= 1.55;
        $nearKmWeight *= 1.35;
        $adjacentZonePenalty *= 0.75;
    }

    $preferredWeekdays = VisitRuleResolver::weekdayList($row['preferred_weekdays'] ?? []);
    $blockedWeekdays = VisitRuleResolver::weekdayList($row['blocked_weekdays'] ?? []);
    for ($dow = 1; $dow <= 5; $dow++) {
        if (in_array($dow, $blockedWeekdays, true)) continue;
        $weeks = self::assign_periodicity_by_mirror_matrix($configuredCount, $weekBuckets, $weekLoad, $seedKey, $dow, !empty($options['allow_extra_visits']), $options);
        if (!$weeks) continue;

        // Prioridade: a cadência 4 cria âncoras comerciais por semana.
        // As periodicidades 1/2/3 devem encaixar nessas âncoras por zona/distância antes de abrir dias duplicados.
        $score = (float)($weekdayLoad[$dow] ?? 0) * ($routeStrategy === 'balanced_load' ? 18.0 : 10.0);
        if ($preferredWeekdays) {
            $score += in_array($dow, $preferredWeekdays, true) ? -650.0 : 260.0;
        }
        if ($zone !== '') {
            // Ajuda a consolidar o mesmo cluster no mesmo dia da semana, mas a decisão fina é por data.
            $score -= min(5.0, (float)($weekdayZoneLoad[$dow][$zone] ?? 0)) * ($isAnchor ? 8.0 : 22.0);
        }

        $projectedMaxDateLoad = 0;
        foreach ($weeks as $wk) {
            $date = isset($weekBuckets[$wk]) ? self::date_for_preferred_weekday((array)$weekBuckets[$wk], $dow, true) : '';
            if ($date === '') {
                $score += 100000.0;
                continue;
            }
            $currentDateLoad = (float)($dateLoad[$date] ?? 0);
            $projected = $currentDateLoad + 1.0;
            $projectedMaxDateLoad = max($projectedMaxDateLoad, (int)$projected);
            $weekCount = max(1, count((array)($weekBuckets[$wk]['dates'] ?? [])));
            $weekAvg = ((float)($weekLoad[$wk] ?? 0) + 1.0) / $weekCount;
            $autoTargetStopsForDay = max(1.0, min((float)$maxStops, max(4.0, $weekAvg + 1.25)));
            $targetStopsForDay = $targetStops > 0 ? (float)$targetStops : $autoTargetStopsForDay;
            $score += (float)($weekLoad[$wk] ?? 0) * ($routeStrategy === 'balanced_load' ? 8.0 : 4.0);
            $weekRatio = $weekCapacityRatio((string)$wk, 1.0);
            if ($weekRatio > 0.90 && !$isAnchor) $score += pow(($weekRatio - 0.90) * 100.0, 2) * ($configuredCount === 1 ? 2.2 : 1.4);
            if ($weekRatio > 1.00) $score += pow(($weekRatio - 1.00) * 100.0, 2) * ($isAnchor ? 0.8 : 4.0);
            $score += $projected * $loadWeight;
            $score += abs($projected - $targetStopsForDay) * ($routeStrategy === 'balanced_load' ? 14.0 : 5.0);
            $targetOverflow = max(0.0, $projected - $targetStopsForDay);
            if ($targetOverflow > 0) {
                $targetFactor = $routeStrategy === 'balanced_load' ? 3.2 : ($routeStrategy === 'cluster_district' ? 1.15 : 1.8);
                $score += pow($targetOverflow, 2) * ($loadWeight * $targetFactor);
            }
            if ($projected > $maxStops) $score += pow(($projected - $maxStops), 2) * $maxStopWeight;

            if ($zone !== '') {
                $sameDateZoneHits = (int)($dateZoneLoad[$date][$zone] ?? 0);
                $dateHasCapacity = $projected <= $maxStops;
                $dateIsComfortable = $projected <= $targetStopsForDay + 1.0;
                if ($sameDateZoneHits > 0 && $dateHasCapacity) {
                    // Se já vamos a Gaia nesta semana/dia por uma loja semanal, uma P1/P2/P3 de Gaia
                    // deve cair aqui, mas só enquanto o dia ainda está saudável.
                    $capacityFactor = $dateIsComfortable ? 1.0 : 0.35;
                    $score -= min(6, $sameDateZoneHits) * ($isAnchor ? ($zoneBonusWeight * 0.25) : $zoneBonusWeight) * $capacityFactor;
                } elseif ($sameDateZoneHits > 0 && !$dateHasCapacity) {
                    $score += ($projected - $maxStops + 1.0) * ($maxStopWeight * 0.75);
                }

                if ($macroZone !== '') {
                    $sameMacroHits = 0;
                    foreach ((array)($dateItems[$date] ?? []) as $placedMacro) {
                        if (self::task_macro_zone_key((array)$placedMacro) === $macroZone) $sameMacroHits++;
                    }
                    if ($sameMacroHits > 0 && $dateHasCapacity) {
                        $score -= min(8, $sameMacroHits) * ($configuredCount === 1 ? 72.0 : 34.0) * ($dateIsComfortable ? 1.0 : 0.35);
                    }
                }

                if ($routeCorridor !== '') {
                    $sameCorridorHits = 0;
                    $differentCorridorHits = 0;
                    foreach ((array)($dateItems[$date] ?? []) as $placedCorridor) {
                        $placedKey = self::task_route_corridor_key((array)$placedCorridor, (array)($options['start_point'] ?? []), (array)($options['end_point'] ?? []));
                        if ($placedKey === '') continue;
                        if ($placedKey === $routeCorridor) $sameCorridorHits++; else $differentCorridorHits++;
                    }
                    if ($sameCorridorHits > 0 && $dateHasCapacity) {
                        $score -= min(10, $sameCorridorHits) * ($routeStrategy === 'route_corridor' ? 210.0 : 95.0) * ($dateIsComfortable ? 1.0 : 0.35);
                    } elseif ($differentCorridorHits > 0 && $routeStrategy === 'route_corridor') {
                        $score += min(10, $differentCorridorHits) * 120.0;
                    }
                }

                $nearKm = null;
                foreach ((array)($dateItems[$date] ?? []) as $placed) {
                    if (!is_numeric($placed['lat'] ?? null) || !is_numeric($placed['lng'] ?? null) || !is_numeric($row['lat'] ?? null) || !is_numeric($row['lng'] ?? null)) continue;
                    $km = self::haversine_km((float)$placed['lat'], (float)$placed['lng'], (float)$row['lat'], (float)$row['lng']);
                    $nearKm = $nearKm === null ? $km : min($nearKm, $km);
                }
                if ($nearKm !== null && $nearKm <= 35.0 && $dateHasCapacity) {
                    $capacityFactor = $dateIsComfortable ? 1.0 : 0.25;
                    $score -= max(0.0, 35.0 - $nearKm) * ($isAnchor ? ($nearKmWeight * 0.4) : $nearKmWeight) * $capacityFactor;
                }

                // Penaliza abrir o mesmo cluster noutro dia da mesma semana, mas não mais do que respeitar
                // o limite de visitas/dia e a harmonia de carga.
                if ($sameDateZoneHits <= 0) {
                    foreach ([-1, 1] as $delta) {
                        $adj = date('Y-m-d', strtotime($date . ($delta < 0 ? ' -1 day' : ' +1 day')));
                        $adjHits = (int)($dateZoneLoad[$adj][$zone] ?? 0);
                        if ($adjHits > 0) $score += min(5, $adjHits) * ($isAnchor ? ($adjacentZonePenalty * 0.25) : $adjacentZonePenalty);
                    }
                    foreach ((array)($weekBuckets[$wk]['dates'] ?? []) as $otherDate) {
                        $otherDate = (string)$otherDate;
                        if ($otherDate === '' || $otherDate === $date) continue;
                        $weekHits = (int)($dateZoneLoad[$otherDate][$zone] ?? 0);
                        if ($weekHits > 0) $score += min(5, $weekHits) * ($isAnchor ? ($adjacentZonePenalty * 0.12) : ($adjacentZonePenalty * 0.65));
                    }
                }
            }
        }
        $score += $projectedMaxDateLoad * ($routeStrategy === 'balanced_load' ? 110.0 : ($isAnchor ? 62.0 : 76.0));

        // Pequeno fator determinístico para desempatar sem mudar aleatoriamente entre refreshes.
        $score += (($dow + abs(crc32($seedKey . '|' . $dow)) % 17) * 0.001);

        if ($score < $bestScore) {
            $bestScore = $score;
            $best = ['weekday' => $dow, 'weeks' => $weeks];
        }
    }

    return $best;
}

private static function allocate_monthly_week_assignments(int $count, array $weekBuckets, array $weekLoad = [], string $seedKey = '', int $preferredWeekday = 0, bool $allowExtraVisits = false): array {
    $weekKeys = array_values(array_keys($weekBuckets));
    if (!$weekKeys) return [];

    $count = max(1, (int)$count);
    $preferredWeekday = max(1, min(5, (int)$preferredWeekday));

    $validKeys = [];
    $weekIndexByKey = [];
    $fullKeys = [];
    foreach ($weekKeys as $idx => $wk) {
        $wk = (string)$wk;
        $weekIndexByKey[$wk] = $idx + 1;
        $bucket = (array)($weekBuckets[$wk] ?? []);
        $date = self::date_for_preferred_weekday($bucket, $preferredWeekday, true);
        if ($date === '') continue;
        $validKeys[] = $wk;
        if (!empty($bucket['is_full_week']) || count((array)($bucket['dates'] ?? [])) >= 5) $fullKeys[] = $wk;
    }
    if (!$validKeys) return [];

    $n = count($validKeys);
    $firstKey = $validKeys[0] ?? '';
    $lastKey = $validKeys[$n - 1] ?? '';
    $firstPartial = $firstKey !== '' && !empty($weekBuckets[$firstKey]['is_partial_week']);
    $lastPartial = $lastKey !== '' && !empty($weekBuckets[$lastKey]['is_partial_week']);

    // Espelho comercial contínuo: 1/3/5 e 2/4. Se a primeira semana é parcial,
    // ela é continuação do mês anterior. Se a última é parcial, ela continua no mês seguinte.
    $patternA = $n >= 5 ? array_values(array_intersect_key($validKeys, array_flip([0, 2, 4]))) : [];
    $patternB = $n >= 5 ? array_values(array_intersect_key($validKeys, array_flip([1, 3]))) : [];

    if ($n >= 5 && $count === 1) {
        // P1 é visita flexível por geografia: prefere semanas completas, incluindo semana 5 se for completa.
        $preferred = $fullKeys ?: array_values(array_filter($validKeys, function($wk) use ($weekBuckets) {
            return empty($weekBuckets[$wk]['is_partial_week']);
        }));
        if (!$preferred) $preferred = $validKeys;
        $candidateSets = [];
        foreach ($preferred as $wk) $candidateSets[] = [$wk];
        $targetCount = 1;
    } elseif ($n >= 5 && $count === 2) {
        $candidateSets = [];
        if ($firstPartial && count($patternB) >= 2) {
            $candidateSets[] = array_slice($patternB, 0, 2); // 2 e 4
        } elseif ($lastPartial && count($patternA) >= 2) {
            $candidateSets[] = array_slice($patternA, 0, 2); // 1 e 3
        } else {
            if (count($patternB) >= 2) $candidateSets[] = array_slice($patternB, 0, 2);
            if (count($patternA) >= 2) $candidateSets[] = array_slice($patternA, 0, 2);
        }
        if (!$candidateSets) $candidateSets = self::build_even_week_candidate_sets($validKeys, 2, $weekIndexByKey);
        $targetCount = 2;
    } elseif ($n >= 5 && $count === 3) {
        // P3: base 2/4, mais uma visita na melhor semana livre. Se o fim é parcial, usa 1/3 como base.
        $candidateSets = [];
        $base = [];
        $extras = [];
        if ($lastPartial && count($patternA) >= 2) {
            $base = array_slice($patternA, 0, 2);
            $extras = $patternB;
        } elseif (count($patternB) >= 2) {
            $base = array_slice($patternB, 0, 2);
            $extras = $patternA;
        }
        foreach ($extras as $extraWeek) {
            if (in_array($extraWeek, $base, true)) continue;
            $set = array_values(array_merge($base, [$extraWeek]));
            usort($set, function($a, $b) use ($weekIndexByKey) { return ((int)($weekIndexByKey[(string)$a] ?? 0)) <=> ((int)($weekIndexByKey[(string)$b] ?? 0)); });
            if (count($set) === 3) $candidateSets[] = $set;
        }
        if (!$candidateSets) $candidateSets = self::build_even_week_candidate_sets($validKeys, 3, $weekIndexByKey);
        $targetCount = 3;
    } elseif ($n >= 5 && $allowExtraVisits) {
        if ($count >= 4) {
            $candidateSets = [$validKeys];
        } elseif ($count === 2) {
            $candidateSets = [];
            if (count($patternA) >= 3) $candidateSets[] = $patternA;
            if (count($patternB) >= 2) $candidateSets[] = $patternB;
            if (!$candidateSets) $candidateSets = self::build_even_week_candidate_sets($validKeys, min(3, $n), $weekIndexByKey);
        } else {
            $candidateSets = [];
            foreach (($fullKeys ?: $validKeys) as $wk) $candidateSets[] = [$wk];
        }
        if (!$candidateSets) $candidateSets = [array_slice($validKeys, 0, min($count, $n))];
        $targetCount = count($candidateSets[0]);
    } else {
        $targetCount = max(1, min($count, $n));
        $candidateSets = self::build_even_week_candidate_sets($validKeys, $targetCount, $weekIndexByKey);
        if (!$candidateSets) $candidateSets = [array_slice($validKeys, 0, $targetCount)];
    }

    $scoreKeys = static function(array $keys) use ($weekLoad, $weekIndexByKey, $seedKey, $validKeys, $targetCount, $weekBuckets): float {
        $score = 0.0;
        $positions = [];
        foreach ($keys as $wk) {
            $idx = (int)($weekIndexByKey[(string)$wk] ?? 0);
            $positions[] = $idx;
            $score += (float)($weekLoad[$wk] ?? 0) * 24.0;
            if (!empty($weekBuckets[$wk]['is_partial_week'])) $score += 22.0;
            if ($targetCount === 1 && !empty($weekBuckets[$wk]['is_full_week'])) $score -= 10.0;
        }
        sort($positions);

        if (count($positions) > 1) {
            $gaps = [];
            for ($i = 1; $i < count($positions); $i++) $gaps[] = $positions[$i] - $positions[$i - 1];
            $idealGap = max(1.0, count($validKeys) / max(1, $targetCount));
            foreach ($gaps as $gap) $score += abs($gap - $idealGap) * 9.0;
            if ($targetCount <= 3 && $gaps && min($gaps) <= 1) $score += 35.0;
        }

        $score += (abs(crc32($seedKey . '|' . implode(',', $keys))) % 101) * 0.001;
        return $score;
    };

    usort($candidateSets, function($a, $b) use ($scoreKeys) { return $scoreKeys($a) <=> $scoreKeys($b); });
    return array_values($candidateSets[0] ?? []);
}

private static function build_even_week_candidate_sets(array $validKeys, int $targetCount, array $weekIndexByKey): array {
    $validKeys = array_values($validKeys);
    $n = count($validKeys);
    $targetCount = max(1, min($targetCount, $n));
    if ($targetCount >= $n) return [$validKeys];

    $all = [];
    $walk = function(int $offset, array $picked) use (&$walk, &$all, $validKeys, $n, $targetCount) {
        if (count($picked) === $targetCount) {
            $all[] = $picked;
            return;
        }
        $remaining = $targetCount - count($picked);
        for ($i = $offset; $i <= $n - $remaining; $i++) {
            $next = $picked;
            $next[] = $validKeys[$i];
            $walk($i + 1, $next);
        }
    };
    $walk(0, []);

    $scoreSpread = static function(array $keys) use ($validKeys, $targetCount, $weekIndexByKey): float {
        $positions = [];
        foreach ($keys as $wk) $positions[] = (int)($weekIndexByKey[(string)$wk] ?? 0);
        sort($positions);
        if (count($positions) <= 1) return 0.0;
        $idealGap = max(1.0, count($validKeys) / max(1, $targetCount));
        $score = 0.0;
        $gaps = [];
        for ($i = 1; $i < count($positions); $i++) {
            $gap = $positions[$i] - $positions[$i - 1];
            $gaps[] = $gap;
            $score += abs($gap - $idealGap) * 10.0;
        }
        if ($targetCount <= 3 && $gaps && min($gaps) <= 1) $score += 40.0;
        return $score;
    };

    usort($all, function($a, $b) use ($scoreSpread) { return $scoreSpread($a) <=> $scoreSpread($b); });

    // Mantém os melhores padrões de espaçamento. A carga semanal desempata depois.
    return array_slice($all, 0, 12);
}

private static function date_for_preferred_weekday(array $weekBucket, int $preferredWeekday, bool $strict = false): string {
    $dates = array_values((array)($weekBucket['dates'] ?? []));
    if (!$dates) return '';
    $preferredWeekday = max(1, min(5, (int)$preferredWeekday));
    foreach ($dates as $date) {
        $ts = strtotime((string)$date);
        if ($ts && (int)date('N', $ts) === $preferredWeekday) return (string)$date;
    }
    if ($strict) return '';
    $bestDate = (string)$dates[0];
    $bestDiff = PHP_INT_MAX;
    foreach ($dates as $date) {
        $ts = strtotime((string)$date);
        if (!$ts) continue;
        $diff = abs((int)date('N', $ts) - $preferredWeekday);
        if ($diff < $bestDiff) {
            $bestDiff = $diff;
            $bestDate = (string)$date;
        }
    }
    return $bestDate;
}

private static function describe_fixed_cadence_label(int $weekday, string $patternLabel = ''): string {
    $names = [1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira', 4 => 'quinta-feira', 5 => 'sexta-feira'];
    $label = 'Dia fixo: ' . ($names[max(1, min(5, $weekday))] ?? 'dia útil');
    if ($patternLabel !== '') $label .= ' · ' . $patternLabel;
    return $label;
}

private static function plan_tasks_into_dates(array $tasks, array $dates, string $label_prefix = 'Dia', array $options = []): array {
    if (!$tasks || !$dates) return ['days' => [], 'unassigned' => [], 'reinforcement' => []];

    $rawOptions = is_array($options) ? $options : [];
    $norm = self::normalize_plan_options($rawOptions);
    $options = array_merge($rawOptions, $norm);
    $dates = array_values($dates);

    $seen = [];
    $deduped = [];
    foreach ($tasks as $t) {
        $uid = (string)((int)($t['id'] ?? 0)) . '|' . (string)($t['visit_frequency'] ?? '') . '|' . (string)((int)($t['copy_index'] ?? 1)) . '|' . (string)($t['target_week_key'] ?? '');
        if ($uid === '0|||') continue;
        if (isset($seen[$uid])) continue;
        $seen[$uid] = true;
        $deduped[] = $t;
    }
    $tasks = $deduped;

    foreach ($tasks as $idx => &$taskForCluster) {
        $taskForCluster['_ff_geo_index'] = $idx;
    }
    unset($taskForCluster);

    // If candidates were already annotated by RouteCandidateSelector (Phase 2),
    // skip the re-annotation to preserve the pre-computed geo metadata.
    // This is the key hook that makes the pipeline's pre-partitioning effective.
    if (empty($options['_geo_pre_annotated'])) {
        $geoClusterRadiusKm = self::default_geo_cluster_radius_km($tasks);
        $geoClusters = self::build_geo_clusters($tasks, $geoClusterRadiusKm, (array)($options['start_point'] ?? []), (array)($options['end_point'] ?? []));
        $geoClusterByTaskIndex = [];
        foreach ($geoClusters as $cluster) {
            foreach ((array)($cluster['locations'] ?? []) as $clusterLocation) {
                $idx = isset($clusterLocation['_ff_geo_index']) ? (int)$clusterLocation['_ff_geo_index'] : -1;
                if ($idx >= 0) $geoClusterByTaskIndex[$idx] = $cluster;
            }
        }
        foreach ($tasks as $idx => &$taskWithCluster) {
            if (!empty($geoClusterByTaskIndex[$idx])) {
                $cluster = (array)$geoClusterByTaskIndex[$idx];
                $taskWithCluster['geo_cluster_id'] = (int)($cluster['cluster_id'] ?? 0);
                $taskWithCluster['geo_cluster_density'] = (float)($cluster['density_score'] ?? 0);
                $taskWithCluster['geo_cluster_dispersion'] = (float)($cluster['dispersion_score'] ?? 0);
                $taskWithCluster['geo_cluster_access_km'] = (float)($cluster['distance_from_base'] ?? 0);
                $taskWithCluster['geo_cluster_radius_km'] = (float)($cluster['radius_km'] ?? 0);
                $taskWithCluster['route_corridor_key'] = self::task_route_corridor_key((array)$taskWithCluster, (array)($options['start_point'] ?? []), (array)($options['end_point'] ?? []));
                $taskWithCluster['route_corridor_position'] = self::task_route_corridor_position((array)$taskWithCluster, (array)($options['start_point'] ?? []), (array)($options['end_point'] ?? []));
            }
        }
        unset($taskWithCluster);
    }

    $visitsPerStore = [];
    foreach ($tasks as $t) {
        $sid = (int)($t['id'] ?? 0);
        if ($sid > 0) $visitsPerStore[$sid] = ($visitsPerStore[$sid] ?? 0) + 1;
    }

    $minOpenDaysRaw = isset($options['min_open_days']) ? (int)$options['min_open_days'] : 0;
    $minGapDays = max(0, min(14, (int)($options['min_days_between_same_store'] ?? 2)));
    $maxStops = (int)($options['max_stops_per_day'] ?? 12);
    $targetStops = self::resolve_target_stops_per_day($options, $maxStops);
    $targetWorkMin = (int)($options['work_minutes'] ?? 480);
    $lunchMin = (int)($options['lunch_minutes'] ?? 60);
    // Interpretação operacional: Horas úteis representa a janela total do dia.
    // O almoço consome capacidade real, por isso a alocação usa viagem + visita + almoço.
    $effectiveWorkCapacityMin = max(60, $targetWorkMin - max(0, $lunchMin));
    $globalAllowOvertime = !empty($options['allow_overtime']);
    $defaultExtraMin = max(0, min(150, (int)($options['overtime_extra_minutes'] ?? 0)));
    $dailyOvertimeDates = array_flip((array)($options['daily_overtime_dates'] ?? []));
    $dailyOvertimeMinutes = (array)($options['daily_overtime_minutes'] ?? []);
    $startPoint = is_array($options['start_point'] ?? null) ? $options['start_point'] : [];
    $endPoint = is_array($options['end_point'] ?? null) ? $options['end_point'] : [];
    $routeStrategy = self::normalize_route_strategy((string)($options['route_strategy'] ?? 'complete_coverage'));
    $forceCompleteCoverage = false; // regra operacional 2.2.80: max. visitas/dia e horas sao limites duros; o que nao couber fica por atribuir para validacao
    $preferMinKm = ($routeStrategy === 'minimize_km');
    $preferClusterDistrict = ($routeStrategy === 'cluster_district' || $routeStrategy === 'route_corridor');
    $preferRouteCorridor = ($routeStrategy === 'route_corridor');
    $preferBalancedLoad = ($routeStrategy === 'balanced_load' || $routeStrategy === 'complete_coverage' || $routeStrategy === 'operational_balanced');

    usort($tasks, function($a, $b) use ($routeStrategy){
        if ($routeStrategy === 'route_corridor') {
            $ca = (string)($a['route_corridor_key'] ?? '');
            $cb = (string)($b['route_corridor_key'] ?? '');
            if ($ca !== $cb) return strcmp($ca, $cb);
            $pa = (float)($a['route_corridor_position'] ?? 0);
            $pb = (float)($b['route_corridor_position'] ?? 0);
            if (abs($pa - $pb) > 0.001) return $pa <=> $pb;
        }
        $fa = max(1, (int)($a['frequency_count'] ?? 1));
        $fb = max(1, (int)($b['frequency_count'] ?? 1));
        if ($fa !== $fb) return $fb <=> $fa;
        $pa = (int)($a['priority'] ?? 0);
        $pb = (int)($b['priority'] ?? 0);
        if ($pa !== $pb) return $pb <=> $pa;
        $za = strtolower(trim((string)($a['district'] ?? '') . '|' . (string)($a['city'] ?? '') . '|' . (string)($a['name'] ?? '')));
        $zb = strtolower(trim((string)($b['district'] ?? '') . '|' . (string)($b['city'] ?? '') . '|' . (string)($b['name'] ?? '')));
        return $za <=> $zb;
    });

    $taskPool = array_values($tasks);
    $totalVisitMin = 0;
    foreach ($taskPool as $t) $totalVisitMin += (int)($t['visit_duration_min'] ?? 45);

    $estimatedTravelMin = (int) round(count($taskPool) * 18);
    $autoStopsTarget = count($dates) > 0 ? (int)ceil(count($taskPool) / max(1, count($dates))) : $maxStops;
    $softStopsTarget = $targetStops > 0 ? $targetStops : max(1, min($maxStops, max(4, $autoStopsTarget)));
    $estimatedMonthTotalMin = $totalVisitMin + $estimatedTravelMin;
    $monthlyTargetPerDay = count($dates) > 0 ? (int) ceil($estimatedMonthTotalMin / max(1, count($dates))) : $effectiveWorkCapacityMin;
    $monthlyTargetPerDay = max(90, min($effectiveWorkCapacityMin, max((int)round($effectiveWorkCapacityMin * 0.72), $monthlyTargetPerDay)));
    $daysByStops = (int) ceil(count($taskPool) / max(1, $maxStops));
    $daysByTime = (int) ceil(($totalVisitMin + $estimatedTravelMin) / max(1, ($monthlyTargetPerDay * 0.85)));
    $initialDays = max(1, count($dates));
    $minOpenDays = max(0, (int)$minOpenDaysRaw);
    if ($minOpenDays > 0) $initialDays = max($initialDays, min(count($dates), $minOpenDays));


    $days = [];
    for ($i = 0; $i < $initialDays; $i++) {
        $date = (string)($dates[$i] ?? '');
        if ($date === '') continue;
        $hasDayOverride = isset($dailyOvertimeDates[$date]) || isset($dailyOvertimeMinutes[$date]);
        $dayExtraMin = isset($dailyOvertimeMinutes[$date]) ? min(150, (int)$dailyOvertimeMinutes[$date]) : ($hasDayOverride ? ($defaultExtraMin > 0 ? $defaultExtraMin : 150) : 0);
        $allowOvertime = ($hasDayOverride || $globalAllowOvertime) && $dayExtraMin > 0;
        if (!$allowOvertime) $dayExtraMin = 0;
        $hardWorkLimit = min($targetWorkMin + $dayExtraMin, 600);
        $labelNumber = $i + 1;
        $dateTsForLabel = strtotime($date);
        if (strpos((string)$label_prefix, 'Semana ') === 0 && $dateTsForLabel) {
            $labelNumber = (int) date('N', $dateTsForLabel);
        }
        $dayLabel = strpos((string)$label_prefix, 'Semana ') === 0 ? ($label_prefix . '-' . $labelNumber) : ($label_prefix . ' · ' . $labelNumber);

        $days[] = [
            'label' => $dayLabel,
            'date' => $date,
            'week_key' => date('Y-m-d', strtotime('monday this week', strtotime($date))),
            'items' => [],
            'travel_min' => 0.0,
            'visit_min' => 0,
            'stops' => 0,
            'start_point' => $startPoint,
            'end_point' => $endPoint,
            'allow_overtime' => $allowOvertime,
            'extra_minutes' => $dayExtraMin,
            'hard_limit_minutes' => $hardWorkLimit,
        ];
    }

    $dateIndexMap = [];
    foreach ($dates as $idx => $d) $dateIndexMap[(string)$d] = (int)$idx;
    $lastVisitByStore = [];

    $dayHasStore = function(array $day, int $storeId): bool {
        foreach ((array)($day['items'] ?? []) as $it) {
            if ((int)($it['id'] ?? 0) === $storeId) return true;
        }
        return false;
    };

    $taskZoneKey = function(array $task): string {
        $city = strtolower(trim((string)($task['city'] ?? '')));
        $district = strtolower(trim((string)($task['district'] ?? '')));
        if ($city !== '') return $city;
        if ($district !== '') return $district;
        return strtolower(trim((string)($task['address'] ?? '')));
    };

    $dayZoneBreakdown = function(array $day) use ($taskZoneKey): array {
        $zones = [];
        foreach ((array)($day['items'] ?? []) as $it) {
            $zone = $taskZoneKey((array)$it);
            if ($zone === '') continue;
            $zones[$zone] = ($zones[$zone] ?? 0) + 1;
        }
        arsort($zones);
        return $zones;
    };

    $dayDominantZone = function(array $day) use ($dayZoneBreakdown): string {
        $zones = $dayZoneBreakdown($day);
        if (!$zones) return '';
        return (string)array_key_first($zones);
    };

    $dayCorridorBreakdown = function(array $day) use ($startPoint, $endPoint): array {
        $zones = [];
        foreach ((array)($day['items'] ?? []) as $it) {
            $key = self::task_route_corridor_key((array)$it, $startPoint, $endPoint);
            if ($key === '') continue;
            $zones[$key] = ($zones[$key] ?? 0) + 1;
        }
        arsort($zones);
        return $zones;
    };

    $dayDominantCorridor = function(array $day) use ($dayCorridorBreakdown): string {
        $zones = $dayCorridorBreakdown($day);
        if (!$zones) return '';
        return (string)array_key_first($zones);
    };

    $taskMacroZoneKey = function(array $task): string {
        return self::task_macro_zone_key($task);
    };

    $dayMacroBreakdown = function(array $day) use ($taskMacroZoneKey): array {
        $zones = [];
        foreach ((array)($day['items'] ?? []) as $it) {
            $zone = $taskMacroZoneKey((array)$it);
            if ($zone === '') continue;
            $zones[$zone] = ($zones[$zone] ?? 0) + 1;
        }
        arsort($zones);
        return $zones;
    };

    $dayDominantMacro = function(array $day) use ($dayMacroBreakdown): string {
        $zones = $dayMacroBreakdown($day);
        if (!$zones) return '';
        return (string)array_key_first($zones);
    };

    $dayClusterBreakdown = function(array $day): array {
        $clusters = [];
        foreach ((array)($day['items'] ?? []) as $it) {
            $cid = (int)($it['geo_cluster_id'] ?? 0);
            if ($cid <= 0) continue;
            $clusters[$cid] = ($clusters[$cid] ?? 0) + 1;
        }
        arsort($clusters);
        return $clusters;
    };

    $dayDominantCluster = function(array $day) use ($dayClusterBreakdown): int {
        $clusters = $dayClusterBreakdown($day);
        if (!$clusters) return 0;
        return (int)array_key_first($clusters);
    };

    $dayNearestKmToTask = function(array $day, array $task): float {
        if (!self::point_has_coordinates($task)) return 9999.0;
        $best = 9999.0;
        foreach ((array)($day['items'] ?? []) as $it) {
            $it = (array)$it;
            if (!self::point_has_coordinates($it)) continue;
            $best = min($best, self::safe_haversine_between_points($task, $it));
        }
        return $best;
    };

    $dayCenterPoint = function(array $day): ?array {
        $latSum = 0.0;
        $lngSum = 0.0;
        $count = 0;
        foreach ((array)($day['items'] ?? []) as $it) {
            if (!is_numeric($it['lat'] ?? null) || !is_numeric($it['lng'] ?? null)) continue;
            $latSum += (float)$it['lat'];
            $lngSum += (float)$it['lng'];
            $count++;
        }
        if ($count <= 0) return null;
        return ['lat' => $latSum / $count, 'lng' => $lngSum / $count];
    };

    $dayGeoFitScore = function(array $day, array $task) use ($taskZoneKey, $taskMacroZoneKey, $dayZoneBreakdown, $dayDominantZone, $dayMacroBreakdown, $dayDominantMacro, $dayCorridorBreakdown, $dayDominantCorridor, $dayClusterBreakdown, $dayDominantCluster, $dayCenterPoint, $dayNearestKmToTask, $startPoint, $endPoint, $routeStrategy): array {
        $items = array_values((array)($day['items'] ?? []));
        $currentStops = count($items);
        $taskZone = $taskZoneKey($task);
        $taskMacro = $taskMacroZoneKey($task);
        $taskCorridor = self::task_route_corridor_key($task, $startPoint, $endPoint);
        $taskCluster = (int)($task['geo_cluster_id'] ?? 0);
        $score = 0.0;
        $reasons = [];

        if ($currentStops <= 0) {
            $baseKm = self::base_distance_km_for_item($task, $startPoint, $endPoint);
            $score += min(260.0, $baseKm * 1.5);
            return ['score' => $score, 'reasons' => ['dia vazio'], 'nearest_km' => 0.0, 'radius_km' => 0.0];
        }

        $zones = $dayZoneBreakdown($day);
        $dominantZone = $dayDominantZone($day);
        if ($taskZone !== '') {
            if (isset($zones[$taskZone])) {
                $score -= 620.0 + min(360.0, (float)$zones[$taskZone] * 120.0);
                $reasons[] = 'mesma zona';
            } elseif ($dominantZone !== '' && $dominantZone !== $taskZone) {
                $score += 520.0 + min(360.0, $currentStops * 70.0);
                $reasons[] = 'zona diferente';
            }
        }

        $macros = $dayMacroBreakdown($day);
        $dominantMacro = $dayDominantMacro($day);
        if ($taskMacro !== '') {
            if (isset($macros[$taskMacro])) {
                $score -= 210.0;
            } elseif ($dominantMacro !== '' && $dominantMacro !== $taskMacro) {
                $score += 320.0;
                $reasons[] = 'macro-zona diferente';
            }
        }

        $corridors = $dayCorridorBreakdown($day);
        $dominantCorridor = $dayDominantCorridor($day);
        if ($taskCorridor !== '') {
            $strongAxis = ($routeStrategy === 'route_corridor');
            if (isset($corridors[$taskCorridor])) {
                $score -= $strongAxis ? 340.0 : 120.0;
            } elseif ($dominantCorridor !== '' && $dominantCorridor !== $taskCorridor) {
                // 2.2.103: eixo/corredor deixa de esmagar filtros e carga quando a estrategia nao e explicitamente "Corredor partida/chegada".
                // Continua a orientar a rota, mas nao decide sozinho.
                $axisPenalty = self::route_corridor_opposition_penalty((string)$dominantCorridor, (string)$taskCorridor) * ($strongAxis ? 1.0 : 0.28);
                $score += ($strongAxis ? 430.0 : 130.0) + $axisPenalty;
                $reasons[] = 'corredor/eixo diferente';
            }
        }

        $clusters = $dayClusterBreakdown($day);
        $dominantCluster = $dayDominantCluster($day);
        if ($taskCluster > 0) {
            if (isset($clusters[$taskCluster])) {
                $score -= 760.0 + min(420.0, (float)$clusters[$taskCluster] * 150.0);
                $reasons[] = 'mesmo cluster';
            } elseif ($dominantCluster > 0 && $dominantCluster !== $taskCluster && $currentStops >= 2) {
                $score += 880.0;
                $reasons[] = 'cluster diferente';
            }
        }

        $center = $dayCenterPoint($day);
        if ($center && self::point_has_coordinates($task)) {
            $centerKm = self::safe_haversine_between_points($center, $task);
            $score += min(980.0, $centerKm * 18.0);
            if ($centerKm > 28.0) $score += min(520.0, ($centerKm - 28.0) * 18.0);
        }

        $nearestKm = $dayNearestKmToTask($day, $task);
        if ($nearestKm < 9999.0) {
            $score += min(780.0, $nearestKm * 14.0);
            if ($nearestKm <= 6.0) $score -= 220.0;
            elseif ($nearestKm > 24.0) $score += min(420.0, ($nearestKm - 24.0) * 20.0);
        }

        $candidateItems = $items;
        $candidateItems[] = $task;
        $metrics = self::geo_route_metrics_for_items($candidateItems, (array)($day['start_point'] ?? $startPoint), (array)($day['end_point'] ?? $endPoint));
        $radius = (float)($metrics['radius_km'] ?? 0.0);
        $dispersion = (float)($metrics['dispersion_score'] ?? 0.0);
        if ($radius > 18.0) $score += min(700.0, ($radius - 18.0) * 20.0);
        if ($dispersion > 28.0) $score += min(500.0, ($dispersion - 28.0) * 12.0);

        return ['score' => $score, 'reasons' => $reasons, 'nearest_km' => $nearestKm, 'radius_km' => $radius];
    };

    $rebuildLastVisitMap = function() use (&$days, $dateIndexMap, &$lastVisitByStore): void {
        $lastVisitByStore = [];
        foreach ($days as $day) {
            $dIdx = $dateIndexMap[(string)($day['date'] ?? '')] ?? 0;
            foreach ((array)($day['items'] ?? []) as $it) {
                $sid = (int)($it['id'] ?? 0);
                if ($sid > 0) $lastVisitByStore[$sid] = (int)$dIdx;
            }
        }
    };

    $sequenceItemsForEstimation = function(array $items, array $startPoint): array {
        if (!$items) return [];
        $remaining = array_values($items);
        $seedIdx = 0;
        if (is_numeric($startPoint['lat'] ?? null) && is_numeric($startPoint['lng'] ?? null)) {
            $best = PHP_FLOAT_MAX;
            foreach ($remaining as $i => $t) {
                if (!is_numeric($t['lat'] ?? null) || !is_numeric($t['lng'] ?? null)) continue;
                $km = self::haversine_km((float)$startPoint['lat'], (float)$startPoint['lng'], (float)$t['lat'], (float)$t['lng']);
                if ($km < $best) { $best = $km; $seedIdx = $i; }
            }
        }
        $route = [];
        $current = $remaining[$seedIdx];
        $route[] = $current;
        array_splice($remaining, $seedIdx, 1);
        while ($remaining) {
            $bestIdx = 0;
            $bestKm = PHP_FLOAT_MAX;
            foreach ($remaining as $i => $t) {
                if (is_numeric($current['lat'] ?? null) && is_numeric($current['lng'] ?? null) && is_numeric($t['lat'] ?? null) && is_numeric($t['lng'] ?? null)) {
                    $km = self::haversine_km((float)$current['lat'], (float)$current['lng'], (float)$t['lat'], (float)$t['lng']);
                } else {
                    $km = 9999.0;
                }
                if ($km < $bestKm) { $bestKm = $km; $bestIdx = $i; }
            }
            $current = $remaining[$bestIdx];
            $route[] = $current;
            array_splice($remaining, $bestIdx, 1);
        }
        return $route;
    };

    $estimateRouteTravelMin = function(array $items, array $startPoint, array $endPoint) use ($sequenceItemsForEstimation): float {
        if (!$items) return 0.0;
        $route = $sequenceItemsForEstimation($items, $startPoint);
        if (!$route) return 0.0;
        $travelMin = 0.0;
        $first = $route[0] ?? null;
        if (is_array($first) && is_numeric($startPoint['lat'] ?? null) && is_numeric($startPoint['lng'] ?? null) && is_numeric($first['lat'] ?? null) && is_numeric($first['lng'] ?? null)) {
            $travelMin += self::haversine_km((float)$startPoint['lat'], (float)$startPoint['lng'], (float)$first['lat'], (float)$first['lng']) / 45 * 60;
        } else {
            $travelMin += 12.0;
        }
        for ($i = 1; $i < count($route); $i++) {
            $prev = $route[$i-1];
            $curr = $route[$i];
            if (is_numeric($prev['lat'] ?? null) && is_numeric($prev['lng'] ?? null) && is_numeric($curr['lat'] ?? null) && is_numeric($curr['lng'] ?? null)) {
                $travelMin += self::haversine_km((float)$prev['lat'], (float)$prev['lng'], (float)$curr['lat'], (float)$curr['lng']) / 45 * 60;
            } else {
                $travelMin += 15.0;
            }
        }
        $last = end($route);
        if (is_array($last) && is_numeric($endPoint['lat'] ?? null) && is_numeric($endPoint['lng'] ?? null) && is_numeric($last['lat'] ?? null) && is_numeric($last['lng'] ?? null)) {
            $travelMin += self::haversine_km((float)$last['lat'], (float)$last['lng'], (float)$endPoint['lat'], (float)$endPoint['lng']) / 45 * 60;
        }
        return $travelMin;
    };

    $estimateDayWorkWithTask = function(array $day, array $task) use ($estimateRouteTravelMin): float {
        $items = array_values((array)($day['items'] ?? []));
        $items[] = $task;
        $visitMin = 0;
        foreach ($items as $it) $visitMin += (int)($it['visit_duration_min'] ?? 45);
        $travelMin = $estimateRouteTravelMin($items, (array)($day['start_point'] ?? []), (array)($day['end_point'] ?? []));
        return $visitMin + $travelMin;
    };


    $estimateDayRouteKmWithTask = function(array $day, array $task): float {
        $candidate = $day;
        $items = array_values((array)($candidate['items'] ?? []));
        $items[] = $task;
        $candidate['items'] = $items;
        return self::estimate_plan_day_distance_km($candidate, [
            'start_point' => (array)($day['start_point'] ?? []),
            'end_point' => (array)($day['end_point'] ?? []),
            'lock_start_point' => true,
            'lock_end_point' => true,
        ]);
    };

    $dynamicTargetForDay = function(array $day, array $task) use ($softStopsTarget, $maxStops, $options, $estimateDayRouteKmWithTask): int {
        $routeKm = $estimateDayRouteKmWithTask($day, $task);
        return self::dynamic_target_stops_for_distance((int)$softStopsTarget, (int)$maxStops, (float)$routeKm, $options);
    };

    $distancePressureForTask = function(array $task) use ($startPoint, $endPoint, $options): float {
        return self::base_distance_km_for_item($task, $startPoint, $endPoint) * self::distance_sensitivity_multiplier($options);
    };

    $effectiveGapDays = function(array $task, int $baseGap) use ($visitsPerStore): int {
        $storeId = (int)($task['id'] ?? 0);
        if ($storeId <= 0 || (($visitsPerStore[$storeId] ?? 0) <= 1)) return 0;
        $freq = max(1, (int)($task['frequency_count'] ?? 1));
        if ($freq >= 4) return max(0, min(1, $baseGap));
        if ($freq === 3) return max(1, min(1, $baseGap));
        return $baseGap;
    };

    $taskFlexWeight = function(array $task): int {
        $freq = max(1, (int)($task['frequency_count'] ?? 1));
        if ($freq <= 1) return 4;
        if ($freq === 2) return 3;
        if ($freq === 3) return 2;
        return 1;
    };

    $storeAlreadyInWeek = function(array $day, int $storeId) use (&$days): bool {
        if ($storeId <= 0) return false;
        $candidateWeekKey = (string)($day['week_key'] ?? '');
        if ($candidateWeekKey === '') return false;
        foreach ($days as $otherDay) {
            if ((string)($otherDay['week_key'] ?? '') !== $candidateWeekKey) continue;
            foreach ((array)($otherDay['items'] ?? []) as $it) {
                if ((int)($it['id'] ?? 0) === $storeId) return true;
            }
        }
        return false;
    };

    $canPlace = function(array $day, array $task, int $gapDays) use ($dayHasStore, $storeAlreadyInWeek, $dateIndexMap, &$lastVisitByStore, $maxStops, $targetWorkMin, $lunchMin, $estimateDayWorkWithTask): bool {
        $storeId = (int)($task['id'] ?? 0);
        if ($storeId <= 0) return false;
        $targetDate = sanitize_text_field((string)($task['target_date'] ?? ''));
        if ($targetDate !== '' && (string)($day['date'] ?? '') !== $targetDate) return false;
        if ((int)($day['stops'] ?? 0) >= $maxStops) return false;
        if ($dayHasStore($day, $storeId)) return false;
        if ($storeAlreadyInWeek($day, $storeId)) return false;

        $taskWeekKey = (string)($task['target_week_key'] ?? '');
        if ($taskWeekKey !== '' && !empty($day['week_key']) && (string)$day['week_key'] === $taskWeekKey) {
            foreach ((array)($day['items'] ?? []) as $it) {
                if ((int)($it['id'] ?? 0) === $storeId) return false;
            }
        }

        if ($gapDays > 0) {
            $dIdx = $dateIndexMap[(string)($day['date'] ?? '')] ?? 0;
            if (isset($lastVisitByStore[$storeId]) && abs($dIdx - (int)$lastVisitByStore[$storeId]) < $gapDays) return false;
        }

        $hardLimit = (int)($day['hard_limit_minutes'] ?? $targetWorkMin);
        $nextWork = $estimateDayWorkWithTask($day, $task);
        $lunchForCandidate = !empty($day['items']) || $task ? max(0, (int)$lunchMin) : 0;
        if (($nextWork + $lunchForCandidate) > $hardLimit) return false;
        return true;
    };

    $canPlaceLoadFirst = function(array $day, array $task, int $gapDays) use ($dayHasStore, $storeAlreadyInWeek, $dateIndexMap, &$lastVisitByStore, $maxStops, $targetWorkMin, $lunchMin, $estimateDayWorkWithTask): bool {
        $storeId = (int)($task['id'] ?? 0);
        if ($storeId <= 0) return false;
        $targetDate = sanitize_text_field((string)($task['target_date'] ?? ''));
        if ($targetDate !== '' && (string)($day['date'] ?? '') !== $targetDate) return false;
        if ((int)($day['stops'] ?? 0) >= $maxStops) return false;
        if ($dayHasStore($day, $storeId)) return false;
        if ($storeAlreadyInWeek($day, $storeId)) return false;

        if ($gapDays > 0) {
            $dIdx = $dateIndexMap[(string)($day['date'] ?? '')] ?? 0;
            if (isset($lastVisitByStore[$storeId]) && abs($dIdx - (int)$lastVisitByStore[$storeId]) < $gapDays) return false;
        }

        $candidateWeekKey = (string)($day['week_key'] ?? '');
        if ($candidateWeekKey !== '') {
            foreach ((array)($day['items'] ?? []) as $it) {
                if ((int)($it['id'] ?? 0) === $storeId) return false;
            }
        }

        $hardLimit = (int)($day['hard_limit_minutes'] ?? $targetWorkMin);
        $nextWork = $estimateDayWorkWithTask($day, $task);
        $lunchForCandidate = !empty($day['items']) || $task ? max(0, (int)$lunchMin) : 0;
        if (($nextWork + $lunchForCandidate) > $hardLimit) return false;
        return true;
    };

    $placeTask = function(int $dayIdx, array $task) use (&$days, $dateIndexMap, &$lastVisitByStore, $sequenceItemsForEstimation, $estimateRouteTravelMin): void {
        $storeId = (int)($task['id'] ?? 0);
        $targetWeek = (string)($days[$dayIdx]['week_key'] ?? '');
        if ($storeId > 0 && $targetWeek !== '') {
            foreach ($days as $existingDayIdx => $existingDay) {
                if ((int)$existingDayIdx === (int)$dayIdx) continue;
                if ((string)($existingDay['week_key'] ?? '') !== $targetWeek) continue;
                foreach ((array)($existingDay['items'] ?? []) as $existingItem) {
                    if ((int)($existingItem['id'] ?? 0) === $storeId) return;
                }
            }
        }
        $days[$dayIdx]['items'][] = $task;
        $days[$dayIdx]['items'] = $sequenceItemsForEstimation((array)$days[$dayIdx]['items'], (array)($days[$dayIdx]['start_point'] ?? []));
        $days[$dayIdx]['stops'] = count((array)($days[$dayIdx]['items'] ?? []));
        $visitMin = 0;
        foreach ((array)($days[$dayIdx]['items'] ?? []) as $it) $visitMin += (int)($it['visit_duration_min'] ?? 45);
        $days[$dayIdx]['visit_min'] = $visitMin;
        $days[$dayIdx]['travel_min'] = $estimateRouteTravelMin((array)($days[$dayIdx]['items'] ?? []), (array)($days[$dayIdx]['start_point'] ?? []), (array)($days[$dayIdx]['end_point'] ?? []));
        $dIdx = $dateIndexMap[(string)($days[$dayIdx]['date'] ?? '')] ?? 0;
        if ($storeId > 0) $lastVisitByStore[$storeId] = (int)$dIdx;
    };

    $findBestDayIndex = function(array $task, bool $preferEmptyDays, bool $preferLightDays, int $gapDays) use (&$days, $targetWorkMin, $lunchMin, $monthlyTargetPerDay, $softStopsTarget, $maxStops, $canPlace, $estimateDayWorkWithTask, $estimateDayRouteKmWithTask, $dynamicTargetForDay, $distancePressureForTask, $taskZoneKey, $dayDominantZone, $dayZoneBreakdown, $dayCenterPoint, $dayGeoFitScore, $taskFlexWeight, $preferMinKm, $preferClusterDistrict, $preferBalancedLoad) {
        $bestDayIdx = null;
        $bestScore = PHP_FLOAT_MAX;
        $taskZone = $taskZoneKey($task);

        $sameZoneDays = [];
        foreach ($days as $idx => $existingDay) {
            if ((int)($existingDay['stops'] ?? 0) <= 0) continue;
            $zones = $dayZoneBreakdown($existingDay);
            if ($taskZone !== '' && isset($zones[$taskZone])) {
                $sameZoneDays[$idx] = (int)$zones[$taskZone];
            }
        }

        foreach ($days as $di => $day) {
            if (!$canPlace($day, $task, $gapDays)) continue;
            $nextWork = $estimateDayWorkWithTask($day, $task);
            $currentStops = (int)($day['stops'] ?? 0);
            $currentWork = (float)($day['visit_min'] ?? 0) + (float)($day['travel_min'] ?? 0);
            $nextWorkWithLunch = $nextWork + max(0, (int)$lunchMin);
            $distanceToTarget = abs($monthlyTargetPerDay - $nextWork) * 1.8;
            $freq = max(1, (int)($task['frequency_count'] ?? 1));
            $flexWeight = $taskFlexWeight($task);
            $freqBonus = -1.0 * ($freq * 25.0);
            $absoluteDayLimit = min($targetWorkMin + 90, 600) - max(0, (int)$lunchMin);
            $overtimePenalty = max(0.0, $nextWork - $targetWorkMin) * 4.5;
            $hardOverflowPenalty = max(0.0, $nextWork - $absoluteDayLimit) * 50.0;

            $projectedStops = $currentStops + 1;
            $dynamicStopsTarget = $dynamicTargetForDay($day, $task);
            $routeKm = $estimateDayRouteKmWithTask($day, $task);
            $candidateItemsForGeo = array_values((array)($day['items'] ?? []));
            $candidateItemsForGeo[] = $task;
            $geoMetrics = self::geo_route_metrics_for_items($candidateItemsForGeo, (array)($day['start_point'] ?? []), (array)($day['end_point'] ?? []));
            $accessKm = (float)($geoMetrics['access_km'] ?? 0);
            $localKm = (float)($geoMetrics['local_km'] ?? 0);
            $densityScore = (float)($geoMetrics['density_score'] ?? 0);
            $dispersionScore = (float)($geoMetrics['dispersion_score'] ?? 0);
            $softStopOverflow = max(0, $projectedStops - $dynamicStopsTarget);
            $softStopPenalty = abs($projectedStops - $dynamicStopsTarget) * ($preferBalancedLoad ? 28.0 : 10.0);
            $distancePressure = $distancePressureForTask($task);
            $longRoutePenalty = max(0.0, $routeKm - 75.0) * ($preferMinKm ? 8.0 : 4.5);
            $geoFit = $dayGeoFitScore($day, $task);
            $geoFitScore = (float)($geoFit['score'] ?? 0.0);
            $score = $distanceToTarget + $overtimePenalty + $hardOverflowPenalty + $freqBonus + $softStopPenalty + ($softStopOverflow * $softStopOverflow * ($preferBalancedLoad ? 360.0 : 140.0)) + ($distancePressure * max(0, $projectedStops - 1) * 2.2) + $longRoutePenalty + $geoFitScore;

            if ($preferLightDays) {
                $score += ($currentStops * 120.0) + ($currentWork * 3.1);
                if ($currentWork < $monthlyTargetPerDay) $score -= min(320.0, ($monthlyTargetPerDay - $currentWork) * 1.9);
                if ($currentWork > $monthlyTargetPerDay) $score += min(380.0, ($currentWork - $monthlyTargetPerDay) * 1.6);
            }
            if ($preferEmptyDays && $currentStops === 0) {
                $score -= $flexWeight <= 2 ? 420.0 : 180.0;
            }

            $dominantZone = $dayDominantZone($day);
            $zones = $dayZoneBreakdown($day);
            if ($taskZone !== '') {
                if (isset($zones[$taskZone])) {
                    $score -= ($preferClusterDistrict ? 720.0 : 420.0);
                    $score -= min($preferClusterDistrict ? 320.0 : 180.0, (float)$zones[$taskZone] * ($preferClusterDistrict ? 115.0 : 70.0));
                    if ($flexWeight >= 3) $score -= 220.0;
                } elseif ($dominantZone !== '' && $dominantZone !== $taskZone) {
                    $score += 240.0;
                }

                if ($currentStops === 0 && $sameZoneDays) {
                    $score += $flexWeight >= 3 ? 640.0 : 360.0;
                }
            }

            if ($flexWeight >= 3 && $currentStops > 0) {
                $score -= min(220.0, $currentStops * 28.0);
            }

            $center = $dayCenterPoint($day);
            if ($center && is_numeric($task['lat'] ?? null) && is_numeric($task['lng'] ?? null)) {
                $km = self::haversine_km((float)$center['lat'], (float)$center['lng'], (float)$task['lat'], (float)$task['lng']);
                $score += ($km * ($preferMinKm ? 24.0 : 12.0));
            }

            if ($di > 0) {
                $prevZone = $dayDominantZone($days[$di - 1]);
                if ($taskZone !== '' && $prevZone !== '' && $prevZone === $taskZone && $dominantZone !== $taskZone) {
                    $score += 140.0;
                }
            }
            if ($di < count($days) - 1) {
                $nextZone = $dayDominantZone($days[$di + 1]);
                if ($taskZone !== '' && $nextZone !== '' && $nextZone === $taskZone && $dominantZone !== $taskZone) {
                    $score += 100.0;
                }
            }

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestDayIdx = $di;
            }
        }
        return $bestDayIdx;
    };

    $remaining = $taskPool;

        // Fase 1-3: distribuição estrutural por carga primeiro, geografia depois.
    // Objetivo: espalhar o mês de forma homogénea e evitar que o fim do período vire depósito.
    usort($remaining, function($a, $b){
        $fa = max(1, (int)($a['frequency_count'] ?? 1));
        $fb = max(1, (int)($b['frequency_count'] ?? 1));
        if ($fa !== $fb) return $fb <=> $fa;
        $ca = (int)($a['geo_cluster_id'] ?? 0);
        $cb = (int)($b['geo_cluster_id'] ?? 0);
        if ($ca !== $cb) return $ca <=> $cb;
        $daDensity = (float)($a['geo_cluster_density'] ?? 0);
        $dbDensity = (float)($b['geo_cluster_density'] ?? 0);
        if (abs($daDensity - $dbDensity) > 0.001) return $dbDensity <=> $daDensity;
        $accessA = (float)($a['geo_cluster_access_km'] ?? 0);
        $accessB = (float)($b['geo_cluster_access_km'] ?? 0);
        if (abs($accessA - $accessB) > 0.1) return $accessB <=> $accessA;
        $da = (int)($a['visit_duration_min'] ?? 45);
        $db = (int)($b['visit_duration_min'] ?? 45);
        if ($da !== $db) return $db <=> $da;
        return ((int)($b['priority'] ?? 0)) <=> ((int)($a['priority'] ?? 0));
    });

    $newRemaining = [];
    foreach ($remaining as $task) {
        $gap = $effectiveGapDays($task, $minGapDays);
        $bestDayIdx = null;
        $bestScore = PHP_FLOAT_MAX;
        $taskZone = $taskZoneKey((array)$task);

        foreach ($days as $di => $day) {
            if (!$canPlaceLoadFirst($day, $task, $gap)) continue;

            // nunca permitir a mesma loja duas vezes na mesma semana real
            $candidateWeekKey = (string)($day['week_key'] ?? '');
            $duplicateInWeek = false;
            if ($candidateWeekKey !== '') {
                foreach ($days as $otherDay) {
                    if ((string)($otherDay['week_key'] ?? '') !== $candidateWeekKey) continue;
                    foreach ((array)($otherDay['items'] ?? []) as $it) {
                        if ((int)($it['id'] ?? 0) === (int)($task['id'] ?? 0)) {
                            $duplicateInWeek = true;
                            break 2;
                        }
                    }
                }
            }
            if ($duplicateInWeek) continue;

            $currentWork = (float)($day['visit_min'] ?? 0) + (float)($day['travel_min'] ?? 0) + ((int)($day['stops'] ?? 0) > 0 ? max(0, (int)$lunchMin) : 0);
            $nextWork = $estimateDayWorkWithTask($day, $task) + max(0, (int)$lunchMin);
            $loadPenalty = abs($monthlyTargetPerDay - max(0.0, $nextWork - max(0, (int)$lunchMin))) * 3.5;
            $overTargetPenalty = max(0.0, $nextWork - (int)($day['hard_limit_minutes'] ?? $targetWorkMin)) * 45.0 + max(0.0, ($nextWork - max(0, (int)$lunchMin)) - $monthlyTargetPerDay) * 5.5;
            $projectedStops = ((int)($day['stops'] ?? 0)) + 1;
            $dynamicStopsTarget = $dynamicTargetForDay($day, $task);
            $routeKm = $estimateDayRouteKmWithTask($day, $task);
            $candidateItemsForGeo = array_values((array)($day['items'] ?? []));
            $candidateItemsForGeo[] = $task;
            $geoMetrics = self::geo_route_metrics_for_items($candidateItemsForGeo, (array)($day['start_point'] ?? []), (array)($day['end_point'] ?? []));
            $accessKm = (float)($geoMetrics['access_km'] ?? 0);
            $localKm = (float)($geoMetrics['local_km'] ?? 0);
            $densityScore = (float)($geoMetrics['density_score'] ?? 0);
            $dispersionScore = (float)($geoMetrics['dispersion_score'] ?? 0);
            $softStopOverflow = max(0, $projectedStops - $dynamicStopsTarget);
            $stopsPenalty = ((int)($day['stops'] ?? 0)) * ($preferBalancedLoad ? 72.0 : 42.0);
            $targetStopsPenalty = abs($projectedStops - $dynamicStopsTarget) * ($preferBalancedLoad ? 40.0 : 16.0) + ($softStopOverflow * $softStopOverflow * ($preferBalancedLoad ? 420.0 : 155.0));
            $longRoutePenalty = max(0.0, $routeKm - 75.0) * ($preferMinKm ? 7.0 : 4.0);

            $geoDensityBonus = ($accessKm >= 70.0 && $densityScore >= 0.45 && $localKm <= 35.0) ? min(360.0, $densityScore * 180.0) : 0.0;
            $geoDispersionPenalty = ($accessKm >= 70.0 && $densityScore < 0.25) ? min(520.0, $dispersionScore * 11.0) : min(220.0, $dispersionScore * 3.0);
            $score = $loadPenalty + $overTargetPenalty + $stopsPenalty + $targetStopsPenalty + $longRoutePenalty + ($distancePressureForTask($task) * max(0, $projectedStops - 1) * 1.8) + $geoDispersionPenalty - $geoDensityBonus;

            // geografia só entra como segunda camada
            $dominantZone = $dayDominantZone($day);
            if ($taskZone !== '') {
                if ($dominantZone === '') {
                    $score -= 60.0;
                } elseif ($dominantZone === $taskZone) {
                    $score -= 110.0;
                } else {
                    $score += 45.0;
                }
            }

            $taskCorridor = self::task_route_corridor_key((array)$task, $startPoint, $endPoint);
            $dominantCorridor = $dayDominantCorridor($day);
            if ($taskCorridor !== '' && $preferRouteCorridor) {
                if ($dominantCorridor === '') $score -= 120.0;
                elseif ($dominantCorridor === $taskCorridor) $score -= 520.0;
                else $score += 520.0 + self::route_corridor_opposition_penalty((string)$dominantCorridor, (string)$taskCorridor);
            }

            if ($currentWork < $monthlyTargetPerDay) {
                $score -= min(260.0, ($monthlyTargetPerDay - $currentWork) * 1.4);
            }

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestDayIdx = $di;
            }
        }

        if ($bestDayIdx === null) {
            $newRemaining[] = $task;
            continue;
        }
        $placeTask($bestDayIdx, $task);
    }
    $remaining = $newRemaining;

    // Segunda passagem, agora já permitindo alguma flexibilidade geográfica para fechar buracos.
    $unassigned = [];
    foreach ($remaining as $task) {
        $gap = max(0, $effectiveGapDays($task, $minGapDays) - 1);
        $bestDayIdx = null;
        $bestScore = PHP_FLOAT_MAX;
        $taskZone = $taskZoneKey((array)$task);

        foreach ($days as $di => $day) {
            if (!$canPlaceLoadFirst($day, $task, $gap)) continue;

            $candidateWeekKey = (string)($day['week_key'] ?? '');
            $duplicateInWeek = false;
            if ($candidateWeekKey !== '') {
                foreach ($days as $otherDay) {
                    if ((string)($otherDay['week_key'] ?? '') !== $candidateWeekKey) continue;
                    foreach ((array)($otherDay['items'] ?? []) as $it) {
                        if ((int)($it['id'] ?? 0) === (int)($task['id'] ?? 0)) {
                            $duplicateInWeek = true;
                            break 2;
                        }
                    }
                }
            }
            if ($duplicateInWeek) continue;

            $currentWork = (float)($day['visit_min'] ?? 0) + (float)($day['travel_min'] ?? 0) + ((int)($day['stops'] ?? 0) > 0 ? max(0, (int)$lunchMin) : 0);
            $nextWork = $estimateDayWorkWithTask($day, $task) + max(0, (int)$lunchMin);
            $projectedStops = ((int)($day['stops'] ?? 0)) + 1;
            $dynamicStopsTarget = $dynamicTargetForDay($day, $task);
            $routeKm = $estimateDayRouteKmWithTask($day, $task);
            $softStopOverflow = max(0, $projectedStops - $dynamicStopsTarget);
            $score = abs($monthlyTargetPerDay - $nextWork) * 2.2 + max(0.0, $nextWork - min((int)($day['hard_limit_minutes'] ?? $targetWorkMin), 600)) * 55.0 + ((int)($day['stops'] ?? 0) * 35.0) + abs($projectedStops - $dynamicStopsTarget) * 22.0 + ($softStopOverflow * $softStopOverflow * 240.0) + max(0.0, $routeKm - 75.0) * 3.0;

            $dominantZone = $dayDominantZone($day);
            if ($taskZone !== '' && $dominantZone !== '' && $dominantZone === $taskZone) $score -= 90.0;
            $taskCorridor = self::task_route_corridor_key((array)$task, $startPoint, $endPoint);
            $dominantCorridor = $dayDominantCorridor($day);
            if ($taskCorridor !== '' && $preferRouteCorridor) {
                if ($dominantCorridor !== '' && $dominantCorridor === $taskCorridor) $score -= 260.0;
                elseif ($dominantCorridor !== '') $score += 260.0;
            }
            if ($currentWork < $monthlyTargetPerDay) $score -= min(180.0, ($monthlyTargetPerDay - $currentWork) * 0.9);

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestDayIdx = $di;
            }
        }

        if ($bestDayIdx === null) {
            $unassigned[] = $task;
            continue;
        }
        $placeTask($bestDayIdx, $task);
    }

    // Fase 4: pequeno rebalanceamento. Se houver dias vazios e dias carregados, mover 1 visita adequada.
    $hasEmptyDays = true;
    while ($hasEmptyDays) {
        $emptyIdx = null;
        $loadedIdx = null;
        foreach ($days as $di => $day) {
            if ((int)($day['stops'] ?? 0) === 0) { $emptyIdx = $di; break; }
        }
        if ($emptyIdx === null) break;
        foreach ($days as $di => $day) {
            if ($di === $emptyIdx) continue;
            if ((int)($day['stops'] ?? 0) >= 2) { $loadedIdx = $di; break; }
        }
        if ($loadedIdx === null) break;

        $moved = false;
        $items = array_values((array)($days[$loadedIdx]['items'] ?? []));
        foreach (array_reverse($items, true) as $itemIdx => $task) {
            $gap = max(0, $effectiveGapDays($task, $minGapDays) - 1);
            if (!$canPlace($days[$emptyIdx], $task, $gap)) continue;
            $sourceZones = $dayZoneBreakdown($days[$loadedIdx]);
            $sourceDominantZone = $dayDominantZone($days[$loadedIdx]);
            $candidateZone = $taskZoneKey((array)$task);
            // 2.2.101: nao partir um bloco geografico bom so para preencher um dia vazio.
            if ($candidateZone !== '' && $sourceDominantZone === $candidateZone && (int)($sourceZones[$candidateZone] ?? 0) >= 2) continue;
            array_splice($days[$loadedIdx]['items'], $itemIdx, 1);
            $days[$loadedIdx]['items'] = $sequenceItemsForEstimation((array)$days[$loadedIdx]['items'], (array)($days[$loadedIdx]['start_point'] ?? []));
            $days[$loadedIdx]['stops'] = count((array)$days[$loadedIdx]['items']);
            $visitMinLoaded = 0;
            foreach ((array)$days[$loadedIdx]['items'] as $it) $visitMinLoaded += (int)($it['visit_duration_min'] ?? 45);
            $days[$loadedIdx]['visit_min'] = $visitMinLoaded;
            $days[$loadedIdx]['travel_min'] = $estimateRouteTravelMin((array)$days[$loadedIdx]['items'], (array)($days[$loadedIdx]['start_point'] ?? []), (array)($days[$loadedIdx]['end_point'] ?? []));
            $placeTask($emptyIdx, $task);
            $moved = true;
            break;
        }
        if (!$moved) break;
    }


    // Fase 4B: consolidação geográfica. Se a mesma zona caiu em vários dias, tentar agregá-la num único dia com margem.
    $geoPass = 0;
    while ($geoPass < 12) {
        $geoPass++;
        $zoneMap = [];
        foreach ($days as $di => $day) {
            foreach ((array)($day['items'] ?? []) as $it) {
                $zone = $taskZoneKey((array)$it);
                if ($zone === '') continue;
                $zoneMap[$zone][$di] = ($zoneMap[$zone][$di] ?? 0) + 1;
            }
        }

        $movedAny = false;
        foreach ($zoneMap as $zone => $dayCounts) {
            if (count($dayCounts) <= 1) continue;
            arsort($dayCounts);
            $targetDayIdx = (int)array_key_first($dayCounts);
            foreach (array_keys($dayCounts) as $sourceDayIdx) {
                $sourceDayIdx = (int)$sourceDayIdx;
                if ($sourceDayIdx === $targetDayIdx) continue;
                $items = array_values((array)($days[$sourceDayIdx]['items'] ?? []));
                foreach (array_reverse($items, true) as $itemIdx => $task) {
                    if ($taskZoneKey((array)$task) !== $zone) continue;
                    $gap = $effectiveGapDays((array)$task, $minGapDays);
                    if (!$canPlace($days[$targetDayIdx], (array)$task, $gap)) continue;

                    array_splice($days[$sourceDayIdx]['items'], $itemIdx, 1);
                    $days[$sourceDayIdx]['items'] = $sequenceItemsForEstimation((array)$days[$sourceDayIdx]['items'], (array)($days[$sourceDayIdx]['start_point'] ?? []));
                    $days[$sourceDayIdx]['stops'] = count((array)$days[$sourceDayIdx]['items']);
                    $visitMinSource = 0;
                    foreach ((array)$days[$sourceDayIdx]['items'] as $it2) $visitMinSource += (int)($it2['visit_duration_min'] ?? 45);
                    $days[$sourceDayIdx]['visit_min'] = $visitMinSource;
                    $days[$sourceDayIdx]['travel_min'] = $estimateRouteTravelMin((array)$days[$sourceDayIdx]['items'], (array)($days[$sourceDayIdx]['start_point'] ?? []), (array)($days[$sourceDayIdx]['end_point'] ?? []));

                    $placeTask($targetDayIdx, (array)$task);
                    $rebuildLastVisitMap();
                    $movedAny = true;
                    break 2;
                }
            }
        }
        if (!$movedAny) break;
    }

    // Fase 5: se pontos de partida/chegada empurrarem um dia acima do limite, aliviar esse dia antes de fechar o plano.
    $safetyPass = 0;
    while ($safetyPass < 20) {
        $safetyPass++;
        $overIdx = null;
        foreach ($days as $di => $day) {
            $workMin = (float)($day['visit_min'] ?? 0) + (float)($day['travel_min'] ?? 0) + ((int)($day['stops'] ?? 0) > 0 ? max(0, (int)$lunchMin) : 0);
            $hardLimit = min((int)($day['hard_limit_minutes'] ?? $targetWorkMin), 600);
            if ($workMin > $hardLimit + 0.5 && (int)($day['stops'] ?? 0) > 0) {
                $overIdx = $di;
                break;
            }
        }
        if ($overIdx === null) break;

        $moved = false;
        $items = array_values((array)($days[$overIdx]['items'] ?? []));
        foreach (array_reverse($items, true) as $itemIdx => $task) {
            $gap = max(0, $effectiveGapDays($task, $minGapDays) - 1);
            $candidateIdx = $findBestDayIndex($task, true, true, $gap);
            if ($candidateIdx === null || $candidateIdx === $overIdx) continue;

            array_splice($days[$overIdx]['items'], $itemIdx, 1);
            $days[$overIdx]['items'] = $sequenceItemsForEstimation((array)$days[$overIdx]['items'], (array)($days[$overIdx]['start_point'] ?? []));
            $days[$overIdx]['stops'] = count((array)$days[$overIdx]['items']);
            $visitMinOver = 0;
            foreach ((array)$days[$overIdx]['items'] as $it) $visitMinOver += (int)($it['visit_duration_min'] ?? 45);
            $days[$overIdx]['visit_min'] = $visitMinOver;
            $days[$overIdx]['travel_min'] = $estimateRouteTravelMin((array)$days[$overIdx]['items'], (array)($days[$overIdx]['start_point'] ?? []), (array)($days[$overIdx]['end_point'] ?? []));

            $placeTask($candidateIdx, $task);
            $moved = true;
            break;
        }
        if (!$moved) break;
    }

    // Fase 5A: distribuição estrutural por carga global do mês.
    // Antes do overflow, redistribui agressivamente das datas mais carregadas para as mais leves,
    // respeitando unicidade por dia/semana e mantendo proximidade razoável.
    $structuralPass = 0;
    while ($structuralPass < 140) {
        $structuralPass++;

        $dayLoads = [];
        foreach ($days as $di => $day) {
            $dayLoads[$di] = (float)($day['visit_min'] ?? 0) + (float)($day['travel_min'] ?? 0) + ((int)($day['stops'] ?? 0) > 0 ? max(0, (int)$lunchMin) : 0);
        }
        if (!$dayLoads) break;

        $avgLoad = array_sum($dayLoads) / max(1, count($dayLoads));
        $heavyIdx = null;
        $lightIdx = null;
        $heavyLoad = -1.0;
        $lightLoad = PHP_FLOAT_MAX;

        foreach ($days as $di => $day) {
            $load = (float)($dayLoads[$di] ?? 0.0);
            if ($load > $heavyLoad && (int)($day['stops'] ?? 0) > 1) {
                $heavyLoad = $load;
                $heavyIdx = $di;
            }
            if ($load < $lightLoad) {
                $lightLoad = $load;
                $lightIdx = $di;
            }
        }

        if ($heavyIdx === null || $lightIdx === null || $heavyIdx === $lightIdx) break;
        if (($heavyLoad - $lightLoad) < 75) break;

        $moved = false;
        $items = array_values((array)($days[$heavyIdx]['items'] ?? []));

        usort($items, function($a, $b) use ($dayLoads, $heavyIdx) {
            $fa = max(1, (int)($a['frequency_count'] ?? 1));
            $fb = max(1, (int)($b['frequency_count'] ?? 1));
            if ($fa !== $fb) return $fa <=> $fb; // mover primeiro lojas mais flexíveis
            $pa = (int)($a['priority'] ?? 0);
            $pb = (int)($b['priority'] ?? 0);
            if ($pa !== $pb) return $pa <=> $pb;
            return ((int)($b['visit_duration_min'] ?? 45)) <=> ((int)($a['visit_duration_min'] ?? 45));
        });

        foreach ($items as $candidate) {
            $candidateId = (int)($candidate['id'] ?? 0);
            if ($candidateId <= 0) continue;

            // procurar melhor dia de destino entre os mais leves primeiro
            $destCandidates = array_keys($days);
            usort($destCandidates, function($a, $b) use ($dayLoads) {
                return ($dayLoads[$a] ?? 0) <=> ($dayLoads[$b] ?? 0);
            });

            foreach ($destCandidates as $destIdx) {
                if ($destIdx === $heavyIdx) continue;

                $gap = max(0, $effectiveGapDays((array)$candidate, $minGapDays) - 1);
                if (!$canPlace($days[$destIdx], (array)$candidate, $gap)) continue;

                $sourceWeek = (string)($days[$heavyIdx]['week_key'] ?? '');
                $targetWeek = (string)($days[$destIdx]['week_key'] ?? '');
                if ($targetWeek !== '') {
                    $duplicateInTargetWeek = false;
                    foreach ($days as $otherDay) {
                        if ((string)($otherDay['week_key'] ?? '') !== $targetWeek) continue;
                        foreach ((array)($otherDay['items'] ?? []) as $it) {
                            if ((int)($it['id'] ?? 0) === $candidateId) {
                                $duplicateInTargetWeek = true;
                                break 2;
                            }
                        }
                    }
                    if ($duplicateInTargetWeek) continue;
                }

                $heavyBefore = (float)($dayLoads[$heavyIdx] ?? 0);
                $lightBefore = (float)($dayLoads[$destIdx] ?? 0);

                // simular impacto aproximado
                $sourceRemaining = array_values(array_filter((array)($days[$heavyIdx]['items'] ?? []), function($it) use ($candidateId) {
                    return (int)($it['id'] ?? 0) !== $candidateId;
                }));
                $sourceTravel = $estimateRouteTravelMin($sourceRemaining, (array)($days[$heavyIdx]['start_point'] ?? []), (array)($days[$heavyIdx]['end_point'] ?? []));
                $sourceVisit = 0;
                foreach ($sourceRemaining as $it) $sourceVisit += (int)($it['visit_duration_min'] ?? 45);
                $heavyAfter = $sourceVisit + $sourceTravel + (count($sourceRemaining) > 0 ? max(0, (int)$lunchMin) : 0);

                $destItems = array_values((array)($days[$destIdx]['items'] ?? []));
                $destItems[] = $candidate;
                $destTravel = $estimateRouteTravelMin($destItems, (array)($days[$destIdx]['start_point'] ?? []), (array)($days[$destIdx]['end_point'] ?? []));
                $destVisit = 0;
                foreach ($destItems as $it) $destVisit += (int)($it['visit_duration_min'] ?? 45);
                $lightAfter = $destVisit + $destTravel + (count($destItems) > 0 ? max(0, (int)$lunchMin) : 0);

                $beforeSpread = abs($heavyBefore - $avgLoad) + abs($lightBefore - $avgLoad);
                $afterSpread = abs($heavyAfter - $avgLoad) + abs($lightAfter - $avgLoad);

                // impedir criar um novo dia absurdo perto do teto se houver alternativas
                if ($lightAfter > min((int)($days[$destIdx]['hard_limit_minutes'] ?? $targetWorkMin), 600)) continue;

                $heavyZone = $dayDominantZone($days[$heavyIdx]);
                $lightZone = $dayDominantZone($days[$destIdx]);
                $candZone = $taskZoneKey((array)$candidate);
                $geoPenalty = 0.0;
                if ($candZone !== '') {
                    if ($lightZone !== '' && $lightZone !== $candZone) $geoPenalty += 260.0;
                    if ($heavyZone === $candZone) $geoPenalty += 180.0;
                }
                $destGeoFit = $dayGeoFitScore($days[$destIdx], (array)$candidate);
                $sourceGeoFit = $dayGeoFitScore($days[$heavyIdx], (array)$candidate);
                $destGeoScore = (float)($destGeoFit['score'] ?? 0.0);
                $sourceGeoScore = (float)($sourceGeoFit['score'] ?? 0.0);
                $geoPenalty += max(0.0, $destGeoScore);
                // So quebrar uma geografia boa quando a melhoria de carga e realmente relevante.
                if ($sourceGeoScore < -350.0 && $destGeoScore > 250.0 && (($heavyBefore - $lightBefore) < 140.0)) continue;

                if (($afterSpread + $geoPenalty) >= ($beforeSpread - 30.0)) continue;

                // aplicar movimento
                $removed = false;
                foreach ((array)($days[$heavyIdx]['items'] ?? []) as $ix => $it) {
                    if ((int)($it['id'] ?? 0) === $candidateId && !$removed) {
                        array_splice($days[$heavyIdx]['items'], $ix, 1);
                        $removed = true;
                        break;
                    }
                }
                if (!$removed) continue;

                $days[$heavyIdx]['items'] = $sequenceItemsForEstimation((array)($days[$heavyIdx]['items'] ?? []), (array)($days[$heavyIdx]['start_point'] ?? []));
                $days[$heavyIdx]['stops'] = count((array)$days[$heavyIdx]['items']);
                $srcVisit = 0;
                foreach ((array)$days[$heavyIdx]['items'] as $it) $srcVisit += (int)($it['visit_duration_min'] ?? 45);
                $days[$heavyIdx]['visit_min'] = $srcVisit;
                $days[$heavyIdx]['travel_min'] = $estimateRouteTravelMin((array)$days[$heavyIdx]['items'], (array)($days[$heavyIdx]['start_point'] ?? []), (array)($days[$heavyIdx]['end_point'] ?? []));

                $placeTask($destIdx, (array)$candidate);
                $rebuildLastVisitMap();
                $moved = true;
                break 2;
            }
        }

        if (!$moved) break;
    }

    // Fase 5B: balanceamento mensal global. Espalhar melhor a carga antes do overflow final.
    $balancePass = 0;
    while ($balancePass < 80) {
        $balancePass++;
        $heavyIdx = null;
        $lightIdx = null;
        $heaviest = -1.0;
        $lightest = PHP_FLOAT_MAX;

        foreach ($days as $di => $day) {
            $dayWork = (float)($day['visit_min'] ?? 0) + (float)($day['travel_min'] ?? 0) + ((int)($day['stops'] ?? 0) > 0 ? max(0, (int)$lunchMin) : 0);
            if ($dayWork > $heaviest && (int)($day['stops'] ?? 0) > 1) {
                $heaviest = $dayWork;
                $heavyIdx = $di;
            }
            if ($dayWork < $lightest) {
                $lightest = $dayWork;
                $lightIdx = $di;
            }
        }

        if ($heavyIdx === null || $lightIdx === null || $heavyIdx === $lightIdx) break;
        if (($heaviest - $lightest) < 45) break;

        $moved = false;
        $items = array_values((array)($days[$heavyIdx]['items'] ?? []));
        foreach (array_reverse($items, true) as $itemIdx => $task) {
            $gap = max(0, $effectiveGapDays((array)$task, $minGapDays) - 1);
            if (!$canPlace($days[$lightIdx], (array)$task, max(0,$gap-1))) continue;

            $sourceWeek = (string)($days[$heavyIdx]['week_key'] ?? '');
            $targetWeek = (string)($days[$lightIdx]['week_key'] ?? '');
            if ($sourceWeek !== '' && $targetWeek !== '' && $sourceWeek !== $targetWeek) {
                $duplicateInTargetWeek = false;
                foreach ($days as $otherDay) {
                    if ((string)($otherDay['week_key'] ?? '') !== $targetWeek) continue;
                    foreach ((array)($otherDay['items'] ?? []) as $it) {
                        if ((int)($it['id'] ?? 0) === (int)($task['id'] ?? 0)) {
                            $duplicateInTargetWeek = true;
                            break 2;
                        }
                    }
                }
                if ($duplicateInTargetWeek) continue;
            }

            $destGeoFit = $dayGeoFitScore($days[$lightIdx], (array)$task);
            $sourceGeoFit = $dayGeoFitScore($days[$heavyIdx], (array)$task);
            if ((float)($sourceGeoFit['score'] ?? 0.0) < -350.0 && (float)($destGeoFit['score'] ?? 0.0) > 300.0 && (($heaviest - $lightest) < 120.0)) continue;
            if ((float)($destGeoFit['score'] ?? 0.0) > 720.0 && (($heaviest - $lightest) < 180.0)) continue;

            array_splice($days[$heavyIdx]['items'], $itemIdx, 1);
            $days[$heavyIdx]['items'] = $sequenceItemsForEstimation((array)($days[$heavyIdx]['items'] ?? []), (array)($days[$heavyIdx]['start_point'] ?? []));
            $days[$heavyIdx]['stops'] = count((array)$days[$heavyIdx]['items']);
            $visitMinSource = 0;
            foreach ((array)$days[$heavyIdx]['items'] as $it) $visitMinSource += (int)($it['visit_duration_min'] ?? 45);
            $days[$heavyIdx]['visit_min'] = $visitMinSource;
            $days[$heavyIdx]['travel_min'] = $estimateRouteTravelMin((array)$days[$heavyIdx]['items'], (array)($days[$heavyIdx]['start_point'] ?? []), (array)($days[$heavyIdx]['end_point'] ?? []));

            $placeTask($lightIdx, (array)$task);
            $rebuildLastVisitMap();
            $moved = true;
            break;
        }

        if (!$moved) break;
    }

    // Fase 6: constraints duras. Visitas que não couberem ficam por alocar para decisão manual posterior.
    if ($unassigned) {
        foreach ($unassigned as &$task) {
            if (empty($task['overflow_conflict'])) $task['overflow_conflict'] = true;
        }
        unset($task);
    }

    $trimDayToHardLimits = function(array &$day) use (&$unassigned, $maxStops, $targetWorkMin, $lunchMin, $estimateRouteTravelMin): void {
        $changed = false;
        while (true) {
            $items = array_values((array)($day['items'] ?? []));
            $stops = count($items);
            $visitMin = 0;
            foreach ($items as $it) $visitMin += (int)($it['visit_duration_min'] ?? 45);
            $travelMin = $estimateRouteTravelMin($items, (array)($day['start_point'] ?? []), (array)($day['end_point'] ?? []));
            $hardLimit = (int)($day['hard_limit_minutes'] ?? $targetWorkMin);
            $workMin = $visitMin + $travelMin + ($stops > 0 ? max(0, (int)$lunchMin) : 0);
            if ($stops <= $maxStops && $workMin <= $hardLimit + 0.5) {
                $day['items'] = $items;
                $day['stops'] = $stops;
                $day['visit_min'] = $visitMin;
                $day['travel_min'] = $travelMin;
                break;
            }
            if (!$items) break;
            $removed = array_pop($items);
            if (is_array($removed)) {
                $removed['overflow_conflict'] = true;
                $unassigned[] = $removed;
            }
            $day['items'] = $items;
            $changed = true;
        }
        if ($changed) {
            $day['items'] = array_values((array)($day['items'] ?? []));
        }
    };

    $sequenceDay = function(array $items, array $startPoint) : array {
        if (!$items) return [];
        $remaining = array_values($items);
        $seedIdx = 0;
        if (is_numeric($startPoint['lat'] ?? null) && is_numeric($startPoint['lng'] ?? null)) {
            $best = PHP_FLOAT_MAX;
            foreach ($remaining as $i => $t) {
                if (!is_numeric($t['lat'] ?? null) || !is_numeric($t['lng'] ?? null)) continue;
                $km = self::haversine_km((float)$startPoint['lat'], (float)$startPoint['lng'], (float)$t['lat'], (float)$t['lng']);
                if ($km < $best) { $best = $km; $seedIdx = $i; }
            }
        }
        $route = [];
        $current = $remaining[$seedIdx];
        $route[] = $current;
        array_splice($remaining, $seedIdx, 1);
        while ($remaining) {
            $bestIdx = 0;
            $bestKm = PHP_FLOAT_MAX;
            foreach ($remaining as $i => $t) {
                if (is_numeric($current['lat'] ?? null) && is_numeric($current['lng'] ?? null) && is_numeric($t['lat'] ?? null) && is_numeric($t['lng'] ?? null)) {
                    $km = self::haversine_km((float)$current['lat'], (float)$current['lng'], (float)$t['lat'], (float)$t['lng']);
                } else {
                    $km = 9999.0;
                }
                if ($km < $bestKm) { $bestKm = $km; $bestIdx = $i; }
            }
            $current = $remaining[$bestIdx];
            $route[] = $current;
            array_splice($remaining, $bestIdx, 1);
        }
        return $route;
    };

    $weekSeenStores = [];
    foreach ($days as &$day) {
        $weekKey = (string)($day['week_key'] ?? '');
        if ($weekKey === '' && !empty($day['date'])) {
            $weekKey = date('Y-m-d', strtotime('monday this week', strtotime((string)$day['date'])));
            $day['week_key'] = $weekKey;
        }
        $cleanItems = [];
        $seenDayStores = [];
        foreach ((array)($day['items'] ?? []) as $item) {
            $storeId = (int)($item['id'] ?? 0);
            if ($storeId <= 0) continue;
            if (isset($seenDayStores[$storeId])) {
                if (empty($item['overflow_conflict'])) $item['overflow_conflict'] = true;
                $unassigned[] = $item;
                continue;
            }
            if ($weekKey !== '' && isset($weekSeenStores[$weekKey][$storeId])) {
                if (empty($item['overflow_conflict'])) $item['overflow_conflict'] = true;
                $unassigned[] = $item;
                continue;
            }
            $seenDayStores[$storeId] = true;
            if ($weekKey !== '') {
                if (!isset($weekSeenStores[$weekKey])) $weekSeenStores[$weekKey] = [];
                $weekSeenStores[$weekKey][$storeId] = true;
            }
            $cleanItems[] = $item;
        }
        $day['items'] = $sequenceDay((array)$cleanItems, (array)($day['start_point'] ?? []));
        $day['stops'] = count((array)$day['items']);
        $visitMinSafe = 0;
        foreach ((array)$day['items'] as $it) $visitMinSafe += (int)($it['visit_duration_min'] ?? 45);
        $day['visit_min'] = $visitMinSafe;
        $day['travel_min'] = $estimateRouteTravelMin((array)$day['items'], (array)($day['start_point'] ?? []), (array)($day['end_point'] ?? []));
        $trimDayToHardLimits($day);
        self::finalize_planned_day($day, $targetWorkMin, $lunchMin);
        unset($day['hard_limit_minutes']);
    }
    unset($day);

    // Passagem final de segurança, dedupe absoluta por semana real visível no plano.
    $globalWeekSeenStores = [];
    foreach ($days as &$day) {
        $realWeekKey = (string)($day['week_key'] ?? '');
        if ($realWeekKey === '' && !empty($day['date'])) {
            $realWeekKey = date('Y-m-d', strtotime('monday this week', strtotime((string)$day['date'])));
            $day['week_key'] = $realWeekKey;
        }
        $cleanItems = [];
        foreach ((array)($day['items'] ?? []) as $item) {
            $storeId = (int)($item['id'] ?? 0);
            if ($storeId <= 0) continue;
            if ($realWeekKey !== '') {
                if (!isset($globalWeekSeenStores[$realWeekKey])) $globalWeekSeenStores[$realWeekKey] = [];
                if (isset($globalWeekSeenStores[$realWeekKey][$storeId])) {
                    $item['overflow_conflict'] = true;
                    $unassigned[] = $item;
                    continue;
                }
                $globalWeekSeenStores[$realWeekKey][$storeId] = true;
            }
            $cleanItems[] = $item;
        }
        $day['items'] = $sequenceDay((array)$cleanItems, (array)($day['start_point'] ?? []));
        $day['stops'] = count((array)$day['items']);
        $visitMinWeekSafe = 0;
        foreach ((array)$day['items'] as $it) $visitMinWeekSafe += (int)($it['visit_duration_min'] ?? 45);
        $day['visit_min'] = $visitMinWeekSafe;
        $day['travel_min'] = $estimateRouteTravelMin((array)$day['items'], (array)($day['start_point'] ?? []), (array)($day['end_point'] ?? []));
        $trimDayToHardLimits($day);
        self::finalize_planned_day($day, $targetWorkMin, $lunchMin);
    }
    unset($day);

    if ($forceCompleteCoverage && $unassigned) {
        $remainingForced = array_values($unassigned);
        $unassigned = [];
        foreach ($remainingForced as $task) {
            if (!is_array($task)) continue;
            $storeId = (int)($task['id'] ?? 0);
            if ($storeId <= 0) continue;
            $targetWeek = (string)($task['target_week_key'] ?? '');
            $taskZone = $taskZoneKey((array)$task);
            $bestIdx = null;
            $bestScore = PHP_FLOAT_MAX;
            foreach ($days as $di => $day) {
                if ($dayHasStore((array)$day, $storeId)) {
                    continue;
                }
                $dayWeek = (string)($day['week_key'] ?? '');
                $duplicateInWeek = false;
                if ($dayWeek !== '') {
                    foreach ($days as $otherDay) {
                        if ((string)($otherDay['week_key'] ?? '') !== $dayWeek) continue;
                        foreach ((array)($otherDay['items'] ?? []) as $existing) {
                            if ((int)($existing['id'] ?? 0) === $storeId) {
                                $duplicateInWeek = true;
                                break 2;
                            }
                        }
                    }
                }
                $nextWork = $estimateDayWorkWithTask((array)$day, (array)$task);
                $currentStops = (int)($day['stops'] ?? 0);
                $score = 0.0;
                $targetDate = sanitize_text_field((string)($task['target_date'] ?? ''));
                if ($targetDate !== '') {
                    $score += ((string)($day['date'] ?? '') === $targetDate) ? -120000.0 : 35000.0;
                }
                if ($targetWeek !== '' && $dayWeek !== '') {
                    $score += ($targetWeek === $dayWeek) ? -50000.0 : 8000.0;
                }
                // Mesmo em cobertura forçada, nunca repetir a mesma loja na mesma semana real.
                if ($duplicateInWeek) continue;
                if ($currentStops >= $maxStops) $score += 6000.0 + (($currentStops - $maxStops + 1) * 1200.0);
                $hardLimit = (int)($day['hard_limit_minutes'] ?? $targetWorkMin);
                $score += max(0.0, $nextWork - $hardLimit) * 18.0;
                $score += $currentStops * ($preferBalancedLoad ? 90.0 : 35.0);
                $dominantZone = $dayDominantZone((array)$day);
                if ($taskZone !== '' && $dominantZone !== '') {
                    if ($dominantZone === $taskZone) $score -= $preferClusterDistrict ? 900.0 : 260.0;
                    else $score += $preferClusterDistrict ? 520.0 : 80.0;
                }
                if ($preferMinKm) {
                    $center = $dayCenterPoint((array)$day);
                    if ($center && is_numeric($task['lat'] ?? null) && is_numeric($task['lng'] ?? null)) {
                        $score += self::haversine_km((float)$center['lat'], (float)$center['lng'], (float)$task['lat'], (float)$task['lng']) * 28.0;
                    }
                }
                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestIdx = $di;
                }
            }
            if ($bestIdx === null && $days) {
                $bestIdx = 0;
                foreach ($days as $di => $day) {
                    if ((int)($day['stops'] ?? 0) < (int)($days[$bestIdx]['stops'] ?? 0)) $bestIdx = $di;
                }
            }
            if ($bestIdx === null) {
                $task['overflow_conflict'] = true;
                $unassigned[] = $task;
                continue;
            }
            $task['overflow_forced'] = true;
            $task['route_exception_reason'] = 'Forçado pelo motor para cumprir cobertura e periodicidade';
            $placeTask((int)$bestIdx, (array)$task);
            self::finalize_planned_day($days[(int)$bestIdx], $targetWorkMin, $lunchMin);
        }
    }

    $reinforcement = self::build_reinforcement_summary($days, $unassigned, $targetWorkMin, count($dates));
    return ['days' => $days, 'unassigned' => $unassigned, 'reinforcement' => $reinforcement];
}


    private static function minimum_productive_stops_per_day(array $options = []): int {
        // Regra operacional: evitar deslocações com uma só visita. Uma visita isolada só é aceitável
        // quando o percurso é realmente extremo ou quando não existe encaixe compatível no caminho.
        return 2;
    }

    private static function is_extreme_single_visit_day(array $day, array $options = []): bool {
        $km = self::estimate_plan_day_distance_km($day, array_merge($options, [
            'start_point' => (array)($day['start_point'] ?? []),
            'end_point' => (array)($day['end_point'] ?? []),
            'lock_start_point' => true,
            'lock_end_point' => true,
        ]));
        $travelMin = (float)($day['travel_min'] ?? 0);
        if ($travelMin <= 0 && !empty($day['items'])) {
            $travelMin = (float)self::estimate_day_travel_minutes((array)$day['items'], array_merge($options, [
                'start_point' => (array)($day['start_point'] ?? []),
                'end_point' => (array)($day['end_point'] ?? []),
                'lock_start_point' => true,
                'lock_end_point' => true,
            ]));
        }
        $mult = self::distance_sensitivity_multiplier($options);
        $extremeKm = $mult >= 1.4 ? 240.0 : ($mult <= 0.7 ? 310.0 : 275.0);
        $extremeMin = $mult >= 1.4 ? 230.0 : ($mult <= 0.7 ? 290.0 : 260.0);
        return $km >= $extremeKm || $travelMin >= $extremeMin;
    }

    private static function route_detour_km_for_candidate(array $day, array $candidate, array $options = []): float {
        $before = self::estimate_plan_day_distance_km($day, array_merge($options, [
            'start_point' => (array)($day['start_point'] ?? []),
            'end_point' => (array)($day['end_point'] ?? []),
            'lock_start_point' => true,
            'lock_end_point' => true,
        ]));
        $afterDay = $day;
        $items = array_values((array)($afterDay['items'] ?? []));
        $items[] = $candidate;
        $afterDay['items'] = $items;
        $after = self::estimate_plan_day_distance_km($afterDay, array_merge($options, [
            'start_point' => (array)($day['start_point'] ?? []),
            'end_point' => (array)($day['end_point'] ?? []),
            'lock_start_point' => true,
            'lock_end_point' => true,
        ]));
        return max(0.0, $after - $before);
    }

    private static function corridor_fit_score(array $lonelyDay, array $candidate, array $options = []): float {
        $items = array_values((array)($lonelyDay['items'] ?? []));
        $anchor = $items[0] ?? [];
        $detour = self::route_detour_km_for_candidate($lonelyDay, $candidate, $options);
        $anchorKm = self::point_has_coordinates((array)$anchor) && self::point_has_coordinates($candidate)
            ? self::safe_haversine_between_points((array)$anchor, $candidate)
            : 999.0;
        $sameZoneBonus = 0.0;
        $anchorZone = self::task_zone_key((array)$anchor);
        $candidateZone = self::task_zone_key($candidate);
        if ($anchorZone !== '' && $anchorZone === $candidateZone) $sameZoneBonus = 32.0;

        $baseDistance = self::estimate_plan_day_distance_km($lonelyDay, $options);
        $detourLimit = max(22.0, min(75.0, ($baseDistance * 0.28) + 14.0));
        if ($detour > $detourLimit && $anchorKm > 45.0) return PHP_FLOAT_MAX;

        return ($detour * 9.0) + ($anchorKm * 2.25) - $sameZoneBonus;
    }

    private static function fill_single_visit_days_from_corridor(array $days, array $options, int $maxStops, int $targetWorkMin, int $lunchMin): array {
        $minStops = self::minimum_productive_stops_per_day($options);
        if ($minStops <= 1 || count($days) <= 1) return $days;
        $baseTarget = self::resolve_target_stops_per_day($options, $maxStops);
        if ($baseTarget <= 0) $baseTarget = max(1, min($maxStops, 6));
        $routeStrategy = self::normalize_route_strategy((string)($options['route_strategy'] ?? 'operational_balanced'));

        $recalc = function(array &$day) use ($options, $targetWorkMin, $lunchMin): void {
            $dayOptions = array_merge($options, [
                'start_point' => (array)($day['start_point'] ?? []),
                'end_point' => (array)($day['end_point'] ?? []),
                'lock_start_point' => true,
                'lock_end_point' => true,
            ]);
            $items = self::optimize_day_items(array_values((array)($day['items'] ?? [])), $dayOptions);
            $visit = 0;
            foreach ($items as $it) $visit += max(0, (int)($it['visit_duration_min'] ?? 45));
            $day['items'] = $items;
            $day['stops'] = count($items);
            $day['visit_min'] = $visit;
            $day['travel_min'] = self::estimate_day_travel_minutes($items, $dayOptions);
            self::finalize_planned_day($day, $targetWorkMin, $lunchMin);
        };

        foreach ($days as &$d) $recalc($d);
        unset($d);

        $sameWeek = function(array $a, array $b): bool {
            return (string)($a['week_key'] ?? '') !== '' && (string)($a['week_key'] ?? '') === (string)($b['week_key'] ?? '');
        };
        $hasStore = function(array $day, int $storeId): bool {
            foreach ((array)($day['items'] ?? []) as $it) if ((int)($it['id'] ?? 0) === $storeId) return true;
            return false;
        };

        $passes = 4;
        for ($pass = 0; $pass < $passes; $pass++) {
            $moved = false;
            foreach ($days as $lonelyIdx => $lonelyDay) {
                if ((int)($lonelyDay['stops'] ?? 0) !== 1) continue;
                if (self::is_extreme_single_visit_day((array)$lonelyDay, $options)) continue;

                $best = null;
                $bestScore = PHP_FLOAT_MAX;
                foreach ($days as $srcIdx => $srcDay) {
                    if ($srcIdx === $lonelyIdx) continue;
                    if (!$sameWeek((array)$lonelyDay, (array)$srcDay)) continue;
                    $srcStops = (int)($srcDay['stops'] ?? 0);
                    if ($srcStops <= $minStops) continue;

                    $srcKm = self::estimate_plan_day_distance_km((array)$srcDay, array_merge($options, [
                        'start_point' => (array)($srcDay['start_point'] ?? []),
                        'end_point' => (array)($srcDay['end_point'] ?? []),
                        'lock_start_point' => true,
                        'lock_end_point' => true,
                    ]));
                    $srcCapacity = self::distance_capacity_stops_for_route($baseTarget, $maxStops, $srcKm, $options, true);
                    $sourcePressure = max(0, $srcStops - $srcCapacity);
                    // Só vamos buscar lojas a dias realmente carregados para a sua distância.
                    // Isto impede que a regra de mínimo 2 estrague dias que já estavam equilibrados.
                    if ($sourcePressure <= 0) continue;
                    if (($srcStops - 1) < $minStops) continue;

                    foreach (array_values((array)($srcDay['items'] ?? [])) as $itemIndex => $candidate) {
                        $candidate = (array)$candidate;
                        $storeId = (int)($candidate['id'] ?? 0);
                        if ($storeId <= 0) continue;
                        if ($hasStore((array)$lonelyDay, $storeId)) continue;

                        $freq = max(1, (int)($candidate['frequency_count'] ?? 1));
                        $anchorPenalty = $freq >= 4 ? 120.0 : ($freq === 3 ? 45.0 : 0.0);
                        if ($freq >= 4 && $sourcePressure <= 1 && $routeStrategy !== 'balanced_load') continue;

                        $candidateDay = $lonelyDay;
                        $candidateItems = array_values((array)($candidateDay['items'] ?? []));
                        $candidateItems[] = $candidate;
                        if (count($candidateItems) > $maxStops) continue;
                        $candidateDay['items'] = $candidateItems;
                        $newKm = self::estimate_plan_day_distance_km($candidateDay, array_merge($options, [
                            'start_point' => (array)($lonelyDay['start_point'] ?? []),
                            'end_point' => (array)($lonelyDay['end_point'] ?? []),
                            'lock_start_point' => true,
                            'lock_end_point' => true,
                        ]));
                        $newCapacity = self::distance_capacity_stops_for_route($baseTarget, $maxStops, $newKm, $options, true);
                        if (count($candidateItems) > $newCapacity && $routeStrategy !== 'minimize_km') continue;

                        $fit = self::corridor_fit_score((array)$lonelyDay, $candidate, $options);
                        if (!is_finite($fit)) continue;

                        $visitAfter = 0;
                        foreach ($candidateItems as $ci) $visitAfter += max(0, (int)($ci['visit_duration_min'] ?? 45));
                        $travelAfter = self::estimate_day_travel_minutes(self::optimize_day_items($candidateItems, array_merge($options, [
                            'start_point' => (array)($lonelyDay['start_point'] ?? []),
                            'end_point' => (array)($lonelyDay['end_point'] ?? []),
                            'lock_start_point' => true,
                            'lock_end_point' => true,
                        ])), array_merge($options, [
                            'start_point' => (array)($lonelyDay['start_point'] ?? []),
                            'end_point' => (array)($lonelyDay['end_point'] ?? []),
                            'lock_start_point' => true,
                            'lock_end_point' => true,
                        ]));
                        $hardLimit = (int)($lonelyDay['hard_limit_minutes'] ?? $targetWorkMin);
                        $timeOverflowPenalty = max(0, ($visitAfter + $travelAfter) - ($hardLimit + 30)) * 5.0;
                        if ($timeOverflowPenalty > 450.0) continue;

                        $distanceCapacityPenalty = max(0, count($candidateItems) - $newCapacity) * 500.0;
                        $score = $fit + $anchorPenalty + $timeOverflowPenalty + $distanceCapacityPenalty - ($sourcePressure * 85.0) - max(0, $srcStops - $srcCapacity) * 22.0;
                        if ($score < $bestScore) {
                            $bestScore = $score;
                            $best = [$srcIdx, $itemIndex, $candidate];
                        }
                    }
                }

                if ($best === null) continue;
                [$srcIdx, $itemIndex, $candidate] = $best;
                $candidate['route_exception_reason'] = trim((string)($candidate['route_exception_reason'] ?? '') . ' Reposicionado para evitar rota isolada e aproveitar o caminho de ida/volta.');
                array_splice($days[$srcIdx]['items'], $itemIndex, 1);
                $days[$lonelyIdx]['items'][] = $candidate;
                $recalc($days[$srcIdx]);
                $recalc($days[$lonelyIdx]);
                $moved = true;
                break;
            }
            if (!$moved) break;
        }

        return $days;
    }

    private static function rebalance_fixed_cadence_days_by_effort(array $days, array $options, int $maxStops, int $targetWorkMin, int $lunchMin): array {
        $baseTarget = self::resolve_target_stops_per_day($options, $maxStops);
        if ($baseTarget <= 0) $baseTarget = max(1, min($maxStops, 6));
        $routeStrategy = self::normalize_route_strategy((string)($options['route_strategy'] ?? 'operational_balanced'));
        $passes = in_array($routeStrategy, ['cluster_district', 'route_corridor'], true) ? 2 : 3;

        $recalc = function(array &$day) use ($options, $targetWorkMin, $lunchMin): void {
            $items = array_values((array)($day['items'] ?? []));
            $dayOptions = array_merge($options, [
                'start_point' => (array)($day['start_point'] ?? []),
                'end_point' => (array)($day['end_point'] ?? []),
                'lock_start_point' => true,
                'lock_end_point' => true,
            ]);
            $items = self::optimize_day_items($items, $dayOptions);
            $visit = 0;
            foreach ($items as $it) $visit += max(0, (int)($it['visit_duration_min'] ?? 45));
            $day['items'] = $items;
            $day['stops'] = count($items);
            $day['visit_min'] = $visit;
            $day['travel_min'] = self::estimate_day_travel_minutes($items, $dayOptions);
            self::finalize_planned_day($day, $targetWorkMin, $lunchMin);
        };

        foreach ($days as &$d) $recalc($d);
        unset($d);

        $sameWeek = function(array $a, array $b): bool {
            return (string)($a['week_key'] ?? '') !== '' && (string)($a['week_key'] ?? '') === (string)($b['week_key'] ?? '');
        };
        $hasStore = function(array $day, int $storeId): bool {
            foreach ((array)($day['items'] ?? []) as $it) if ((int)($it['id'] ?? 0) === $storeId) return true;
            return false;
        };
        $zone = function(array $item): string { return self::task_zone_key($item); };
        $dominantZone = function(array $day) use ($zone): string {
            $zones = [];
            foreach ((array)($day['items'] ?? []) as $it) {
                $z = $zone((array)$it);
                if ($z !== '') $zones[$z] = ($zones[$z] ?? 0) + 1;
            }
            if (!$zones) return '';
            arsort($zones);
            return (string)array_key_first($zones);
        };
        $routeKm = function(array $day, array $extra = []) use ($options): float {
            $items = array_values((array)($day['items'] ?? []));
            foreach ($extra as $it) $items[] = $it;
            return self::estimate_day_distance_km_fast($items, (array)($day['start_point'] ?? []), (array)($day['end_point'] ?? []));
        };

        for ($pass = 0; $pass < $passes; $pass++) {
            $moved = false;
            $scores = [];
            foreach ($days as $i => $day) {
                $km = $routeKm((array)$day);
                $dynTarget = self::dynamic_target_stops_for_distance($baseTarget, $maxStops, $km, $options);
                $distanceCapacity = self::distance_capacity_stops_for_day((array)$day, $baseTarget, $maxStops, $options, true);
                $densityBonus = self::cluster_density_bonus_for_items(array_values((array)($day['items'] ?? [])));
                $work = (float)($day['work_min'] ?? ((float)($day['visit_min'] ?? 0) + (float)($day['travel_min'] ?? 0)));
                $distancePressure = max(0.0, $km - 90.0) * max(0.8, 3.0 - ($densityBonus * 0.75));
                $scores[$i] = [
                    'km' => $km,
                    'target' => $dynTarget,
                    'capacity' => $distanceCapacity,
                    'density' => $densityBonus,
                    'pressure' => max(0, ((int)($day['stops'] ?? 0)) - $distanceCapacity) * 260.0 + max(0.0, $work - $targetWorkMin) * 5.0 + $distancePressure,
                ];
            }
            uasort($scores, function($a, $b){ return ($b['pressure'] <=> $a['pressure']); });
            foreach (array_keys($scores) as $srcIdx) {
                if (($scores[$srcIdx]['pressure'] ?? 0) <= 0.0) continue;
                $minProductive = self::minimum_productive_stops_per_day($options);
                if ((int)($days[$srcIdx]['stops'] ?? 0) <= $minProductive) continue;
                $items = array_values((array)($days[$srcIdx]['items'] ?? []));
                usort($items, function($a, $b) {
                    $fa = max(1, (int)($a['frequency_count'] ?? 1));
                    $fb = max(1, (int)($b['frequency_count'] ?? 1));
                    if ($fa !== $fb) return $fa <=> $fb;
                    return ((int)($b['visit_duration_min'] ?? 45)) <=> ((int)($a['visit_duration_min'] ?? 45));
                });
                foreach ($items as $candidate) {
                    $storeId = (int)($candidate['id'] ?? 0);
                    if ($storeId <= 0) continue;
                    $bestDest = null;
                    $bestGain = 0.0;
                    foreach ($days as $destIdx => $destDay) {
                        if ($destIdx === $srcIdx) continue;
                        if (!$sameWeek((array)$days[$srcIdx], (array)$destDay)) continue;
                        if ($hasStore((array)$destDay, $storeId)) continue;
                        if ((int)($destDay['stops'] ?? 0) >= $maxStops) continue;
                        $destKmAfter = $routeKm((array)$destDay, [(array)$candidate]);
                        $destTmpForCapacity = $destDay; $destTmpForCapacity['items'] = array_values(array_merge((array)($destTmpForCapacity['items'] ?? []), [(array)$candidate]));
                        $destCapacity = self::distance_capacity_stops_for_day((array)$destTmpForCapacity, $baseTarget, $maxStops, $options, true);
                        // Guardrail principal: a distância manda na capacidade. Só permitimos ultrapassar
                        // este limite em estratégia de kms, e mesmo assim com penalização forte abaixo.
                        if (((int)($destDay['stops'] ?? 0) + 1) > $destCapacity && $routeStrategy !== 'minimize_km') continue;
                        $sourceItems = array_values(array_filter((array)($days[$srcIdx]['items'] ?? []), function($it) use ($storeId) { return (int)($it['id'] ?? 0) !== $storeId; }));
                        $sourceTmp = $days[$srcIdx];
                        $sourceTmp['items'] = $sourceItems;
                        $srcKmAfter = $routeKm((array)$sourceTmp);
                        $before = ($scores[$srcIdx]['pressure'] ?? 0) + max(0, ((int)($destDay['stops'] ?? 0)) - (int)($scores[$destIdx]['capacity'] ?? $baseTarget)) * 160.0;
                        $afterSourceKm = $srcKmAfter;
                        $afterSourceCapacity = self::distance_capacity_stops_for_day((array)$sourceTmp, $baseTarget, $maxStops, $options, true);
                        $after = max(0, count($sourceItems) - $afterSourceCapacity) * 220.0 + max(0, ((int)($destDay['stops'] ?? 0) + 1) - $destCapacity) * 260.0;
                        $geoPenalty = 0.0;
                        $candZone = $zone((array)$candidate);
                        $destZone = $dominantZone((array)$destDay);
                        if ($candZone !== '' && $destZone !== '' && $candZone !== $destZone) $geoPenalty += ($routeStrategy === 'cluster_district') ? 260.0 : 90.0;
                        $distanceGuardPenalty = max(0, ((int)($destDay['stops'] ?? 0) + 1) - $destCapacity) * 700.0;
                        $gain = $before - $after - $geoPenalty - $distanceGuardPenalty - max(0.0, $destKmAfter - 90.0) * 2.4;
                        if ($gain > $bestGain) { $bestGain = $gain; $bestDest = $destIdx; }
                    }
                    if ($bestDest === null) continue;
                    foreach ($days[$srcIdx]['items'] as $ix => $it) {
                        if ((int)($it['id'] ?? 0) === $storeId) { array_splice($days[$srcIdx]['items'], $ix, 1); break; }
                    }
                    $days[$bestDest]['items'][] = $candidate;
                    $recalc($days[$srcIdx]);
                    $recalc($days[$bestDest]);
                    $moved = true;
                    break 2;
                }
            }
            if (!$moved) break;
        }
        $days = self::fill_single_visit_days_from_corridor($days, $options, $maxStops, $targetWorkMin, $lunchMin);
        return $days;
    }


    private static function estimate_day_distance_km_fast(array $items, array $startPoint = [], array $endPoint = []): float {
        $items = array_values($items);
        if (!$items) return 0.0;
        $total = 0.0;
        $prev = self::point_has_coordinates($startPoint) ? $startPoint : [];
        if (!$prev) $prev = (array)$items[0];
        foreach ($items as $item) {
            $item = (array)$item;
            $total += self::safe_haversine_between_points($prev, $item);
            $prev = $item;
        }
        if (self::point_has_coordinates($endPoint)) $total += self::safe_haversine_between_points($prev, $endPoint);
        return round($total, 2);
    }

    private static function estimate_day_travel_minutes_fast(array $items, array $startPoint = [], array $endPoint = []): int {
        $km = self::estimate_day_distance_km_fast($items, $startPoint, $endPoint);
        if ($km <= 0.0) return max(0, (count($items) - 1) * 18);
        return (int)round(max(0, $km * 1.7));
    }

    private static function recalc_operational_day(array &$day, array $options, int $targetWorkMin, int $lunchMin): void {
        $items = array_values((array)($day['items'] ?? []));
        $visit = 0;
        foreach ($items as $it) $visit += max(0, (int)($it['visit_duration_min'] ?? 45));
        $day['items'] = $items;
        $day['stops'] = count($items);
        $day['visit_min'] = $visit;
        $day['travel_min'] = self::estimate_day_travel_minutes_fast($items, (array)($day['start_point'] ?? []), (array)($day['end_point'] ?? []));
        self::finalize_planned_day($day, $targetWorkMin, $lunchMin);
    }

    private static function operational_total_minutes_for_day(array $day): int {
        return (int)($day['work_min'] ?? round((float)($day['travel_min'] ?? 0) + (float)($day['visit_min'] ?? 0))) + (int)($day['lunch_min'] ?? 0);
    }

    private static function can_place_visit_on_day(array $day, array $candidate, array $options, int $maxStops, int $targetWorkMin, int $lunchMin): bool {
        if ((int)($day['stops'] ?? count((array)($day['items'] ?? []))) >= $maxStops) return false;
        foreach ((array)($day['items'] ?? []) as $it) {
            if ((int)($it['id'] ?? 0) === (int)($candidate['id'] ?? 0)) return false;
        }
        $tmpItems = array_values(array_merge((array)($day['items'] ?? []), [$candidate]));
        $visit = 0;
        foreach ($tmpItems as $it) $visit += max(0, (int)($it['visit_duration_min'] ?? 45));
        $travel = self::estimate_day_travel_minutes_fast($tmpItems, (array)($day['start_point'] ?? []), (array)($day['end_point'] ?? []));
        $hardLimit = min(600, max(60, (int)($day['hard_limit_minutes'] ?? ($targetWorkMin + (!empty($day['allow_overtime']) ? (int)($day['extra_minutes'] ?? 0) : 0)))));
        return ($visit + $travel + ($tmpItems ? $lunchMin : 0)) <= $hardLimit && count($tmpItems) <= $maxStops;
    }

    private static function movable_visit_priority(array $item): int {
        $freq = max(1, (int)($item['configured_frequency_count'] ?? ($item['frequency_count'] ?? 1)));
        if ($freq <= 1) return 10;
        if ($freq === 3) return 20;
        if ($freq === 2) return 30;
        return 90;
    }

    private static function rebalance_overloaded_days(array $days, array &$unassigned, array $options, int $maxStops, int $targetWorkMin, int $lunchMin): array {
        $baseTarget = self::resolve_target_stops_per_day($options, $maxStops);
        if ($baseTarget <= 0) $baseTarget = max(1, min($maxStops, 6));
        foreach ($days as &$day) self::recalc_operational_day($day, $options, $targetWorkMin, $lunchMin);
        unset($day);

        $dayHardLimit = static function(array $day) use ($targetWorkMin): int {
            return min(600, max(60, (int)($day['hard_limit_minutes'] ?? ($targetWorkMin + (!empty($day['allow_overtime']) ? (int)($day['extra_minutes'] ?? 0) : 0)))));
        };
        $sameWeek = static function(array $a, array $b): bool {
            return (string)($a['week_key'] ?? '') !== '' && (string)($a['week_key'] ?? '') === (string)($b['week_key'] ?? '');
        };
        $zone = static function(array $item): string { return self::task_zone_key($item); };
        $dominantZone = static function(array $day) use ($zone): string {
            $zones = [];
            foreach ((array)($day['items'] ?? []) as $it) {
                $z = $zone((array)$it);
                if ($z !== '') $zones[$z] = ($zones[$z] ?? 0) + 1;
            }
            if (!$zones) return '';
            arsort($zones);
            return (string)array_key_first($zones);
        };
        $dayPressure = function(array $day) use ($baseTarget, $maxStops, $options, $dayHardLimit): float {
            $total = self::operational_total_minutes_for_day($day);
            $hard = $dayHardLimit($day);
            $dyn = self::distance_capacity_stops_for_day($day, $baseTarget, $maxStops, $options, true);
            return max(0, (int)($day['stops'] ?? 0) - $maxStops) * 9000.0
                + max(0, (int)($day['stops'] ?? 0) - $dyn) * 900.0
                + max(0, $total - $hard) * 90.0
                + max(0, $total - (int)($options['work_minutes'] ?? 480)) * 8.0;
        };

        for ($pass = 0; $pass < 8; $pass++) {
            $srcIdx = null;
            $srcPressure = 0.0;
            foreach ($days as $i => $day) {
                $p = $dayPressure((array)$day);
                if ($p > $srcPressure) { $srcPressure = $p; $srcIdx = $i; }
            }
            if ($srcIdx === null || $srcPressure <= 0.0) break;
            $items = array_values((array)($days[$srcIdx]['items'] ?? []));
            usort($items, function($a, $b) {
                $pa = self::movable_visit_priority((array)$a);
                $pb = self::movable_visit_priority((array)$b);
                if ($pa !== $pb) return $pa <=> $pb;
                return ((int)($b['visit_duration_min'] ?? 45)) <=> ((int)($a['visit_duration_min'] ?? 45));
            });
            $moved = false;
            foreach ($items as $candidate) {
                $bestDest = null;
                $bestScore = PHP_FLOAT_MAX;
                $candZone = $zone((array)$candidate);
                foreach ($days as $destIdx => $destDay) {
                    if ($destIdx === $srcIdx) continue;
                    // Para não estragar a cadência espelho, reequilibra dentro da mesma semana.
                    // P1 pode ir para qualquer semana útil se for necessário salvar um dia crítico.
                    $freq = max(1, (int)($candidate['configured_frequency_count'] ?? ($candidate['frequency_count'] ?? 1)));
                    if ($freq > 1 && !$sameWeek((array)$days[$srcIdx], (array)$destDay)) continue;
                    if (!self::can_place_visit_on_day((array)$destDay, (array)$candidate, $options, $maxStops, $targetWorkMin, $lunchMin)) continue;
                    $tmp = $destDay;
                    $tmp['items'] = array_values(array_merge((array)($tmp['items'] ?? []), [(array)$candidate]));
                    self::recalc_operational_day($tmp, $options, $targetWorkMin, $lunchMin);
                    $score = self::operational_total_minutes_for_day($tmp)
                        + ((int)($tmp['stops'] ?? 0) * 18.0)
                        + max(0, self::estimate_day_distance_km_fast((array)($tmp['items'] ?? []), (array)($tmp['start_point'] ?? []), (array)($tmp['end_point'] ?? [])) - 120.0) * 2.0;
                    $destZone = $dominantZone((array)$destDay);
                    if ($candZone !== '' && $destZone !== '' && $candZone === $destZone) $score -= 220.0;
                    elseif ($destZone !== '' && $candZone !== '') $score += 130.0;
                    if ($sameWeek((array)$days[$srcIdx], (array)$destDay)) $score -= 80.0;
                    if ($score < $bestScore) { $bestScore = $score; $bestDest = $destIdx; }
                }
                if ($bestDest === null) continue;
                $storeId = (int)($candidate['id'] ?? 0);
                foreach ($days[$srcIdx]['items'] as $ix => $it) {
                    if ((int)($it['id'] ?? 0) === $storeId && (string)($it['uid'] ?? '') === (string)($candidate['uid'] ?? '')) { array_splice($days[$srcIdx]['items'], $ix, 1); break; }
                }
                $candidate['route_exception_reason'] = trim((string)($candidate['route_exception_reason'] ?? '') . ' Rebalanceado por carga operacional.');
                $days[$bestDest]['items'][] = $candidate;
                self::recalc_operational_day($days[$srcIdx], $options, $targetWorkMin, $lunchMin);
                self::recalc_operational_day($days[$bestDest], $options, $targetWorkMin, $lunchMin);
                $moved = true;
                break;
            }
            if (!$moved) break;
        }
        return $days;
    }

    private static function move_soft_visits_from_heavy_to_light_days(array $days, array &$unassigned, array $options, int $maxStops, int $targetWorkMin, int $lunchMin): array {
        $days = self::rebalance_overloaded_days($days, $unassigned, $options, $maxStops, $targetWorkMin, $lunchMin);
        $baseTarget = self::resolve_target_stops_per_day($options, $maxStops);
        if ($baseTarget <= 0) $baseTarget = max(1, min($maxStops, 6));
        foreach ($days as &$day) self::recalc_operational_day($day, $options, $targetWorkMin, $lunchMin);
        unset($day);
        foreach ($days as $di => &$day) {
            $hardLimit = min(600, max(60, (int)($day['hard_limit_minutes'] ?? $targetWorkMin)));
            $safety = 0;
            while ($safety++ < 20) {
                $dyn = self::distance_capacity_stops_for_day((array)$day, $baseTarget, $maxStops, $options, true);
                $total = self::operational_total_minutes_for_day((array)$day);
                if ($total <= $hardLimit && (int)($day['stops'] ?? 0) <= $maxStops) break;
                $items = array_values((array)($day['items'] ?? []));
                if (!$items) break;
                usort($items, function($a, $b) {
                    $pa = self::movable_visit_priority((array)$a);
                    $pb = self::movable_visit_priority((array)$b);
                    if ($pa !== $pb) return $pa <=> $pb;
                    return ((int)($b['visit_duration_min'] ?? 45)) <=> ((int)($a['visit_duration_min'] ?? 45));
                });
                $candidate = $items[0];
                $bestDest = null;
                $bestScore = PHP_FLOAT_MAX;
                foreach ($days as $destIdx => $destDay) {
                    if ((int)$destIdx === (int)$di) continue;
                    if (!self::can_place_visit_on_day((array)$destDay, (array)$candidate, $options, $maxStops, $targetWorkMin, $lunchMin)) continue;
                    $tmp = $destDay;
                    $tmp['items'] = array_values(array_merge((array)($tmp['items'] ?? []), [(array)$candidate]));
                    $score = self::estimate_day_travel_minutes_fast((array)($tmp['items'] ?? []), (array)($tmp['start_point'] ?? []), (array)($tmp['end_point'] ?? []))
                        + (count((array)($tmp['items'] ?? [])) * 25.0);
                    if ((string)($destDay['week_key'] ?? '') === (string)($day['week_key'] ?? '')) $score -= 120.0;
                    if (self::task_zone_key((array)$candidate) !== '' && self::task_zone_key((array)$candidate) === self::dominant_zone_for_day((array)$destDay)) $score -= 180.0;
                    if ($score < $bestScore) { $bestScore = $score; $bestDest = (int)$destIdx; }
                }
                if ($bestDest === null) break;
                $removed = false;
                foreach ($day['items'] as $ix => $it) {
                    if ((string)($it['uid'] ?? '') === (string)($candidate['uid'] ?? '') && (int)($it['id'] ?? 0) === (int)($candidate['id'] ?? 0)) {
                        array_splice($day['items'], $ix, 1);
                        $removed = true;
                        break;
                    }
                }
                if (!$removed) break;
                $candidate['route_exception_reason'] = trim((string)($candidate['route_exception_reason'] ?? '') . ' Movido para dia mais leve, preservando cobertura.');
                $days[$bestDest]['items'][] = $candidate;
                self::recalc_operational_day($day, $options, $targetWorkMin, $lunchMin);
                self::recalc_operational_day($days[$bestDest], $options, $targetWorkMin, $lunchMin);
            }
        }
        unset($day);
        return $days;
    }


    private static function dominant_zone_for_day(array $day): string {
        $zones = [];
        foreach ((array)($day['items'] ?? []) as $it) {
            $z = self::task_zone_key((array)$it);
            if ($z !== '') $zones[$z] = ($zones[$z] ?? 0) + 1;
        }
        if (!$zones) return '';
        arsort($zones);
        return (string)array_key_first($zones);
    }

    private static function recover_unassigned_visits_to_best_days(array $days, array &$unassigned, array $options, int $maxStops, int $targetWorkMin, int $lunchMin): array {
        if (!$unassigned) return $days;
        foreach ($days as &$day) self::recalc_operational_day($day, $options, $targetWorkMin, $lunchMin);
        unset($day);

        $still = [];
        foreach (array_values((array)$unassigned) as $candidate) {
            if (!is_array($candidate)) continue;
            $bestStrict = null;
            $bestLoose = null;
            $bestStrictScore = PHP_FLOAT_MAX;
            $bestLooseScore = PHP_FLOAT_MAX;
            $candZone = self::task_zone_key((array)$candidate);
            foreach ($days as $idx => $day) {
                foreach ((array)($day['items'] ?? []) as $it) {
                    if ((string)($it['uid'] ?? '') !== '' && (string)($it['uid'] ?? '') === (string)($candidate['uid'] ?? '')) continue 2;
                    if ((int)($it['id'] ?? 0) > 0 && (int)($it['id'] ?? 0) === (int)($candidate['id'] ?? 0) && (int)($it['copy_index'] ?? 1) === (int)($candidate['copy_index'] ?? 1)) continue 2;
                }
                $items = array_values(array_merge((array)($day['items'] ?? []), [(array)$candidate]));
                if (count($items) > $maxStops) continue;
                $visit = 0;
                foreach ($items as $it) $visit += max(0, (int)($it['visit_duration_min'] ?? 45));
                $travel = self::estimate_day_travel_minutes_fast($items, (array)($day['start_point'] ?? []), (array)($day['end_point'] ?? []));
                $hard = min(600, max(60, (int)($day['hard_limit_minutes'] ?? ($targetWorkMin + (!empty($day['allow_overtime']) ? (int)($day['extra_minutes'] ?? 0) : 0)))));
                $total = $visit + $travel + ($items ? $lunchMin : 0);
                $zoneMatch = $candZone !== '' && $candZone === self::dominant_zone_for_day((array)$day);
                $sameWeek = (string)($candidate['target_week_key'] ?? '') !== '' && (string)($candidate['target_week_key'] ?? '') === (string)($day['week_key'] ?? '');
                $score = $total + (count($items) * 30.0) + ($zoneMatch ? -220.0 : 0.0) + ($sameWeek ? -120.0 : 0.0);
                if ($total <= $hard && $score < $bestStrictScore) { $bestStrictScore = $score; $bestStrict = (int)$idx; }
                if ($score < $bestLooseScore) { $bestLooseScore = $score; $bestLoose = (int)$idx; }
            }
            $chosen = $bestStrict !== null ? $bestStrict : $bestLoose;
            if ($chosen === null) { $still[] = $candidate; continue; }
            if ($bestStrict === null) {
                $candidate['overflow_forced'] = true;
                $candidate['route_exception_reason'] = trim((string)($candidate['route_exception_reason'] ?? '') . ' Cobertura recuperada em modo forçado, requer validação operacional.');
            } else {
                unset($candidate['overflow_conflict']);
                $candidate['route_exception_reason'] = trim((string)($candidate['route_exception_reason'] ?? '') . ' Recuperada para garantir cobertura mínima.');
            }
            $days[$chosen]['items'][] = $candidate;
            self::recalc_operational_day($days[$chosen], $options, $targetWorkMin, $lunchMin);
        }
        $unassigned = $still;
        return $days;
    }


    private static function rebalance_to_even_stop_distribution(array $days, array &$unassigned, array $options, int $maxStops, int $targetWorkMin, int $lunchMin): array {
        if (!$days) return $days;
        $maxStops = max(1, min(20, $maxStops));
        $baseTarget = self::resolve_target_stops_per_day($options, $maxStops);
        if ($baseTarget <= 0) $baseTarget = max(1, min($maxStops, (int)ceil(self::count_items_in_days($days) / max(1, self::count_open_days($days)))));
        $target = max(1, min($maxStops, $baseTarget));
        foreach ($days as &$day) self::recalc_operational_day($day, $options, $targetWorkMin, $lunchMin);
        unset($day);

        for ($pass = 0; $pass < 24; $pass++) {
            $srcIdx = null;
            $srcLoad = -1;
            $destIdx = null;
            $destLoad = PHP_INT_MAX;
            foreach ($days as $idx => $day) {
                $stops = (int)($day['stops'] ?? count((array)($day['items'] ?? [])));
                if ($stops > $srcLoad) { $srcLoad = $stops; $srcIdx = (int)$idx; }
                if ($stops < $destLoad) { $destLoad = $stops; $destIdx = (int)$idx; }
            }
            if ($srcIdx === null || $destIdx === null || $srcIdx === $destIdx) break;
            if ($srcLoad <= $target && ($srcLoad - $destLoad) <= 1) break;
            if ($destLoad >= $maxStops) break;

            $best = null;
            $bestScore = PHP_FLOAT_MAX;
            $sourceDay = (array)$days[$srcIdx];
            $destDay = (array)$days[$destIdx];
            $sourceItems = array_values((array)($sourceDay['items'] ?? []));
            $sameWeek = (string)($sourceDay['week_key'] ?? '') !== '' && (string)($sourceDay['week_key'] ?? '') === (string)($destDay['week_key'] ?? '');

            foreach ($sourceItems as $ix => $candidate) {
                $candidate = (array)$candidate;
                if (!empty($candidate['locked_date']) || !empty($candidate['hard_fixed_date'])) continue;
                if (!$sameWeek && (string)($candidate['target_week_key'] ?? '') !== '') continue;
                if (!self::can_place_visit_on_day($destDay, $candidate, $options, $maxStops, $targetWorkMin, $lunchMin)) continue;

                $destItems = array_values(array_merge((array)($destDay['items'] ?? []), [$candidate]));
                $srcItems = $sourceItems;
                array_splice($srcItems, $ix, 1);
                $destKm = self::estimate_day_distance_km_fast($destItems, (array)($destDay['start_point'] ?? []), (array)($destDay['end_point'] ?? []));
                $srcKm = self::estimate_day_distance_km_fast($srcItems, (array)($sourceDay['start_point'] ?? []), (array)($sourceDay['end_point'] ?? []));
                $zonePenalty = 0.0;
                $candZone = self::task_zone_key($candidate);
                $destZone = self::dominant_zone_for_day($destDay);
                if ($candZone !== '' && $destZone !== '' && $candZone !== $destZone) $zonePenalty += 180.0;
                $corrPenalty = 0.0;
                $candCorr = (string)($candidate['route_corridor_key'] ?? '');
                $destCorr = self::dominant_corridor_for_day($destDay);
                if ($candCorr !== '' && $destCorr !== '' && $candCorr !== $destCorr) $corrPenalty += 220.0;
                $score = ($destKm + $srcKm) + $zonePenalty + $corrPenalty + max(0, count($destItems) - $target) * 260.0;
                if ($score < $bestScore) { $bestScore = $score; $best = ['index' => $ix, 'item' => $candidate]; }
            }
            if ($best === null) break;
            array_splice($days[$srcIdx]['items'], (int)$best['index'], 1);
            $moved = (array)$best['item'];
            $moved['route_exception_reason'] = trim((string)($moved['route_exception_reason'] ?? '') . ' Rebalanceado para distribuir melhor os dias sem exceder o limite diário.');
            $days[$destIdx]['items'][] = $moved;
            self::recalc_operational_day($days[$srcIdx], $options, $targetWorkMin, $lunchMin);
            self::recalc_operational_day($days[$destIdx], $options, $targetWorkMin, $lunchMin);
        }
        return $days;
    }

    private static function enforce_hard_daily_stop_cap(array $days, array &$unassigned, array $options, int $maxStops, int $targetWorkMin, int $lunchMin): array {
        $maxStops = max(1, min(20, $maxStops));
        foreach ($days as &$day) self::recalc_operational_day($day, $options, $targetWorkMin, $lunchMin);
        unset($day);

        foreach ($days as $di => &$day) {
            $safety = 0;
            while ((int)($day['stops'] ?? count((array)($day['items'] ?? []))) > $maxStops && $safety++ < 30) {
                $items = array_values((array)($day['items'] ?? []));
                usort($items, function($a, $b) {
                    $pa = self::movable_visit_priority((array)$a);
                    $pb = self::movable_visit_priority((array)$b);
                    if ($pa !== $pb) return $pa <=> $pb;
                    return ((int)($a['priority'] ?? 0)) <=> ((int)($b['priority'] ?? 0));
                });
                $candidate = (array)($items[0] ?? []);
                if (!$candidate) break;
                $bestDest = null;
                $bestScore = PHP_FLOAT_MAX;
                foreach ($days as $destIdx => $destDay) {
                    if ((int)$destIdx === (int)$di) continue;
                    if (!self::can_place_visit_on_day((array)$destDay, $candidate, $options, $maxStops, $targetWorkMin, $lunchMin)) continue;
                    $sameWeek = (string)($candidate['target_week_key'] ?? '') === '' || (string)($candidate['target_week_key'] ?? '') === (string)($destDay['week_key'] ?? '');
                    if (!$sameWeek) continue;
                    $tmp = (array)$destDay;
                    $tmp['items'] = array_values(array_merge((array)($tmp['items'] ?? []), [$candidate]));
                    $score = self::estimate_day_distance_km_fast((array)$tmp['items'], (array)($tmp['start_point'] ?? []), (array)($tmp['end_point'] ?? []))
                        + ((int)($tmp['stops'] ?? count((array)$tmp['items'])) * 20.0);
                    $candZone = self::task_zone_key($candidate);
                    $destZone = self::dominant_zone_for_day((array)$destDay);
                    if ($candZone !== '' && $destZone !== '' && $candZone === $destZone) $score -= 140.0;
                    $candCorr = (string)($candidate['route_corridor_key'] ?? '');
                    $destCorr = self::dominant_corridor_for_day((array)$destDay);
                    if ($candCorr !== '' && $destCorr !== '' && $candCorr === $destCorr) $score -= 170.0;
                    if ($score < $bestScore) { $bestScore = $score; $bestDest = (int)$destIdx; }
                }

                $removed = false;
                foreach ($day['items'] as $ix => $it) {
                    if ((string)($it['uid'] ?? '') === (string)($candidate['uid'] ?? '') && (int)($it['id'] ?? 0) === (int)($candidate['id'] ?? 0)) {
                        array_splice($day['items'], $ix, 1);
                        $removed = true;
                        break;
                    }
                }
                if (!$removed) break;
                $candidate['route_exception_reason'] = trim((string)($candidate['route_exception_reason'] ?? '') . ' Máx. visitas/dia aplicado como limite duro.');
                if ($bestDest !== null) {
                    $days[$bestDest]['items'][] = $candidate;
                    self::recalc_operational_day($days[$bestDest], $options, $targetWorkMin, $lunchMin);
                } else {
                    $candidate['overflow_conflict'] = true;
                    $candidate['hard_cap_unassigned'] = true;
                    $candidate['route_exception_reason'] = trim((string)($candidate['route_exception_reason'] ?? '') . ' Sem dia disponível sem exceder o máximo configurado.');
                    $unassigned[] = $candidate;
                }
                self::recalc_operational_day($day, $options, $targetWorkMin, $lunchMin);
            }
        }
        unset($day);
        return $days;
    }

    private static function rebalance_weekly_even_stop_distribution_v2(array $days, array &$unassigned, array $options, int $maxStops, int $targetWorkMin, int $lunchMin): array {
        if (!$days) return $days;
        $maxStops = max(1, min(20, $maxStops));
        $target = self::resolve_target_stops_per_day($options, $maxStops);
        if ($target <= 0) {
            $total = self::count_items_in_days($days);
            $open = max(1, self::count_open_days($days));
            $target = max(1, min($maxStops, (int)ceil($total / $open)));
        }
        $idealMin = max(1, min($target, (int)floor($maxStops * 0.70)));
        $byWeek = [];
        foreach ($days as $idx => $day) {
            $wk = (string)($day['week_key'] ?? '');
            if ($wk === '') $wk = 'all';
            $byWeek[$wk][] = (int)$idx;
            self::recalc_operational_day($days[$idx], $options, $targetWorkMin, $lunchMin);
        }

        foreach ($byWeek as $weekKey => $indexes) {
            if (count($indexes) < 2) continue;
            for ($pass = 0; $pass < 36; $pass++) {
                $heavyIdx = null;
                $lightIdx = null;
                $heavyScore = -INF;
                $lightScore = INF;
                foreach ($indexes as $idx) {
                    $stops = (int)($days[$idx]['stops'] ?? count((array)($days[$idx]['items'] ?? [])));
                    $work = (int)($days[$idx]['work_min'] ?? 0) + (int)($days[$idx]['lunch_min'] ?? 0);
                    $scoreHeavy = ($stops * 1000) + $work;
                    $scoreLight = ($stops * 1000) + $work;
                    if ($stops > $target && $scoreHeavy > $heavyScore) { $heavyScore = $scoreHeavy; $heavyIdx = $idx; }
                    if ($stops < $idealMin && $scoreLight < $lightScore) { $lightScore = $scoreLight; $lightIdx = $idx; }
                }
                if ($heavyIdx === null || $lightIdx === null || $heavyIdx === $lightIdx) break;
                if ((int)($days[$lightIdx]['stops'] ?? 0) >= $maxStops) break;

                $sourceItems = array_values((array)($days[$heavyIdx]['items'] ?? []));
                if (!$sourceItems) break;
                $best = null;
                $bestScore = PHP_FLOAT_MAX;
                foreach ($sourceItems as $ix => $candidate) {
                    $candidate = (array)$candidate;
                    if (!empty($candidate['locked_date']) || !empty($candidate['hard_fixed_date'])) continue;
                    $targetWeek = (string)($candidate['target_week_key'] ?? '');
                    if ($targetWeek !== '' && $targetWeek !== (string)($days[$lightIdx]['week_key'] ?? '')) continue;
                    if (!self::can_place_visit_on_day((array)$days[$lightIdx], $candidate, $options, $maxStops, $targetWorkMin, $lunchMin)) continue;

                    $destItems = array_values(array_merge((array)($days[$lightIdx]['items'] ?? []), [$candidate]));
                    $srcItems = $sourceItems;
                    array_splice($srcItems, $ix, 1);
                    $destKm = self::estimate_day_distance_km_fast($destItems, (array)($days[$lightIdx]['start_point'] ?? []), (array)($days[$lightIdx]['end_point'] ?? []));
                    $srcKm = self::estimate_day_distance_km_fast($srcItems, (array)($days[$heavyIdx]['start_point'] ?? []), (array)($days[$heavyIdx]['end_point'] ?? []));
                    $score = $destKm + $srcKm;
                    $candZone = self::task_zone_key($candidate);
                    $destZone = self::dominant_zone_for_day((array)$days[$lightIdx]);
                    $srcZone = self::dominant_zone_for_day((array)$days[$heavyIdx]);
                    if ($candZone !== '' && $destZone !== '' && $candZone !== $destZone) $score += 180.0;
                    if ($candZone !== '' && $srcZone !== '' && $candZone === $srcZone) $score += 40.0;
                    $candCorr = (string)($candidate['route_corridor_key'] ?? '');
                    $destCorr = self::dominant_corridor_for_day((array)$days[$lightIdx]);
                    if ($candCorr !== '' && $destCorr !== '' && $candCorr !== $destCorr) $score += 220.0;
                    $priority = (int)($candidate['priority'] ?? 0);
                    $score += max(0, $priority) * 0.4;
                    if ($score < $bestScore) { $bestScore = $score; $best = ['ix' => $ix, 'item' => $candidate]; }
                }
                if ($best === null) break;
                array_splice($days[$heavyIdx]['items'], (int)$best['ix'], 1);
                $moved = (array)$best['item'];
                $moved['route_exception_reason'] = trim((string)($moved['route_exception_reason'] ?? '') . ' Day Balancer v2: movida dentro da mesma semana para nivelar carga sem exceder limite diário.');
                $days[$lightIdx]['items'][] = $moved;
                self::recalc_operational_day($days[$heavyIdx], $options, $targetWorkMin, $lunchMin);
                self::recalc_operational_day($days[$lightIdx], $options, $targetWorkMin, $lunchMin);
            }
        }
        return $days;
    }

    private static function build_plan_diagnostics(array $days, array $unassigned, array $options, int $maxStops, int $targetWorkMin, int $lunchMin): array {
        $maxStops = max(1, min(20, $maxStops));
        $target = self::resolve_target_stops_per_day($options, $maxStops);
        if ($target <= 0) {
            $target = max(1, min($maxStops, (int)ceil(self::count_items_in_days($days) / max(1, self::count_open_days($days)))));
        }
        $idealMin = max(1, min($target, (int)floor($maxStops * 0.70)));
        $dayScores = [];
        $overCap = 0;
        $underUsed = 0;
        $longRoutes = 0;
        $mixedZones = 0;
        $empty = 0;
        $zoneCounts = [];
        $corridorCounts = [];
        $reasons = [];
        foreach ($days as $day) {
            $items = array_values((array)($day['items'] ?? []));
            $stops = count($items);
            if ($stops === 0) $empty++;
            if ($stops > $maxStops) $overCap++;
            if ($stops > 0 && $stops < $idealMin) $underUsed++;
            $km = self::estimate_day_distance_km_fast($items, (array)($day['start_point'] ?? []), (array)($day['end_point'] ?? []));
            if ($km >= 220.0) $longRoutes++;
            $zones = [];
            $corrs = [];
            foreach ($items as $it) {
                $z = self::task_zone_key((array)$it);
                if ($z !== '') $zones[$z] = ($zones[$z] ?? 0) + 1;
                $c = (string)($it['route_corridor_key'] ?? '');
                if ($c !== '') $corrs[$c] = ($corrs[$c] ?? 0) + 1;
            }
            if (count($zones) > 2 || count($corrs) > 2) $mixedZones++;
            foreach ($zones as $z => $n) $zoneCounts[$z] = ($zoneCounts[$z] ?? 0) + $n;
            foreach ($corrs as $c => $n) $corridorCounts[$c] = ($corridorCounts[$c] ?? 0) + $n;
            $loadPct = $maxStops > 0 ? round(($stops / $maxStops) * 100) : 0;
            $type = self::classify_route_day_type($items, $km);
            $score = 100;
            if ($stops > $maxStops) $score -= 35;
            if ($stops > 0 && $stops < $idealMin) $score -= 12;
            if ($km >= 220.0) $score -= 14;
            if (count($zones) > 2) $score -= 10;
            if ((int)($day['overtime_min'] ?? 0) > 0) $score -= 8;
            $dayScores[] = [
                'date' => (string)($day['date'] ?? ''),
                'label' => (string)($day['label'] ?? ''),
                'stops' => $stops,
                'load_pct' => $loadPct,
                'km' => round($km, 1),
                'type' => $type,
                'dominant_zone' => self::human_zone_label(self::dominant_zone_for_day((array)$day)),
                'dominant_corridor' => self::human_corridor_label(self::dominant_corridor_for_day((array)$day)),
                'score' => max(0, min(100, $score)),
            ];
        }
        foreach ($unassigned as $task) {
            $reason = (string)($task['route_exception_reason'] ?? 'Sem encaixe operacional');
            if ($reason === '') $reason = 'Sem encaixe operacional';
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        }
        arsort($zoneCounts);
        arsort($corridorCounts);
        arsort($reasons);
        $messages = [];
        if ($overCap > 0) $messages[] = $overCap . ' dia(s) acima do máximo configurado. Deve ser tratado como bloqueio.';
        if ($underUsed > 0) $messages[] = $underUsed . ' dia(s) com pouca carga. Podem existir restrições de semana, zona ou horas a impedir melhor encaixe.';
        if ($longRoutes > 0) $messages[] = $longRoutes . ' dia(s) com rota longa. Validar interior/dispersos ou ponto de partida.';
        if ($mixedZones > 0) $messages[] = $mixedZones . ' dia(s) misturam várias zonas/corredores. Pode haver PDVs isolados.';
        if ($unassigned) $messages[] = count($unassigned) . ' visita(s) ficaram por atribuir por limites duros ou falta de capacidade.';
        if (!$messages) $messages[] = 'Plano equilibrado dentro dos limites definidos.';
        return [
            'target_stops' => $target,
            'ideal_min_stops' => $idealMin,
            'max_stops' => $maxStops,
            'open_days' => count($days),
            'empty_days' => $empty,
            'underused_days' => $underUsed,
            'over_cap_days' => $overCap,
            'long_route_days' => $longRoutes,
            'mixed_zone_days' => $mixedZones,
            'top_zones' => array_slice($zoneCounts, 0, 5, true),
            'top_corridors' => array_slice($corridorCounts, 0, 5, true),
            'unassigned_reasons' => array_slice($reasons, 0, 5, true),
            'messages' => $messages,
            'day_scores' => $dayScores,
        ];
    }

    private static function classify_route_day_type(array $items, float $km): string {
        $stops = count($items);
        if ($stops <= 0) return 'Sem rota';
        if ($km >= 220.0) return 'Longa/interior';
        if ($km >= 120.0) return 'Regional';
        if ($stops >= 7 && $km < 90.0) return 'Urbana/densa';
        if ($stops <= 3 && $km >= 80.0) return 'Dispersa';
        return 'Equilibrada';
    }

    private static function human_zone_label(string $key): string {
        $key = trim($key);
        if ($key === '') return 'Sem zona dominante';
        return str_replace(['|','_'], [' / ',' '], $key);
    }

    private static function human_corridor_label(string $key): string {
        $key = trim($key);
        if ($key === '') return 'Sem corredor dominante';
        return ucfirst(str_replace(['|','_'], [' / ',' '], $key));
    }

    private static function count_items_in_days(array $days): int {
        $n = 0;
        foreach ($days as $day) $n += count((array)($day['items'] ?? []));
        return $n;
    }

    private static function count_open_days(array $days): int {
        $n = 0;
        foreach ($days as $day) $n += 1;
        return max(1, $n);
    }

    private static function dominant_corridor_for_day(array $day): string {
        $keys = [];
        foreach ((array)($day['items'] ?? []) as $it) {
            $k = (string)($it['route_corridor_key'] ?? '');
            if ($k !== '') $keys[$k] = ($keys[$k] ?? 0) + 1;
        }
        if (!$keys) return '';
        arsort($keys);
        return (string)array_key_first($keys);
    }

    private static function operational_day_diagnostic(array $day, array $options = [], ?float $distanceKm = null): array {
        $options = self::normalize_plan_options($options);
        $stops = (int)($day['stops'] ?? count((array)($day['items'] ?? [])));
        $workMin = (int)($day['work_min'] ?? round((float)($day['travel_min'] ?? 0) + (float)($day['visit_min'] ?? 0))) + (int)($day['lunch_min'] ?? 0);
        $travelMin = (int)round((float)($day['travel_min'] ?? 0));
        $targetWork = max(60, (int)($options['work_minutes'] ?? 480));
        $maxStops = max(1, min(20, (int)($options['max_stops_per_day'] ?? 12)));
        $baseTarget = self::resolve_target_stops_per_day($options, $maxStops);
        if ($baseTarget <= 0) $baseTarget = max(1, min($maxStops, 6));
        $km = $distanceKm !== null ? max(0.0, (float)$distanceKm) : self::estimate_plan_day_distance_km($day, $options);
        $dynamicTarget = self::distance_capacity_stops_for_day($day, $baseTarget, $maxStops, $options, true);

        $warnings = [];
        $score = 100;
        $level = 'Ótimo';

        if ($stops <= 0) {
            return ['score' => 0, 'level' => 'Sem rota', 'target_stops' => $dynamicTarget, 'warnings' => ['Dia sem visitas planeadas.']];
        }
        if ($stops > $maxStops) { $score -= 35; $warnings[] = 'Excede o máximo de visitas/dia.'; }
        if ($workMin > $targetWork + 150) { $score -= 35; $warnings[] = 'Carga crítica, ultrapassa o limite operacional com horas extra.'; }
        elseif ($workMin > $targetWork) { $score -= 18; $warnings[] = 'Dia pesado, acima das horas úteis configuradas.'; }
        if ($stops > $dynamicTarget) { $score -= min(28, ($stops - $dynamicTarget) * 9); $warnings[] = 'Acima do target ajustado pela distância, recomenda aliviar lojas.'; }
        $geoMetrics = is_array($day['geo_metrics'] ?? null) ? (array)$day['geo_metrics'] : self::geo_route_metrics_for_items((array)($day['items'] ?? []), (array)($day['start_point'] ?? []), (array)($day['end_point'] ?? []));
        $densityScore = (float)($geoMetrics['density_score'] ?? 0);
        $dispersionScore = (float)($geoMetrics['dispersion_score'] ?? 0);
        $localKm = (float)($geoMetrics['local_km'] ?? 0);
        if ($km >= 180 && $densityScore >= 0.45 && $localKm <= 45.0) { $score -= 4; $warnings[] = 'Rota longa mas densa, carga aceitável se o tempo total estiver controlado.'; }
        elseif ($km >= 180) { $score -= 15; $warnings[] = 'Rota muito longa, carga reduzida faz sentido.'; }
        elseif ($km >= 120 && $densityScore >= 0.45 && $localKm <= 35.0) { $score -= 3; $warnings[] = 'Rota longa com miolo geográfico denso.'; }
        elseif ($km >= 120) { $score -= 9; $warnings[] = 'Rota longa, evitar encher demasiado.'; }
        if ($km >= 90 && $densityScore < 0.22 && $dispersionScore >= 18.0) { $score -= 14; $warnings[] = 'Rota longa e dispersa, reduzir lojas ou dividir zona.'; }
        if ($stops === 1 && $km < 220) { $score -= 22; $warnings[] = 'Só 1 visita, procurar loja compatível no corredor ida/volta.'; }
        if ($stops < max(2, $dynamicTarget - 2) && $km < 100) { $score -= 10; $warnings[] = 'Dia subaproveitado para a distância prevista.'; }
        if (!empty($day['overflow_count'])) { $score -= 18; $warnings[] = 'Tem exceção operacional/overflow.'; }

        $score = max(0, min(100, (int)round($score)));
        if ($score < 45 || $workMin > $targetWork + 150 || $stops > $maxStops) $level = 'Crítico';
        elseif ($score < 68 || $workMin > $targetWork || $stops > $dynamicTarget) $level = 'Pesado';
        elseif ($stops === 1 || ($stops < max(2, $dynamicTarget - 2) && $km < 100)) $level = 'Subaproveitado';
        elseif ($score < 84) $level = 'Aceitável';

        if (!$warnings) $warnings[] = 'Dia equilibrado face a distância, carga e capacidade.';
        return ['score' => $score, 'level' => $level, 'target_stops' => $dynamicTarget, 'warnings' => $warnings];
    }

    private static function finalize_planned_day(array &$day, int $targetWorkMin, int $lunchMin): void {
        $items = (array)($day['items'] ?? []);
        $day['return_min'] = 0.0;
        $workMin = (int) round((float)($day['travel_min'] ?? 0) + (float)($day['visit_min'] ?? 0));
        $overtimeMin = max(0, $workMin - $targetWorkMin);
        $lunchToApply = !empty($day['items']) ? $lunchMin : 0;
        $overflowCount = 0;
        foreach ($items as $it) {
            if (!empty($it['overflow_forced'])) $overflowCount++;
        }
        $totalWorkWithLunch = $workMin + $lunchToApply;
        $overtimeMin = max(0, $totalWorkWithLunch - $targetWorkMin);
        $day['work_min'] = $workMin;
        $day['lunch_min'] = $lunchToApply;
        $day['overtime_min'] = $overtimeMin;
        $day['overflow_count'] = $overflowCount;
        $day['travel_human'] = self::human_minutes((int)round((float)($day['travel_min'] ?? 0)));
        $day['visit_human'] = self::human_minutes((int)($day['visit_min'] ?? 0));
        $day['work_human'] = self::human_minutes($workMin);
        $day['lunch_human'] = self::human_minutes($lunchToApply);
        $day['overtime_human'] = self::human_minutes($overtimeMin);
        $day['total_human'] = self::human_minutes($workMin + $lunchToApply);
        $geoOptions = is_array($day['start_point'] ?? null) || is_array($day['end_point'] ?? null) ? [
            'start_point' => (array)($day['start_point'] ?? []),
            'end_point' => (array)($day['end_point'] ?? []),
        ] : [];
        $day['geo_metrics'] = self::geo_route_metrics_for_items($items, (array)($geoOptions['start_point'] ?? []), (array)($geoOptions['end_point'] ?? []));
        $dayExtraMin = !empty($day['allow_overtime']) ? max(0, min(150, (int)($day['extra_minutes'] ?? 0))) : 0;
        $day['can_add_store'] = ((int)($day['stops'] ?? 0) < 20) && (($workMin + $lunchToApply + 30) <= min($targetWorkMin + $dayExtraMin, 600)) && $overflowCount === 0;
    }

    private static function build_preview_days(array $plannedDays, array $allDates, string $labelPrefix = 'Dia'): array {
        $preview = [];
        $byDate = [];
        foreach ($plannedDays as $day) {
            $date = (string)($day['date'] ?? '');
            if ($date !== '') $byDate[$date] = $day;
        }
        foreach (array_values($allDates) as $idx => $date) {
            $date = (string)$date;
            if ($date === '') continue;
            if (isset($byDate[$date])) {
                $preview[] = $byDate[$date];
                continue;
            }
            $labelNumber = $idx + 1;
            $dateTsForLabel = strtotime($date);
            if (strpos((string)$labelPrefix, 'Semana ') === 0 && $dateTsForLabel) {
                $labelNumber = (int) date('N', $dateTsForLabel);
            }
            $dayLabel = strpos((string)$labelPrefix, 'Semana ') === 0 ? ($labelPrefix . '-' . $labelNumber) : ($labelPrefix . ' · ' . $labelNumber);
            $preview[] = [
                'label' => $dayLabel,
                'date' => $date,
                'items' => [],
                'travel_min' => 0,
                'visit_min' => 0,
                'stops' => 0,
                'allow_overtime' => false,
                'extra_minutes' => 0,
                'return_min' => 0,
                'work_min' => 0,
                'lunch_min' => 0,
                'overtime_min' => 0,
                'travel_human' => self::human_minutes(0),
                'visit_human' => self::human_minutes(0),
                'work_human' => self::human_minutes(0),
                'lunch_human' => self::human_minutes(0),
                'overtime_human' => self::human_minutes(0),
                'total_human' => self::human_minutes(0),
                'can_add_store' => true,
                'is_empty_slot' => true,
            ];
        }
        usort($preview, function($a, $b){ return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')); });
        return $preview;
    }

    private static function summarize_days(array $days, array $unassigned = [], int $periodDays = 0): array {
        $summary = ['stops'=>0,'travel_min'=>0,'visit_min'=>0,'work_min'=>0,'lunch_min'=>0,'overtime_min'=>0,'unassigned_count'=>count($unassigned),'period_days'=>$periodDays,'overflow_count'=>0,'overflow_days'=>0];
        foreach ($days as $d) {
            $summary['stops'] += (int)($d['stops'] ?? 0);
            $summary['travel_min'] += (int)round($d['travel_min'] ?? 0);
            $summary['visit_min'] += (int)($d['visit_min'] ?? 0);
            $summary['work_min'] += (int)($d['work_min'] ?? round(($d['travel_min'] ?? 0) + ($d['visit_min'] ?? 0)));
            $summary['lunch_min'] += (int)($d['lunch_min'] ?? 0);
            $summary['overtime_min'] += (int)($d['overtime_min'] ?? 0);
            $summary['overflow_count'] += (int)($d['overflow_count'] ?? 0);
            if ((int)($d['overflow_count'] ?? 0) > 0 || (int)($d['overtime_min'] ?? 0) > 0) $summary['overflow_days']++;
        }
        $summary['travel_human'] = self::human_minutes($summary['travel_min']);
        $summary['visit_human'] = self::human_minutes($summary['visit_min']);
        $summary['work_human'] = self::human_minutes($summary['work_min']);
        $summary['lunch_human'] = self::human_minutes($summary['lunch_min']);
        $summary['overtime_human'] = self::human_minutes($summary['overtime_min']);
        $summary['total_human'] = self::human_minutes($summary['work_min'] + $summary['lunch_min']);
        return $summary;
    }

    private static function same_zone_score($a, $b): bool {
        if (!is_array($a) || !is_array($b)) return false;
        return self::task_zone_key((array)$a) !== '' && self::task_zone_key((array)$a) === self::task_zone_key((array)$b);
    }

    private static function find_best_seed_index(array $taskPool, array $existingDays, array $startPoint = []): int {
        if (!$taskPool) return 0;
        $zoneLoad = [];
        foreach ($existingDays as $day) {
            foreach ((array)($day['items'] ?? []) as $item) {
                $zone = self::task_zone_key($item);
                if ($zone === '') continue;
                $zoneLoad[$zone] = ($zoneLoad[$zone] ?? 0) + 1;
            }
        }
        $bestIndex = 0;
        $bestScore = PHP_FLOAT_MAX;
        foreach (array_values($taskPool) as $i => $task) {
            $zone = self::task_zone_key($task);
            $score = (float)($zoneLoad[$zone] ?? 0) * 50;
            if (is_numeric($startPoint['lat'] ?? null) && is_numeric($startPoint['lng'] ?? null) && is_numeric($task['lat'] ?? null) && is_numeric($task['lng'] ?? null)) {
                $score += self::haversine_km((float)$startPoint['lat'], (float)$startPoint['lng'], (float)$task['lat'], (float)$task['lng']);
            }
            $score -= (float)((int)($task['priority'] ?? 0)) * 3;
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestIndex = $i;
            }
        }
        return $bestIndex;
    }


    private static function task_base_axis_key(array $task, array $startPoint = []): string {
        if (!self::point_has_coordinates($task) || !self::point_has_coordinates($startPoint)) {
            return self::task_macro_zone_key($task);
        }

        $dLat = (float)$task['lat'] - (float)$startPoint['lat'];
        $dLng = (float)$task['lng'] - (float)$startPoint['lng'];
        $northSouth = abs($dLat) < 0.035 ? 'eixo' : ($dLat > 0 ? 'norte' : 'sul');
        $eastWest = abs($dLng) < 0.045 ? 'eixo' : ($dLng > 0 ? 'interior' : 'litoral');

        $macro = self::task_macro_zone_key($task);
        if (strpos($macro, 'interior') !== false) $eastWest = 'interior';
        elseif (strpos($macro, 'litoral') !== false) $eastWest = 'litoral';

        return trim('base|' . $northSouth . '|' . $eastWest);
    }

    private static function route_corridor_opposition_penalty(string $dominantKey, string $candidateKey): float {
        $a = strtolower($dominantKey);
        $b = strtolower($candidateKey);
        $penalty = 0.0;
        if ((strpos($a, 'norte') !== false && strpos($b, 'sul') !== false) || (strpos($a, 'sul') !== false && strpos($b, 'norte') !== false)) {
            $penalty += 1450.0;
        }
        if ((strpos($a, 'interior') !== false && strpos($b, 'litoral') !== false) || (strpos($a, 'litoral') !== false && strpos($b, 'interior') !== false)) {
            $penalty += 760.0;
        }
        return $penalty;
    }

    private static function task_route_corridor_key(array $task, array $startPoint = [], array $endPoint = []): string {
        if (!self::point_has_coordinates($task)) return self::task_macro_zone_key($task);
        $macro = self::task_macro_zone_key($task);
        if (!self::point_has_coordinates($startPoint)) return $macro;
        if (!self::point_has_coordinates($endPoint)) return self::task_base_axis_key($task, $startPoint);

        $sx = (float)$startPoint['lng'];
        $sy = (float)$startPoint['lat'];
        $ex = (float)$endPoint['lng'];
        $ey = (float)$endPoint['lat'];
        $px = (float)$task['lng'];
        $py = (float)$task['lat'];
        $vx = $ex - $sx;
        $vy = $ey - $sy;
        $len2 = ($vx * $vx) + ($vy * $vy);
        if ($len2 <= 0.000001) return self::task_base_axis_key($task, $startPoint);

        $t = (($px - $sx) * $vx + ($py - $sy) * $vy) / $len2;
        $segment = 'meio';
        if ($t < 0.22) $segment = 'partida';
        elseif ($t > 0.78) $segment = 'chegada';

        $cross = ($vx * ($py - $sy)) - ($vy * ($px - $sx));
        $side = abs($cross) < 0.05 ? 'eixo' : ($cross > 0 ? 'norte' : 'sul');

        $horizontal = '';
        if (strpos($macro, 'interior') !== false) $horizontal = 'interior';
        elseif (strpos($macro, 'litoral') !== false) $horizontal = 'litoral';
        else $horizontal = ((float)$task['lng'] < -8.15) ? 'litoral' : 'interior';

        return trim($segment . '|' . $side . '|' . $horizontal);
    }

    private static function task_route_corridor_position(array $task, array $startPoint = [], array $endPoint = []): float {
        if (!self::point_has_coordinates($task) || !self::point_has_coordinates($startPoint) || !self::point_has_coordinates($endPoint)) {
            return self::base_distance_km_for_item($task, $startPoint, $endPoint);
        }
        $sx = (float)$startPoint['lng'];
        $sy = (float)$startPoint['lat'];
        $ex = (float)$endPoint['lng'];
        $ey = (float)$endPoint['lat'];
        $px = (float)$task['lng'];
        $py = (float)$task['lat'];
        $vx = $ex - $sx;
        $vy = $ey - $sy;
        $len2 = ($vx * $vx) + ($vy * $vy);
        if ($len2 <= 0.000001) return 0.0;
        $t = (($px - $sx) * $vx + ($py - $sy) * $vy) / $len2;
        return round(max(-1.0, min(2.0, $t)), 4);
    }

    private static function task_zone_key(array $task): string {
        return GeoPT::cluster_key((string)($task['city'] ?? ''), (string)($task['district'] ?? ''), (string)($task['address'] ?? ''));
    }

    private static function task_macro_zone_key(array $task): string {
        $district = self::normalize_ascii_key((string)($task['district'] ?? ''));
        $city = self::normalize_ascii_key((string)($task['city'] ?? ''));
        $lng = is_numeric($task['lng'] ?? null) ? (float)$task['lng'] : null;

        $north = ['braga','braganca','porto','viana do castelo','vila real'];
        $centre = ['aveiro','castelo branco','coimbra','guarda','leiria','viseu'];
        $lisbon = ['lisboa','santarem','setubal'];
        $south = ['beja','evora','faro','portalegre'];
        $islands = ['acores','madeira','ilha da madeira','ilha de sao miguel','ilha terceira'];
        $interior = ['braganca','vila real','viseu','guarda','castelo branco','portalegre','evora','beja'];
        $coast = ['viana do castelo','braga','porto','aveiro','coimbra','leiria','lisboa','setubal','faro'];

        $base = $district !== '' ? $district : $city;
        if ($base === '') return '';
        if (in_array($base, $islands, true)) return 'ilhas';

        $vertical = 'centro';
        if (in_array($base, $north, true)) $vertical = 'norte';
        elseif (in_array($base, $south, true)) $vertical = 'sul';
        elseif (in_array($base, $lisbon, true)) $vertical = 'centro-litoral';
        elseif (in_array($base, $centre, true)) $vertical = 'centro';

        $horizontal = '';
        if (in_array($base, $interior, true)) $horizontal = 'interior';
        elseif (in_array($base, $coast, true)) $horizontal = 'litoral';
        elseif ($lng !== null) $horizontal = ($lng < -8.15 ? 'litoral' : 'interior');

        return trim($vertical . ($horizontal !== '' ? '|' . $horizontal : ''));
    }

    private static function normalize_ascii_key(string $value): string {
        $value = strtolower(trim($value));
        if ($value === '') return '';
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') $value = $converted;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim((string)$value);
    }

    private static function pick_reinforcement_zone(array $tasks): string {
        if (!$tasks) return '';
        $counts = [];
        foreach ($tasks as $task) {
            $district = trim((string)($task['district'] ?? ''));
            $city = trim((string)($task['city'] ?? ''));
            $label = $district !== '' && $city !== '' ? $district . ' / ' . $city : ($district !== '' ? $district : ($city !== '' ? $city : trim((string)($task['address'] ?? ''))));
            if ($label === '') continue;
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        if (!$counts) return '';
        arsort($counts);
        return (string)array_key_first($counts);
    }

    private static function build_reinforcement_summary(array $days, array $unassigned, int $targetWorkMin, int $periodDays): array {
        $summary = self::summarize_days($days, $unassigned, $periodDays);
        $peakDay = null;
        $availableOvertimeMinutes = 0;
        $daysOverMax = 0;
        $overloadedDays = 0;
        foreach ($days as $day) {
            if ($peakDay === null || (int)($day['overtime_min'] ?? 0) > (int)($peakDay['overtime_min'] ?? 0)) {
                $peakDay = $day;
            }
            $allowedExtra = !empty($day['allow_overtime']) ? max(0, min(150, (int)($day['extra_minutes'] ?? 0))) : 0;
            $availableOvertimeMinutes += $allowedExtra;
            if ((int)($day['overtime_min'] ?? 0) > 0) $overloadedDays++;
            if (((int)($day['work_min'] ?? 0) + (int)($day['lunch_min'] ?? 0)) > min($targetWorkMin + $allowedExtra, 600)) $daysOverMax++;
        }
        $zoneSource = $unassigned;
        if (!$zoneSource && is_array($peakDay)) $zoneSource = (array)($peakDay['items'] ?? []);
        $zone = self::pick_reinforcement_zone($zoneSource);
        $requiredExtraMinutes = 0;
        foreach ($unassigned as $task) {
            $requiredExtraMinutes += (int)($task['visit_duration_min'] ?? 45) + 20;
        }
        $requiredExtraMinutes += max(0, (int)$summary['overtime_min'] - $availableOvertimeMinutes);
        $recommended = !empty($unassigned) || $daysOverMax > 0;

        return [
            'recommended' => $recommended,
            'days_over_max' => $daysOverMax,
            'zone' => $zone,
            'normal_minutes' => $targetWorkMin * max(1, $periodDays),
            'normal_human' => self::human_minutes($targetWorkMin * max(1, $periodDays)),
            'overtime_minutes' => (int)$summary['overtime_min'],
            'overtime_human' => self::human_minutes((int)$summary['overtime_min']),
            'max_overtime_per_day_minutes' => 150,
            'max_overtime_per_day_human' => self::human_minutes(150),
            'available_overtime_minutes' => $availableOvertimeMinutes,
            'available_overtime_human' => self::human_minutes($availableOvertimeMinutes),
            'overloaded_days' => $overloadedDays,
            'unassigned_count' => count($unassigned),
            'unassigned_minutes' => max(0, $requiredExtraMinutes),
            'unassigned_human' => self::human_minutes(max(0, $requiredExtraMinutes)),
            'second_member_share_minutes' => (int)max($requiredExtraMinutes, 0),
            'second_member_share_human' => self::human_minutes((int)max($requiredExtraMinutes, 0)),
            'ratio_vs_normal' => $targetWorkMin > 0 && $periodDays > 0 ? round(((int)$summary['overtime_min'] / ($targetWorkMin * $periodDays)) * 100, 1) : 0,
        ];
    }
    private static function merge_reinforcement_summaries(array $weeks, array $unassigned, array $summary, int $periodDays, int $targetWorkMin): array {
        if (!$weeks) return self::build_reinforcement_summary([], $unassigned, $targetWorkMin, $periodDays);
        $zoneCounts = [];
        $overloadedDays = 0;
        $daysOverMax = 0;
        $availableOvertimeMinutes = 0;
        foreach ($weeks as $week) {
            if (!empty($week['zone'])) $zoneCounts[$week['zone']] = ($zoneCounts[$week['zone']] ?? 0) + 1;
            $overloadedDays += (int)($week['overloaded_days'] ?? 0);
            $daysOverMax += (int)($week['days_over_max'] ?? 0);
            $availableOvertimeMinutes += (int)($week['available_overtime_minutes'] ?? 0);
        }
        arsort($zoneCounts);
        $zone = $zoneCounts ? (string)array_key_first($zoneCounts) : self::pick_reinforcement_zone($unassigned);
        $requiredExtraMinutes = 0;
        foreach ($unassigned as $task) {
            $requiredExtraMinutes += (int)($task['visit_duration_min'] ?? 45) + 20;
        }
        $requiredExtraMinutes += max(0, (int)($summary['overtime_min'] ?? 0) - $availableOvertimeMinutes);
        $recommended = !empty($unassigned) || $daysOverMax > 0;

        return [
            'recommended' => $recommended,
            'days_over_max' => $daysOverMax,
            'zone' => $zone,
            'normal_minutes' => $targetWorkMin * max(1, $periodDays),
            'normal_human' => self::human_minutes($targetWorkMin * max(1, $periodDays)),
            'overtime_minutes' => (int)($summary['overtime_min'] ?? 0),
            'overtime_human' => self::human_minutes((int)($summary['overtime_min'] ?? 0)),
            'max_overtime_per_day_minutes' => 150,
            'max_overtime_per_day_human' => self::human_minutes(150),
            'available_overtime_minutes' => $availableOvertimeMinutes,
            'available_overtime_human' => self::human_minutes($availableOvertimeMinutes),
            'overloaded_days' => $overloadedDays,
            'unassigned_count' => count($unassigned),
            'unassigned_minutes' => max(0, $requiredExtraMinutes),
            'unassigned_human' => self::human_minutes(max(0, $requiredExtraMinutes)),
            'second_member_share_minutes' => max(0, $requiredExtraMinutes),
            'second_member_share_human' => self::human_minutes(max(0, $requiredExtraMinutes)),
            'ratio_vs_normal' => $targetWorkMin > 0 && $periodDays > 0 ? round(((int)($summary['overtime_min'] ?? 0) / ($targetWorkMin * $periodDays)) * 100, 1) : 0,
        ];
    }

    private static function stream_plan_csv(array $project, array $plan, string $scope, string $base_date, string $holiday_country = 'pt', array $simulation_options = []): void {
        $simulation_options = self::normalize_plan_options($simulation_options ?: (array)($plan['options'] ?? []));
        $filename = 'routespro-plan-' . sanitize_title($project['name'] ?? 'campanha') . '-' . $scope . '-' . date('Ymd', strtotime($base_date ?: date('Y-m-d'))) . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $out = fopen('php://output', 'w');
        if (!$out) exit;
        fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, [get_bloginfo('name')]);
        fputcsv($out, ['Campanha', (string)($project['name'] ?? '')]);
        fputcsv($out, ['Plano', $scope]);
        fputcsv($out, ['Data base', $base_date]);
        fputcsv($out, ['Feriados', strtoupper($holiday_country) === 'ES' ? 'Espanha' : 'Portugal']);
        fputcsv($out, ['Folgas automáticas', 'Fins de semana e feriados']);
        fputcsv($out, ['Máx. visitas/dia', (int)$simulation_options['max_stops_per_day']]);
        fputcsv($out, ['Média alvo/dia', self::target_stops_label($simulation_options)]);
        fputcsv($out, ['Sensibilidade distância', self::normalize_distance_sensitivity((string)($simulation_options['distance_sensitivity'] ?? 'normal'))]);
        fputcsv($out, ['Horas úteis', self::human_minutes((int)$simulation_options['work_minutes'])]);
        fputcsv($out, ['Almoço', self::human_minutes((int)$simulation_options['lunch_minutes'])]);
        fputcsv($out, ['Permitir fora do horário, geral', !empty($simulation_options['allow_overtime']) ? 'Sim' : 'Não']);
        fputcsv($out, ['Permitir visitas extra', !empty($simulation_options['allow_extra_visits']) ? 'Sim' : 'Não']);
        fputcsv($out, ['Horas adicionais, geral', self::human_minutes((int)($simulation_options['overtime_extra_minutes'] ?? 0))]);
        $dayExtras = []; foreach ((array)($simulation_options['daily_overtime_minutes'] ?? []) as $date => $mins) { $dayExtras[] = $date . ' (' . self::human_minutes((int)$mins) . ')'; }
        fputcsv($out, ['Dias com fora de horas', implode(' | ', (array)($simulation_options['daily_overtime_dates'] ?? []))]);
        fputcsv($out, ['Horas adicionais por dia', implode(' | ', $dayExtras)]);
        fputcsv($out, ['Ponto de partida', (string)($simulation_options['start_point']['address'] ?? '')]);
        fputcsv($out, ['Partida lat', (string)($simulation_options['start_point']['lat'] ?? '')]);
        fputcsv($out, ['Partida lng', (string)($simulation_options['start_point']['lng'] ?? '')]);
        fputcsv($out, ['Bloquear partida', !empty($simulation_options['lock_start_point']) ? 'Sim' : 'Não']);
        fputcsv($out, ['Ponto de chegada', (string)($simulation_options['end_point']['address'] ?? '')]);
        fputcsv($out, ['Chegada lat', (string)($simulation_options['end_point']['lat'] ?? '')]);
        fputcsv($out, ['Chegada lng', (string)($simulation_options['end_point']['lng'] ?? '')]);
        fputcsv($out, ['Bloquear chegada', !empty($simulation_options['lock_end_point']) ? 'Sim' : 'Não']);
        $excludedDays = (array)($plan['excluded_days'] ?? []);
        if ($excludedDays) {
            $parts = [];
            foreach ($excludedDays as $date => $reason) $parts[] = $date . ' (' . $reason . ')';
            fputcsv($out, ['Datas excluídas', implode(' | ', $parts)]);
        }
        $reinforcement = (array)($plan['reinforcement'] ?? []);
        if ($reinforcement) {
            fputcsv($out, ['Reforço sugerido', !empty($reinforcement['recommended']) ? 'Sim' : 'Não']);
            fputcsv($out, ['Zona prioritária reforço', (string)($reinforcement['zone'] ?? '')]);
            fputcsv($out, ['Horas extra totais estimadas', (string)($reinforcement['overtime_human'] ?? '0m')]);
            fputcsv($out, ['Carga sugerida para 2.º membro', (string)($reinforcement['second_member_share_human'] ?? '0m')]);
            fputcsv($out, ['Visitas por encaixar', (int)($reinforcement['unassigned_count'] ?? 0)]);
        }
        fputcsv($out, []);
        fputcsv($out, ['plano','data','bloco','link_id','location_id','owner_user_id','owner_nome','fora_de_horas_no_dia','stops_no_bloco','max_visitas_dia','horas_uteis_min','almoco_bloco_min','almoco_geral_min','trabalho_bloco','total_bloco','nome','morada','cidade','distrito','categoria','subcategoria','periodicidade','repeticao','duracao_visita_min','prioridade','lat','lng','travel_min_bloco','ponto_partida','partida_lat','partida_lng','bloquear_partida','ponto_chegada','chegada_lat','chegada_lng','bloquear_chegada']);
        foreach ((array)($plan['days'] ?? []) as $day) {
            $items = (array)($day['items'] ?? []);
            foreach ($items as $item) {
                fputcsv($out, [
                    $scope,
                    (string)($day['date'] ?? ''),
                    (string)($day['label'] ?? ''),
                    (int)($item['link_id'] ?? 0),
                    (int)($item['id'] ?? 0),
                    (int)($item['assigned_to'] ?? 0),
                    (string)($item['assigned_to_name'] ?? ''),
                    !empty($day['allow_overtime']) ? 'Sim' : 'Não',
                    (int)($day['stops'] ?? 0),
                    (int)$simulation_options['max_stops_per_day'],
                    (int)$simulation_options['work_minutes'],
                    (int)($day['lunch_min'] ?? $simulation_options['lunch_minutes']),
                    (int)$simulation_options['lunch_minutes'],
                    (string)($day['work_human'] ?? ''),
                    (string)($day['total_human'] ?? ''),
                    (string)($item['name'] ?? ''),
                    (string)($item['address'] ?? ''),
                    (string)($item['city'] ?? ''),
                    (string)($item['district'] ?? ''),
                    (string)($item['category_name'] ?? ''),
                    (string)($item['subcategory_name'] ?? ''),
                    (string)($item['visit_frequency'] ?? ''),
                    (int)($item['copy_index'] ?? 1),
                    (int)($item['visit_duration_min'] ?? 45),
                    (int)($item['priority'] ?? 0),
                    (string)($item['lat'] ?? ''),
                    (string)($item['lng'] ?? ''),
                    (int)round($day['travel_min'] ?? 0),
                    (string)($simulation_options['start_point']['address'] ?? ''),
                    (string)($simulation_options['start_point']['lat'] ?? ''),
                    (string)($simulation_options['start_point']['lng'] ?? ''),
                    !empty($simulation_options['lock_start_point']) ? '1' : '0',
                    (string)($simulation_options['end_point']['address'] ?? ''),
                    (string)($simulation_options['end_point']['lat'] ?? ''),
                    (string)($simulation_options['end_point']['lng'] ?? ''),
                    !empty($simulation_options['lock_end_point']) ? '1' : '0',
                ]);
            }
        }
        fclose($out);
        exit;
    }

    private static function create_routes_from_plan(int $client_id, int $project_id, int $owner_user_id, string $week_start, array $plan): int {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $days = (array)($plan['days'] ?? []);
        if (!$days) return 0;
        $defs = get_option('routespro_route_defaults', []);
        if (!is_array($defs)) $defs = [];
        $routeDefaults = $defs[$client_id . '|' . $project_id . '|' . $owner_user_id] ?? $defs[$client_id . '|' . $project_id . '|0'] ?? [];
        $planOptions = self::normalize_plan_options((array)($plan['options'] ?? []));
        $base = strtotime($week_start ?: date('Y-m-d'));
        if (!$base) $base = current_time('timestamp');
        $created = 0;
        foreach ($days as $idx => $day) {
            $date = !empty($day['date']) ? sanitize_text_field((string)$day['date']) : date('Y-m-d', strtotime('+' . $idx . ' day', $base));
            $startPoint = $planOptions['start_point']['address'] || $planOptions['start_point']['lat'] !== null || $planOptions['start_point']['lng'] !== null ? $planOptions['start_point'] : ($routeDefaults['start_point'] ?? ['address'=>'','lat'=>null,'lng'=>null]);
            $endPoint = $planOptions['end_point']['address'] || $planOptions['end_point']['lat'] !== null || $planOptions['end_point']['lng'] !== null ? $planOptions['end_point'] : ($routeDefaults['end_point'] ?? ['address'=>'','lat'=>null,'lng'=>null]);
            $resolvedStartPoint = self::resolve_route_geo_point((array)$startPoint);
            $resolvedEndPoint = self::resolve_route_geo_point((array)$endPoint);
            $lunchMin = (int)($day['lunch_min'] ?? $planOptions['lunch_minutes'] ?? 0);
            $dayForSuggestionDistance = (array)$day;
            $dayForSuggestionDistance['start_point'] = (array)$resolvedStartPoint;
            $dayForSuggestionDistance['end_point'] = (array)$resolvedEndPoint;
            $suggestionDistanceKm = self::estimate_plan_day_distance_km($dayForSuggestionDistance, $planOptions);
            $suggestionTollCostEur = \RoutesPro\Support\TollEstimator::costFromKm($suggestionDistanceKm, 'route');
            $optimizedItems = self::optimize_day_items((array)($day['items'] ?? []), array_merge($planOptions, [
                'start_point' => $resolvedStartPoint,
                'end_point' => $resolvedEndPoint,
                'lock_start_point' => true,
                'lock_end_point' => true,
            ]));
            $schedule = self::build_route_schedule_details($date, $optimizedItems, (array)$resolvedStartPoint, (array)$resolvedEndPoint, $lunchMin);
            $meta = [
                'district' => '',
                'county' => '',
                'city' => '',
                'category_id' => 0,
                'subcategory_id' => 0,
                'generated_week_plan' => 1,
                'start_point' => $resolvedStartPoint,
                'end_point' => $resolvedEndPoint,
                'lock_start_point' => !empty($planOptions['lock_start_point']),
                'lock_end_point' => !empty($planOptions['lock_end_point']),
                'plan_summary' => [
                    'travel_min' => (int) round($schedule['travel_min'] ?? ($day['travel_min'] ?? 0)),
                    'visit_min' => (int) ($schedule['visit_min'] ?? ($day['visit_min'] ?? 0)),
                    'stops' => (int) ($day['stops'] ?? count((array)($day['items'] ?? []))),
                    'overtime_min' => (int) ($day['overtime_min'] ?? 0),
                    'distance_km' => round((float)($suggestionDistanceKm ?? $schedule['distance_km'] ?? 0), 2),
                    'suggestion_distance_km' => round((float)($suggestionDistanceKm ?? 0), 2),
                    'toll_cost_eur' => round((float)($suggestionTollCostEur ?? $schedule['toll_cost_eur'] ?? 0), 2),
                    'toll_estimated_eur' => round((float)($suggestionTollCostEur ?? $schedule['toll_estimated_eur'] ?? $schedule['toll_cost_eur'] ?? 0), 2),
                    'suggestion_toll_cost_eur' => round((float)($suggestionTollCostEur ?? 0), 2),
                    'toll_model' => (string)($schedule['toll_model'] ?? ''),
                    'toll_provider' => (string)($schedule['toll_provider'] ?? ''),
                    'toll_is_real_api' => !empty($schedule['toll_is_real_api']) ? 1 : 0,
                    'routing_provider' => (string)($schedule['routing_provider'] ?? ''),
                    'routing_calculated_at' => (string)($schedule['routing_calculated_at'] ?? ''),
                    'lunch_min' => (int)($schedule['lunch_min'] ?? $lunchMin),
                    'work_min' => (int)($schedule['work_min'] ?? 0),
                    'total_min' => (int)($schedule['total_min'] ?? 0),
                    'route_start' => (string)($schedule['route_start'] ?? ''),
                    'route_end' => (string)($schedule['route_end'] ?? ''),
                    'return_min' => (int)round((float)($schedule['return_min'] ?? 0)),
                ],
                'generated_plan_summary' => [
                    'distance_km' => round((float)($suggestionDistanceKm ?? $schedule['distance_km'] ?? 0), 2),
                    'suggestion_distance_km' => round((float)($suggestionDistanceKm ?? 0), 2),
                    'toll_cost_eur' => round((float)($suggestionTollCostEur ?? $schedule['toll_cost_eur'] ?? 0), 2),
                    'toll_estimated_eur' => round((float)($suggestionTollCostEur ?? $schedule['toll_estimated_eur'] ?? $schedule['toll_cost_eur'] ?? 0), 2),
                    'suggestion_toll_cost_eur' => round((float)($suggestionTollCostEur ?? 0), 2),
                    'travel_min' => (int) round($schedule['travel_min'] ?? 0),
                    'visit_min' => (int) ($schedule['visit_min'] ?? 0),
                    'stops' => (int) ($day['stops'] ?? count((array)($day['items'] ?? []))),
                    'created_from' => 'automatic_route_suggestion',
                ],
                'portal_summary' => [
                    'distance_km' => round((float)($suggestionDistanceKm ?? $schedule['distance_km'] ?? 0), 2),
                    'suggestion_distance_km' => round((float)($suggestionDistanceKm ?? 0), 2),
                    'toll_cost_eur' => round((float)($suggestionTollCostEur ?? $schedule['toll_cost_eur'] ?? 0), 2),
                    'toll_estimated_eur' => round((float)($suggestionTollCostEur ?? $schedule['toll_estimated_eur'] ?? $schedule['toll_cost_eur'] ?? 0), 2),
                    'suggestion_toll_cost_eur' => round((float)($suggestionTollCostEur ?? 0), 2),
                    'source' => 'automatic_route_suggestion',
                ],
                'route_metrics' => [
                    'distance_km' => round((float)($schedule['distance_km'] ?? 0), 2),
                    'toll_cost_eur' => round((float)($schedule['toll_cost_eur'] ?? 0), 2),
                    'toll_estimated_eur' => round((float)($schedule['toll_estimated_eur'] ?? $schedule['toll_cost_eur'] ?? 0), 2),
                    'toll_model' => (string)($schedule['toll_model'] ?? ''),
                    'toll_provider' => (string)($schedule['toll_provider'] ?? ''),
                    'toll_is_real_api' => !empty($schedule['toll_is_real_api']) ? 1 : 0,
                    'routing_provider' => (string)($schedule['routing_provider'] ?? ''),
                    'routing_calculated_at' => (string)($schedule['routing_calculated_at'] ?? ''),
                    'travel_min' => (int) round($schedule['travel_min'] ?? 0),
                    'visit_min' => (int) ($schedule['visit_min'] ?? 0),
                    'lunch_min' => (int)($schedule['lunch_min'] ?? $lunchMin),
                    'work_min' => (int)($schedule['work_min'] ?? 0),
                    'total_min' => (int)($schedule['total_min'] ?? 0),
                    'route_start' => (string)($schedule['route_start'] ?? ''),
                    'route_end' => (string)($schedule['route_end'] ?? ''),
                    'end_leg_distance_km' => round((float)($schedule['end_leg_distance_km'] ?? 0), 2),
                    'end_leg_travel_min' => (int)round((float)($schedule['end_leg_travel_min'] ?? 0)),
                    'end_leg_toll_cost_eur' => round((float)($schedule['end_leg_toll_cost_eur'] ?? 0), 2),
                ],
            ];
            $wpdb->insert($px . 'routes', [
                'client_id' => $client_id,
                'project_id' => $project_id,
                'date' => $date,
                'status' => 'planned',
                'owner_user_id' => $owner_user_id ?: null,
                'meta_json' => wp_json_encode($meta),
            ]);
            $route_id = (int) $wpdb->insert_id;
            if (!$route_id) continue;
            foreach ((array)($schedule['stops'] ?? []) as $seq => $stop) {
                $item = (array)($stop['item'] ?? []);
                $wpdb->insert($px . 'route_stops', [
                    'route_id' => $route_id,
                    'location_id' => (int) ($item['id'] ?? 0),
                    'seq' => $seq,
                    'planned_arrival' => !empty($stop['planned_arrival']) ? $stop['planned_arrival'] : null,
                    'planned_departure' => !empty($stop['planned_departure']) ? $stop['planned_departure'] : null,
                    'duration_s' => (int)($stop['duration_s'] ?? max(0, ((int)($item['visit_duration_min'] ?? 45)) * 60)),
                    'status' => 'pending',
                    'meta_json' => wp_json_encode([
                        'visit_time_min' => (int) ($item['visit_duration_min'] ?? 45),
                        'visit_time_mode' => 'bucket',
                        'campaign_frequency' => (string) ($item['visit_frequency'] ?? 'weekly'),
                        'copy_index' => (int) ($item['copy_index'] ?? 1),
                        'distance_from_prev_km' => round((float)($stop['distance_from_prev_km'] ?? 0), 2),
                        'travel_min_from_prev' => (int)round((float)($stop['travel_min_from_prev'] ?? 0)),
                        'toll_cost_eur_from_prev' => round((float)($stop['toll_cost_eur_from_prev'] ?? 0), 2),
                        'toll_provider' => (string)($stop['toll_provider'] ?? ''),
                        'toll_is_real_api' => !empty($stop['toll_is_real_api']) ? 1 : 0,
                        'routing_provider' => (string)($stop['routing_provider'] ?? ''),
                        'arrival_point' => (array)($stop['arrival_point'] ?? []),
                        'departure_point' => [
                            'lat' => is_numeric($item['lat'] ?? null) ? (float)$item['lat'] : null,
                            'lng' => is_numeric($item['lng'] ?? null) ? (float)$item['lng'] : null,
                            'address' => (string)($item['address'] ?? ''),
                        ],
                        'snapshot' => [
                            'location_id' => (int)($item['id'] ?? 0),
                            'name' => (string)($item['name'] ?? ''),
                            'address' => (string)($item['address'] ?? ''),
                            'district' => (string)($item['district'] ?? ''),
                            'county' => (string)($item['county'] ?? ''),
                            'city' => (string)($item['city'] ?? ''),
                            'lat' => is_numeric($item['lat'] ?? null) ? (float)$item['lat'] : null,
                            'lng' => is_numeric($item['lng'] ?? null) ? (float)$item['lng'] : null,
                            'place_id' => (string)($item['place_id'] ?? ''),
                        ],
                    ]),
                ], ['%d','%d','%d','%s','%s','%d','%s','%s']);
                $stop_id = (int) $wpdb->insert_id;
                if ($stop_id && class_exists('\RoutesPro\Services\RouteSnapshotService')) {
                    \RoutesPro\Services\RouteSnapshotService::capture($route_id, $stop_id, (int) ($item['id'] ?? 0));
                }
            }
            $created++;
        }
        return $created;
    }

    private static function build_route_schedule_details(string $date, array $items, array $startPoint, array $endPoint, int $lunchMin = 0): array {
        $items = array_values((new RouteCalculator())->reorderStops((array)$items, (array)$startPoint, (array)$endPoint));
        $routePoints = array_merge([$startPoint], $items, [$endPoint]);
        $routing = class_exists('\\RoutesPro\\Services\\GoogleRoutes') ? \RoutesPro\Services\GoogleRoutes::calculateRoute($routePoints) : ['ok'=>false];
        $routingOk = !empty($routing['ok']); $routingLegs = $routingOk && is_array($routing['legs'] ?? null) ? array_values((array)$routing['legs']) : [];
        $routeStartTs = strtotime(trim($date) . ' 09:00:00'); if (!$routeStartTs) $routeStartTs = current_time('timestamp');
        $cursorTs = $routeStartTs; $stops=[]; $travelMin=0.0; $visitMin=0; $distanceKm=0.0; $tollTotal=0.0; $lunchApplied=0; $insertLunchAfter = ($lunchMin > 0 && count($items) > 1) ? (int) floor((count($items) - 1) / 2) : -1; $prevPoint = $startPoint;
        foreach (array_values($items) as $idx => $item) {
            $leg = $routingLegs[$idx] ?? self::estimate_leg_details((array)$prevPoint, (array)$item);
            $legDistance=(float)($leg['distance_km'] ?? 0); $legTravel=(float)($leg['travel_min'] ?? 0); $legToll=isset($leg['toll_cost_eur']) && is_numeric($leg['toll_cost_eur']) ? (float)$leg['toll_cost_eur'] : (float)\RoutesPro\Support\TollEstimator::costFromKm($legDistance, 'segment');
            $travelMin += $legTravel; $distanceKm += $legDistance; $tollTotal += $legToll; $cursorTs += (int)round($legTravel * 60); $plannedArrival=date('Y-m-d H:i:s',$cursorTs); $currentVisitMin=max(0,(int)($item['visit_duration_min'] ?? 45)); $visitMin += $currentVisitMin; $cursorTs += $currentVisitMin * 60; $plannedDeparture=date('Y-m-d H:i:s',$cursorTs);
            $stops[] = ['item'=>$item,'planned_arrival'=>$plannedArrival,'planned_departure'=>$plannedDeparture,'duration_s'=>$currentVisitMin * 60,'distance_from_prev_km'=>$legDistance,'travel_min_from_prev'=>$legTravel,'toll_cost_eur_from_prev'=>round($legToll,2),'toll_provider'=>$routingOk ? 'google_routes' : 'internal_estimator','toll_is_real_api'=>!empty($leg['toll_has_price']) ? 1 : 0,'routing_provider'=>$routingOk ? 'google_routes' : 'internal','arrival_point'=>(array)$prevPoint];
            if ($insertLunchAfter === $idx) { $cursorTs += max(0, $lunchMin) * 60; $lunchApplied = max(0, $lunchMin); }
            $prevPoint = ['lat'=>is_numeric($item['lat'] ?? null) ? (float)$item['lat'] : null,'lng'=>is_numeric($item['lng'] ?? null) ? (float)$item['lng'] : null,'address'=>(string)($item['address'] ?? '')];
        }
        $endLeg = $routingLegs[count($items)] ?? self::estimate_leg_details((array)$prevPoint, (array)$endPoint);
        $endDistance=(float)($endLeg['distance_km'] ?? 0); $endTravel=(float)($endLeg['travel_min'] ?? 0); $endToll=isset($endLeg['toll_cost_eur']) && is_numeric($endLeg['toll_cost_eur']) ? (float)$endLeg['toll_cost_eur'] : (float)\RoutesPro\Support\TollEstimator::costFromKm($endDistance, 'segment');
        $travelMin += $endTravel; $distanceKm += $endDistance; $tollTotal += $endToll; $cursorTs += (int)round($endTravel * 60);
        if ($routingOk) { $distanceKm=(float)($routing['distance_km'] ?? $distanceKm); $travelMin=(float)($routing['travel_min'] ?? $travelMin); $tollTotal=(float)($routing['toll_cost_eur'] ?? $tollTotal); }
        if (!$routingOk && $tollTotal <= 0 && $distanceKm > 0) $tollTotal=(float)\RoutesPro\Support\TollEstimator::costFromKm($distanceKm, 'route');
        return ['route_start'=>date('Y-m-d H:i:s',$routeStartTs),'route_end'=>date('Y-m-d H:i:s',$cursorTs),'travel_min'=>$travelMin,'visit_min'=>$visitMin,'lunch_min'=>$lunchApplied,'work_min'=>(int)round($travelMin + $visitMin),'total_min'=>(int)round($travelMin + $visitMin + $lunchApplied),'distance_km'=>$distanceKm,'return_min'=>$endTravel,'end_leg_distance_km'=>$endDistance,'end_leg_travel_min'=>$endTravel,'end_leg_toll_cost_eur'=>round($endToll,2),'toll_cost_eur'=>round($tollTotal,2),'toll_estimated_eur'=>round($tollTotal,2),'toll_model'=>$routingOk ? (string)($routing['toll_model'] ?? 'Google Routes API') : (string)(\RoutesPro\Support\TollEstimator::estimateFromKm($distanceKm, 'route')['model'] ?? ''),'toll_provider'=>$routingOk ? 'google_routes' : 'internal_estimator','toll_is_real_api'=>$routingOk && !empty($routing['toll_is_real_api']) ? 1 : 0,'routing_provider'=>$routingOk ? 'google_routes' : 'internal','routing_calculated_at'=>$routingOk ? (string)($routing['calculated_at'] ?? current_time('mysql')) : current_time('mysql'),'stops'=>$stops];
    }

    private static function resolve_route_geo_point(array $point, int $location_id = 0): array {
        global $wpdb;
        $px = $wpdb->prefix . 'routespro_';
        $resolved = [
            'id' => $location_id ?: (int)($point['id'] ?? 0),
            'name' => sanitize_text_field((string)($point['name'] ?? '')),
            'address' => sanitize_text_field((string)($point['address'] ?? '')),
            'district' => sanitize_text_field((string)($point['district'] ?? '')),
            'county' => sanitize_text_field((string)($point['county'] ?? '')),
            'city' => sanitize_text_field((string)($point['city'] ?? '')),
            'place_id' => sanitize_text_field((string)($point['place_id'] ?? '')),
            'visit_duration_min' => max(0, (int)($point['visit_duration_min'] ?? 45)),
            'visit_frequency' => sanitize_text_field((string)($point['visit_frequency'] ?? 'weekly')),
            'copy_index' => max(1, (int)($point['copy_index'] ?? 1)),
            'priority' => (int)($point['priority'] ?? 0),
            'lat' => is_numeric($point['lat'] ?? null) ? (float)$point['lat'] : null,
            'lng' => is_numeric($point['lng'] ?? null) ? (float)$point['lng'] : null,
        ];
        $location_id = (int)($resolved['id'] ?? 0);
        if ($location_id > 0) {
            $loc = $wpdb->get_row($wpdb->prepare("SELECT id,name,address,district,county,city,place_id,lat,lng FROM {$px}locations WHERE id=%d LIMIT 1", $location_id), ARRAY_A);
            if (is_array($loc)) {
                foreach (['name','address','district','county','city','place_id'] as $key) {
                    if ($resolved[$key] === '' && !empty($loc[$key])) $resolved[$key] = sanitize_text_field((string)$loc[$key]);
                }
                if ($resolved['lat'] === null && is_numeric($loc['lat'] ?? null)) $resolved['lat'] = (float)$loc['lat'];
                if ($resolved['lng'] === null && is_numeric($loc['lng'] ?? null)) $resolved['lng'] = (float)$loc['lng'];
            }
        }
        if (($resolved['lat'] === null || $resolved['lng'] === null) && $resolved['address'] !== '') {
            $geo = self::geocode_address($resolved['address']);
            if ($geo) {
                $resolved['lat'] = $geo['lat'];
                $resolved['lng'] = $geo['lng'];
                if ($location_id > 0) {
                    $wpdb->update($px . 'locations', ['lat' => $geo['lat'], 'lng' => $geo['lng']], ['id' => $location_id]);
                }
            }
        }
        return $resolved;
    }

    private static function geocode_address(string $address): ?array {
        static $cache = [];
        $address = trim($address);
        if ($address === '') return null;
        if (array_key_exists($address, $cache)) return $cache[$address];
        $provider = class_exists('\RoutesPro\Services\MapsFactory') ? MapsFactory::make() : null;
        if (!$provider || !method_exists($provider, 'geocode')) return $cache[$address] = null;
        $result = $provider->geocode($address);
        if (is_array($result) && isset($result['lat'], $result['lng']) && is_numeric($result['lat']) && is_numeric($result['lng'])) {
            return $cache[$address] = ['lat' => (float)$result['lat'], 'lng' => (float)$result['lng']];
        }
        return $cache[$address] = null;
    }

    private static function estimate_leg_details(array $fromPoint, array $toPoint): array {
        static $cache = [];
        $fromLat = is_numeric($fromPoint['lat'] ?? null) ? (float)$fromPoint['lat'] : null;
        $fromLng = is_numeric($fromPoint['lng'] ?? null) ? (float)$fromPoint['lng'] : null;
        $toLat = is_numeric($toPoint['lat'] ?? null) ? (float)$toPoint['lat'] : null;
        $toLng = is_numeric($toPoint['lng'] ?? null) ? (float)$toPoint['lng'] : null;
        if ($fromLat === null || $fromLng === null || $toLat === null || $toLng === null) {
            return ['distance_km' => 0.0, 'travel_min' => 0.0];
        }
        $cacheKey = implode('|', [round($fromLat, 6), round($fromLng, 6), round($toLat, 6), round($toLng, 6)]);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        $provider = class_exists('\RoutesPro\Services\MapsFactory') ? MapsFactory::make() : null;
        if ($provider && method_exists($provider, 'distanceMatrix')) {
            $matrix = $provider->distanceMatrix([['lat' => $fromLat, 'lng' => $fromLng]], [['lat' => $toLat, 'lng' => $toLng]]);
            $element = null;
            if (is_array($matrix)) {
                $element = $matrix['rows'][0]['elements'][0] ?? $matrix['matrix'][0][0] ?? null;
            }
            if (is_array($element)) {
                $meters = $element['distance']['value'] ?? $element['routeSummary']['lengthInMeters'] ?? null;
                $seconds = $element['duration']['value'] ?? $element['travelTimeInSeconds'] ?? null;
                if (is_numeric($meters) && is_numeric($seconds)) {
                    return $cache[$cacheKey] = ['distance_km' => round(((float)$meters) / 1000, 3), 'travel_min' => round(((float)$seconds) / 60, 1)];
                }
            }
        }
        $distanceKm = self::haversine_km($fromLat, $fromLng, $toLat, $toLng);
        $travelMin = $distanceKm * 1.6;
        return $cache[$cacheKey] = ['distance_km' => $distanceKm, 'travel_min' => $travelMin];
    }

    private static function get_holiday_map(string $country, array $years): array {
        $country = strtolower($country);
        if (!in_array($country, ['pt','es'], true)) $country = 'pt';
        $map = [];
        foreach (array_unique(array_map('intval', $years)) as $year) {
            if ($year < 2000 || $year > 2100) continue;
            foreach (self::get_holidays_for_year($country, $year) as $date => $label) $map[$date] = $label;
        }
        ksort($map);
        return $map;
    }

    private static function get_holidays_for_year(string $country, int $year): array {
        $easter = easter_date($year);
        $map = [];
        $add = function(string $date, string $label) use (&$map) { $map[$date] = $label; };
        if ($country === 'es') {
            $add(sprintf('%04d-01-01', $year), 'Año Nuevo');
            $add(sprintf('%04d-01-06', $year), 'Epifanía del Señor');
            $add(date('Y-m-d', strtotime('-2 day', $easter)), 'Viernes Santo');
            $add(sprintf('%04d-05-01', $year), 'Fiesta del Trabajo');
            $add(sprintf('%04d-08-15', $year), 'Asunción de la Virgen');
            $add(sprintf('%04d-10-12', $year), 'Fiesta Nacional de España');
            $add(sprintf('%04d-11-01', $year), 'Todos los Santos');
            $add(sprintf('%04d-12-06', $year), 'Día de la Constitución Española');
            $add(sprintf('%04d-12-08', $year), 'Inmaculada Concepción');
            $add(sprintf('%04d-12-25', $year), 'Natividad del Señor');
        } else {
            $add(sprintf('%04d-01-01', $year), 'Ano Novo');
            $add(date('Y-m-d', strtotime('-2 day', $easter)), 'Sexta-Feira Santa');
            $add(date('Y-m-d', $easter), 'Domingo de Páscoa');
            $add(sprintf('%04d-04-25', $year), 'Dia da Liberdade');
            $add(sprintf('%04d-05-01', $year), 'Dia do Trabalhador');
            $add(date('Y-m-d', strtotime('+60 day', $easter)), 'Corpo de Deus');
            $add(sprintf('%04d-06-10', $year), 'Dia de Portugal');
            $add(sprintf('%04d-08-15', $year), 'Assunção de Nossa Senhora');
            $add(sprintf('%04d-10-05', $year), 'Implantação da República');
            $add(sprintf('%04d-11-01', $year), 'Dia de Todos os Santos');
            $add(sprintf('%04d-12-01', $year), 'Restauração da Independência');
            $add(sprintf('%04d-12-08', $year), 'Imaculada Conceição');
            $add(sprintf('%04d-12-25', $year), 'Natal');
        }
        ksort($map);
        return $map;
    }


    private static function describe_monthly_week_pattern(int $count, array $assignedWeekKeys, string $currentWeekKey, int $totalWeeks): string {
        $count = max(1, $count);
        $ordered = array_values($assignedWeekKeys);
        $indexes = [];
        foreach ($ordered as $idx => $wk) {
            if ((string)$wk === (string)$currentWeekKey) {
                $indexes[] = $idx;
            }
        }
        $position = $indexes ? ((int)$indexes[0] + 1) : 1;
        if ($count <= 1) return 'Visita mensal flexível, encaixada pela geografia e carga do mês';
        if ($count === 2) {
            $positions = [];
            foreach ($ordered as $wk) {
                $positions[] = array_search($wk, array_values($assignedWeekKeys), true);
            }
            $uniqueKeys = array_values(array_unique($assignedWeekKeys));
            $weekOrderMap = [];
            foreach ($uniqueKeys as $i => $wk) $weekOrderMap[(string)$wk] = $i + 1;
            $first = $weekOrderMap[(string)($assignedWeekKeys[0] ?? '')] ?? 1;
            $second = $weekOrderMap[(string)($assignedWeekKeys[1] ?? '')] ?? $first;
            if (abs($second - $first) >= 2) return 'Periodicidade 2, semana intercalada ' . $position . '/2';
            return 'Periodicidade 2, fallback por carga operacional ' . $position . '/2';
        }
        if ($count === 3) return 'Periodicidade 3, distribuída para cobertura mensal equilibrada, visita ' . $position . '/3';
        if ($count >= 4 && $totalWeeks >= 5 && $count === $totalWeeks) return 'Cobertura semanal completa com reforço de 5.ª semana';
        if ($count >= 4) return 'Cobertura semanal, uma visita por semana';
        return 'Periodicidade mensal ajustada ao plano';
    }

    private static function describe_assigned_visit_reason(array $item, array $day = [], array $options = []): string {
        $parts = [];
        $pattern = trim((string)($item['assignment_pattern_label'] ?? ''));
        if ($pattern !== '') $parts[] = $pattern;
        if (!empty($item['periodicity_broken']) && !empty($item['moved_from_week']) && !empty($item['moved_to_week'])) {
            $parts[] = 'Movida de ' . (string)$item['moved_from_week'] . ' para ' . (string)$item['moved_to_week'] . ' para equilibrar carga';
        }
        if ((int)($item['priority'] ?? 0) >= 8) $parts[] = 'Prioridade alta';
        $weekKey = (string)($day['week_key'] ?? $item['target_week_key'] ?? '');
        if ($weekKey !== '') $parts[] = 'Planeada em ' . str_replace('-', '/', (string)$weekKey);
        return implode(' · ', array_slice(array_unique(array_filter($parts)), 0, 3));
    }

    private static function describe_unassigned_visit_reason(array $task, array $options = []): string {
        $maxStops = (int)($options['max_stops_per_day'] ?? 12);
        $workMin = (int)($options['work_minutes'] ?? 480);
        if (!empty($task['overflow_conflict'])) return 'Não coube sem ultrapassar ' . $maxStops . ' visitas/dia ou ' . self::human_minutes($workMin) . ' úteis';
        if (!empty($task['periodicity_broken'])) return 'Ficou fora para proteger a periodicidade e evitar conflito semanal';
        if (!empty($task['overflow_forced'])) return 'Ficou fora por excesso de carga no dia original';
        return 'Sem janela viável com os limites operacionais atuais';
    }

    private static function build_unassigned_day_suggestions(array $unassigned, array $days, array $options = []): array {
        $maxStops = (int)($options['max_stops_per_day'] ?? 12);
        $workMin = (int)($options['work_minutes'] ?? 480);
        $suggestions = [];
        foreach ($unassigned as $task) {
            $uid = (string)($task['uid'] ?? ((int)($task['id'] ?? 0) . '__' . max(1, (int)($task['copy_index'] ?? 1))));
            if ($uid === '') continue;
            $taskLat = is_numeric($task['lat'] ?? null) ? (float)$task['lat'] : null;
            $taskLng = is_numeric($task['lng'] ?? null) ? (float)$task['lng'] : null;
            $ranked = [];
            foreach ($days as $day) {
                $items = array_values((array)($day['items'] ?? []));
                $storeAlreadyThere = false;
                foreach ($items as $it) {
                    if ((int)($it['id'] ?? 0) === (int)($task['id'] ?? 0)) { $storeAlreadyThere = true; break; }
                }
                if ($storeAlreadyThere) continue;
                $stops = count($items);
                if ($stops >= $maxStops) continue;
                $visitMin = (int)($day['visit_min'] ?? 0) + (int)($task['visit_duration_min'] ?? 45);
                $travelMin = (float)($day['travel_min'] ?? 0);
                $distanceScore = 0.0;
                $zoneHits = 0;
                foreach ($items as $it) {
                    if (strtolower(trim((string)($it['city'] ?? ''))) !== '' && strtolower(trim((string)($it['city'] ?? ''))) === strtolower(trim((string)($task['city'] ?? '')))) $zoneHits++;
                }
                if ($taskLat !== null && $taskLng !== null && $items) {
                    $latSum = 0.0; $lngSum = 0.0; $geoCount = 0;
                    foreach ($items as $it) {
                        if (!is_numeric($it['lat'] ?? null) || !is_numeric($it['lng'] ?? null)) continue;
                        $latSum += (float)$it['lat']; $lngSum += (float)$it['lng']; $geoCount++;
                    }
                    if ($geoCount > 0) {
                        $distanceScore = self::haversine_km($taskLat, $taskLng, $latSum / $geoCount, $lngSum / $geoCount);
                        $travelMin += min(90.0, $distanceScore * 1.7);
                    }
                }
                $projectedWork = $visitMin + $travelMin;
                if ($projectedWork > ($workMin + 150)) continue;
                $score = ($stops * 25.0) + max(0, $projectedWork - $workMin) * 3.0 + $distanceScore - ($zoneHits * 12.0);
                $ranked[] = [
                    'label' => (string)($day['label'] ?? 'Dia') . (!empty($day['date']) ? ' · ' . date_i18n('d/m', strtotime((string)$day['date'])) : ''),
                    'score' => $score,
                ];
            }
            usort($ranked, function($a, $b){ return ($a['score'] <=> $b['score']); });
            $suggestions[$uid] = array_slice($ranked, 0, 3);
        }
        return $suggestions;
    }

    private static function human_minutes(int $mins): string {
        $h = floor($mins / 60); $m = $mins % 60;
        if ($h <= 0) return $m . 'm';
        return $h . 'h ' . $m . 'm';
    }

    private static function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) * sin($dLng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $earth * $c;
    }
}
