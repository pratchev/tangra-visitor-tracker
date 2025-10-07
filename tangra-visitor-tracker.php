<?php
/**
 * Plugin Name: Tangra – Visitor Tracker
 * Description: Logs visits and logins (timestamp, email, IP, URL, UA). Integrates with Google Front Gate (tgfg_session JWT), includes retention, anonymization, CSV export, analytics, and privacy exporter/eraser.
 * Version: 1.1.2
 * Author: Tangra Inc.
 * License: GPL2+
 */

if (!defined('ABSPATH')) exit;

class Tangra_Visitor_Tracker {
    const OPT = 'tvt_settings';
    const TABLE = 'tvt_logs';
    const CRON_HOOK = 'tvt_cleanup_daily';

    public function __construct(){
        register_activation_hook(__FILE__, [$this,'activate']);
        register_deactivation_hook(__FILE__, [$this,'deactivate']);

        add_action('init', [$this,'maybe_schedule_cron']);
        add_action(self::CRON_HOOK, [$this,'cleanup']);

        add_action('template_redirect', [$this,'log_view'], 1);
        add_action('wp_login', [$this,'log_login'], 10, 2);

        add_action('admin_menu', [$this,'admin_menu']);
        add_action('admin_init', [$this,'register_settings']);
        add_action('admin_post_tvt_export_csv', [$this,'handle_export_csv']);
        add_action('admin_post_tvt_clear_logs', [$this,'handle_clear_logs']);

        // Analytics AJAX
        add_action('wp_ajax_tvt_fetch_stats', [$this,'ajax_fetch_stats']);

        // Privacy export/erase
        add_filter('wp_privacy_personal_data_exporters', function($exporters){
            $exporters['tangra-visitor-tracker'] = [
                'exporter_friendly_name' => __('Tangra Visitor Tracker','tvt'),
                'callback' => [$this,'privacy_exporter']
            ];
            return $exporters;
        });
        add_filter('wp_privacy_personal_data_erasers', function($erasers){
            $erasers['tangra-visitor-tracker'] = [
                'eraser_friendly_name' => __('Tangra Visitor Tracker','tvt'),
                'callback' => [$this,'privacy_eraser']
            ];
            return $erasers;
        });
    }

    public function activate(){
        global $wpdb;
        $table = $wpdb->base_prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            email VARCHAR(255) NULL,
            ip VARBINARY(16) NULL,
            url TEXT NULL,
            ua TEXT NULL,
            event ENUM('view','login') NOT NULL DEFAULT 'view',
            PRIMARY KEY(id),
            KEY ts_idx (ts),
            KEY email_idx (email),
            KEY event_idx (event)
        ) $charset;";
        dbDelta($sql);

