<?php
namespace RoutesPro\Repositories;

if (!defined('ABSPATH')) exit;

class CampaignLocationRepository {
    public static function findLinkedRows(int $project_id, array $filters = []): array {
        global $wpdb;
        if ($project_id <= 0) return [];

        $px = $wpdb->prefix . 'routespro_';
        $where = ['cl.project_id=%d'];
        $args = [$project_id];

        $q = sanitize_text_field((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where[] = '(l.name LIKE %s OR l.address LIKE %s OR l.city LIKE %s OR l.phone LIKE %s OR l.postal_code LIKE %s)';
            array_push($args, $like, $like, $like, $like, $like);
        }

        $category_id = absint($filters['category_id'] ?? 0);
        if ($category_id > 0) {
            $where[] = '(l.category_id=%d OR l.subcategory_id=%d OR parent_cat.id=%d)';
            array_push($args, $category_id, $category_id, $category_id);
        }

        $status = sanitize_text_field((string)($filters['status'] ?? ''));
        if (in_array($status, ['active', 'paused'], true)) {
            $where[] = 'cl.status=%s';
            $args[] = $status;
        }

        $active = (string)($filters['active'] ?? '');
        if ($active === '1' || $active === '0') {
            $where[] = 'cl.is_active=%d';
            $args[] = (int) $active;
        }

        $owner_user_id = absint($filters['owner_user_id'] ?? 0);
        if ($owner_user_id > 0) {
            $where[] = 'cl.assigned_to=%d';
            $args[] = $owner_user_id;
        }

        $sql = "SELECT cl.id AS link_id, cl.status AS campaign_status, cl.priority, cl.visit_frequency, cl.frequency_count, cl.visit_duration_min, cl.min_gap_days, cl.max_gap_days, cl.preferred_weekdays, cl.blocked_weekdays, cl.time_window_start, cl.time_window_end, cl.allow_auto_reschedule, cl.allow_overtime, cl.rule_notes, cl.assigned_to, cl.is_active AS campaign_active, l.*, c.name AS category_name, sc.name AS subcategory_name, owner.display_name AS assigned_to_name FROM {$px}campaign_locations cl INNER JOIN {$px}locations l ON l.id=cl.location_id LEFT JOIN {$px}categories c ON c.id=l.category_id LEFT JOIN {$px}categories sc ON sc.id=l.subcategory_id LEFT JOIN {$px}categories parent_cat ON parent_cat.id=l.subcategory_id LEFT JOIN {$wpdb->users} owner ON owner.ID=cl.assigned_to WHERE " . implode(' AND ', $where) . ' ORDER BY cl.priority DESC, l.city ASC, l.name ASC';

        return $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
    }
}
