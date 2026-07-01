<?php
namespace RoutesPro\Performance;

use RoutesPro\Support\AssignmentResolver;

if (!defined('ABSPATH')) exit;

class Module {
    const SCHEMA_OPT = 'fieldflow_performance_schema_version';
    const SCHEMA_VERSION = '1.4.9';

    public static function register_hooks(): void {
        add_action('admin_post_fieldflow_perf_save_course', [self::class, 'handle_save_course']);
        add_action('admin_post_fieldflow_perf_delete_course', [self::class, 'handle_delete_course']);
        add_action('admin_post_fieldflow_perf_duplicate_course', [self::class, 'handle_duplicate_course']);
        add_action('admin_post_fieldflow_perf_duplicate_course_to_scope', [self::class, 'handle_duplicate_course_to_scope']);
        add_action('admin_post_fieldflow_perf_move_lesson', [self::class, 'handle_move_lesson']);
        add_action('admin_post_fieldflow_perf_save_media', [self::class, 'handle_save_media']);
        add_action('admin_post_fieldflow_perf_delete_media', [self::class, 'handle_delete_media']);
        add_action('admin_post_fieldflow_perf_save_mission', [self::class, 'handle_save_mission']);
        add_action('admin_post_fieldflow_perf_delete_mission', [self::class, 'handle_delete_mission']);
        add_action('admin_post_fieldflow_perf_complete_mission', [self::class, 'handle_complete_mission']);
        add_action('admin_post_fieldflow_perf_mark_lesson', [self::class, 'handle_mark_lesson']);
        add_action('admin_post_fieldflow_perf_seed_demo', [self::class, 'handle_seed_demo']);
        add_action('admin_post_fieldflow_perf_save_certificate_settings', [self::class, 'handle_save_certificate_settings']);
        add_action('admin_post_fieldflow_perf_download_certificate', [self::class, 'handle_download_certificate']);
        add_action('admin_post_fieldflow_perf_verify_certificate', [self::class, 'handle_verify_certificate']);
        add_action('admin_post_nopriv_fieldflow_perf_verify_certificate', [self::class, 'handle_verify_certificate']);
        add_filter('query_vars', [self::class, 'add_public_query_vars']);
        add_action('template_redirect', [self::class, 'maybe_render_public_certificate']);
        add_action('wp_ajax_fieldflow_perf_dashboard_fragment', [self::class, 'ajax_dashboard_fragment']);
        add_action('admin_post_fieldflow_perf_save_automation', [self::class, 'handle_save_automation']);
        add_action('admin_post_fieldflow_perf_delete_automation', [self::class, 'handle_delete_automation']);
        add_action('admin_post_fieldflow_perf_reset_course_progress', [self::class, 'handle_reset_course_progress']);
        add_action('admin_post_fieldflow_perf_save_skill', [self::class, 'handle_save_skill']);
        add_action('admin_post_fieldflow_perf_save_skill_rules', [self::class, 'handle_save_skill_rules']);
        add_action('admin_post_fieldflow_perf_clear_activity', [self::class, 'handle_clear_activity']);
        add_action('fieldflow_form_submitted', [self::class, 'handle_form_submission_automation'], 10, 3);
    }

    public static function maybe_install(): void {
        if ((string)get_option(self::SCHEMA_OPT, '') !== self::SCHEMA_VERSION) self::install();
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $px = $wpdb->prefix . 'routespro_perf_';
        $sql = [];
        $sql[] = "CREATE TABLE {$px}courses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NULL,
            project_id BIGINT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT NULL,
            content_url TEXT NULL,
            duration_min INT NOT NULL DEFAULT 0,
            points INT NOT NULL DEFAULT 50,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY client_idx (client_id),
            KEY project_idx (project_id),
            KEY status_idx (status)
        ) $charset;";
        $sql[] = "CREATE TABLE {$px}modules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            course_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY course_idx (course_id)
        ) $charset;";
        $sql[] = "CREATE TABLE {$px}lessons (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            course_id BIGINT UNSIGNED NOT NULL,
            module_id BIGINT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            body LONGTEXT NULL,
            content_type VARCHAR(40) NOT NULL DEFAULT 'link',
            media_url TEXT NULL,
            media_id BIGINT UNSIGNED NULL,
            estimated_min INT NOT NULL DEFAULT 3,
            is_required TINYINT(1) NOT NULL DEFAULT 1,
            min_watch_seconds INT NOT NULL DEFAULT 8,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY course_idx (course_id),
            KEY module_idx (module_id),
            KEY type_idx (content_type)
        ) $charset;";
        $sql[] = "CREATE TABLE {$px}media (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NULL,
            project_id BIGINT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            media_type VARCHAR(40) NOT NULL DEFAULT 'link',
            media_url TEXT NOT NULL,
            notes LONGTEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY client_idx (client_id),
            KEY project_idx (project_id),
            KEY type_idx (media_type),
            KEY status_idx (status)
        ) $charset;";
        $sql[] = "CREATE TABLE {$px}missions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NULL,
            project_id BIGINT UNSIGNED NULL,
            course_id BIGINT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT NULL,
            mission_type VARCHAR(48) NOT NULL DEFAULT 'field_action',
            target_value INT NOT NULL DEFAULT 1,
            points INT NOT NULL DEFAULT 100,
            due_at DATETIME NULL,
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            evidence_type VARCHAR(40) NOT NULL DEFAULT 'link',
            evidence_required TINYINT(1) NOT NULL DEFAULT 0,
            approval_required TINYINT(1) NOT NULL DEFAULT 0,
            success_criteria LONGTEXT NULL,
            mission_content_type VARCHAR(40) NOT NULL DEFAULT 'none',
            mission_content_url TEXT NULL,
            quiz_enabled TINYINT(1) NOT NULL DEFAULT 0,
            quiz_pass_score INT NOT NULL DEFAULT 70,
            quiz_json LONGTEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY client_idx (client_id),
            KEY project_idx (project_id),
            KEY course_idx (course_id),
            KEY status_idx (status),
            KEY due_idx (due_at)
        ) $charset;";
        $sql[] = "CREATE TABLE {$px}mission_users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            mission_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'assigned',
            score INT NOT NULL DEFAULT 0,
            evidence_url TEXT NULL,
            note LONGTEXT NULL,
            completed_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mission_user (mission_id, user_id),
            KEY mission_idx (mission_id),
            KEY user_idx (user_id),
            KEY status_idx (status)
        ) $charset;";
        $sql[] = "CREATE TABLE {$px}lesson_progress (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            lesson_id BIGINT UNSIGNED NOT NULL,
            course_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'completed',
            completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_lesson_user (lesson_id, user_id),
            KEY course_idx (course_id),
            KEY user_idx (user_id)
        ) $charset;";
        $sql[] = "CREATE TABLE {$px}certificate_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NULL,
            project_id BIGINT UNSIGNED NULL,
            template_name VARCHAR(255) NOT NULL DEFAULT 'Premium Certificate',
            logo_left_url TEXT NULL,
            logo_right_url TEXT NULL,
            signature_url TEXT NULL,
            seal_image_url TEXT NULL,
            signer_name VARCHAR(255) NULL,
            signer_role VARCHAR(255) NULL,
            primary_color VARCHAR(16) NOT NULL DEFAULT '#0f172a',
            accent_color VARCHAR(16) NOT NULL DEFAULT '#d9a441',
            background_color VARCHAR(16) NOT NULL DEFAULT '#ffffff',
            title_color VARCHAR(16) NOT NULL DEFAULT '#0f172a',
            subtitle_color VARCHAR(16) NOT NULL DEFAULT '#64748b',
            body_color VARCHAR(16) NOT NULL DEFAULT '#334155',
            link_color VARCHAR(16) NOT NULL DEFAULT '#2563eb',
            line_color VARCHAR(16) NOT NULL DEFAULT '#d9a441',
            button_color VARCHAR(16) NOT NULL DEFAULT '#0f172a',
            button_text_color VARCHAR(16) NOT NULL DEFAULT '#ffffff',
            modal_bg_color VARCHAR(16) NOT NULL DEFAULT '#ffffff',
            modal_text_color VARCHAR(16) NOT NULL DEFAULT '#0f172a',
            card_bg_color VARCHAR(16) NOT NULL DEFAULT '#ffffff',
            title_font VARCHAR(120) NOT NULL DEFAULT 'Helvetica-Bold',
            body_font VARCHAR(120) NOT NULL DEFAULT 'Helvetica',
            certificate_title VARCHAR(255) NOT NULL DEFAULT 'Certificate of Completion',
            certificate_text LONGTEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY client_idx (client_id),
            KEY project_idx (project_id),
            KEY status_idx (status)
        ) $charset;";
        $sql[] = "CREATE TABLE {$px}certificates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            certificate_uid VARCHAR(80) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            course_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NULL,
            project_id BIGINT UNSIGNED NULL,
            issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_certificate_uid (certificate_uid),
            UNIQUE KEY uniq_user_course (user_id, course_id),
            KEY user_idx (user_id),
            KEY course_idx (course_id),
            KEY client_idx (client_id),
            KEY project_idx (project_id)
        ) $charset;";
        $sql[] = "CREATE TABLE {$px}automations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            client_id BIGINT UNSIGNED NULL,
            project_id BIGINT UNSIGNED NULL,
            form_id BIGINT UNSIGNED NULL,
            question_key VARCHAR(191) NULL,
            question_label VARCHAR(255) NULL,
            operator VARCHAR(32) NOT NULL DEFAULT 'equals',
            compare_value TEXT NULL,
            action_mission_title VARCHAR(255) NULL,
            action_mission_description LONGTEXT NULL,
            action_course_id BIGINT UNSIGNED NULL,
            action_points INT NOT NULL DEFAULT 0,
            action_email TINYINT(1) NOT NULL DEFAULT 0,
            email_subject VARCHAR(255) NULL,
            email_body LONGTEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY client_idx (client_id),
            KEY project_idx (project_id),
            KEY form_idx (form_id),
            KEY status_idx (status)
        ) $charset;";
        
        $sql[] = "CREATE TABLE {$px}reset_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            course_id BIGINT UNSIGNED NULL,
            client_id BIGINT UNSIGNED NULL,
            project_id BIGINT UNSIGNED NULL,
            action_type VARCHAR(64) NOT NULL DEFAULT 'course_reset',
            reason LONGTEXT NULL,
            admin_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY user_idx (user_id),
            KEY course_idx (course_id),
            KEY client_idx (client_id),
            KEY project_idx (project_id),
            KEY created_idx (created_at)
        ) $charset;";
