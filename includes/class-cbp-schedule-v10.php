<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Repairs Rosary locations when the PDF parser separates the church/location
 * from a relative Rosary rule. The Rosary time itself identifies the matching
 * Mass: normally 30 minutes later, or the same Mass for Friday "after Mass".
 */
final class CBP_Schedule_V10
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
        if (! is_array($review) || empty($review['weekly']) || ! is_array($review['weekly'])) {
            return;
        }

        $review['weekly'] = self::repair_weekly($review['weekly']);
        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    public static function repair_weekly(array $weekly)
    {
        $masses = isset($weekly['masses']) && is_array($weekly['masses']) ? $weekly['masses'] : array();
        $devotions = isset($weekly['devotions']) && is_array($weekly['devotions']) ? $weekly['devotions'] : array();

        if (empty($masses) || empty($devotions)) {
            return $weekly;
        }

        foreach ($devotions as &$row) {
            $title = isset($row['title']) ? trim((string) $row['title']) : '';
            $location = isset($row['location']) ? trim((string) $row['location']) : '';
            if (strcasecmp($title, 'Rosary') !== 0 || $location !== '') {
                continue;
            }

            $date = isset($row['date']) ? (string) $row['date'] : '';
            $time = isset($row['time']) ? trim((string) $row['time']) : '';
            if ($date === '' || $time === '') {
                continue;
            }

            $target_mass_minutes = null;
            if (preg_match('/^After\s+(\d{1,2}:\d{2}\s*[AP]M)\s+Mass$/i', $time, $m)) {
                $target_mass_minutes = self::clock_minutes($m[1]);
            } else {
                $rosary_minutes = self::clock_minutes($time);
                if ($rosary_minutes !== null) {
                    $target_mass_minutes = ($rosary_minutes + 30) % (24 * 60);
                }
            }

            if ($target_mass_minutes === null) {
                continue;
            }

            foreach ($masses as $mass) {
                if ((isset($mass['date']) ? $mass['date'] : '') !== $date) {
                    continue;
                }
                $mass_location = isset($mass['location']) ? trim((string) $mass['location']) : '';
                if ($mass_location === '') {
                    continue;
                }
                $mass_minutes = self::clock_minutes(isset($mass['time']) ? $mass['time'] : '');
                if ($mass_minutes !== null && $mass_minutes === $target_mass_minutes) {
                    $row['location'] = $mass_location;
                    break;
                }
            }
        }
        unset($row);

        $weekly['devotions'] = $devotions;
        return $weekly;
    }

    private static function clock_minutes($time)
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})\s*([AP]M)$/i', trim((string) $time), $m)) {
            return null;
        }

        $hour = ((int) $m[1]) % 12;
        if (strtoupper($m[3]) === 'PM') {
            $hour += 12;
        }
        return ($hour * 60) + (int) $m[2];
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
