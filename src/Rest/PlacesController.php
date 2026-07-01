<?php
namespace RoutesPro\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use RoutesPro\Admin\Settings;
use RoutesPro\Services\MapsFactory;

if (!defined('ABSPATH')) exit;

class PlacesController {
    const NS = 'routespro/v1';

    public function register_routes(): void {
        register_rest_route(self::NS, '/places/search', [[
            'methods' => 'GET',
            'callback' => [$this, 'search'],
            'permission_callback' => function(){ return current_user_can('routespro_manage'); },
        ]]);
    }

    public function search(WP_REST_Request $req) {
        $provider = MapsFactory::make();
        if (!$provider) return new WP_Error('missing_maps_provider', 'Fornecedor de mapas não configurado.', ['status' => 400]);

        $district = sanitize_text_field($req->get_param('district') ?: '');
        $county = sanitize_text_field($req->get_param('county') ?: '');
        $city = sanitize_text_field($req->get_param('city') ?: '');
        $category_id = absint($req->get_param('category_id') ?: 0);

        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $category = $category_id ? $wpdb->get_var($wpdb->prepare("SELECT name FROM {$px}categories WHERE id=%d LIMIT 1", $category_id)) : '';
        $pieces = array_filter([$category ?: 'estabelecimentos comerciais', $city, $county, $district, 'Portugal']);
        $query = implode(', ', $pieces);
        $body = $provider->placeSearch($query, ['language' => 'pt-PT', 'region' => 'pt', 'limit' => 20]);
        if (!$body) return new WP_Error('maps_failed', 'Falha na pesquisa de locais.', ['status' => 502]);

        $items = [];
        foreach (($body['results'] ?? []) as $r) {
            $items[] = [
                'name' => $r['name'] ?? '',
                'address' => $r['formatted_address'] ?? ($r['address']['freeformAddress'] ?? ''),
                'lat' => $r['geometry']['location']['lat'] ?? ($r['position']['lat'] ?? null),
                'lng' => $r['geometry']['location']['lng'] ?? ($r['position']['lon'] ?? null),
                'place_id' => $r['place_id'] ?? ($r['id'] ?? ''),
                'source' => Settings::get('maps_provider', 'leaflet'),
            ];
        }
        return new WP_REST_Response(['query' => $query, 'items' => $items], 200);
    }
}