$sql[] = "CREATE TABLE {$px}automation_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            automation_id BIGINT UNSIGNED NOT NULL,
            submission_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NULL,
            client_id BIGINT UNSIGNED NULL,
            project_id BIGINT UNSIGNED NULL,
            result VARCHAR(32) NOT NULL DEFAULT 'matched',
            actions_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY automation_idx (automation_id),
            KEY submission_idx (submission_id),
            KEY user_idx (user_id),
            KEY client_idx (client_id),
            KEY project_idx (project_id),
            KEY created_idx (created_at)
        ) $charset;";
        $sql[] = "CREATE TABLE {$px}skills (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            description TEXT NULL,
            color VARCHAR(16) NOT NULL DEFAULT '#0f172a',
            sort_order INT NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY status_idx (status),
            KEY sort_idx (sort_order)
        ) $charset;";
        $sql[] = "CREATE TABLE {$px}skill_rules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            skill_id BIGINT UNSIGNED NOT NULL,
            object_type VARCHAR(32) NOT NULL DEFAULT 'course',
            object_id BIGINT UNSIGNED NOT NULL,
            points INT NOT NULL DEFAULT 10,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_skill_object (skill_id, object_type, object_id),
            KEY skill_idx (skill_id),
            KEY object_idx (object_type, object_id)
        ) $charset;";
        $sql[] = "CREATE TABLE {$px}mission_quiz_results (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            mission_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            score INT NOT NULL DEFAULT 0,
            passed TINYINT(1) NOT NULL DEFAULT 0,
            answers_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mission_user (mission_id, user_id),
            KEY mission_idx (mission_id),
            KEY user_idx (user_id),
            KEY passed_idx (passed)
        ) $charset;";
        foreach ($sql as $statement) dbDelta($statement);
        self::seed_default_skills();
        update_option(self::SCHEMA_OPT, self::SCHEMA_VERSION);
    }

    public static function table(string $name): string { global $wpdb; return $wpdb->prefix . 'routespro_perf_' . $name; }
    public static function user_can_manage(): bool { return current_user_can('routespro_manage') || current_user_can('manage_options'); }

    public static function get_clients(): array { global $wpdb; return $wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}routespro_clients ORDER BY name ASC", ARRAY_A) ?: []; }
    public static function get_projects(int $client_id = 0): array { global $wpdb; $px=$wpdb->prefix.'routespro_'; if($client_id) return $wpdb->get_results($wpdb->prepare("SELECT id,name,client_id FROM {$px}projects WHERE client_id=%d ORDER BY name ASC", $client_id), ARRAY_A) ?: []; return $wpdb->get_results("SELECT id,name,client_id FROM {$px}projects ORDER BY name ASC", ARRAY_A) ?: []; }

    public static function get_users_for_project(int $project_id = 0): array {
        global $wpdb;
        if ($project_id) {
            $ids = self::get_project_user_ids($project_id);
            if (!$ids) return [];
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            return $wpdb->get_results($wpdb->prepare("SELECT ID,display_name,user_email FROM {$wpdb->users} WHERE ID IN ({$placeholders}) ORDER BY display_name ASC", ...$ids), ARRAY_A) ?: [];
        }
        return get_users(['fields'=>['ID','display_name','user_email'], 'number'=>200, 'orderby'=>'display_name']);
    }

    public static function get_project_user_ids(int $project_id, int $client_id = 0): array {
        if ($project_id <= 0) return [];
        $ids = [];
        if (class_exists('\RoutesPro\Support\Permissions')) {
            foreach ((array)\RoutesPro\Support\Permissions::get_associated_user_ids($client_id, $project_id) as $uid) { $uid=absint($uid); if($uid) $ids[$uid]=$uid; }
        }
        global $wpdb; $px=$wpdb->prefix.'routespro_';
        if (!$ids && class_exists('\RoutesPro\Support\AssignmentResolver')) {
            $ctx = AssignmentResolver::get_project_context($project_id);
            foreach ((array)($ctx['associated_user_ids'] ?? []) as $uid) { $uid=absint($uid); if($uid) $ids[$uid]=$uid; }
            foreach ((array)($ctx['owners'] ?? []) as $row) { if(empty($row['is_active'])) continue; $uid=absint($row['user_id'] ?? 0); if($uid) $ids[$uid]=$uid; }
        }
        if (!$ids) {
            $rows = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$px}project_assignments WHERE project_id=%d AND is_active=1", $project_id)) ?: [];
            foreach ($rows as $uid) { $uid=absint($uid); if($uid) $ids[$uid]=$uid; }
        }
        ksort($ids); return array_values($ids);
    }

    public static function get_client_user_ids(int $client_id): array {
        if ($client_id <= 0) return [];
        $ids = [];
        if (class_exists('\RoutesPro\Support\Permissions')) {
            foreach ((array)\RoutesPro\Support\Permissions::get_associated_user_ids($client_id, 0) as $uid) { $uid=absint($uid); if($uid) $ids[$uid]=$uid; }
        }
        global $wpdb;
        if (!$ids && class_exists('\RoutesPro\Support\AssignmentResolver')) {
            foreach ((array)AssignmentResolver::get_client_user_ids($client_id) as $uid) { $uid=absint($uid); if($uid) $ids[$uid]=$uid; }
        }
        $project_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}routespro_projects WHERE client_id=%d", $client_id)) ?: [];
        foreach ($project_ids as $pid) foreach (self::get_project_user_ids((int)$pid, $client_id) as $uid) $ids[$uid]=$uid;
        ksort($ids); return array_values($ids);
    }

    public static function scope_user_ids(int $client_id = 0, int $project_id = 0): array { if($project_id) return self::get_project_user_ids($project_id, $client_id); if($client_id) return self::get_client_user_ids($client_id); return []; }
    private static function apply_user_scope(array &$where, array &$args, int $client_id=0, int $project_id=0, string $column='mu.user_id'): void { if(!$client_id && !$project_id) return; $ids=self::scope_user_ids($client_id,$project_id); if(!$ids){$where[]='1=0'; return;} $where[]=$column.' IN ('.implode(',', array_fill(0,count($ids),'%d')).')'; $args=array_merge($args,$ids); }
    public static function user_in_scope(int $user_id, int $client_id=0, int $project_id=0): bool { if(self::user_can_manage()) return true; $ids=self::scope_user_ids($client_id,$project_id); return !$ids || in_array($user_id,$ids,true); }

    public static function get_courses(array $filters = []): array {
        global $wpdb; $t=self::table('courses'); $m=self::table('missions'); $mu=self::table('mission_users'); $where=['1=1']; $args=[];
        if(!empty($filters['client_id'])){$where[]='c.client_id=%d'; $args[]=(int)$filters['client_id'];}
        if(!empty($filters['project_id'])){$where[]='c.project_id=%d'; $args[]=(int)$filters['project_id'];}
        if(!empty($filters['user_id'])){$where[]='(mu.user_id=%d OR c.project_id IS NULL OR c.project_id=0)'; $args[]=(int)$filters['user_id'];}
        if(!isset($filters['include_inactive']) || !$filters['include_inactive']){$where[]="c.status='active'";}
        $sql="SELECT DISTINCT c.*, (SELECT COUNT(*) FROM ".self::table('lessons')." l WHERE l.course_id=c.id) AS lessons_count FROM {$t} c LEFT JOIN {$m} m ON m.course_id=c.id LEFT JOIN {$mu} mu ON mu.mission_id=m.id WHERE ".implode(' AND ',$where)." ORDER BY c.updated_at DESC,c.id DESC";
        return $args ? ($wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A) ?: []) : ($wpdb->get_results($sql,ARRAY_A) ?: []);
    }

    public static function get_lessons(int $course_id): array { global $wpdb; return $wpdb->get_results($wpdb->prepare("SELECT l.*, md.title AS media_title, md.media_type AS library_type, md.media_url AS library_url FROM ".self::table('lessons')." l LEFT JOIN ".self::table('media')." md ON md.id=l.media_id WHERE l.course_id=%d ORDER BY l.sort_order ASC,l.id ASC", $course_id), ARRAY_A) ?: []; }
    public static function get_media(array $filters=[]): array { global $wpdb; $t=self::table('media'); $where=['1=1']; $args=[]; if(!empty($filters['client_id'])){$where[]='client_id=%d';$args[]=(int)$filters['client_id'];} if(!empty($filters['project_id'])){$where[]='project_id=%d';$args[]=(int)$filters['project_id'];} $sql="SELECT * FROM {$t} WHERE ".implode(' AND ',$where)." ORDER BY updated_at DESC,id DESC"; return $args?($wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A)?:[]):($wpdb->get_results($sql,ARRAY_A)?:[]); }

    public static function get_missions(array $filters = []): array {
        global $wpdb; $m=self::table('missions'); $c=self::table('courses'); $mu=self::table('mission_users'); $where=['1=1']; $args=[]; $join_user=!empty($filters['user_id']);
        if(!empty($filters['client_id'])){$where[]='m.client_id=%d';$args[]=(int)$filters['client_id'];}
        if(!empty($filters['project_id'])){$where[]='m.project_id=%d';$args[]=(int)$filters['project_id'];}
        if($join_user){$where[]='mu.user_id=%d';$args[]=(int)$filters['user_id'];}
        $join=$join_user?" INNER JOIN {$mu} mu ON mu.mission_id=m.id":'';
        $sql="SELECT DISTINCT m.*, c.title AS course_title FROM {$m} m{$join} LEFT JOIN {$c} c ON c.id=m.course_id WHERE ".implode(' AND ',$where)." ORDER BY COALESCE(m.due_at,m.created_at) DESC,m.id DESC";
        return $args ? ($wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A) ?: []) : ($wpdb->get_results($sql,ARRAY_A) ?: []);
    }

    public static function stats(int $client_id=0, int $project_id=0, int $user_id=0): array {
        global $wpdb; $m=self::table('missions'); $mu=self::table('mission_users'); $c=self::table('courses'); $lp=self::table('lesson_progress');
        $where=['1=1']; $args=[]; if($client_id){$where[]='m.client_id=%d';$args[]=$client_id;} if($project_id){$where[]='m.project_id=%d';$args[]=$project_id;} if($user_id){$where[]='mu.user_id=%d';$args[]=$user_id;} if(!$user_id) self::apply_user_scope($where,$args,$client_id,$project_id,'mu.user_id');
        $w=implode(' AND ',$where);
        $sql="SELECT COUNT(mu.id) assigned, SUM(mu.status='completed') completed, COALESCE(AVG(NULLIF(mu.score,0)),0) avg_score FROM {$mu} mu INNER JOIN {$m} m ON m.id=mu.mission_id WHERE {$w}";
        $row=$args?$wpdb->get_row($wpdb->prepare($sql,...$args),ARRAY_A):$wpdb->get_row($sql,ARRAY_A);
        $courseWhere=['1=1']; $courseArgs=[]; if($client_id){$courseWhere[]='client_id=%d';$courseArgs[]=$client_id;} if($project_id){$courseWhere[]='project_id=%d';$courseArgs[]=$project_id;} $cw=implode(' AND ',$courseWhere);
        $courses=$courseArgs?(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$c} WHERE {$cw}",...$courseArgs)):(int)$wpdb->get_var("SELECT COUNT(*) FROM {$c} WHERE {$cw}");
        if($user_id){$lessonsDone=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$lp} WHERE user_id=%d",$user_id));}
        elseif($client_id||$project_id){$lessonWhere=[];$lessonArgs=[]; self::apply_user_scope($lessonWhere,$lessonArgs,$client_id,$project_id,'user_id'); $lw=$lessonWhere?implode(' AND ',$lessonWhere):'1=1'; $lessonsDone=$lessonArgs?(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$lp} WHERE {$lw}",...$lessonArgs)):0;}
        else {$lessonsDone=0;}
        $assigned=(int)($row['assigned']??0); $completed=(int)($row['completed']??0);
        return ['assigned'=>$assigned,'completed'=>$completed,'completion_rate'=>$assigned?round(($completed/$assigned)*100):0,'avg_score'=>round((float)($row['avg_score']??0)),'courses'=>$courses,'lessons_done'=>$lessonsDone];
    }

    public static function top_performers(int $client_id=0, int $project_id=0): array {
        global $wpdb; $m=self::table('missions'); $mu=self::table('mission_users'); $where=['1=1']; $args=[]; if($client_id){$where[]='m.client_id=%d';$args[]=$client_id;} if($project_id){$where[]='m.project_id=%d';$args[]=$project_id;}
        $ids=self::scope_user_ids($client_id,$project_id); if($ids){$where[]='mu.user_id IN ('.implode(',',array_fill(0,count($ids),'%d')).')'; $args=array_merge($args,$ids);} elseif($client_id||$project_id){$where[]='1=0';}
        $sql="SELECT u.display_name,COUNT(mu.id) total,SUM(mu.status='completed') completed,COALESCE(AVG(NULLIF(mu.score,0)),0) avg_score FROM {$mu} mu INNER JOIN {$m} m ON m.id=mu.mission_id INNER JOIN {$wpdb->users} u ON u.ID=mu.user_id WHERE ".implode(' AND ',$where)." GROUP BY mu.user_id ORDER BY completed DESC,avg_score DESC LIMIT 8";
        return $args?($wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A)?:[]):($wpdb->get_results($sql,ARRAY_A)?:[]);
    }



    public static function performance_summary(int $client_id=0, int $project_id=0, int $user_id=0, string $date_from='', string $date_to=''): array {
        global $wpdb;
        $m=self::table('missions'); $mu=self::table('mission_users'); $c=self::table('courses'); $l=self::table('lessons'); $lp=self::table('lesson_progress'); $cert=self::table('certificates');
        $scoped_ids = $user_id ? [$user_id] : self::scope_user_ids($client_id,$project_id);
        if(($client_id || $project_id || $user_id) && !$scoped_ids){
            return [
                'users'=>0,'active_courses'=>0,'assigned'=>0,'completed'=>0,'pending'=>0,'mission_rate'=>0,'avg_score'=>0,
                'lessons_total'=>0,'lessons_done'=>0,'academy_rate'=>0,'certificates'=>0,'cert_rate'=>0,'readiness'=>0,
                'top'=>[],'risk'=>[],'timeline'=>[],'activity'=>[]
            ];
        }
        $userWhere='1=1'; $userArgs=[];
        if($scoped_ids){ $userWhere='mu.user_id IN ('.implode(',',array_fill(0,count($scoped_ids),'%d')).')'; $userArgs=$scoped_ids; }
        $missionWhere=['1=1']; $missionArgs=[];
        if($client_id){$missionWhere[]='m.client_id=%d';$missionArgs[]=$client_id;}
        if($project_id){$missionWhere[]='m.project_id=%d';$missionArgs[]=$project_id;}
        if($scoped_ids){$missionWhere[]=$userWhere;$missionArgs=array_merge($missionArgs,$userArgs);} elseif($client_id||$project_id||$user_id){$missionWhere[]='1=0';}
        if($date_from){$missionWhere[]='DATE(COALESCE(mu.completed_at,mu.created_at)) >= %s';$missionArgs[]=$date_from;}
        if($date_to){$missionWhere[]='DATE(COALESCE(mu.completed_at,mu.created_at)) <= %s';$missionArgs[]=$date_to;}
        $mw=implode(' AND ',$missionWhere);
        $row=$missionArgs?$wpdb->get_row($wpdb->prepare("SELECT COUNT(mu.id) assigned, SUM(mu.status='completed') completed, COALESCE(AVG(NULLIF(mu.score,0)),0) avg_score FROM {$mu} mu INNER JOIN {$m} m ON m.id=mu.mission_id WHERE {$mw}",...$missionArgs),ARRAY_A):$wpdb->get_row("SELECT COUNT(mu.id) assigned, SUM(mu.status='completed') completed, COALESCE(AVG(NULLIF(mu.score,0)),0) avg_score FROM {$mu} mu INNER JOIN {$m} m ON m.id=mu.mission_id WHERE {$mw}",ARRAY_A);
        $courseWhere=['c.status=\'active\'']; $courseArgs=[];
        if($client_id){$courseWhere[]='c.client_id=%d';$courseArgs[]=$client_id;}
        if($project_id){$courseWhere[]='c.project_id=%d';$courseArgs[]=$project_id;}
        $cw=implode(' AND ',$courseWhere);
        $active_courses=$courseArgs?(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$c} c WHERE {$cw}",...$courseArgs)):(int)$wpdb->get_var("SELECT COUNT(*) FROM {$c} c WHERE {$cw}");
        $lessons_per_scope=$courseArgs?(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(l.id) FROM {$l} l INNER JOIN {$c} c ON c.id=l.course_id WHERE {$cw}",...$courseArgs)):(int)$wpdb->get_var("SELECT COUNT(l.id) FROM {$l} l INNER JOIN {$c} c ON c.id=l.course_id WHERE {$cw}");
        $users_count=count($scoped_ids);
        $lessonWhere=['1=1']; $lessonArgs=[];
        if($client_id){$lessonWhere[]='c.client_id=%d';$lessonArgs[]=$client_id;}
        if($project_id){$lessonWhere[]='c.project_id=%d';$lessonArgs[]=$project_id;}
        if($scoped_ids){$lessonWhere[]='lp.user_id IN ('.implode(',',array_fill(0,count($scoped_ids),'%d')).')';$lessonArgs=array_merge($lessonArgs,$scoped_ids);} elseif($client_id||$project_id||$user_id){$lessonWhere[]='1=0';}
        if($date_from){$lessonWhere[]='DATE(lp.completed_at) >= %s';$lessonArgs[]=$date_from;}
        if($date_to){$lessonWhere[]='DATE(lp.completed_at) <= %s';$lessonArgs[]=$date_to;}
        $lw=implode(' AND ',$lessonWhere);
        $lessons_done=$lessonArgs?(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT CONCAT(lp.user_id,':',lp.lesson_id)) FROM {$lp} lp INNER JOIN {$c} c ON c.id=lp.course_id WHERE {$lw}",...$lessonArgs)):(int)$wpdb->get_var("SELECT COUNT(DISTINCT CONCAT(lp.user_id,':',lp.lesson_id)) FROM {$lp} lp INNER JOIN {$c} c ON c.id=lp.course_id WHERE {$lw}");
        $lessons_total=max(0,$lessons_per_scope*max(1,$users_count));
        $certWhere=['1=1']; $certArgs=[];
        if($client_id){$certWhere[]='client_id=%d';$certArgs[]=$client_id;}
        if($project_id){$certWhere[]='project_id=%d';$certArgs[]=$project_id;}
        if($scoped_ids){$certWhere[]='user_id IN ('.implode(',',array_fill(0,count($scoped_ids),'%d')).')';$certArgs=array_merge($certArgs,$scoped_ids);} elseif($client_id||$project_id||$user_id){$certWhere[]='1=0';}
        if($date_from){$certWhere[]='DATE(issued_at) >= %s';$certArgs[]=$date_from;}
        if($date_to){$certWhere[]='DATE(issued_at) <= %s';$certArgs[]=$date_to;}
        $certW=implode(' AND ',$certWhere);
        $certificates=$certArgs?(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$cert} WHERE {$certW}",...$certArgs)):(int)$wpdb->get_var("SELECT COUNT(*) FROM {$cert} WHERE {$certW}");
        $assigned=(int)($row['assigned']??0); $completed=(int)($row['completed']??0); $pending=max(0,$assigned-$completed);
        $mission_rate=$assigned?round(($completed/$assigned)*100):0;
        $academy_rate=$lessons_total?round((min($lessons_done,$lessons_total)/$lessons_total)*100):0;
        $cert_possible=max(1,$active_courses*max(1,$users_count));
        $cert_rate=$active_courses&&$users_count?round(($certificates/$cert_possible)*100):0;
        $readiness=round(($mission_rate*0.45)+($academy_rate*0.35)+($cert_rate*0.20));
        $top=self::top_performers_v2($client_id,$project_id,$user_id,$date_from,$date_to,$scoped_ids);
        $risk=self::risk_users($client_id,$project_id,$date_from,$date_to,$scoped_ids);
        $timeline=self::completion_timeline($client_id,$project_id,$user_id,$date_from,$date_to,$scoped_ids);
        $activity=self::activity_feed($client_id,$project_id,$user_id,$date_from,$date_to,$scoped_ids);
        return ['users'=>$users_count,'active_courses'=>$active_courses,'assigned'=>$assigned,'completed'=>$completed,'pending'=>$pending,'mission_rate'=>$mission_rate,'avg_score'=>round((float)($row['avg_score']??0)),'lessons_total'=>$lessons_total,'lessons_done'=>$lessons_done,'academy_rate'=>$academy_rate,'certificates'=>$certificates,'cert_rate'=>$cert_rate,'readiness'=>$readiness,'top'=>$top,'risk'=>$risk,'timeline'=>$timeline,'activity'=>$activity];
    }

    public static function top_performers_v2(int $client_id=0,int $project_id=0,int $user_id=0,string $date_from='',string $date_to='',array $scoped_ids=[]): array {
        global $wpdb; $m=self::table('missions'); $mu=self::table('mission_users'); $lp=self::table('lesson_progress'); $cert=self::table('certificates');
        if($user_id) $scoped_ids=[$user_id];
        $where=['1=1']; $args=[];
        if($client_id){$where[]='m.client_id=%d';$args[]=$client_id;} if($project_id){$where[]='m.project_id=%d';$args[]=$project_id;}
        if($scoped_ids){$where[]='mu.user_id IN ('.implode(',',array_fill(0,count($scoped_ids),'%d')).')';$args=array_merge($args,$scoped_ids);} elseif($client_id||$project_id||$user_id){$where[]='1=0';}
        if($date_from){$where[]='DATE(COALESCE(mu.completed_at,mu.created_at)) >= %s';$args[]=$date_from;} if($date_to){$where[]='DATE(COALESCE(mu.completed_at,mu.created_at)) <= %s';$args[]=$date_to;}
        $sql="SELECT u.ID,u.display_name,COUNT(mu.id) total,SUM(mu.status='completed') completed,COALESCE(AVG(NULLIF(mu.score,0)),0) avg_score FROM {$mu} mu INNER JOIN {$m} m ON m.id=mu.mission_id INNER JOIN {$wpdb->users} u ON u.ID=mu.user_id WHERE ".implode(' AND ',$where)." GROUP BY mu.user_id ORDER BY completed DESC,avg_score DESC LIMIT 8";
        $rows=$args?($wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A)?:[]):($wpdb->get_results($sql,ARRAY_A)?:[]);
        foreach($rows as &$r){
            $uid=(int)$r['ID'];
            $r['lessons_done']=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT lesson_id) FROM {$lp} WHERE user_id=%d",$uid));
            $r['certificates']=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$cert} WHERE user_id=%d",$uid));
        }
        return $rows;
    }

    public static function risk_users(int $client_id=0,int $project_id=0,string $date_from='',string $date_to='',array $scoped_ids=[]): array {
        global $wpdb; $m=self::table('missions'); $mu=self::table('mission_users');
        $where=['1=1']; $args=[];
        if($client_id){$where[]='m.client_id=%d';$args[]=$client_id;} if($project_id){$where[]='m.project_id=%d';$args[]=$project_id;}
        if($scoped_ids){$where[]='mu.user_id IN ('.implode(',',array_fill(0,count($scoped_ids),'%d')).')';$args=array_merge($args,$scoped_ids);} elseif($client_id||$project_id){$where[]='1=0';}
        if($date_from){$where[]='DATE(COALESCE(mu.completed_at,mu.created_at)) >= %s';$args[]=$date_from;} if($date_to){$where[]='DATE(COALESCE(mu.completed_at,mu.created_at)) <= %s';$args[]=$date_to;}
        $sql="SELECT u.display_name,COUNT(mu.id) assigned,SUM(mu.status='completed') completed,MAX(mu.completed_at) last_completed FROM {$mu} mu INNER JOIN {$m} m ON m.id=mu.mission_id INNER JOIN {$wpdb->users} u ON u.ID=mu.user_id WHERE ".implode(' AND ',$where)." GROUP BY mu.user_id HAVING assigned>0 AND (completed/assigned)<0.65 ORDER BY (completed/assigned) ASC, assigned DESC LIMIT 6";
        return $args?($wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A)?:[]):($wpdb->get_results($sql,ARRAY_A)?:[]);
    }

    public static function completion_timeline(int $client_id=0,int $project_id=0,int $user_id=0,string $date_from='',string $date_to='',array $scoped_ids=[]): array {
        global $wpdb; $m=self::table('missions'); $mu=self::table('mission_users');
        $where=["mu.status='completed'",'mu.completed_at IS NOT NULL']; $args=[];
        if($client_id){$where[]='m.client_id=%d';$args[]=$client_id;} if($project_id){$where[]='m.project_id=%d';$args[]=$project_id;}
        if($user_id){$where[]='mu.user_id=%d';$args[]=$user_id;} elseif($scoped_ids){$where[]='mu.user_id IN ('.implode(',',array_fill(0,count($scoped_ids),'%d')).')';$args=array_merge($args,$scoped_ids);} elseif($client_id||$project_id){$where[]='1=0';}
        if($date_from){$where[]='DATE(mu.completed_at) >= %s';$args[]=$date_from;} if($date_to){$where[]='DATE(mu.completed_at) <= %s';$args[]=$date_to;}
        $sql="SELECT DATE(mu.completed_at) d, COUNT(*) c FROM {$mu} mu INNER JOIN {$m} m ON m.id=mu.mission_id WHERE ".implode(' AND ',$where)." GROUP BY DATE(mu.completed_at) ORDER BY d ASC LIMIT 14";
        return $args?($wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A)?:[]):($wpdb->get_results($sql,ARRAY_A)?:[]);
    }

    private static function activity_cutoff_key(int $client_id=0, int $project_id=0): string {
        return 'fieldflow_perf_activity_cutoff_' . max(0, $client_id) . '_' . max(0, $project_id);
    }

    private static function get_activity_cutoff(int $client_id=0, int $project_id=0): string {
        $specific = (string) get_option(self::activity_cutoff_key($client_id, $project_id), '');
        if ($specific !== '') return $specific;
        return (string) get_option(self::activity_cutoff_key(0, 0), '');
    }

    public static function handle_clear_activity(): void {
        if (!self::user_can_manage()) wp_die('Sem permissões.');
        check_admin_referer('fieldflow_perf_clear_activity');
        $client_id = absint($_POST['client_id'] ?? 0);
        $project_id = absint($_POST['project_id'] ?? 0);
        update_option(self::activity_cutoff_key($client_id, $project_id), current_time('mysql'), false);
        $ref = wp_get_referer() ?: home_url('/');
        $ref = add_query_arg('ffp_activity_cleared', '1', $ref);
        wp_safe_redirect($ref . '#fieldflow-growth-hub');
        exit;
    }

    public static function activity_feed(int $client_id=0,int $project_id=0,int $user_id=0,string $date_from='',string $date_to='',array $scoped_ids=[]): array {
        global $wpdb;
        $items=[];
        $lp=self::table('lesson_progress'); $courses=self::table('courses'); $cert=self::table('certificates'); $mu=self::table('mission_users'); $m=self::table('missions');
        $scopeSql=''; $scopeArgs=[];
        if($user_id){$scopeSql=' AND ev_user_id=%d'; $scopeArgs[]=$user_id;}
        elseif($scoped_ids){$scopeSql=' AND ev_user_id IN ('.implode(',',array_fill(0,count($scoped_ids),'%d')).')'; $scopeArgs=array_merge($scopeArgs,$scoped_ids);}
        elseif($client_id||$project_id){$scopeSql=' AND 1=0';}
        $dateSql=''; $dateArgs=[];
        if($date_from){$dateSql.=' AND DATE(ev_date) >= %s'; $dateArgs[]=$date_from;}
        if($date_to){$dateSql.=' AND DATE(ev_date) <= %s'; $dateArgs[]=$date_to;}
        $cutoff = self::get_activity_cutoff($client_id, $project_id);
        if($cutoff !== ''){ $dateSql.=' AND ev_date > %s'; $dateArgs[]=$cutoff; }
        $baseArgs=[];
        $clientCourse=''; if($client_id){$clientCourse.=' AND c.client_id=%d'; $baseArgs[]=$client_id;} if($project_id){$clientCourse.=' AND c.project_id=%d'; $baseArgs[]=$project_id;}
        $clientMission=''; $missionArgs=[]; if($client_id){$clientMission.=' AND m.client_id=%d'; $missionArgs[]=$client_id;} if($project_id){$clientMission.=' AND m.project_id=%d'; $missionArgs[]=$project_id;}
        $sql="
            SELECT * FROM (
                SELECT lp.user_id ev_user_id, u.display_name, 'Lição concluída' label, c.title item, lp.completed_at ev_date
                FROM {$lp} lp INNER JOIN {$courses} c ON c.id=lp.course_id INNER JOIN {$wpdb->users} u ON u.ID=lp.user_id
                WHERE lp.completed_at IS NOT NULL {$clientCourse}
                UNION ALL
                SELECT ce.user_id ev_user_id, u.display_name, 'Certificado emitido' label, c.title item, ce.issued_at ev_date
                FROM {$cert} ce LEFT JOIN {$courses} c ON c.id=ce.course_id INNER JOIN {$wpdb->users} u ON u.ID=ce.user_id
                WHERE ce.issued_at IS NOT NULL {$clientCourse}
                UNION ALL
                SELECT mu.user_id ev_user_id, u.display_name, 'Missão concluída' label, m.title item, mu.completed_at ev_date
                FROM {$mu} mu INNER JOIN {$m} m ON m.id=mu.mission_id INNER JOIN {$wpdb->users} u ON u.ID=mu.user_id
                WHERE mu.completed_at IS NOT NULL {$clientMission}
            ) x WHERE ev_date IS NOT NULL {$scopeSql} {$dateSql}
            ORDER BY ev_date DESC LIMIT 80";
        $args=array_merge($baseArgs,$baseArgs,$missionArgs,$scopeArgs,$dateArgs);
        $rows=$args?($wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A)?:[]):($wpdb->get_results($sql,ARRAY_A)?:[]);
        foreach($rows as $r){
            $items[]=[
                'label'=>(string)($r['label']??''),
                'item'=>(string)($r['item']??''),
                'display_name'=>(string)($r['display_name']??''),
                'date_label'=>!empty($r['ev_date']) ? date_i18n('d/m H:i', strtotime($r['ev_date'])) : '',
            ];
        }
        return $items;
    }

    public static function ajax_dashboard_fragment(): void { if(!is_user_logged_in()) wp_send_json_error(['message'=>'Sessão necessária.'],403); $client_id=absint($_GET['client_id']??0); $project_id=absint($_GET['project_id']??0); if(class_exists('\RoutesPro\Support\Permissions')){ $perm=\RoutesPro\Support\Permissions::assert_scope_or_error($client_id,$project_id); if(is_wp_error($perm)) wp_send_json_error(['message'=>$perm->get_error_message()],403); } $title=sanitize_text_field($_GET['title']??'Growth Hub da Campanha'); $html=\RoutesPro\Front\PerformanceShortcodeRenderer::dashboard(['client_id'=>$client_id,'project_id'=>$project_id,'user_id'=>absint($_GET['user_id']??0),'date_from'=>sanitize_text_field($_GET['date_from']??''),'date_to'=>sanitize_text_field($_GET['date_to']??''),'title'=>$title]); wp_send_json_success(['html'=>$html]); }

    public static function get_course(int $id): ?array { global $wpdb; $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('courses')." WHERE id=%d",$id),ARRAY_A); return $row ?: null; }
    public static function get_media_item(int $id): ?array { global $wpdb; $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('media')." WHERE id=%d",$id),ARRAY_A); return $row ?: null; }

    public static function build_mission_quiz_from_post(): array {
        $quiz = [];
        $questions = (array)($_POST['quiz_question'] ?? []);
        $types = (array)($_POST['quiz_type'] ?? []);
        $answers = (array)($_POST['quiz_answer'] ?? []);
        $opts = (array)($_POST['quiz_options'] ?? []);
        $points = (array)($_POST['quiz_points'] ?? []);
        $required = (array)($_POST['quiz_required'] ?? []);
        $feedback = (array)($_POST['quiz_feedback'] ?? []);
        $allowed = ['multiple','truefalse','short','long','checklist','scale','evidence'];
        foreach ($questions as $i => $q) {
            $question = sanitize_text_field((string)$q);
            if ($question === '') continue;
            $type = sanitize_key((string)($types[$i] ?? 'multiple'));
            if (!in_array($type, $allowed, true)) $type = 'multiple';
            $answer = sanitize_text_field((string)($answers[$i] ?? ''));
            $options_raw = (string)($opts[$i] ?? '');
            $options = [];
            foreach (preg_split('/\r\n|\r|\n/', $options_raw) as $line) {
                $line = trim(sanitize_text_field($line));
                if ($line !== '') $options[] = $line;
            }
            if ($type === 'truefalse') $options = ['Verdadeiro','Falso'];
            if ($type === 'scale' && !$options) $options = ['1','2','3','4','5'];
            $quiz[] = [
                'question'=>$question,
                'type'=>$type,
                'options'=>$options,
                'answer'=>$answer,
                'points'=>max(1, absint($points[$i] ?? 1)),
                'required'=>isset($required[$i]) ? 1 : 0,
                'feedback'=>sanitize_text_field((string)($feedback[$i] ?? '')),
            ];
        }
        return $quiz;
    }

    public static function parse_mission_quiz($mission): array {
        $json = is_array($mission) ? (string)($mission['quiz_json'] ?? '') : (string)$mission;
        if ($json === '') return [];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private static function normalize_quiz_value($value): string {
        if (is_array($value)) {
            $value = array_filter(array_map('sanitize_text_field', array_map('strval', $value)), static fn($v) => trim($v) !== '');
            sort($value, SORT_NATURAL | SORT_FLAG_CASE);
            return mb_strtolower(trim(implode('|', $value)), 'UTF-8');
        }
        return mb_strtolower(trim(sanitize_text_field((string)$value)), 'UTF-8');
    }

    public static function grade_mission_quiz(array $quiz, array $answers): array {
        $total_points = 0; $earned_points = 0; $answered_required = true; $clean = [];
        foreach ($quiz as $i => $q) {
            $question = (string)($q['question'] ?? '');
            if ($question === '') continue;
            $type = sanitize_key((string)($q['type'] ?? 'multiple'));
            $points = max(1, absint($q['points'] ?? 1));
            $expected_raw = (string)($q['answer'] ?? '');
            $given_raw = $answers[$i] ?? '';
            $given_norm = self::normalize_quiz_value($given_raw);
            $expected_norm = self::normalize_quiz_value($expected_raw);
            $is_required = !empty($q['required']);
            if ($is_required && $given_norm === '') $answered_required = false;
            $scored = true;
            if (in_array($type, ['long','evidence'], true) && $expected_norm === '') $scored = false;
            if ($type === 'scale' && $expected_norm === '') $scored = false;
            $ok = false;
            if (!$scored) {
                $ok = ($given_norm !== '');
                if ($ok) $earned_points += $points;
            } else {
                if ($type === 'checklist') {
                    $expected = array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,|\|/', $expected_raw)));
                    $given = is_array($given_raw) ? array_map('strval', $given_raw) : array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,|\|/', (string)$given_raw)));
                    $expected = array_map(static fn($v) => mb_strtolower(trim($v), 'UTF-8'), $expected);
                    $given = array_map(static fn($v) => mb_strtolower(trim($v), 'UTF-8'), $given);
                    $ok = $expected ? !array_diff($expected, $given) : !empty($given);
                } else {
                    $ok = ($expected_norm !== '' && $given_norm !== '' && $expected_norm === $given_norm);
                }
                if ($ok) $earned_points += $points;
            }
            $answer_for_log = is_array($given_raw) ? implode(', ', array_map('sanitize_text_field', array_map('strval', $given_raw))) : sanitize_text_field((string)$given_raw);
            $clean[] = ['question'=>$question,'type'=>$type,'answer'=>$answer_for_log,'correct'=>$ok ? 1 : 0,'points'=>$ok ? $points : 0,'max_points'=>$points];
            $total_points += $points;
        }
        $score = $total_points ? (int)round(($earned_points / $total_points) * 100) : 100;
        if (!$answered_required) $score = 0;
        return ['score'=>$score,'total'=>$total_points,'correct'=>$earned_points,'answers'=>$clean];
    }

    public static function get_mission(int $id): ?array { global $wpdb; $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('missions')." WHERE id=%d",$id),ARRAY_A); return $row ?: null; }

    private static function redirect_back_to_academy(): void {
        $ref = wp_get_referer() ?: home_url('/');
        $ref = remove_query_arg(['ffp_saved','ffp_error'], $ref);
        $ref = add_query_arg('ffp_panel', 'academy', $ref);
        wp_safe_redirect($ref . '#rp-app-academy');
        exit;
    }

    private static function redirect_admin(string $tab, array $args=[]): void {
        $url = admin_url('admin.php?page=routespro-performance&tab=' . sanitize_key($tab));
        if ($args) $url = add_query_arg($args, $url);
        wp_safe_redirect($url);
        exit;
    }

    public static function detect_content_type(string $url): string { $u=strtolower($url); if(strpos($u,'canva.com')!==false) return 'canva'; if(strpos($u,'youtube.com')!==false || strpos($u,'youtu.be')!==false) return 'youtube'; if(preg_match('/\.pdf(\?|$)/',$u)) return 'pdf'; return 'link'; }

    public static function handle_save_media(): void {
        if(!self::user_can_manage()) wp_die('Sem permissões.'); check_admin_referer('fieldflow_perf_save_media'); global $wpdb;
        $id=absint($_POST['media_id']??0); $url=esc_url_raw($_POST['media_url']??''); $type=sanitize_key($_POST['media_type']??''); if(!$type||$type==='auto') $type=self::detect_content_type($url);
        $title=sanitize_text_field($_POST['title']??''); if($title===''||$url==='') wp_die('Título e URL obrigatórios.');
        $data=['client_id'=>absint($_POST['client_id']??0)?:null,'project_id'=>absint($_POST['project_id']??0)?:null,'title'=>$title,'media_type'=>$type,'media_url'=>$url,'notes'=>wp_kses_post($_POST['notes']??''),'status'=>sanitize_key($_POST['status']??'active') ?: 'active'];
        if($id) $wpdb->update(self::table('media'), $data, ['id'=>$id]); else $wpdb->insert(self::table('media'), $data);
        self::redirect_admin('media',['saved'=>1]);
    }

    public static function handle_delete_media(): void {
        if(!self::user_can_manage()) wp_die('Sem permissões.'); $id=absint($_GET['id']??0); check_admin_referer('fieldflow_perf_delete_media_'.$id); global $wpdb;
        if($id) $wpdb->delete(self::table('media'), ['id'=>$id]);
        self::redirect_admin('media',['deleted'=>1]);
    }

    public static function handle_save_course(): void {
        if(!self::user_can_manage()) wp_die('Sem permissões.'); check_admin_referer('fieldflow_perf_save_course'); global $wpdb;
        $course_id=absint($_POST['course_id']??0);
        $data=['client_id'=>absint($_POST['client_id']??0)?:null,'project_id'=>absint($_POST['project_id']??0)?:null,'title'=>sanitize_text_field($_POST['title']??''),'description'=>wp_kses_post($_POST['description']??''),'content_url'=>esc_url_raw($_POST['content_url']??''),'duration_min'=>absint($_POST['duration_min']??0),'points'=>absint($_POST['points']??50),'status'=>sanitize_key($_POST['status']??'active') ?: 'active'];
        if($data['title']==='') wp_die('Título obrigatório.');
        if($course_id){ $wpdb->update(self::table('courses'),$data,['id'=>$course_id]); $wpdb->delete(self::table('lessons'),['course_id'=>$course_id]); }
        else { $wpdb->insert(self::table('courses'),$data); $course_id=(int)$wpdb->insert_id; }
        $lesson_titles=(array)($_POST['lesson_title']??[]); $lesson_urls=(array)($_POST['lesson_url']??[]); $lesson_types=(array)($_POST['lesson_type']??[]); $lesson_bodies=(array)($_POST['lesson_body']??[]); $lesson_mins=(array)($_POST['lesson_min']??[]); $lesson_required=(array)($_POST['lesson_required']??[]); $lesson_min_watch=(array)($_POST['lesson_min_watch']??[]); $lesson_order=(array)($_POST['lesson_order']??[]);
        for($i=0;$i<count($lesson_titles);$i++){ $lt=sanitize_text_field($lesson_titles[$i]??''); $lu=esc_url_raw($lesson_urls[$i]??''); $lb=wp_kses_post($lesson_bodies[$i]??''); if($lt==='' && $lu==='' && $lb==='') continue; $type=sanitize_key($lesson_types[$i]??''); if(!$type||$type==='auto') $type=self::detect_content_type($lu); $is_required=!empty($lesson_required[$i])?1:0; $watch=max(0,absint($lesson_min_watch[$i]??8)); $order=absint($lesson_order[$i]??($i+1)); $wpdb->insert(self::table('lessons'), ['course_id'=>$course_id,'title'=>$lt ?: 'Conteúdo '.($i+1),'body'=>$lb,'content_type'=>$type,'media_url'=>$lu,'estimated_min'=>max(1,absint($lesson_mins[$i]??3)),'is_required'=>$is_required,'min_watch_seconds'=>$watch,'sort_order'=>$order]); }
        self::redirect_admin('academy',['saved'=>1]);
    }


    public static function handle_duplicate_course(): void {
        if(!self::user_can_manage()) wp_die('Sem permissões.');
        $id=absint($_GET['id']??0);
        check_admin_referer('fieldflow_perf_duplicate_course_'.$id);
        global $wpdb;
        $course=self::get_course($id); if(!$course) self::redirect_admin('academy',['duplicated'=>0]);
        $new=$course; unset($new['id'],$new['lessons_count'],$new['created_at'],$new['updated_at']);
        $new['title']=sanitize_text_field(($course['title']??'Curso').' - cópia');
        $new['status']='draft';
        $wpdb->insert(self::table('courses'),$new);
        $new_id=(int)$wpdb->insert_id;
        foreach(self::get_lessons($id) as $l){
            $row=[
                'course_id'=>$new_id,'module_id'=>!empty($l['module_id'])?(int)$l['module_id']:null,'title'=>$l['title'],'body'=>$l['body'],
                'content_type'=>$l['content_type'],'media_url'=>$l['media_url'],'media_id'=>!empty($l['media_id'])?(int)$l['media_id']:null,
                'estimated_min'=>(int)$l['estimated_min'],'is_required'=>(int)$l['is_required'],'min_watch_seconds'=>(int)($l['min_watch_seconds']??8),'sort_order'=>(int)$l['sort_order']
            ];
            $wpdb->insert(self::table('lessons'),$row);
        }
        self::redirect_admin('academy',['duplicated'=>1,'edit'=>$new_id]);
    }


    public static function handle_duplicate_course_to_scope(): void {
        if(!self::user_can_manage()) wp_die('Sem permissões.');
        check_admin_referer('fieldflow_perf_duplicate_course_to_scope');
        $id=absint($_POST['course_id']??0);
        $client_id=absint($_POST['target_client_id']??0);
        $project_id=absint($_POST['target_project_id']??0);
        $status=sanitize_key($_POST['target_status']??'draft') ?: 'draft';
        $status=in_array($status,['active','draft','archived'],true)?$status:'draft';
        global $wpdb;
        $course=self::get_course($id); if(!$course) self::redirect_admin('academy',['duplicated'=>0]);
        $new=$course; unset($new['id'],$new['lessons_count'],$new['created_at'],$new['updated_at']);
        $new['client_id']=$client_id?:null;
        $new['project_id']=$project_id?:null;
        $new['title']=sanitize_text_field(($course['title']??'Curso').' - nova campanha');
        $new['status']=$status;
        $wpdb->insert(self::table('courses'),$new);
        $new_id=(int)$wpdb->insert_id;
        foreach(self::get_lessons($id) as $l){
            $row=[
                'course_id'=>$new_id,'module_id'=>!empty($l['module_id'])?(int)$l['module_id']:null,'title'=>$l['title'],'body'=>$l['body'],
                'content_type'=>$l['content_type'],'media_url'=>$l['media_url'],'media_id'=>!empty($l['media_id'])?(int)$l['media_id']:null,
                'estimated_min'=>(int)$l['estimated_min'],'is_required'=>(int)$l['is_required'],'min_watch_seconds'=>(int)($l['min_watch_seconds']??8),'sort_order'=>(int)$l['sort_order']
            ];
            $wpdb->insert(self::table('lessons'),$row);
        }
        self::redirect_admin('academy',['duplicated'=>1,'edit'=>$new_id]);
    }

    public static function handle_move_lesson(): void {
        if(!self::user_can_manage()) wp_die('Sem permissões.');
        $lesson_id=absint($_GET['lesson_id']??0);
        $course_id=absint($_GET['course_id']??0);
        $dir=sanitize_key($_GET['dir']??'');
        check_admin_referer('fieldflow_perf_move_lesson_'.$lesson_id);
        if(!$lesson_id || !$course_id || !in_array($dir,['up','down'],true)) self::redirect_admin('academy',['edit'=>$course_id]);
        global $wpdb;
        $table=self::table('lessons');
        $current=$wpdb->get_row($wpdb->prepare("SELECT id, sort_order FROM $table WHERE id=%d AND course_id=%d",$lesson_id,$course_id),ARRAY_A);
        if(!$current) self::redirect_admin('academy',['edit'=>$course_id]);
        if($dir==='up'){
            $other=$wpdb->get_row($wpdb->prepare("SELECT id, sort_order FROM $table WHERE course_id=%d AND sort_order < %d ORDER BY sort_order DESC, id DESC LIMIT 1",$course_id,(int)$current['sort_order']),ARRAY_A);
        } else {
            $other=$wpdb->get_row($wpdb->prepare("SELECT id, sort_order FROM $table WHERE course_id=%d AND sort_order > %d ORDER BY sort_order ASC, id ASC LIMIT 1",$course_id,(int)$current['sort_order']),ARRAY_A);
        }
        if($other){
            $wpdb->update($table,['sort_order'=>(int)$other['sort_order']],['id'=>(int)$current['id']]);
            $wpdb->update($table,['sort_order'=>(int)$current['sort_order']],['id'=>(int)$other['id']]);
        }
        self::redirect_admin('academy',['edit'=>$course_id,'moved'=>1]);
    }

    public static function handle_delete_course(): void {
        if(!self::user_can_manage()) wp_die('Sem permissões.'); $id=absint($_GET['id']??0); check_admin_referer('fieldflow_perf_delete_course_'.$id); global $wpdb;
        if($id){ $wpdb->delete(self::table('lesson_progress'), ['course_id'=>$id]); $wpdb->delete(self::table('lessons'), ['course_id'=>$id]); $wpdb->update(self::table('missions'), ['course_id'=>null], ['course_id'=>$id]); $wpdb->delete(self::table('courses'), ['id'=>$id]); }
        self::redirect_admin('academy',['deleted'=>1]);
    }

    public static function handle_save_mission(): void {
        if(!self::user_can_manage()) wp_die('Sem permissões.'); check_admin_referer('fieldflow_perf_save_mission'); global $wpdb; $due=sanitize_text_field($_POST['due_at']??'');
        $mission_id=absint($_POST['mission_id']??0);
        $data=['client_id'=>absint($_POST['client_id']??0)?:null,'project_id'=>absint($_POST['project_id']??0)?:null,'course_id'=>absint($_POST['course_id']??0)?:null,'title'=>sanitize_text_field($_POST['title']??''),'description'=>wp_kses_post($_POST['description']??''),'mission_type'=>sanitize_key($_POST['mission_type']??'field_action'),'target_value'=>max(1,absint($_POST['target_value']??1)),'points'=>absint($_POST['points']??100),'due_at'=>$due?date('Y-m-d H:i:s',strtotime($due)):null,'priority'=>sanitize_key($_POST['priority']??'normal') ?: 'normal','evidence_type'=>sanitize_key($_POST['evidence_type']??'link') ?: 'link','evidence_required'=>!empty($_POST['evidence_required'])?1:0,'approval_required'=>!empty($_POST['approval_required'])?1:0,'success_criteria'=>wp_kses_post($_POST['success_criteria']??''),'mission_content_type'=>sanitize_key($_POST['mission_content_type']??'none') ?: 'none','mission_content_url'=>esc_url_raw($_POST['mission_content_url']??''),'quiz_enabled'=>(!empty($_POST['quiz_enabled']) || sanitize_key($_POST['mission_content_type']??'none')==='quiz')?1:0,'quiz_pass_score'=>max(0,min(100,absint($_POST['quiz_pass_score']??70))),'quiz_json'=>wp_json_encode(self::build_mission_quiz_from_post()),'status'=>sanitize_key($_POST['status']??'active') ?: 'active'];
        if($data['title']==='') wp_die('Título obrigatório.');
        if($mission_id){ $wpdb->update(self::table('missions'),$data,['id'=>$mission_id]); }
        else { $wpdb->insert(self::table('missions'),$data); $mission_id=(int)$wpdb->insert_id; }
        $user_ids=array_map('absint',(array)($_POST['user_ids']??[])); $allowed=self::scope_user_ids((int)($data['client_id']?:0),(int)($data['project_id']?:0));
        if(!empty($_POST['replace_users'])) $wpdb->delete(self::table('mission_users'), ['mission_id'=>$mission_id]);
        foreach(array_unique(array_filter($user_ids)) as $uid){ if($allowed && !in_array($uid,$allowed,true)) continue; $wpdb->replace(self::table('mission_users'), ['mission_id'=>$mission_id,'user_id'=>$uid,'status'=>'assigned']); }
        self::redirect_admin('missions',['saved'=>1]);
    }

    public static function handle_delete_mission(): void {
        if(!self::user_can_manage()) wp_die('Sem permissões.'); $id=absint($_GET['id']??0); check_admin_referer('fieldflow_perf_delete_mission_'.$id); global $wpdb;
        if($id){ $wpdb->delete(self::table('mission_users'), ['mission_id'=>$id]); $wpdb->delete(self::table('missions'), ['id'=>$id]); }
        self::redirect_admin('missions',['deleted'=>1]);
    }

    public static function handle_seed_demo(): void { if(!self::user_can_manage()) wp_die('Sem permissões.'); check_admin_referer('fieldflow_perf_seed_demo'); self::maybe_install(); self::seed_demo(); wp_safe_redirect(admin_url('admin.php?page=routespro-performance&tab=academy&demo=1')); exit; }

    public static function seed_demo(): array {
        global $wpdb; $course_table=self::table('courses'); $lesson_table=self::table('lessons'); $mission_table=self::table('missions'); $mu_table=self::table('mission_users'); $media_table=self::table('media');
        $client_id=(int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}routespro_clients ORDER BY id ASC LIMIT 1"); $project_id=$client_id?(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}routespro_projects WHERE client_id=%d ORDER BY id ASC LIMIT 1",$client_id)):0; if(!$project_id) $project_id=(int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}routespro_projects ORDER BY id ASC LIMIT 1");
        $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$course_table} WHERE title=%s LIMIT 1",'Negociação Comercial no PDV'));
        if($existing) return ['course_id'=>$existing,'created'=>false];
        $wpdb->insert($media_table, ['client_id'=>$client_id?:null,'project_id'=>$project_id?:null,'title'=>'Canva exemplo de apresentação comercial','media_type'=>'canva','media_url'=>'https://www.canva.com/','notes'=>'Substituir pelo link Canva real do cliente.','status'=>'active']);
        $wpdb->insert($media_table, ['client_id'=>$client_id?:null,'project_id'=>$project_id?:null,'title'=>'Video exemplo de negociação','media_type'=>'youtube','media_url'=>'https://www.youtube.com/watch?v=dQw4w9WgXcQ','notes'=>'Substituir por video corporativo.','status'=>'active']);
        $wpdb->insert($course_table, ['client_id'=>$client_id?:null,'project_id'=>$project_id?:null,'title'=>'Negociação Comercial no PDV','description'=>'Curso prático para equipas de terreno: abordagem inicial, leitura de necessidade, resposta a objeções, defesa de valor e fecho simples no ponto de venda. Criado como demonstração premium do FieldFlow Academy.','content_url'=>'','duration_min'=>18,'points'=>120,'status'=>'active']);
        $course_id=(int)$wpdb->insert_id;
        $lessons=[
            ['Abertura de conversa em 30 segundos','Texto Rico','texto de enquadramento prático para iniciar conversa com naturalidade.','text','',3],
            ['Argumentar valor sem cair no desconto','Canva','Apresentação de apoio comercial. Substituir pelo Canva oficial da campanha.','canva','https://www.canva.com/',5],
            ['Objeção: está caro','YouTube','Video curto para praticar a resposta à objeção de preço.','youtube','https://www.youtube.com/watch?v=dQw4w9WgXcQ',4],
            ['Guia rápido de fecho alternativo','Link externo','Material complementar da campanha.','link','https://www.thewildtheory.com/',3],
        ];
        foreach($lessons as $i=>$l){ $wpdb->insert($lesson_table, ['course_id'=>$course_id,'title'=>$l[0],'body'=>$l[2],'content_type'=>$l[3],'media_url'=>$l[4],'estimated_min'=>$l[5],'is_required'=>1,'sort_order'=>$i+1]); }
        $wpdb->insert($mission_table, ['client_id'=>$client_id?:null,'project_id'=>$project_id?:null,'course_id'=>$course_id,'title'=>'Aplicar fecho alternativo em 3 interações','description'=>'Durante o turno, aplicar a técnica de fecho alternativo com pelo menos 3 clientes. No final, registar uma nota com o que funcionou melhor e, se aplicável, anexar evidência.','mission_type'=>'sales','target_value'=>3,'points'=>120,'due_at'=>date('Y-m-d H:i:s',strtotime('+7 days')),'status'=>'active']);
        $mission_id=(int)$wpdb->insert_id; $users=self::get_users_for_project($project_id); $count=0; foreach(array_slice($users,0,8) as $u){$uid=is_object($u)?(int)$u->ID:(int)($u['ID']??0); if(!$uid) continue; $wpdb->replace($mu_table,['mission_id'=>$mission_id,'user_id'=>$uid,'status'=>'assigned','score'=>0]); $count++;}
        return ['course_id'=>$course_id,'mission_id'=>$mission_id,'assigned'=>$count,'created'=>true];
    }


    public static function get_forms(): array {
        global $wpdb;
        $t = $wpdb->prefix . 'routespro_forms';
        return $wpdb->get_results("SELECT id,title FROM {$t} ORDER BY title ASC", ARRAY_A) ?: [];
    }

    public static function get_automations(array $filters=[]): array {
        global $wpdb; $t=self::table('automations'); $where=['1=1']; $args=[];
        if(!empty($filters['client_id'])){$where[]='client_id=%d';$args[]=(int)$filters['client_id'];}
        if(!empty($filters['project_id'])){$where[]='project_id=%d';$args[]=(int)$filters['project_id'];}
        $sql="SELECT * FROM {$t} WHERE ".implode(' AND ',$where)." ORDER BY updated_at DESC,id DESC";
        return $args ? ($wpdb->get_results($wpdb->prepare($sql,...$args),ARRAY_A) ?: []) : ($wpdb->get_results($sql,ARRAY_A) ?: []);
    }

    public static function handle_save_automation(): void {
        if(!self::user_can_manage()) wp_die('Sem permissões.'); check_admin_referer('fieldflow_perf_save_automation'); global $wpdb;
        $id=absint($_POST['automation_id']??0);
        $data=[
            'name'=>sanitize_text_field($_POST['name']??''),
            'client_id'=>absint($_POST['client_id']??0)?:null,
            'project_id'=>absint($_POST['project_id']??0)?:null,
            'form_id'=>absint($_POST['form_id']??0)?:null,
            'question_key'=>sanitize_text_field($_POST['question_key']??''),
            'question_label'=>sanitize_text_field($_POST['question_label']??''),
            'operator'=>sanitize_key($_POST['operator']??'equals') ?: 'equals',
            'compare_value'=>sanitize_text_field($_POST['compare_value']??''),
            'action_mission_title'=>sanitize_text_field($_POST['action_mission_title']??''),
            'action_mission_description'=>wp_kses_post($_POST['action_mission_description']??''),
            'action_course_id'=>absint($_POST['action_course_id']??0)?:null,
            'action_points'=>(int)($_POST['action_points']??0),
            'action_email'=>!empty($_POST['action_email'])?1:0,
            'email_subject'=>sanitize_text_field($_POST['email_subject']??''),
            'email_body'=>wp_kses_post($_POST['email_body']??''),
            'status'=>sanitize_key($_POST['status']??'active') ?: 'active'
        ];
        if($data['name']==='') $data['name']='Regra automática';
        if($id) $wpdb->update(self::table('automations'),$data,['id'=>$id]); else $wpdb->insert(self::table('automations'),$data);
        self::redirect_admin('automations',['saved'=>1]);
    }

    public static function handle_delete_automation(): void {
        if(!self::user_can_manage()) wp_die('Sem permissões.'); $id=absint($_GET['id']??0); check_admin_referer('fieldflow_perf_delete_automation_'.$id); global $wpdb;
        if($id) $wpdb->delete(self::table('automations'),['id'=>$id]);
        self::redirect_admin('automations',['deleted'=>1]);
    }

    private static function automation_match(string $actual, string $operator, string $expected): bool {
        $a=trim(mb_strtolower(wp_strip_all_tags($actual),'UTF-8'));
        $e=trim(mb_strtolower(wp_strip_all_tags($expected),'UTF-8'));
        switch($operator){
            case 'not_equals': return $a !== $e;
            case 'contains': return $e !== '' && strpos($a,$e) !== false;
            case 'not_contains': return $e === '' || strpos($a,$e) === false;
            case 'greater_than': return is_numeric($actual) && is_numeric($expected) && (float)$actual > (float)$expected;
            case 'less_than': return is_numeric($actual) && is_numeric($expected) && (float)$actual < (float)$expected;
            case 'equals': default: return $a === $e;
        }
    }

    public static function handle_form_submission_automation(int $submission_id, array $submission, array $answers): void {
        global $wpdb;
        $client_id=(int)($submission['client_id']??0); $project_id=(int)($submission['project_id']??0); $form_id=(int)($submission['form_id']??0); $user_id=(int)($submission['user_id']??0);
        if($submission_id<=0 || $user_id<=0) return;
        $rules=self::get_automations(['client_id'=>0]);
        $rules=array_filter($rules, function($r) use($client_id,$project_id,$form_id){
            if(($r['status']??'')!=='active') return false;
            if(!empty($r['client_id']) && (int)$r['client_id']!==$client_id) return false;
            if(!empty($r['project_id']) && (int)$r['project_id']!==$project_id) return false;
            if(!empty($r['form_id']) && (int)$r['form_id']!==$form_id) return false;
            return true;
        });
        if(!$rules) return;
        foreach($rules as $rule){
            $actual=null;
            foreach($answers as $key=>$item){
                $label=(string)($item['label']??'');
                if(!empty($rule['question_key']) && (string)$rule['question_key']===(string)$key){ $actual=$item['value']??''; break; }
                if(!empty($rule['question_label']) && mb_strtolower($rule['question_label'],'UTF-8')===mb_strtolower($label,'UTF-8')){ $actual=$item['value']??''; break; }
            }
            if(is_array($actual)) $actual=implode(', ',array_map('strval',$actual));
            if($actual===null || !self::automation_match((string)$actual,(string)$rule['operator'],(string)$rule['compare_value'])) continue;
            $actions=[];
            $course_id=(int)($rule['action_course_id']??0);
            $mission_title=trim((string)($rule['action_mission_title']??''));
            if($mission_title!=='' || $course_id){
                $title=$mission_title!==''?$mission_title:'Formação recomendada';
                if($course_id){ $course=self::get_course($course_id); if($course && $mission_title==='') $title='Formação recomendada: '.$course['title']; }
                $mission_id=self::create_or_assign_automation_mission($title,(string)($rule['action_mission_description']??''),$client_id,$project_id,$course_id,$user_id,(int)($rule['action_points']??0));
                if($mission_id) $actions[]=['type'=>'mission','mission_id'=>$mission_id,'user_id'=>$user_id];
            }
            if(!empty($rule['action_email'])){
                $u=get_user_by('id',$user_id);
                if($u && $u->user_email){
                    $sub=self::replace_automation_vars((string)($rule['email_subject']?:'Nova ação FieldFlow'),$rule,$submission,$actual);
                    $body=self::replace_automation_vars((string)($rule['email_body']?:'Foi criada uma nova ação no FieldFlow.'),$rule,$submission,$actual);
                    wp_mail($u->user_email,$sub,wp_strip_all_tags($body));
                    $actions[]=['type'=>'email','to'=>$u->user_email];
                }
            }
            $wpdb->insert(self::table('automation_logs'),[
                'automation_id'=>(int)$rule['id'],'submission_id'=>$submission_id,'user_id'=>$user_id,'client_id'=>$client_id?:null,'project_id'=>$project_id?:null,'result'=>'matched','actions_json'=>wp_json_encode($actions,JSON_UNESCAPED_UNICODE),'created_at'=>current_time('mysql')
            ]);
        }
    }

    private static function replace_automation_vars(string $text, array $rule, array $submission, string $answer): string {
        $user=get_user_by('id',(int)($submission['user_id']??0));
        $vars=['{{name}}'=>$user?$user->display_name:'','{{answer}}'=>$answer,'{{rule}}'=>(string)($rule['name']??''),'{{submission_id}}'=>(string)($submission['id']??'')];
        return strtr($text,$vars);
    }

    private static function create_or_assign_automation_mission(string $title,string $description,int $client_id,int $project_id,int $course_id,int $user_id,int $points=0): int {
        global $wpdb; $m=self::table('missions'); $mu=self::table('mission_users');
        $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$m} WHERE title=%s AND client_id=%d AND project_id=%d AND COALESCE(course_id,0)=%d AND status='active' ORDER BY id DESC LIMIT 1",$title,$client_id,$project_id,$course_id));
        $mission_id=$existing;
        if(!$mission_id){
            $wpdb->insert($m,['client_id'=>$client_id?:null,'project_id'=>$project_id?:null,'course_id'=>$course_id?:null,'title'=>$title,'description'=>$description,'mission_type'=>'automation','target_value'=>1,'points'=>$points?:100,'due_at'=>date('Y-m-d H:i:s',current_time('timestamp')+(2*DAY_IN_SECONDS)),'status'=>'active','created_at'=>current_time('mysql')]);
            $mission_id=(int)$wpdb->insert_id;
        }
        if($mission_id){
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$mu} (mission_id,user_id,status,score,created_at) VALUES (%d,%d,'assigned',0,%s)",$mission_id,$user_id,current_time('mysql')));
        }
        return $mission_id;
    }

    public static function automation_logs(int $limit=50): array {
        global $wpdb; $t=self::table('automation_logs'); $a=self::table('automations');
        return $wpdb->get_results($wpdb->prepare("SELECT l.*, r.name AS rule_name, u.display_name FROM {$t} l LEFT JOIN {$a} r ON r.id=l.automation_id LEFT JOIN {$wpdb->users} u ON u.ID=l.user_id ORDER BY l.created_at DESC LIMIT %d",$limit),ARRAY_A) ?: [];
    }


    public static function get_certificate_settings(int $client_id=0, int $project_id=0): array {
        global $wpdb; $t=self::table('certificate_settings');
        $row=null;
        if($project_id){ $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE project_id=%d AND status='active' ORDER BY id DESC LIMIT 1",$project_id),ARRAY_A); }
        if(!$row && $client_id){ $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE client_id=%d AND (project_id IS NULL OR project_id=0) AND status='active' ORDER BY id DESC LIMIT 1",$client_id),ARRAY_A); }
        if(!$row){ $row=$wpdb->get_row("SELECT * FROM {$t} WHERE (client_id IS NULL OR client_id=0) AND (project_id IS NULL OR project_id=0) AND status='active' ORDER BY id DESC LIMIT 1",ARRAY_A); }
        return $row ?: ['template_name'=>'Premium Certificate','certificate_title'=>'Certificate of Completion','certificate_text'=>'Certificamos que {{name}} concluiu com sucesso {{course}} no âmbito {{campaign}}.','primary_color'=>'#0f172a','accent_color'=>'#d9a441','background_color'=>'#ffffff','title_color'=>'#0f172a','subtitle_color'=>'#64748b','body_color'=>'#334155','link_color'=>'#2563eb','line_color'=>'#d9a441','button_color'=>'#0f172a','button_text_color'=>'#ffffff','modal_bg_color'=>'#ffffff','modal_text_color'=>'#0f172a','card_bg_color'=>'#ffffff','title_font'=>'Helvetica-Bold','body_font'=>'Helvetica','signer_name'=>'The Wild Theory','signer_role'=>'FieldFlow Academy','seal_image_url'=>''];
    }

    public static function handle_save_certificate_settings(): void {
        if(!self::user_can_manage()) wp_die('Sem permissões.'); check_admin_referer('fieldflow_perf_save_certificate_settings'); global $wpdb;
        $id=absint($_POST['settings_id']??0);
        $data=[
            'client_id'=>absint($_POST['client_id']??0)?:null,
            'project_id'=>absint($_POST['project_id']??0)?:null,
            'template_name'=>sanitize_text_field($_POST['template_name']??'Premium Certificate'),
            'logo_left_url'=>esc_url_raw($_POST['logo_left_url']??''),
            'logo_right_url'=>esc_url_raw($_POST['logo_right_url']??''),
            'signature_url'=>esc_url_raw($_POST['signature_url']??''),
            'seal_image_url'=>esc_url_raw($_POST['seal_image_url']??''),
            'signer_name'=>sanitize_text_field($_POST['signer_name']??''),
            'signer_role'=>sanitize_text_field($_POST['signer_role']??''),
            'primary_color'=>sanitize_hex_color($_POST['primary_color']??'#0f172a') ?: '#0f172a',
            'accent_color'=>sanitize_hex_color($_POST['accent_color']??'#d9a441') ?: '#d9a441',
            'background_color'=>sanitize_hex_color($_POST['background_color']??'#ffffff') ?: '#ffffff',
            'title_color'=>sanitize_hex_color($_POST['title_color']??'#0f172a') ?: '#0f172a',
            'subtitle_color'=>sanitize_hex_color($_POST['subtitle_color']??'#64748b') ?: '#64748b',
            'body_color'=>sanitize_hex_color($_POST['body_color']??'#334155') ?: '#334155',
            'link_color'=>sanitize_hex_color($_POST['link_color']??'#2563eb') ?: '#2563eb',
            'line_color'=>sanitize_hex_color($_POST['line_color']??'#d9a441') ?: '#d9a441',
            'button_color'=>sanitize_hex_color($_POST['button_color']??'#0f172a') ?: '#0f172a',
            'button_text_color'=>sanitize_hex_color($_POST['button_text_color']??'#ffffff') ?: '#ffffff',
            'modal_bg_color'=>sanitize_hex_color($_POST['modal_bg_color']??'#ffffff') ?: '#ffffff',
            'modal_text_color'=>sanitize_hex_color($_POST['modal_text_color']??'#0f172a') ?: '#0f172a',
            'card_bg_color'=>sanitize_hex_color($_POST['card_bg_color']??'#ffffff') ?: '#ffffff',
            'title_font'=>sanitize_text_field($_POST['title_font']??'Helvetica-Bold'),
            'body_font'=>sanitize_text_field($_POST['body_font']??'Helvetica'),
            'certificate_title'=>sanitize_text_field($_POST['certificate_title']??'Certificate of Completion'),
            'certificate_text'=>wp_kses_post($_POST['certificate_text']??'Certificamos que {{name}} concluiu com sucesso {{course}} no âmbito {{campaign}}.'),
            'status'=>sanitize_key($_POST['status']??'active') ?: 'active'
        ];
        if($id) $wpdb->update(self::table('certificate_settings'),$data,['id'=>$id]); else $wpdb->insert(self::table('certificate_settings'),$data);
        self::redirect_admin('certificates',['saved'=>1]);
    }

    public static function course_completion(int $course_id, int $user_id): array {
        global $wpdb; $total=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table('lessons').' WHERE course_id=%d',$course_id));
        $done=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT lesson_id) FROM ".self::table('lesson_progress')." WHERE course_id=%d AND user_id=%d AND status='completed'",$course_id,$user_id));
        return ['total'=>$total,'done'=>$done,'percent'=>$total?round((min($done,$total)/$total)*100):0,'complete'=>$total>0 && $done>=$total];
    }

    public static function get_or_create_certificate(int $course_id, int $user_id): ?array {
        global $wpdb; $course=self::get_course($course_id); if(!$course) return null;
        $completion=self::course_completion($course_id,$user_id); if(empty($completion['complete'])) return null;
        $t=self::table('certificates');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE course_id=%d AND user_id=%d LIMIT 1",$course_id,$user_id),ARRAY_A);
        if($row) return $row;
        $uid='FF-'.date('Y').'-'.strtoupper(wp_generate_password(10,false,false));
        $wpdb->insert($t,['certificate_uid'=>$uid,'user_id'=>$user_id,'course_id'=>$course_id,'client_id'=>$course['client_id']?:null,'project_id'=>$course['project_id']?:null,'issued_at'=>current_time('mysql')]);
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",(int)$wpdb->insert_id),ARRAY_A) ?: null;
    }


    public static function certificate_validation_url(string $uid): string {
        return add_query_arg(['fieldflow_certificate'=>$uid], home_url('/'));
    }

    public static function add_public_query_vars(array $vars): array {
        $vars[] = 'fieldflow_certificate';
        return $vars;
    }

    public static function maybe_render_public_certificate(): void {
        $uid = '';
        if (isset($_GET['fieldflow_certificate'])) {
            $uid = sanitize_text_field(wp_unslash((string) $_GET['fieldflow_certificate']));
        }
        if (!$uid) {
            $qv = get_query_var('fieldflow_certificate');
            if ($qv) $uid = sanitize_text_field((string) $qv);
        }
        if ($uid !== '') {
            $_GET['uid'] = $uid;
            status_header(200);
            self::handle_verify_certificate();
        }
    }

    private static function qr_image_url(string $url): string {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=8&data=' . rawurlencode($url);
    }

    public static function get_user_badges(int $user_id): array {
        global $wpdb;
        $cert=self::table('certificates'); $courses=self::table('courses');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT ce.*, c.title AS course_title, c.client_id AS course_client_id, c.project_id AS course_project_id FROM {$cert} ce LEFT JOIN {$courses} c ON c.id=ce.course_id WHERE ce.user_id=%d ORDER BY ce.issued_at DESC", $user_id), ARRAY_A) ?: [];
        foreach($rows as &$r){
            $settings=self::get_certificate_settings((int)($r['client_id'] ?: $r['course_client_id'] ?: 0), (int)($r['project_id'] ?: $r['course_project_id'] ?: 0));
            $r['seal_image_url']=$settings['seal_image_url'] ?? '';
            $r['button_color']=$settings['button_color'] ?? ($settings['primary_color'] ?? '#0f172a');
            $r['button_text_color']=$settings['button_text_color'] ?? '#ffffff';
            $r['modal_bg_color']=$settings['modal_bg_color'] ?? '#ffffff';
            $r['modal_text_color']=$settings['modal_text_color'] ?? '#0f172a';
            $r['card_bg_color']=$settings['card_bg_color'] ?? '#ffffff';
            $r['title_font']=$settings['title_font'] ?? 'Helvetica-Bold';
            $r['body_font']=$settings['body_font'] ?? 'Helvetica';
            $r['validation_url']=self::certificate_validation_url((string)$r['certificate_uid']);
        }
        unset($r);
        return $rows;
    }

    public static function handle_verify_certificate(): void {
        global $wpdb;
        $uid=sanitize_text_field($_GET['uid'] ?? '');
        $cert=self::table('certificates'); $courses=self::table('courses');
        $row=$uid ? $wpdb->get_row($wpdb->prepare("SELECT ce.*, u.display_name, u.user_email, c.title AS course_title, c.client_id AS course_client_id, c.project_id AS course_project_id FROM {$cert} ce LEFT JOIN {$wpdb->users} u ON u.ID=ce.user_id LEFT JOIN {$courses} c ON c.id=ce.course_id WHERE ce.certificate_uid=%s LIMIT 1", $uid), ARRAY_A) : null;
        nocache_headers(); header('Content-Type: text/html; charset=utf-8');
        $valid=(bool)$row;
        $title=$valid ? 'Certificado válido' : 'Certificado não encontrado';
        $public_url = $valid ? self::certificate_validation_url((string)$row['certificate_uid']) : '';
        $badges = $valid ? self::get_user_badges((int)$row['user_id']) : [];
        $settings = $valid ? self::get_certificate_settings((int)($row['client_id'] ?: $row['course_client_id'] ?: 0), (int)($row['project_id'] ?: $row['course_project_id'] ?: 0)) : self::get_certificate_settings();
        $titleFontCss = esc_attr(self::css_font_family((string)($settings['title_font'] ?? 'Inter')));
        $bodyFontCss = esc_attr(self::css_font_family((string)($settings['body_font'] ?? 'Inter')));
        echo '<!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc_html($title).'</title><style>body{margin:0;font-family:'.$bodyFontCss.',Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;background:radial-gradient(circle at top left,#fff7ed,#f8fafc 42%,#eef2ff);color:#0f172a}.wrap{max-width:1040px;margin:38px auto;padding:20px}.card{background:rgba(255,255,255,.94);border:1px solid #e2e8f0;border-radius:30px;padding:30px;box-shadow:0 24px 70px rgba(15,23,42,.12)}.badge{display:inline-flex;border-radius:999px;padding:9px 14px;font-weight:900;background:#dcfce7;color:#166534}.bad{background:#fee2e2;color:#991b1b}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:20px}.box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:18px;padding:14px}.box small{display:block;color:#64748b;margin-bottom:4px}h1{font-family:'.$titleFontCss.',Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;font-size:clamp(30px,5vw,48px);letter-spacing:-.05em;margin:14px 0}h2{font-family:'.$titleFontCss.',Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;margin:30px 0 14px;font-size:24px}.id{font-family:monospace}.verify-link{margin-top:18px;display:inline-flex;align-items:center;gap:10px;padding:13px 18px;border-radius:999px;background:#0f172a;color:#fff;text-decoration:none;font-weight:900}.wallet{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-top:16px}.wallet-card{position:relative;overflow:hidden;background:#fff;border:1px solid #e2e8f0;border-radius:24px;padding:18px;min-height:210px;box-shadow:0 12px 34px rgba(15,23,42,.08)}.wallet-card:before{content:"";position:absolute;inset:0;background:linear-gradient(135deg,rgba(217,164,65,.12),transparent 42%);pointer-events:none}.wallet-seal{height:92px;display:flex;align-items:center;justify-content:center;margin-bottom:12px}.wallet-seal img{max-width:92px;max-height:92px;object-fit:contain}.wallet-empty{width:84px;height:84px;border-radius:50%;display:grid;place-items:center;background:#f8fafc;border:1px solid #e2e8f0;font-weight:900;color:#d9a441}.wallet-title{font-weight:900;line-height:1.2}.wallet-meta{margin-top:8px;color:#64748b;font-size:13px}.wallet-link{display:inline-flex;margin-top:12px;color:#1d4ed8;font-weight:800;text-decoration:none}@media(max-width:640px){.card{padding:22px;border-radius:24px}.wrap{margin:16px auto;padding:14px}}</style></head><body><div class="wrap"><div class="card">';
        if(!$valid){
            echo '<span class="badge bad">Não verificado</span><h1>Certificado não encontrado</h1><p>Confirma o ID ou pede ao emissor para validar novamente.</p>';
        } else {
            echo '<span class="badge">FieldFlow Verified</span><h1>Certificado válido</h1><div class="grid"><div class="box"><small>Participante</small><strong>'.esc_html($row['display_name'] ?: $row['user_email']).'</strong></div><div class="box"><small>Curso</small><strong>'.esc_html($row['course_title']).'</strong></div><div class="box"><small>Emitido em</small><strong>'.esc_html(date_i18n('d/m/Y', strtotime($row['issued_at']))).'</strong></div><div class="box"><small>ID</small><strong class="id">'.esc_html($row['certificate_uid']).'</strong></div></div><a class="verify-link" href="#badge-wallet">Ver caderneta de badges</a>';
            echo '<h2 id="badge-wallet">Caderneta de Badges</h2><div class="wallet">';
            foreach($badges as $b){
                $isCurrent = ((string)$b['certificate_uid'] === (string)$row['certificate_uid']);
                echo '<div class="wallet-card"'.($isCurrent?' style="border-color:#d9a441;box-shadow:0 18px 46px rgba(217,164,65,.22)"':'').'>';
                echo '<div class="wallet-seal">';
                if(!empty($b['seal_image_url'])) echo '<img src="'.esc_url($b['seal_image_url']).'" alt="Badge">'; else echo '<div class="wallet-empty">FF</div>';
                echo '</div><div class="wallet-title">'.esc_html($b['course_title'] ?: 'Curso certificado').'</div>';
                echo '<div class="wallet-meta">'.esc_html(date_i18n('d/m/Y', strtotime($b['issued_at']))).' · '.esc_html($b['certificate_uid']).'</div>';
                echo '<a class="wallet-link" href="'.esc_url($b['validation_url']).'">Ver badge</a></div>';
            }
            if(!$badges) echo '<div class="box">Ainda não existem badges nesta caderneta.</div>';
            echo '</div>';
        }
        echo '</div></div></body></html>'; exit;
    }

    public static function css_font_family(string $font): string {
        $font=trim(wp_strip_all_tags($font));
        if($font==='') return 'Inter';
        $map=[
            'Helvetica-Bold'=>'Helvetica',
            'Helvetica'=>'Helvetica',
            'Inter'=>'Inter',
            'System UI'=>'-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif',
            'Arial'=>'Arial',
            'Verdana'=>'Verdana',
            'Georgia'=>'Georgia',
            'Times New Roman'=>'"Times New Roman"',
            'Courier New'=>'"Courier New"',
            'Poppins'=>'Poppins',
            'Montserrat'=>'Montserrat',
            'Roboto'=>'Roboto',
            'Lato'=>'Lato',
            'Open Sans'=>'"Open Sans"',
            'Playfair Display'=>'"Playfair Display"',
        ];
        if(isset($map[$font])) return $map[$font];
        return preg_replace("/[^A-Za-z0-9 ,_\"'\\-]/", '', $font) ?: 'Inter';
    }

    private static function hex_rgb(string $hex): array { $hex=ltrim($hex,'#'); if(strlen($hex)===3) $hex=$hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; return [hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2))]; }
    private static function pdf_color(array $rgb): string {
        $r=max(0,min(255,(int)($rgb[0]??0)))/255;
        $g=max(0,min(255,(int)($rgb[1]??0)))/255;
        $b=max(0,min(255,(int)($rgb[2]??0)))/255;
        return sprintf('%.4F %.4F %.4F', $r, $g, $b);
    }

    private static function pdf_text_escape(string $s): string {
        $s=wp_strip_all_tags($s);
        $s=html_entity_decode($s, ENT_QUOTES, 'UTF-8');
        if(function_exists('iconv')){ $converted=@iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$s); if($converted!==false) $s=$converted; }
        $s=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$s);
        return $s;
    }

    private static function pdf_circle(int $cx, int $cy, int $r, array $fill, array $stroke, float $w=1.0): string {
        $c = round($r * 0.5522847498, 2);
        $x0=$cx-$r; $x1=$cx+$r; $y0=$cy-$r; $y1=$cy+$r;
        $d = sprintf('%d %d m %.2f %d %.2f %.2f %d %.2f c %d %.2f %.2f %.2f %.2f %d c %.2f %d %.2f %.2f %d %.2f c %d %.2f %.2f %.2f %.2f %d c',
            $cx,$y1,$cx+$c,$y1,$x1,$cy+$c,$x1,$cy,$x1,$cy-$c,$cx+$c,$y0,$cx,$y0,$cx-$c,$y0,$x0,$cy-$c,$x0,$cy,$x0,$cy+$c,$cx-$c,$y1,$cx,$y1);
        return 'q '.self::pdf_color($fill).' rg '.self::pdf_color($stroke).' RG '.$w.' w '.$d.' B Q\n';
    }

    private static function pdf_seal(int $cx, int $cy, array $primary, array $accent, array $muted): string {
        // Premium vector seal, no external images, stable in all PDF viewers.
        $out = '';
        $out .= self::pdf_circle($cx, $cy, 34, [255,255,255], $accent, 1.5);
        $out .= self::pdf_circle($cx, $cy, 27, [255,255,255], [226,232,240], 0.8);
        $out .= self::pdf_circle($cx, $cy, 19, [255,247,237], $accent, 1.0);
        $out .= 'q '.self::pdf_color($accent).' rg '.($cx-11).' '.($cy-5).' 22 10 re f Q\n';
        $out .= self::pdf_center_line('FF', $cx, $cy-1, 11, 'F2', [255,255,255]);
        $out .= self::pdf_center_line('FIELD', $cx, $cy+13, 5, 'F2', $accent);
        $out .= self::pdf_center_line('FLOW', $cx, $cy-18, 5, 'F2', $accent);
        $out .= self::pdf_center_line('VERIFIED CERTIFICATE', $cx, $cy-45, 7, 'F2', $primary);
        $out .= self::pdf_center_line('AUTHENTICATED LEARNING RECORD', $cx, $cy-56, 5, 'F1', $muted);
        return $out;
    }

    private static function pdf_line(string $txt, int $x, int $y, int $size=14, string $font='F1', array $rgb=[15,23,42]): string {
        $color=self::pdf_color($rgb);
        return "BT /{$font} {$size} Tf {$color} rg {$x} {$y} Td (".self::pdf_text_escape($txt).") Tj ET\n";
    }

    private static function pdf_center_line(string $txt, int $centerX, int $y, int $size=14, string $font='F1', array $rgb=[15,23,42]): string {
        $plain=wp_strip_all_tags(html_entity_decode($txt, ENT_QUOTES, 'UTF-8'));
        $factor=($font==='F2') ? 0.56 : 0.50;
        $width=(int)round(function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') * $size * $factor : strlen($plain) * $size * $factor);
        $x=max(36, $centerX - (int)round($width/2));
        return self::pdf_line($txt,$x,$y,$size,$font,$rgb);
    }

    private static function pdf_image_data(string $url): ?array {
        $url = trim($url);
        if ($url === '') return null;
        $bytes = null;
        $local = '';
        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
        if (!empty($uploads['baseurl']) && !empty($uploads['basedir']) && strpos($url, $uploads['baseurl']) === 0) {
            $local = str_replace($uploads['baseurl'], $uploads['basedir'], $url);
        } elseif (strpos($url, home_url('/')) === 0 && defined('ABSPATH')) {
            $rel = ltrim(str_replace(home_url('/'), '', $url), '/');
            $candidate = ABSPATH . $rel;
            if (file_exists($candidate)) $local = $candidate;
        } elseif (strpos($url, '/') === 0 && file_exists($url)) {
            $local = $url;
        }
        if ($local && file_exists($local) && is_readable($local)) {
            $bytes = @file_get_contents($local);
        }
        if (!$bytes && preg_match('#^https?://#i', $url) && function_exists('wp_remote_get')) {
            $res = wp_remote_get($url, ['timeout'=>12, 'redirection'=>3, 'sslverify'=>false]);
            if (!is_wp_error($res) && (int)wp_remote_retrieve_response_code($res) < 400) $bytes = wp_remote_retrieve_body($res);
        }
        if (!$bytes) return null;
        $is_svg = preg_match('/<svg[\s>]/i', substr($bytes,0,500));
        if ($is_svg && class_exists('Imagick')) {
            try {
                $im = new \Imagick();
                $im->setBackgroundColor(new \ImagickPixel('transparent'));
                $im->readImageBlob($bytes);
                $im->setImageFormat('png32');
                $im->resizeImage(600, 600, \Imagick::FILTER_LANCZOS, 1, true);
                $png = $im->getImagesBlob();
                $im->clear();
                $im->destroy();
                if ($png) $bytes = $png;
            } catch (\Throwable $e) {
                return null;
            }
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || empty($info[0]) || empty($info[1])) return null;
        $mime = strtolower((string)($info['mime'] ?? ''));
        if ($mime !== 'image/jpeg' && function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring($bytes);
            if (!$src) return null;
            $w = imagesx($src); $h = imagesy($src);
            $canvas = imagecreatetruecolor($w, $h);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
            imagecopy($canvas, $src, 0, 0, 0, 0, $w, $h);
            ob_start(); imagejpeg($canvas, null, 88); $jpg = ob_get_clean();
            imagedestroy($src); imagedestroy($canvas);
            if (!$jpg) return null;
            return ['w'=>$w, 'h'=>$h, 'data'=>$jpg, 'filter'=>'DCTDecode'];
        }
        return ['w'=>(int)$info[0], 'h'=>(int)$info[1], 'data'=>$bytes, 'filter'=>'DCTDecode'];
    }

    private static function pdf_fit_image_cmd(string $name, array $img, int $x, int $y, int $maxW, int $maxH): string {
        $w = max(1, (int)($img['w'] ?? 1)); $h = max(1, (int)($img['h'] ?? 1));
        $scale = min($maxW / $w, $maxH / $h);
        $dw = max(1, (int)round($w * $scale)); $dh = max(1, (int)round($h * $scale));
        $dx = $x + (int)round(($maxW - $dw) / 2); $dy = $y + (int)round(($maxH - $dh) / 2);
        return "q {$dw} 0 0 {$dh} {$dx} {$dy} cm /{$name} Do Q\n";
    }

    private static function build_certificate_pdf(array $payload): string {
        $primary=self::hex_rgb($payload['primary_color']??'#0f172a');
        $accent=self::hex_rgb($payload['accent_color']??'#d9a441');
        $bg=self::hex_rgb($payload['background_color']??'#ffffff');
        $titleColor=self::hex_rgb($payload['title_color']??($payload['primary_color']??'#0f172a'));
        $subtitleColor=self::hex_rgb($payload['subtitle_color']??'#64748b');
        $bodyColor=self::hex_rgb($payload['body_color']??'#334155');
        $linkColor=self::hex_rgb($payload['link_color']??'#2563eb');
        $lineColor=self::hex_rgb($payload['line_color']??($payload['accent_color']??'#d9a441'));
        $titleFont=((string)($payload['title_font']??'Helvetica-Bold')==='Helvetica')?'F1':'F2';
        $bodyFont=((string)($payload['body_font']??'Helvetica')==='Helvetica-Bold')?'F2':'F1';
        $ink=[15,23,42]; $muted=$subtitleColor; $soft=[226,232,240];
        $leftLogo=self::pdf_image_data((string)($payload['logo_left_url']??''));
        $rightLogo=self::pdf_image_data((string)($payload['logo_right_url']??''));
        $signature=self::pdf_image_data((string)($payload['signature_url']??''));
        $sealImage=self::pdf_image_data((string)($payload['seal_image_url']??''));
        $validationUrl=(string)($payload['validation_url']??'');
        $qrImage=$validationUrl ? self::pdf_image_data(self::qr_image_url($validationUrl)) : null;

        $xobjectNames=[];
        if($leftLogo){ $xobjectNames['Im1']=$leftLogo; }
        if($rightLogo){ $xobjectNames['Im2']=$rightLogo; }
        if($signature){ $xobjectNames['Im3']=$signature; }
        if($sealImage){ $xobjectNames['Im4']=$sealImage; }
        if($qrImage){ $xobjectNames['Im5']=$qrImage; }

        // A4 landscape: 842 x 595. Fixed premium grid.
        $content="q 0.946 0.960 0.976 rg 0 0 842 595 re f Q\n";
        $content.="q ".self::pdf_color($bg)." rg 54 42 734 510 re f Q\n";
        $content.="q ".self::pdf_color($primary)." rg 54 42 734 510 re S Q\n";
        $content.="q ".self::pdf_color($primary)." rg 54 522 734 30 re f Q\n";
        $content.="q ".self::pdf_color($lineColor)." rg 54 42 734 7 re f Q\n";
        $content.="q ".self::pdf_color($soft)." RG 0.6 w 78 134 686 1 re S Q\n";
        $content.="q ".self::pdf_color($soft)." RG 0.6 w 78 458 686 1 re S Q\n";
        $content.=self::pdf_line('FIELDFLOW ACADEMY',82,532,10,'F2',[255,255,255]);
        $content.=self::pdf_line('CERTIFIED LEARNING PROGRAM',620,532,8,'F1',[255,255,255]);

        // Logos are kept in a disciplined brand row.
        if($leftLogo) $content.=self::pdf_fit_image_cmd('Im1',$leftLogo,78,468,145,40);
        else $content.=self::pdf_line('FIELD',88,488,16,'F2',$primary);
        if($rightLogo) $content.=self::pdf_fit_image_cmd('Im2',$rightLogo,620,468,145,40);

        $title=(string)($payload['certificate_title']??'Certificate of Completion');
        $name=(string)($payload['user_name']??'');
        $course=(string)($payload['course_title']??'');
        if(function_exists('mb_strlen')){
            if(mb_strlen($name,'UTF-8')>42) $name=mb_substr($name,0,39,'UTF-8').'...';
            if(mb_strlen($course,'UTF-8')>54) $course=mb_substr($course,0,51,'UTF-8').'...';
        } else {
            if(strlen($name)>42) $name=substr($name,0,39).'...';
            if(strlen($course)>54) $course=substr($course,0,51).'...';
        }

        $content.=self::pdf_center_line($title,421,422,27,$titleFont,$titleColor);
        $content.=self::pdf_center_line('atribuído a',421,384,10,$bodyFont,$subtitleColor);
        $content.=self::pdf_center_line($name,421,348,24,$titleFont,$accent);
        $content.=self::pdf_center_line('por concluir com sucesso',421,318,10,$bodyFont,$subtitleColor);
        $content.=self::pdf_center_line($course,421,286,19,$titleFont,$titleColor);

        $text=(string)($payload['certificate_text']??'');
        $text=str_replace(['{{name}}','{{course}}','{{campaign}}'],[(string)($payload['user_name']??''), (string)($payload['course_title']??''), (string)($payload['campaign_name']??'FieldFlow')], $text);
        $text=trim(wp_strip_all_tags($text));
        $words=preg_split('/\s+/', $text) ?: [];
        $lines=[]; $line='';
        foreach($words as $w){
            $candidate=trim($line.' '.$w);
            $len=function_exists('mb_strlen') ? mb_strlen($candidate,'UTF-8') : strlen($candidate);
            if($len>74 && $line!==''){ $lines[]=trim($line); $line=$w; } else { $line=$candidate; }
        }
        if($line) $lines[]=trim($line);
        $y=244; foreach(array_slice($lines,0,3) as $ln){ $content.=self::pdf_center_line($ln,421,$y,10,$bodyFont,$bodyColor); $y-=15; }

        // Footer grid: metadata, seal, signature.
        $content.=self::pdf_line('Emitido em',84,108,8,'F2',$muted);
        $content.=self::pdf_line((string)($payload['issued_at']??date('Y-m-d')),84,91,10,'F1',$ink);
        $content.=self::pdf_line('Certificado ID',84,70,8,'F2',$muted);
        $content.=self::pdf_line((string)($payload['certificate_uid']??''),84,54,10,'F1',$ink);
        if($qrImage){ $content.=self::pdf_fit_image_cmd('Im5',$qrImage,250,76,76,76); }
        if($validationUrl){ $content.=self::pdf_line('Clique aqui para verificar certificado',232,58,8,'F2',$linkColor); }

        if($sealImage){
            $content.=self::pdf_fit_image_cmd('Im4',$sealImage,372,61,98,98);
        } else {
            $content.=self::pdf_seal(421,100,$primary,$accent,$muted);
        }

        if($signature) $content.=self::pdf_fit_image_cmd('Im3',$signature,608,106,130,34);
        $content.="q ".self::pdf_color($lineColor)." rg 592 98 156 2 re f Q\n";
        $content.=self::pdf_line((string)($payload['signer_name']??''),592,78,11,'F2',$titleColor);
        $content.=self::pdf_line((string)($payload['signer_role']??''),592,62,8,'F1',$muted);

        $objects=[];
        $objects[]="<< /Type /Catalog /Pages 2 0 R >>";
        $objects[]="<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objects[]='__PAGE__';
        $objects[]="<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[]="<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
        $objects[]="<< /Length ".strlen($content)." >>\nstream\n{$content}\nendstream";
        $annotationRef = '';
        if($validationUrl){
            $annNum = count($objects)+1;
            $annotationRef = ' /Annots ['.$annNum.' 0 R]';
            $safeUrl = str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $validationUrl);
            $objects[] = "<< /Type /Annot /Subtype /Link /Rect [228 52 418 72] /Border [0 0 0] /A << /S /URI /URI (".$safeUrl.") >> >>";
        }
        $imageRefs=[];
        foreach($xobjectNames as $name=>$img){
            $num=count($objects)+1;
            $imageRefs[]="/{$name} {$num} 0 R";
            $data=$img['data'];
            $objects[]="<< /Type /XObject /Subtype /Image /Width ".(int)$img['w']." /Height ".(int)$img['h']." /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($data)." >>\nstream\n{$data}\nendstream";
        }
        $xobj=$imageRefs ? ' /XObject << '.implode(' ', $imageRefs).' >>' : '';
        $objects[2]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 4 0 R /F2 5 0 R >>{$xobj} >> /Contents 6 0 R{$annotationRef} >>";
        $pdf="%PDF-1.4\n"; $offsets=[0];
        foreach($objects as $i=>$obj){$offsets[] = strlen($pdf); $n=$i+1; $pdf.="{$n} 0 obj\n{$obj}\nendobj\n";}
        $xref=strlen($pdf); $pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
        for($i=1;$i<=count($objects);$i++) $pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);
        $pdf.="trailer << /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $pdf;
    }

    public static function handle_download_certificate(): void {
        if(!is_user_logged_in()) wp_die('Inicia sessão.'); $course_id=absint($_GET['course_id']??0); $user_id=get_current_user_id(); $nonce=$_GET['_wpnonce']??''; if(!wp_verify_nonce($nonce,'fieldflow_perf_download_certificate_'.$course_id)) wp_die('Pedido inválido.');
        $course=self::get_course($course_id); if(!$course) wp_die('Curso inválido.'); if(!self::user_in_scope($user_id,(int)($course['client_id']??0),(int)($course['project_id']??0))) wp_die('Curso fora do teu âmbito.');
        $cert=self::get_or_create_certificate($course_id,$user_id); if(!$cert) wp_die('O certificado só fica disponível depois de concluir todas as lições do curso.');
        $user=get_userdata($user_id); global $wpdb; $campaign='FieldFlow'; if(!empty($course['project_id'])){ $campaign=(string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}routespro_projects WHERE id=%d",(int)$course['project_id'])); }
        $settings=self::get_certificate_settings((int)($course['client_id']??0),(int)($course['project_id']??0));
        $payload=array_merge($settings,['user_name'=>$user?$user->display_name:'Operacional','course_title'=>$course['title'],'campaign_name'=>$campaign ?: 'FieldFlow','certificate_uid'=>$cert['certificate_uid'],'issued_at'=>date_i18n('Y-m-d',strtotime($cert['issued_at'])),'validation_url'=>self::certificate_validation_url((string)$cert['certificate_uid'])]);
        $pdf=self::build_certificate_pdf($payload); nocache_headers(); header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="fieldflow-certificate-'.$cert['certificate_uid'].'.pdf"'); header('Content-Length: '.strlen($pdf)); echo $pdf; exit;
    }

    public static function handle_complete_mission(): void {
        if(!is_user_logged_in()) wp_die('Inicia sessão.');
        check_admin_referer('fieldflow_perf_complete_mission');
        global $wpdb;
        $mission_id=absint($_POST['mission_id']??0);
        $user_id=get_current_user_id();
        $note=wp_kses_post($_POST['note']??'');
        $evidence=esc_url_raw($_POST['evidence_url']??'');
        $mission=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('missions')." WHERE id=%d",$mission_id),ARRAY_A);
        if(!$mission) wp_die('Missão inválida.');
        if(!self::user_in_scope($user_id,(int)($mission['client_id']??0),(int)($mission['project_id']??0))) wp_die('Missão fora do teu âmbito.');
        $assigned=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table('mission_users')." WHERE mission_id=%d AND user_id=%d",$mission_id,$user_id));
        if(!$assigned) wp_die('Esta missão não está atribuída ao teu utilizador.');
        if(!empty($mission['evidence_required']) && $evidence==='') wp_die('Esta missão exige evidência antes da submissão.');
        $quiz_score = null;
        if(!empty($mission['quiz_enabled'])){
            $quiz = self::parse_mission_quiz($mission);
            if($quiz){
                $result = self::grade_mission_quiz($quiz, (array)($_POST['quiz_answers'] ?? []));
                $quiz_score = (int)$result['score'];
                $passed = $quiz_score >= (int)($mission['quiz_pass_score'] ?? 70);
                $wpdb->replace(self::table('mission_quiz_results'), [
                    'mission_id'=>$mission_id,
                    'user_id'=>$user_id,
                    'score'=>$quiz_score,
                    'passed'=>$passed?1:0,
                    'answers_json'=>wp_json_encode($result['answers']),
                    'created_at'=>current_time('mysql'),
                ]);
                if(!$passed){
                    wp_safe_redirect(add_query_arg(['ffp_panel'=>'academy','ffp_error'=>'quiz_failed','quiz_score'=>$quiz_score], wp_get_referer() ?: home_url('/')).'#rp-app-academy');
                    exit;
                }
            }
        }
        $status=!empty($mission['approval_required'])?'submitted':'completed';
        $score=$status==='completed'?(int)$mission['points']:0;
        if($quiz_score !== null && $status==='completed') $score = max(0, min((int)$mission['points'], (int)round(((int)$mission['points'] * $quiz_score) / 100)));
        $wpdb->replace(self::table('mission_users'), ['mission_id'=>$mission_id,'user_id'=>$user_id,'status'=>$status,'score'=>$score,'note'=>$note,'evidence_url'=>$evidence,'completed_at'=>current_time('mysql')]);
        self::redirect_back_to_academy();
    }
    public static function handle_mark_lesson(): void {
        if(!is_user_logged_in()) wp_die('Inicia sessão.');
        check_admin_referer('fieldflow_perf_mark_lesson');
        global $wpdb;
        $lesson_id=absint($_POST['lesson_id']??0);
        $course_id=absint($_POST['course_id']??0);
        if(!$lesson_id || !$course_id) self::redirect_back_to_academy();
        $lesson=$wpdb->get_row($wpdb->prepare('SELECT id,course_id,estimated_min,is_required,content_type,min_watch_seconds FROM '.self::table('lessons').' WHERE id=%d AND course_id=%d',$lesson_id,$course_id),ARRAY_A);
        if(!$lesson) wp_die('Lição inválida.');
        $course=self::get_course($course_id);
        if($course && !self::user_in_scope(get_current_user_id(),(int)($course['client_id']??0),(int)($course['project_id']??0))) wp_die('Curso fora do teu âmbito.');
        $watched=absint($_POST['watched_seconds']??0);
        $type=sanitize_key((string)($lesson['content_type']??''));
        $needs_gate=in_array($type,['youtube','canva','pdf','link'],true) && (int)($lesson['is_required']??1)===1;
        $required=$needs_gate ? max(0, min(600, (int)($lesson['min_watch_seconds']??8))) : 0;
        if($needs_gate && $watched < $required){
            wp_safe_redirect(add_query_arg(['ffp_panel'=>'academy','ffp_error'=>'watch_required'], wp_get_referer() ?: home_url('/')).'#rp-app-academy');
            exit;
        }
        $wpdb->replace(self::table('lesson_progress'), ['lesson_id'=>$lesson_id,'course_id'=>$course_id,'user_id'=>get_current_user_id(),'status'=>'completed','completed_at'=>current_time('mysql')]);
        self::redirect_back_to_academy();
    }

    public static function get_reset_engine_rows(int $client_id = 0, int $project_id = 0, int $course_id = 0, int $user_id = 0): array {
        global $wpdb;
        $courses_table = self::table('courses');
        $lessons_table = self::table('lessons');
        $progress_table = self::table('lesson_progress');
        $cert_table = self::table('certificates');
        $where = ['1=1'];
        $args = [];
        if ($client_id) { $where[] = 'c.client_id=%d'; $args[] = $client_id; }
        if ($project_id) { $where[] = 'c.project_id=%d'; $args[] = $project_id; }
        if ($course_id) { $where[] = 'c.id=%d'; $args[] = $course_id; }
        $course_sql = "SELECT c.*, (SELECT COUNT(*) FROM {$lessons_table} l WHERE l.course_id=c.id AND l.is_required=1) AS required_lessons FROM {$courses_table} c WHERE " . implode(' AND ', $where) . " ORDER BY c.title ASC";
        $courses = $args ? ($wpdb->get_results($wpdb->prepare($course_sql, ...$args), ARRAY_A) ?: []) : ($wpdb->get_results($course_sql, ARRAY_A) ?: []);
        if (!$courses) return [];
        $scope_ids = [];
        if ($project_id || $client_id) $scope_ids = self::scope_user_ids($client_id, $project_id);
        $rows = [];
        foreach ($courses as $course) {
            $cid = (int) $course['id'];
            $ids = [];
            foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$progress_table} WHERE course_id=%d", $cid)) as $uid) { $uid = absint($uid); if ($uid) $ids[$uid] = $uid; }
            foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$cert_table} WHERE course_id=%d", $cid)) as $uid) { $uid = absint($uid); if ($uid) $ids[$uid] = $uid; }
            if ($course['project_id']) foreach (self::get_project_user_ids((int)$course['project_id'], (int)($course['client_id'] ?? 0)) as $uid) $ids[$uid] = $uid;
            elseif ($course['client_id']) foreach (self::get_client_user_ids((int)$course['client_id']) as $uid) $ids[$uid] = $uid;
            if ($scope_ids) $ids = array_intersect_key($ids, array_flip($scope_ids));
            if ($user_id) $ids = isset($ids[$user_id]) ? [$user_id => $user_id] : [];
            if (!$ids) continue;
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $users = $wpdb->get_results($wpdb->prepare("SELECT ID,display_name,user_email FROM {$wpdb->users} WHERE ID IN ({$placeholders}) ORDER BY display_name ASC", ...array_values($ids)), ARRAY_A) ?: [];
            foreach ($users as $u) {
                $uid = (int) $u['ID'];
                $done = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT lesson_id) FROM {$progress_table} WHERE course_id=%d AND user_id=%d", $cid, $uid));
                $required = max(0, (int)($course['required_lessons'] ?? 0));
                $cert = $wpdb->get_row($wpdb->prepare("SELECT certificate_uid, issued_at FROM {$cert_table} WHERE course_id=%d AND user_id=%d LIMIT 1", $cid, $uid), ARRAY_A);
                $rows[] = [
                    'course_id' => $cid,
                    'course_title' => (string) $course['title'],
                    'client_id' => (int)($course['client_id'] ?? 0),
                    'project_id' => (int)($course['project_id'] ?? 0),
                    'user_id' => $uid,
                    'display_name' => (string) $u['display_name'],
                    'user_email' => (string) $u['user_email'],
                    'done' => $done,
                    'required' => $required,
                    'progress' => $required ? min(100, round(($done / $required) * 100)) : ($done ? 100 : 0),
                    'certificate_uid' => $cert['certificate_uid'] ?? '',
                    'issued_at' => $cert['issued_at'] ?? '',
                ];
            }
        }
        return $rows;
    }

    public static function get_reset_logs(int $limit = 50): array {
        global $wpdb;
        $table = self::table('reset_logs');
        $courses = self::table('courses');
        return $wpdb->get_results($wpdb->prepare("SELECT rl.*, u.display_name, c.title AS course_title, au.display_name AS admin_name FROM {$table} rl LEFT JOIN {$wpdb->users} u ON u.ID=rl.user_id LEFT JOIN {$courses} c ON c.id=rl.course_id LEFT JOIN {$wpdb->users} au ON au.ID=rl.admin_user_id ORDER BY rl.created_at DESC, rl.id DESC LIMIT %d", max(1, $limit)), ARRAY_A) ?: [];
    }

    public static function handle_reset_course_progress(): void {
        if (!self::user_can_manage()) wp_die('Sem permissões.');
        check_admin_referer('fieldflow_perf_reset_course_progress');
        global $wpdb;
        $user_id = absint($_POST['user_id'] ?? 0);
        $course_id = absint($_POST['course_id'] ?? 0);
        $reason = trim(sanitize_textarea_field($_POST['reason'] ?? ''));
        if (!$user_id || !$course_id) wp_die('Utilizador e curso são obrigatórios.');
        if ($reason === '') wp_die('Motivo obrigatório para reiniciar progresso.');
        $course = self::get_course($course_id);
        if (!$course) wp_die('Curso inválido.');
        $client_id = (int)($course['client_id'] ?? 0);
        $project_id = (int)($course['project_id'] ?? 0);
        $scope_ids = self::scope_user_ids($client_id, $project_id);
        $has_progress = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('lesson_progress') . " WHERE course_id=%d AND user_id=%d", $course_id, $user_id));
        $has_certificate = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('certificates') . " WHERE course_id=%d AND user_id=%d", $course_id, $user_id));
        $is_in_scope = !$scope_ids || in_array($user_id, $scope_ids, true);
        if (($client_id || $project_id) && !$is_in_scope && !$has_progress && !$has_certificate) wp_die('Utilizador fora do âmbito deste curso.');
        $wpdb->delete(self::table('lesson_progress'), ['course_id' => $course_id, 'user_id' => $user_id], ['%d', '%d']);
        $wpdb->delete(self::table('certificates'), ['course_id' => $course_id, 'user_id' => $user_id], ['%d', '%d']);
        $wpdb->insert(self::table('reset_logs'), [
            'user_id' => $user_id,
            'course_id' => $course_id,
            'client_id' => $client_id ?: null,
            'project_id' => $project_id ?: null,
            'action_type' => 'course_reset',
            'reason' => $reason,
            'admin_user_id' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ]);
        self::redirect_admin('reset', ['reset' => 1]);
    }


    public static function seed_default_skills(): void {
        global $wpdb;
        $t = self::table('skills');
        $exists = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}");
        if ($exists > 0) return;
        $defaults = [
            ['Negociação', 'Capacidade de argumentar, gerir objeções e fechar com valor.', '#d9a441', 10],
            ['Comunicação', 'Clareza, escuta ativa e qualidade de interação.', '#2563eb', 20],
            ['Produto', 'Conhecimento de produto, campanhas e proposta de valor.', '#14b8a6', 30],
            ['Execução PDV', 'Implementação, evidência, disciplina e qualidade no ponto de venda.', '#7c3aed', 40],
            ['Compliance', 'Cumprimento de processos, regras e standards de marca.', '#ef4444', 50],
        ];
        foreach ($defaults as $d) {
            $wpdb->insert($t, ['name'=>$d[0], 'description'=>$d[1], 'color'=>$d[2], 'sort_order'=>$d[3], 'status'=>'active', 'created_at'=>current_time('mysql')]);
        }
    }

    public static function get_skills(bool $include_inactive = false): array {
        global $wpdb;
        $t = self::table('skills');
        $where = $include_inactive ? '1=1' : "status='active'";
        return $wpdb->get_results("SELECT * FROM {$t} WHERE {$where} ORDER BY sort_order ASC, name ASC", ARRAY_A) ?: [];
    }

    public static function get_skill_rules(string $object_type = '', int $object_id = 0): array {
        global $wpdb;
        $r = self::table('skill_rules');
        $s = self::table('skills');
        $where = ['1=1']; $args = [];
        if ($object_type !== '') { $where[]='r.object_type=%s'; $args[]=$object_type; }
        if ($object_id > 0) { $where[]='r.object_id=%d'; $args[]=$object_id; }
        $sql = "SELECT r.*, sk.name AS skill_name, sk.color AS skill_color FROM {$r} r INNER JOIN {$s} sk ON sk.id=r.skill_id WHERE ".implode(' AND ', $where)." ORDER BY sk.sort_order ASC, sk.name ASC";
        return $args ? ($wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: []) : ($wpdb->get_results($sql, ARRAY_A) ?: []);
    }

    public static function handle_save_skill(): void {
        if (!self::user_can_manage()) wp_die('Sem permissões.');
        check_admin_referer('fieldflow_perf_save_skill');
        global $wpdb;
        $id = absint($_POST['skill_id'] ?? 0);
        $data = [
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'color' => sanitize_hex_color($_POST['color'] ?? '#0f172a') ?: '#0f172a',
            'sort_order' => intval($_POST['sort_order'] ?? 0),
            'status' => sanitize_key($_POST['status'] ?? 'active') ?: 'active',
        ];
        if ($data['name'] === '') wp_die('Nome da competência obrigatório.');
        if ($id) $wpdb->update(self::table('skills'), $data, ['id'=>$id]); else $wpdb->insert(self::table('skills'), $data + ['created_at'=>current_time('mysql')]);
        self::redirect_admin('skills', ['saved'=>1]);
    }

    public static function handle_save_skill_rules(): void {
        if (!self::user_can_manage()) wp_die('Sem permissões.');
        check_admin_referer('fieldflow_perf_save_skill_rules');
        global $wpdb;
        $object_type = sanitize_key($_POST['object_type'] ?? 'course');
        if (!in_array($object_type, ['course','mission'], true)) $object_type = 'course';
        $object_id = absint($_POST['object_id'] ?? 0);
        if (!$object_id) wp_die('Escolhe um curso ou missão.');
        $points = (array)($_POST['skill_points'] ?? []);
        $table = self::table('skill_rules');
        foreach ($points as $skill_id => $value) {
            $skill_id = absint($skill_id);
            $p = max(0, intval($value));
            if (!$skill_id) continue;
            if ($p <= 0) {
                $wpdb->delete($table, ['skill_id'=>$skill_id, 'object_type'=>$object_type, 'object_id'=>$object_id], ['%d','%s','%d']);
                continue;
            }
            $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE skill_id=%d AND object_type=%s AND object_id=%d", $skill_id, $object_type, $object_id));
            $data = ['skill_id'=>$skill_id, 'object_type'=>$object_type, 'object_id'=>$object_id, 'points'=>$p];
            if ($exists) $wpdb->update($table, ['points'=>$p], ['id'=>$exists]); else $wpdb->insert($table, $data + ['created_at'=>current_time('mysql')]);
        }
        self::redirect_admin('skills', ['rules_saved'=>1, 'object_type'=>$object_type, 'object_id'=>$object_id]);
    }

    public static function skill_matrix_values(int $client_id=0, int $project_id=0, int $user_id=0): array {
        global $wpdb;
        $skills = self::get_skills(false);
        if (!$skills) return [];
        $rules = self::get_skill_rules();
        if (!$rules) return [];
        $scoped_ids = $user_id ? [$user_id] : self::scope_user_ids($client_id, $project_id);
        if (($client_id || $project_id || $user_id) && !$scoped_ids) return [];
        $courses = self::table('courses'); $missions = self::table('missions'); $lp = self::table('lesson_progress'); $lessons = self::table('lessons'); $cert = self::table('certificates'); $mu = self::table('mission_users');
        $values = [];
        foreach ($skills as $sk) $values[(int)$sk['id']] = ['name'=>$sk['name'], 'color'=>$sk['color'], 'earned'=>0.0, 'possible'=>0.0];
        $user_count = max(1, count($scoped_ids));
        foreach ($rules as $r) {
            $sid = (int)$r['skill_id']; if (!isset($values[$sid])) continue;
            $points = max(0, (int)$r['points']); if (!$points) continue;
            if ($r['object_type'] === 'course') {
                $course = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$courses} WHERE id=%d", (int)$r['object_id']), ARRAY_A);
                if (!$course || (string)($course['status'] ?? '') === 'archived') continue;
                if ($client_id && (int)($course['client_id'] ?? 0) !== $client_id) continue;
                if ($project_id && (int)($course['project_id'] ?? 0) !== $project_id) continue;
                $uids = $scoped_ids;
                if (!$uids) {
                    $uids = [];
                    foreach ((array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$lp} WHERE course_id=%d", (int)$course['id'])) as $uid) { $uid=absint($uid); if($uid) $uids[$uid]=$uid; }
                    foreach ((array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$cert} WHERE course_id=%d", (int)$course['id'])) as $uid) { $uid=absint($uid); if($uid) $uids[$uid]=$uid; }
                    $uids = array_values($uids);
                }
                $required = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$lessons} WHERE course_id=%d AND is_required=1", (int)$course['id']));
                if ($required <= 0) $required = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$lessons} WHERE course_id=%d", (int)$course['id']));
                foreach ($uids as $uid) {
                    $values[$sid]['possible'] += $points;
                    $done = $required ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT lesson_id) FROM {$lp} WHERE course_id=%d AND user_id=%d AND status='completed'", (int)$course['id'], (int)$uid)) : 0;
                    $has_cert = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$cert} WHERE course_id=%d AND user_id=%d", (int)$course['id'], (int)$uid));
                    $ratio = $has_cert ? 1.0 : ($required ? min(1.0, $done / max(1, $required)) : 0.0);
                    $values[$sid]['earned'] += ($points * $ratio);
                }
            } elseif ($r['object_type'] === 'mission') {
                $mission = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$missions} WHERE id=%d", (int)$r['object_id']), ARRAY_A);
                if (!$mission || (string)($mission['status'] ?? '') === 'archived') continue;
                if ($client_id && (int)($mission['client_id'] ?? 0) !== $client_id) continue;
                if ($project_id && (int)($mission['project_id'] ?? 0) !== $project_id) continue;
                $where = ['mission_id=%d']; $args=[(int)$mission['id']];
                if ($scoped_ids) { $where[]='user_id IN ('.implode(',', array_fill(0, count($scoped_ids), '%d')).')'; $args=array_merge($args, $scoped_ids); }
                $rows = $wpdb->get_results($wpdb->prepare("SELECT user_id,status FROM {$mu} WHERE ".implode(' AND ', $where), ...$args), ARRAY_A) ?: [];
                foreach ($rows as $row) {
                    $values[$sid]['possible'] += $points;
                    if (in_array((string)$row['status'], ['completed','approved'], true)) $values[$sid]['earned'] += $points;
                }
            }
        }
        $out = [];
        foreach ($values as $v) {
            if ($v['possible'] <= 0) continue;
            $out[$v['name']] = ['value'=>min(100, max(0, (int)round(($v['earned'] / $v['possible']) * 100))), 'color'=>$v['color']];
        }
        return $out;
    }

}
