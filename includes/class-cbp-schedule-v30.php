<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Chronologically orders approved weekly rows.
 *
 * Earlier parser layers compared display time strings lexically, which can put
 * 5:00 PM before 9:00 AM.  This final pass converts the first clock time in a
 * row to minutes after midnight and sorts by date + real time.  Untimed notices
 * (for example "NO Ladies lunch") remain first within their day; unparseable
 * timed labels remain last.  The same repair is applied at option-read time so
 * already-approved weekly data is corrected immediately after upgrade.
 */
final class CBP_Schedule_V30
{
    const REVIEW_TTL = 2 * DAY_IN_SECONDS;

    private static $instance;

    public static function instance()
    {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // V29 is the final event-cleanup layer; sort after it finishes.
        add_action('shutdown', array($this, 'postprocess_review'), 310);

        // Correct already-approved data without requiring another bulletin
        // extraction/approval cycle.
        add_filter('option_' . CBP_Schedule::WEEKLY_OPTION, array(__CLASS__, 'sort_weekly_option'), 30);
    }

    public function postprocess_review()
    {
        if (! is_admin() || ! current_user_can('manage_options')) {
            return;
        }

        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if ($action !== 'cbp_extract_schedule') {
            return;
        }

        $review = get_transient($this->review_key());
        if (! is_array($review) || empty($review['weekly']) || ! is_array($review['weekly'])) {
            return;
        }

        $review['weekly'] = self::sort_weekly($review['weekly']);

        if (! isset($review['source_lines']) || ! is_array($review['source_lines'])) {
            $review['source_lines'] = array();
        }
        $review['source_lines'][] = '[v30] Sorted weekly rows by actual clock time.';
        $review['source_lines'] = array_values(array_unique($review['source_lines']));

        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    public static function sort_weekly_option($weekly)
    {
        return is_array($weekly) ? self::sort_weekly($weekly) : $weekly;
    }

    public static function sort_weekly(array $weekly)
    {
        foreach (array('masses', 'devotions', 'events') as $key) {
            if (! empty($weekly[$key]) && is_array($weekly[$key])) {
                $weekly[$key] = self::sort_calendar_rows($weekly[$key]);
            }
        }
        return $weekly;
    }

    public static function sort_calendar_rows(array $rows)
    {
        $rows = array_values($rows);
        usort($rows, array(__CLASS__, 'compare_rows'));
        return $rows;
    }

    public static function compare_rows($a, $b)
    {
        $ad = is_array($a) && isset($a['date']) ? (string) $a['date'] : '';
        $bd = is_array($b) && isset($b['date']) ? (string) $b['date'] : '';
        $date_compare = strcmp($ad, $bd);
        if ($date_compare !== 0) {
            return $date_compare;
        }

        $at = self::row_time_key(is_array($a) ? $a : array());
        $bt = self::row_time_key(is_array($b) ? $b : array());
        if ($at !== $bt) {
            return $at < $bt ? -1 : 1;
        }

        // Stable deterministic tie-breakers for rows that begin at the same
        // time.  Keep these human-readable rather than relying on array order.
        $al = is_array($a) && isset($a['location']) ? (string) $a['location'] : '';
        $bl = is_array($b) && isset($b['location']) ? (string) $b['location'] : '';
        $location_compare = strcasecmp($al, $bl);
        if ($location_compare !== 0) {
            return $location_compare;
        }

        $atitle = is_array($a) && isset($a['title']) ? (string) $a['title'] : '';
        $btitle = is_array($b) && isset($b['title']) ? (string) $b['title'] : '';
        return strcasecmp($atitle, $btitle);
    }

    private static function row_time_key(array $row)
    {
        $time = isset($row['time']) ? trim((string) $row['time']) : '';

        // Untimed notices are intentionally shown first within their day.
        if ($time === '') {
            return -1;
        }

        if (! preg_match('/\b(1[0-2]|0?\d)(?::([0-5]\d))?\s*([AP])\.?\s*M\.?\b/iu', $time, $matches)) {
            // A non-empty label with no recognizable clock time should not jump
            // ahead of real timed events.
            return 24 * 60 + 1;
        }

        $hour = (int) $matches[1];
        $minute = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;
        $period = strtoupper($matches[3]);
        $hour = $hour % 12;
        if ($period === 'P') {
            $hour += 12;
        }

        $key = ($hour * 60) + $minute;

        // "After 9:00 AM Mass" belongs just after a literal 9:00 AM item if
        // both happen to appear in the same bucket.
        if (preg_match('/^\s*after\b/iu', $time)) {
            $key += 1;
        }

        return $key;
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
