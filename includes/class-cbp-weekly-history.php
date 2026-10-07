<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Permanent week-by-week archive of approved bulletin data.
 *
 * The live options remain the fast "what should the website show now?" source,
 * while this table keeps one authoritative snapshot for each approved week.
 * Re-approving the same week intentionally replaces that week's snapshot.
 */
final class CBP_Weekly_History
{
    const SCHEMA_OPTION = 'cbp_weekly_history_schema';
    const SCHEMA_VERSION = '1';

    private static $instance;

    public static function instance()
    {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate()
    {
        $instance = self::instance();
        $instance->install_schema();
        $instance->backfill_current_week();
    }

    private function __construct()
    {
        $this->maybe_install();
        add_action('cbp_weekly_calendar_updated', array($this, 'store_approved_week'), 30, 1);
    }

    public function store_approved_week($weekly)
    {
        if (! is_array($weekly)) {
            return;
        }
        $this->store_week($weekly, true);
    }

    /**
     * Return the archived record for an exact Monday week_start.
     */
    public function get_week($week_start)
    {
        if (! $this->valid_date($week_start)) {
            return null;
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $this->table_name() . ' WHERE week_start = %s LIMIT 1',
                $week_start
            )
        );

        return $this->record_from_row($row);
    }

    /**
     * Choose the week visitors should see when no ?week= value was requested.
     * Prefer the week containing today. If it is unavailable, use the most
     * recent archived week; if there is no past data yet, use the earliest
     * future approved week.
     */
    public function default_week()
    {
        $current_start = $this->current_week_start();
        $current = $this->get_week($current_start);
        if ($current) {
            return $current;
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $this->table_name() . ' WHERE week_start < %s ORDER BY week_start DESC LIMIT 1',
                $current_start
            )
        );
        $record = $this->record_from_row($row);
        if ($record) {
            return $record;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $this->table_name() . ' WHERE week_start > %s ORDER BY week_start ASC LIMIT 1',
                $current_start
            )
        );
        return $this->record_from_row($row);
    }

    /**
     * Previous/next are based on approved records, not calendar arithmetic.
     */
    public function adjacent_week_starts($week_start)
    {
        if (! $this->valid_date($week_start)) {
            return array('previous' => '', 'next' => '');
        }

        global $wpdb;
        $previous = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT week_start FROM ' . $this->table_name() . ' WHERE week_start < %s ORDER BY week_start DESC LIMIT 1',
                $week_start
            )
        );
        $next = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT week_start FROM ' . $this->table_name() . ' WHERE week_start > %s ORDER BY week_start ASC LIMIT 1',
                $week_start
            )
        );

        return array(
            'previous' => $this->valid_date((string) $previous) ? (string) $previous : '',
            'next' => $this->valid_date((string) $next) ? (string) $next : '',
        );
    }

    public function current_week_start()
    {
        $today = DateTimeImmutable::createFromFormat('!Y-m-d', wp_date('Y-m-d'));
        if (! $today) {
            return wp_date('Y-m-d');
        }

        $days_since_monday = ((int) $today->format('N')) - 1;
        if ($days_since_monday > 0) {
            $today = $today->modify('-' . $days_since_monday . ' days');
        }
        return $today->format('Y-m-d');
    }

    private function maybe_install()
    {
        if ((string) get_option(self::SCHEMA_OPTION, '') === self::SCHEMA_VERSION) {
            return;
        }

        $this->install_schema();
        $this->backfill_current_week();
    }

    private function install_schema()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $this->table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            week_start date NOT NULL,
            week_end date NOT NULL,
            bulletin_date date NOT NULL,
            approved_utc datetime NOT NULL,
            approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
            plugin_version varchar(32) NOT NULL DEFAULT '',
            pdf_sha256 char(64) NOT NULL DEFAULT '',
            bulletin_filename varchar(190) NOT NULL DEFAULT '',
            payload longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY week_start (week_start),
            KEY bulletin_date (bulletin_date),
            KEY week_end (week_end)
        ) {$charset_collate};";

        dbDelta($sql);
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
    }

    /**
     * Preserve the currently approved option when this feature is first installed.
     * Older weeks that were already overwritten cannot be reconstructed here.
     */
    private function backfill_current_week()
    {
        if (! class_exists('CBP_Schedule')) {
            return;
        }

        $weekly = wp_parse_args(
            get_option(CBP_Schedule::WEEKLY_OPTION, array()),
            CBP_Schedule::weekly_defaults()
        );
        $week_start = isset($weekly['week_start']) ? (string) $weekly['week_start'] : '';

        if (! $this->valid_week($weekly) || $this->get_week($week_start)) {
            return;
        }

        $this->store_week($weekly, false);
    }

    private function store_week(array $weekly, $fresh_approval)
    {
        if (! $this->valid_week($weekly)) {
            return;
        }

        $bulletin_date = (string) $weekly['source_bulletin_date'];
        $payload = array(
            'weekly' => wp_parse_args($weekly, CBP_Schedule::weekly_defaults()),
            'recurring_schedule' => $this->recurring_schedule_snapshot($bulletin_date),
        );
        $payload_json = wp_json_encode($payload);
        if (! is_string($payload_json)) {
            return;
        }

        $approved_utc = gmdate('Y-m-d H:i:s');
        if (! $fresh_approval && ! empty($weekly['updated_utc'])) {
            $updated = strtotime((string) $weekly['updated_utc']);
            if ($updated !== false) {
                $approved_utc = gmdate('Y-m-d H:i:s', $updated);
            }
        }

        $pdf_sha256 = '';
        if ($fresh_approval) {
            $preview = get_transient('cbp_preview_' . get_current_user_id());
            if (is_array($preview)
                && isset($preview['date'], $preview['sha256'])
                && (string) $preview['date'] === $bulletin_date
                && preg_match('/^[a-f0-9]{64}$/i', (string) $preview['sha256'])) {
                $pdf_sha256 = strtolower((string) $preview['sha256']);
            }
        }

        $stamp = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        $filename = $stamp ? $stamp->format('mdy') . 'bulletin.pdf' : '';

        global $wpdb;
        $wpdb->replace(
            $this->table_name(),
            array(
                'week_start' => (string) $weekly['week_start'],
                'week_end' => (string) $weekly['week_end'],
                'bulletin_date' => $bulletin_date,
                'approved_utc' => $approved_utc,
                'approved_by' => $fresh_approval ? get_current_user_id() : 0,
                'plugin_version' => defined('CBP_VERSION') ? (string) CBP_VERSION : '',
                'pdf_sha256' => $pdf_sha256,
                'bulletin_filename' => $filename,
                'payload' => $payload_json,
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
        );
    }

    private function recurring_schedule_snapshot($bulletin_date)
    {
        $schedule = wp_parse_args(
            get_option(CBP_Schedule::OPTION, array()),
            CBP_Schedule::defaults()
        );

        $keys = array(
            'st_peter_saturday',
            'st_peter_sunday',
            'st_mary_sunday',
            'reconciliation',
            'adoration',
        );

        $values = array();
        $use_pending = ! empty($schedule['pending'])
            && is_array($schedule['pending'])
            && isset($schedule['pending_source_bulletin_date'])
            && (string) $schedule['pending_source_bulletin_date'] === (string) $bulletin_date;

        foreach ($keys as $key) {
            if ($use_pending && array_key_exists($key, $schedule['pending'])) {
                $values[$key] = sanitize_text_field($schedule['pending'][$key]);
            } else {
                $values[$key] = isset($schedule[$key]) ? sanitize_text_field($schedule[$key]) : '';
            }
        }

        $effective_date = '';
        if ($use_pending && $this->valid_date((string) $schedule['pending_effective_date'])) {
            $effective_date = (string) $schedule['pending_effective_date'];
        } else {
            $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $bulletin_date);
            if ($bulletin) {
                $effective_date = $bulletin->modify('next saturday')->format('Y-m-d');
            }
        }

        return array(
            'effective_date' => $effective_date,
            'values' => $values,
        );
    }

    private function record_from_row($row)
    {
        if (! is_object($row) || empty($row->payload)) {
            return null;
        }

        $payload = json_decode((string) $row->payload, true);
        if (! is_array($payload) || empty($payload['weekly']) || ! is_array($payload['weekly'])) {
            return null;
        }

        $weekly = wp_parse_args($payload['weekly'], CBP_Schedule::weekly_defaults());
        if (! $this->valid_week($weekly)) {
            return null;
        }

        return array(
            'week_start' => (string) $row->week_start,
            'week_end' => (string) $row->week_end,
            'bulletin_date' => (string) $row->bulletin_date,
            'approved_utc' => (string) $row->approved_utc,
            'approved_by' => (int) $row->approved_by,
            'plugin_version' => (string) $row->plugin_version,
            'pdf_sha256' => (string) $row->pdf_sha256,
            'bulletin_filename' => (string) $row->bulletin_filename,
            'weekly' => $weekly,
            'recurring_schedule' => isset($payload['recurring_schedule']) && is_array($payload['recurring_schedule'])
                ? $payload['recurring_schedule']
                : array(),
        );
    }

    private function valid_week(array $weekly)
    {
        return isset($weekly['week_start'], $weekly['week_end'], $weekly['source_bulletin_date'])
            && $this->valid_date((string) $weekly['week_start'])
            && $this->valid_date((string) $weekly['week_end'])
            && $this->valid_date((string) $weekly['source_bulletin_date']);
    }

    private function valid_date($date)
    {
        if (! is_string($date) || $date === '') {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'cbp_weekly_history';
    }
}
