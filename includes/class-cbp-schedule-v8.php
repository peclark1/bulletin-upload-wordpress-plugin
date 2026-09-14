<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Cleans up duplicate/fragmented event rows created when bulletin lines contain
 * multiple times or time ranges.
 */
final class CBP_Schedule_V8
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
        // V7 is registered first; run after its continuation-note/Rosary fixes.
        add_action('shutdown', array($this, 'postprocess_review'));
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
        if (! is_array($review) || empty($review['weekly']['events']) || ! is_array($review['weekly']['events'])) {
            return;
        }

        $review['weekly']['events'] = self::collapse_range_duplicates($review['weekly']['events']);
        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    public static function collapse_range_duplicates(array $rows)
    {
        $rows = array_values($rows);
        $remove = array();

        foreach ($rows as $range_index => $range_row) {
            $range = self::time_range(isset($range_row['time']) ? $range_row['time'] : '');
            if ($range === null) {
                continue;
            }

            foreach ($rows as $index => $row) {
                if ($index === $range_index || isset($remove[$index])) {
                    continue;
                }
                if (! self::same_event($range_row, $row)) {
                    continue;
                }

                $single = self::single_time(isset($row['time']) ? $row['time'] : '');
                if ($single === null) {
                    continue;
                }

                if ($single === $range[0] || $single === $range[1]) {
                    $remove[$index] = true;
                }
            }
        }

        if (! empty($remove)) {
            $rows = array_values(array_filter($rows, function ($row, $index) use ($remove) {
                return ! isset($remove[$index]);
            }, ARRAY_FILTER_USE_BOTH));
        }

        // Multi-time lines such as "10:00 am & 6:30 pm Bible Study" can leave
        // a connector attached to the title of the first recovered row. Strip
        // only leading separator/conjunction artifacts; legitimate ampersands
        // inside names (e.g. "Burger & Beer") are preserved.
        foreach ($rows as &$row) {
            if (isset($row['title'])) {
                $row['title'] = self::clean_event_title($row['title']);
            }
        }
        unset($row);

        usort($rows, array(__CLASS__, 'sort_rows'));
        return $rows;
    }

    public static function clean_event_title($title)
    {
        $title = trim((string) $title);
        $title = preg_replace('/^(?:(?:&|\+|\/|,|;|:|[-–—])\s*|and\s+)+/iu', '', $title);
        return trim((string) $title);
    }

    private static function same_event($a, $b)
    {
        foreach (array('date', 'location') as $field) {
            $av = strtolower(trim((string) (isset($a[$field]) ? $a[$field] : '')));
            $bv = strtolower(trim((string) (isset($b[$field]) ? $b[$field] : '')));
            if ($av !== $bv) {
                return false;
            }
        }

        $at = self::normalize_title(isset($a['title']) ? $a['title'] : '');
        $bt = self::normalize_title(isset($b['title']) ? $b['title'] : '');
        if ($at === '' || $bt === '' || $at !== $bt) {
            return false;
        }

        return true;
    }

    private static function normalize_title($title)
    {
        $title = strtolower(self::clean_event_title($title));
        $title = preg_replace('/[^a-z0-9]+/', ' ', $title);
        return trim((string) $title);
    }

    private static function time_range($value)
    {
        $value = trim((string) $value);
        if (! preg_match('/^(\d{1,2}:\d{2}\s*[AP]M)\s*(?:-|–|—|to)\s*(\d{1,2}:\d{2}\s*[AP]M)$/i', $value, $m)) {
            return null;
        }
        return array(self::normalize_time($m[1]), self::normalize_time($m[2]));
    }

    private static function single_time($value)
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d{1,2}:\d{2}\s*[AP]M$/i', $value)) {
            return null;
        }
        return self::normalize_time($value);
    }

    private static function normalize_time($value)
    {
        $value = strtoupper(preg_replace('/\s+/', ' ', trim((string) $value)));
        if (! preg_match('/^(\d{1,2}):(\d{2})\s*([AP]M)$/', $value, $m)) {
            return $value;
        }
        return ((int) $m[1]) . ':' . $m[2] . ' ' . $m[3];
    }

    public static function sort_rows($a, $b)
    {
        $ak = (isset($a['date']) ? $a['date'] : '') . ' ' . (isset($a['time']) ? $a['time'] : '');
        $bk = (isset($b['date']) ? $b['date'] : '') . ' ' . (isset($b['time']) ? $b['time'] : '');
        return strcmp($ak, $bk);
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