        if (!get_option(self::OPT)){
            update_option(self::OPT, [
                'guest' => true,
                'anonymize' => true,
                'retention_days' => 365,
                'exclude_roles' => ['administrator'],
            ]);
        }
        if (!wp_next_scheduled(self::CRON_HOOK)){
            wp_schedule_event(time()+3600, 'daily', self::CRON_HOOK);
        }
    }

    public function deactivate(){
        if ($t = wp_next_scheduled(self::CRON_HOOK)){
            wp_unschedule_event($t, self::CRON_HOOK);
        }
    }
    public function maybe_schedule_cron(){
        if (!wp_next_scheduled(self::CRON_HOOK)){
            wp_schedule_event(time()+3600, 'daily', self::CRON_HOOK);
        }
    }

    public function register_settings(){
        register_setting('tvt_group', self::OPT, [$this,'sanitize']);
        add_settings_section('tvt_main', __('Visitor Tracker Settings','tvt'), null, 'tvt');
        add_settings_field('guest', __('Track guests (not logged in)','tvt'), function(){
            $o = get_option(self::OPT);
            printf('<label><input type="checkbox" name="%s[guest]" %s> %s</label>',
                self::OPT, checked(!empty($o['guest']), true, false), esc_html__('Enable', 'tvt'));
        }, 'tvt', 'tvt_main');
        add_settings_field('anonymize', __('Anonymize IP addresses','tvt'), function(){
            $o = get_option(self::OPT);
            printf('<label><input type="checkbox" name="%s[anonymize]" %s> %s</label>',
                self::OPT, checked(!empty($o['anonymize']), true, false), esc_html__('Recommended', 'tvt'));
        }, 'tvt', 'tvt_main');
        add_settings_field('retention_days', __('Retention (days)','tvt'), function(){
            $o = get_option(self::OPT);
            printf('<input type="number" min="1" name="%s[retention_days]" value="%d">', self::OPT, intval($o['retention_days']??365));
        }, 'tvt', 'tvt_main');
        add_settings_field('exclude_roles', __('Exclude roles','tvt'), function(){
            $o = get_option(self::OPT);
            $roles = wp_roles()->roles;
            $selected = (array)($o['exclude_roles'] ?? []);
            foreach($roles as $key=>$role){
                printf(
                    '<label style="display:inline-block;margin-right:10px;"><input type="checkbox" name="%s[exclude_roles][]" value="%s" %s> %s</label>',
                    self::OPT, esc_attr($key), checked(in_array($key,$selected,true), true, false), esc_html($role['name'])
                );
            }
        }, 'tvt', 'tvt_main');
    }
    public function sanitize($in){
        return [
            'guest' => !empty($in['guest']),
            'anonymize' => !empty($in['anonymize']),
            'retention_days' => max(1, intval($in['retention_days'] ?? 365)),
            'exclude_roles' => array_values(array_filter(array_map('sanitize_text_field', (array)($in['exclude_roles']??[])))),
        ];
    }

    public function admin_menu(){
        add_menu_page('Visitor Tracker','Visitor Tracker','manage_options','tvt',[$this,'page'], 'dashicons-visibility', 80);
        add_submenu_page('tvt','Settings','Settings','manage_options','tvt-settings', function(){
            echo '<div class="wrap"><h1>'.esc_html__('Visitor Tracker – Settings','tvt').'</h1><form method="post" action="options.php">';
            settings_fields('tvt_group'); do_settings_sections('tvt'); submit_button(); echo '</form></div>';
        });
        add_submenu_page('tvt','Analytics','Analytics','manage_options','tvt-analytics', [$this,'analytics_page']);
    }

    /* ---------- Google Front Gate JWT helpers ---------- */

    private function tvt_signing_key() {
        if (defined('SECURE_AUTH_KEY') && SECURE_AUTH_KEY) return (string) SECURE_AUTH_KEY;
        if (defined('AUTH_KEY') && AUTH_KEY) return (string) AUTH_KEY;
        return (string) wp_salt('secure_auth');
    }
    private function tvt_b64url_decode($str) {
        $str = strtr($str, '-_', '+/');
        $pad = strlen($str) % 4;
        if ($pad) $str .= str_repeat('=', 4 - $pad);
        return base64_decode($str);
    }
    private function tvt_decode_tgfg_cookie() {
        if (empty($_COOKIE['tgfg_session'])) return false;
        $tok = (string) $_COOKIE['tgfg_session'];
        $parts = explode('.', $tok);
        if (count($parts) !== 3) return false;
        list($H, $P, $S) = $parts;
        $calc = rtrim(strtr(base64_encode(hash_hmac('sha256', $H . '.' . $P, $this->tvt_signing_key(), true)), '+/', '-_'), '=');
        if (!hash_equals($calc, $S)) return false;
        $payload_raw = $this->tvt_b64url_decode($P);
        if ($payload_raw === false) return false;
        $payload = json_decode($payload_raw, true);
        if (!is_array($payload) || empty($payload['exp']) || time() >= (int) $payload['exp']) return false;
        return $payload;
    }
    private function tvt_resolve_email($wp_user = null) {
        $jwt = $this->tvt_decode_tgfg_cookie();
        if (is_array($jwt) && !empty($jwt['email'])) {
            $email = sanitize_email((string) $jwt['email']);
            if ($email) return $email;
        }
        if ($wp_user && $wp_user->exists()) {
            $email = sanitize_email((string) $wp_user->user_email);
            if ($email) return $email;
        }
        return null;
    }

    /* ---------- Core logging ---------- */

    private function role_excluded($user){
        $o = get_option(self::OPT);
        $exclude = (array)($o['exclude_roles'] ?? []);
        if (!$exclude) return false;
        $roles = (array)($user->roles ?? []);
        return (bool) array_intersect($roles, $exclude);
    }

    private function client_ip(){
        $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR'];
        $ip = '';
        foreach($keys as $k){
            if(!empty($_SERVER[$k])){
                $ip = trim(current(explode(',', $_SERVER[$k]))); break;
            }
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return null;
        return $ip;
    }

    private function pack_ip($ip, $anonymize = false){
        if (!$ip) return null;
        if ($anonymize){
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)){
                $parts = explode('.', $ip); $parts[3] = '0'; $ip = implode('.', $parts);
            } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)){
                $packed = @inet_pton($ip);
                if ($packed !== false){
                    $packed = substr($packed,0,8) . str_repeat("\x00", 8);
                    return $packed;
                }
            }
        }
        return @inet_pton($ip) ?: null;
    }

    private function should_log_guest(){
        $o = get_option(self::OPT);
        return !empty($o['guest']);
    }

    public function log_view(){
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;

        $user = wp_get_current_user();
        if ($user && $user->exists() && $this->role_excluded($user)) return;
        if (!$user->exists() && !$this->should_log_guest()) return;

        $email = $this->tvt_resolve_email($user);

        $ip    = $this->client_ip();
        $o     = get_option(self::OPT);
        $packed= $this->pack_ip($ip, !empty($o['anonymize']));

        $this->insert_row([
            'ts'     => current_time('mysql'),
            'user_id'=> $user->exists() ? $user->ID : null,
            'email'  => $email ?: null,
            'ip'     => $packed,
            'url'    => esc_url_raw((is_ssl()?'https://':'http://').$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']),
            'ua'     => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
            'event'  => 'view'
        ]);
    }

    public function log_login($user_login, $user){
        if ($this->role_excluded($user)) return;
        $email = (string) ($this->tvt_resolve_email($user) ?: $user->user_email);
        $ip    = $this->client_ip();
        $o     = get_option(self::OPT);
        $packed= $this->pack_ip($ip, !empty($o['anonymize']));
        $this->insert_row([
            'ts'     => current_time('mysql'),
            'user_id'=> $user->ID,
            'email'  => $email,
            'ip'     => $packed,
            'url'    => esc_url_raw((is_ssl()?'https://':'http://').$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'] ?? home_url('/')),
            'ua'     => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
            'event'  => 'login'
        ]);
    }

    private function insert_row($data){
        global $wpdb;
        $table = $wpdb->base_prefix . self::TABLE;
        $wpdb->insert($table, [
            'ts'      => $data['ts'],
            'user_id' => $data['user_id'],
            'email'   => $data['email'],
            'ip'      => $data['ip'],
            'url'     => $data['url'],
            'ua'      => $data['ua'],
            'event'   => $data['event'],
        ], ['%s','%d','%s','%s','%s','%s','%s']);
    }

    public function cleanup(){
        $o = get_option(self::OPT);
        $days = max(1, intval($o['retention_days'] ?? 365));
        global $wpdb;
        $table = $wpdb->base_prefix . self::TABLE;
        $wpdb->query($wpdb->prepare("DELETE FROM `$table` WHERE ts < (NOW() - INTERVAL %d DAY)", $days));
    }

    public function page(){
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $table = $wpdb->base_prefix . self::TABLE;

        // QUICK STATUS BADGE
        $jwt = $this->tvt_decode_tgfg_cookie();
        if ($jwt) {
            printf('<div class="notice" style="padding:8px 12px;margin:8px 0;border:1px solid #b7f5c2;background:#e6ffed;border-radius:6px;">Google session detected for <strong>%s</strong></div>',
                esc_html($jwt['email'])
            );
        } else {
            echo '<div class="notice" style="padding:8px 12px;margin:8px 0;border:1px solid #ffd8a8;background:#fff4e5;border-radius:6px;">No Google session cookie found</div>';
        }

        $page = max(1, intval($_GET['paged'] ?? 1));
        $per  = 50;
        $off  = ($page-1)*$per;

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` ORDER BY id DESC LIMIT %d OFFSET %d", $per, $off), ARRAY_A);

        echo '<div class="wrap"><h1>Visitor Tracker</h1>';
        echo '<p>Logged fields: date/time, email (if available), IP ('.(get_option(self::OPT)['anonymize']?'anonymized':'full').'), URL, User-Agent, event.</p>';

        echo '<p>';
        echo '<a class="button button-primary" href="'.esc_url(admin_url('admin-post.php?action=tvt_export_csv&_wpnonce='.wp_create_nonce('tvt_export'))).'">Export CSV</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin-post.php?action=tvt_clear_logs&_wpnonce='.wp_create_nonce('tvt_clear'))).'" onclick="return confirm(\'Clear ALL logs?\')">Clear logs</a>';
        echo '</p>';

        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Date/Time</th><th>Email</th><th>IP</th><th>Event</th><th>URL</th><th>User Agent</th></tr></thead><tbody>';
        foreach($rows as $r){
            $ip = $r['ip'] ? inet_ntop($r['ip']) : '';
            echo '<tr>';
            printf('<td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td style="word-break:break-all">%s</td><td>%s</td>',
                   $r['id'], esc_html($r['ts']), esc_html($r['email']), esc_html($ip), esc_html($r['event']), esc_html($r['url']), esc_html($r['ua']));
            echo '</tr>';
        }
        if (!$rows) echo '<tr><td colspan="7">No logs.</td></tr>';
        echo '</tbody></table>';

        $pages = max(1, ceil($total/$per));
        if ($pages>1){
            echo '<p style="margin-top:10px;">Page '.$page.' of '.$pages.' ';
            if ($page>1) echo '<a class="button" href="'.esc_url(add_query_arg('paged',$page-1)).'">&laquo; Prev</a> ';
            if ($page<$pages) echo '<a class="button" href="'.esc_url(add_query_arg('paged',$page+1)).'">Next &raquo;</a>';
            echo '</p>';
        }

        echo '</div>';
    }

    public function analytics_page(){
        if (!current_user_can('manage_options')) return;

        // Ensure admin page is never cached by page caches/CDNs
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();
        ?>
        <div class="wrap">
          <h1>Visitor Tracker – Analytics</h1>
          <form id="tvt-analytics-form" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin:10px 0 16px;">
            <label>From<br><input type="date" id="tvt_from" name="from"></label>
            <label>To<br><input type="date" id="tvt_to" name="to"></label>
            <label>Event<br>
              <select id="tvt_event" name="event">
                <option value="">All</option>
                <option value="view">View</option>
                <option value="login">Login</option>
              </select>
            </label>
            <label><input type="checkbox" id="tvt_guests" name="guests" checked> Include guests</label>
            <button type="submit" class="button button-primary">Apply</button>
          </form>

          <div id="tvt-kpis" style="display:flex; gap:18px; flex-wrap:wrap; margin-bottom:12px;">
            <div class="card"><strong id="kpi_total">0</strong><div>Total Events</div></div>
            <div class="card"><strong id="kpi_unique">0</strong><div>Unique Emails</div></div>
            <div class="card"><strong id="kpi_views">0</strong><div>Views</div></div>
            <div class="card"><strong id="kpi_logins">0</strong><div>Logins</div></div>
          </div>

          <div style="max-width:1100px;">
            <h2>Daily Events</h2>
            <canvas id="tvt_chart_daily"
                    class="tvt-canvas skip-lazy no-lazyload"
                    data-no-lazy="1" data-nitro-lazy="off"
                    height="130" style="min-height:160px;"></canvas>
            <h2 style="margin-top:24px;">Top Pages</h2>
            <canvas id="tvt_chart_pages"
                    class="tvt-canvas skip-lazy no-lazyload"
                    data-no-lazy="1" data-nitro-lazy="off"
                    height="130" style="min-height:160px;"></canvas>
          </div>

          <style>
            .card{border:1px solid #e5e7eb; padding:10px 14px; border-radius:8px; background:#fff; min-width:150px}
            .card strong{font-size:1.4rem; display:block}
          </style>
        </div>
        <?php
        wp_enqueue_script('chartjs','https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',[], '4.4.1', true);
        wp_enqueue_script('tvt-analytics', plugins_url('tvt-analytics.js', __FILE__), ['chartjs','jquery'], '1.1.2', true);
        wp_localize_script('tvt-analytics', 'TVT_ANALYTICS', [
            'ajax'   => admin_url('admin-ajax.php'),
            'nonce'  => wp_create_nonce('tvt_stats'),
            'today'  => current_time('Y-m-d'),
        ]);
    }

    public function ajax_fetch_stats(){
        if(!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'tvt_stats')){
            wp_send_json_error(['msg'=>'Unauthorized'], 403);
        }
        // Ensure analytics JSON is not cached
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        global $wpdb; $table = $wpdb->base_prefix . self::TABLE;

        $from  = sanitize_text_field($_POST['from'] ?? '');
        $to    = sanitize_text_field($_POST['to'] ?? '');
        $event = sanitize_text_field($_POST['event'] ?? '');
        $guests= !empty($_POST['guests']);

        $where = ' WHERE 1=1 ';
        $params = [];
        if ($from) { $where .= ' AND ts >= %s '; $params[] = $from . ' 00:00:00'; }
        if ($to)   { $where .= ' AND ts <= %s '; $params[] = $to   . ' 23:59:59'; }
        if ($event){ $where .= ' AND event = %s '; $params[] = $event; }
        if (!$guests) { $where .= ' AND email IS NOT NULL AND email <> "" '; }

        $kpi_sql = "SELECT
                      COUNT(*) AS total,
                      SUM(event='view') AS views,
                      SUM(event='login') AS logins,
                      COUNT(DISTINCT NULLIF(email,'')) AS unique_emails
                    FROM `$table` $where";
        $kpis = $wpdb->get_row($wpdb->prepare($kpi_sql, $params), ARRAY_A) ?: ['total'=>0,'views'=>0,'logins'=>0,'unique_emails'=>0];

        $daily_sql = "SELECT DATE(ts) AS d, COUNT(*) AS c FROM `$table` $where GROUP BY DATE(ts) ORDER BY d ASC";
        $daily_rows = $wpdb->get_results($wpdb->prepare($daily_sql, $params), ARRAY_A) ?: [];
        $daily = array_map(function($r){ return ['day'=>$r['d'], 'count'=>intval($r['c'])]; }, $daily_rows);

        $pages_sql = "SELECT url, COUNT(*) AS c FROM `$table` $where GROUP BY url ORDER BY c DESC LIMIT 15";
        $pages_rows = $wpdb->get_results($wpdb->prepare($pages_sql, $params), ARRAY_A) ?: [];
        $top_pages = array_map(function($r){ return ['url'=>$r['url'], 'count'=>intval($r['c'])]; }, $pages_rows);

        wp_send_json_success([
            'kpis' => [
                'total'  => intval($kpis['total']),
                'views'  => intval($kpis['views']),
                'logins' => intval($kpis['logins']),
                'unique' => intval($kpis['unique_emails'])
            ],
            'daily'     => $daily,
            'top_pages' => $top_pages
        ]);
    }

    public function handle_export_csv(){
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'tvt_export')) wp_die('Unauthorized');
        global $wpdb;
        $table = $wpdb->base_prefix . self::TABLE;
        $rows = $wpdb->get_results("SELECT id, ts, email, ip, event, url, ua FROM `$table` ORDER BY id DESC", ARRAY_A);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="visitor-logs.csv"');
        $out = fopen('php://output','w');
        fputcsv($out, ['ID','Timestamp','Email','IP','Event','URL','User-Agent']);
        foreach($rows as $r){
            $r['ip'] = $r['ip'] ? inet_ntop($r['ip']) : '';
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }

    public function handle_clear_logs(){
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'tvt_clear')) wp_die('Unauthorized');
        global $wpdb; $table = $wpdb->base_prefix . self::TABLE;
        $wpdb->query("TRUNCATE TABLE `$table`");
        wp_safe_redirect(admin_url('admin.php?page=tvt'));
        exit;
    }

    public function privacy_exporter($email, $page = 1){
        global $wpdb; $table = $wpdb->base_prefix . self::TABLE; $per = 250; $off = ($page-1)*$per;
        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d", $email, $per, $off), ARRAY_A);
        $data = [];
        foreach($items as $r){
            $data[] = [
                'group_id'    => 'tangra-visitor-tracker',
                'group_label' => __('Tangra Visitor Tracker','tvt'),
                'item_id'     => 'tvt-'.$r['id'],
                'data'        => [
                    ['name'=>'Timestamp','value'=>$r['ts']],
                    ['name'=>'Email','value'=>$r['email']],
                    ['name'=>'IP','value'=> ($r['ip'] ? inet_ntop($r['ip']) : '')],
                    ['name'=>'Event','value'=>$r['event']],
                    ['name'=>'URL','value'=>$r['url']],
                    ['name'=>'User Agent','value'=>$r['ua']],
                ]
            ];
        }
        $done = count($items) < $per;
        return ['data'=>$data, 'done'=>$done];
    }

    public function privacy_eraser($email, $page = 1){
        global $wpdb; $table = $wpdb->base_prefix . self::TABLE; $per = 500; $off = ($page-1)*$per;
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM `$table` WHERE email = %s LIMIT %d OFFSET %d", $email, $per, $off));
        $done = ($deleted < $per);
        return ['items_removed'=> (bool)$deleted, 'items_retained'=> false, 'messages'=>[], 'done'=>$done];
    }
}

new Tangra_Visitor_Tracker();
