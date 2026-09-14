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

        // Repair previously approved data at read time, so front-end shortcodes
        // benefit without requiring another approval cycle.
        add_filter('option_' . CBP_Schedule::WEEKLY_OPTION, array(__CLASS__, 'repair_option'));
    }

    public static function repair_option($weekly)
    {
        return is_array($weekly) ? self::repair_weekly($weekly) : $weekly;
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
        $masses = isset($weekly['masses']) && is_array($weekly['masses']) ? array_values($weekly['masses']) : array();
        $devotions = isset($weekly['devotions']) && is_array($weekly['devotions']) ? array_values($weekly['devotions']) : array();

        if (empty($masses) || empty($devotions)) {
            return $weekly;
        }

        // First salvage Mass locations from their own text when the dedicated
        // location field was lost by PDF column extraction.
        foreach ($masses as &$mass) {
            $location = isset($mass['location']) ? trim((string) $mass['location']) : '';
            if ($location !== '') {
                continue;
            }
            $text = trim(
                (isset($mass['title']) ? (string) $mass['title'] : '') . ' ' .
                (isset($mass['description']) ? (string) $mass['description'] : '')
            );
            $inferred = self::location_from_text($text);
            if ($inferred !== '') {
                $mass['location'] = $inferred;
            }
        }
        unset($mass);

        // On a two-parish Sunday, if one Mass has a known parish and the other
        // lost its location, infer the other parish by elimination.
        self::repair_sunday_mass_locations($masses);

        foreach ($devotions as &$row) {
            $title = isset($row['title']) ? trim((string) $row['title']) : '';
            $description = isset($row['description']) ? trim((string) $row['description']) : '';
            $location = isset($row['location']) ? trim((string) $row['location']) : '';

            // Some approved rows carry "Rosary" in description instead of title.
            if (! self::is_rosary_row($title, $description) || $location !== '') {
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
                $mass_minutes = self::clock_minutes(isset($mass['time']) ? $mass['time'] : '');
                if ($mass_minutes === null || $mass_minutes !== $target_mass_minutes) {
                    continue;
                }

                $mass_location = isset($mass['location']) ? trim((string) $mass['location']) : '';
                if ($mass_location === '') {
                    $mass_location = self::location_from_text(
                        (isset($mass['title']) ? (string) $mass['title'] : '') . ' ' .
                        (isset($mass['description']) ? (string) $mass['description'] : '')
                    );
                }
                if ($mass_location !== '') {
                    $row['location'] = $mass_location;
                    break;
                }
            }
        }
        unset($row);

        $weekly['masses'] = $masses;
        $weekly['devotions'] = $devotions;
        return $weekly;
    }

    private static function is_rosary_row($title, $description)
    {
        return preg_match('/\brosary\b/i', trim((string) $title . ' ' . (string) $description)) === 1;
    }

    private static function repair_sunday_mass_locations(array &$masses)
    {
        $by_date = array();
        foreach ($masses as $index => $mass) {
            $date = isset($mass['date']) ? (string) $mass['date'] : '';
            if ($date === '') {
                continue;
            }
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (! $dt || (int) $dt->format('N') !== 7) {
                continue;
            }
            $by_date[$date][] = $index;
        }

        foreach ($by_date as $indexes) {
            if (count($indexes) !== 2) {
                continue;
            }
            $known = array();
            $unknown = array();
            foreach ($indexes as $index) {
                $location = isset($masses[$index]['location']) ? trim((string) $masses[$index]['location']) : '';
                if ($location === '') {
                    $unknown[] = $index;
                } else {
                    $known[] = array($index, self::canonical_location($location));
                }
            }
            if (count($unknown) !== 1 || count($known) !== 1) {
                continue;
            }
            if ($known[0][1] === "St. Mary's") {
                $masses[$unknown[0]]['location'] = 'St. Peter';
            } elseif ($known[0][1] === 'St. Peter') {
                $masses[$unknown[0]]['location'] = "St. Mary's";
            }
        }
    }

    private static function location_from_text($text)
    {
        $text = (string) $text;
        if (preg_match('/\bSt\.?\s*Peter(?:\'s)?\b/i', $text)) {
            return 'St. Peter';
        }
        if (preg_match('/\bSt\.?\s*Mary(?:\'s|’s)?\b/i', $text)) {
            return "St. Mary's";
        }
        return '';
    }

    private static function canonical_location($location)
    {
        $location = trim((string) $location);
        if (preg_match('/\bSt\.?\s*Peter\b/i', $location)) {
            return 'St. Peter';
        }
        if (preg_match('/\bSt\.?\s*Mary/i', $location)) {
            return "St. Mary's";
        }
        return $location;
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
