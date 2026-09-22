<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Recover a single-day recurring Adoration schedule.
 *
 * V6 handles the common school-year wording that names both Wednesday and
 * Thursday. Historical summer bulletins use a simpler form such as:
 *
 *   Adoration is held at St. Peter's on Thursdays from 6:00 am to 4:00 pm.
 *
 * That form was being missed entirely. This conservative pass runs only when
 * no Adoration candidate has already been established, so it cannot replace
 * V6's proven multi-day result.
 */
final class CBP_Schedule_V20
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
        add_action('shutdown', array($this, 'postprocess_review'), 200);
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

        // Never disturb a recurring Adoration value already established by a
        // stronger parser pass (notably V6's Wednesday + Thursday wording).
        $existing_candidate = isset($review['candidates']['adoration'])
            ? trim((string) $review['candidates']['adoration'])
            : '';
        if ($existing_candidate !== '') {
            return;
        }

        $text = $this->source_text($review);
        if ($text === '') {
            return;
        }

        $time = '(\d{1,2}(?::[0-5][0-9])?\s*(?:a\.?\s*m\.?|p\.?\s*m\.?))';
        $weekday = '(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)s?';

        $patterns = array(
            // "Adoration is held at St. Peter's on Thursdays from 6:00 am to 4:00 pm"
            '/Adoration\s+is\s+held\s+at\s+St\.?\s*Peter[’\'s]*\s+on\s+'
                . $weekday . '\s*,?\s*(?:from\s+)?' . $time
                . '\s*(?:-|–|—|to)\s*' . $time . '/iu',

            // "Adoration at St. Peter's is Thursdays, 6:00 am – 4:00 pm"
            '/Adoration\s+at\s+St\.?\s*Peter[’\'s]*\s+is\s+'
                . $weekday . '\s*,?\s*(?:from\s+)?' . $time
                . '\s*(?:-|–|—|to)\s*' . $time . '/iu',
        );

        $match = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $match = $m;
                break;
            }
        }
        if (! is_array($match)) {
            return;
        }

        $weekday_name = ucfirst(strtolower((string) $match[1]));
        $start = $this->normalize_time($match[2]);
        $end = $this->normalize_time($match[3]);
        if ($start === '' || $end === '') {
            return;
        }

        $date = $this->weekday_in_week(
            isset($review['week_start']) ? (string) $review['week_start'] : '',
            isset($review['week_end']) ? (string) $review['week_end'] : '',
            $this->weekday_number($weekday_name)
        );
        if ($date === '') {
            return;
        }

        $range = $start . '–' . $end;
        $source = trim((string) $match[0]);

        if (! isset($review['weekly']['devotions']) || ! is_array($review['weekly']['devotions'])) {
            $review['weekly']['devotions'] = array();
        }

        // A generic earlier pass may have produced a malformed Adoration row.
        // Once this strong sentence-level match succeeds, replace only the
        // Adoration rows and leave every other devotion untouched.
        $review['weekly']['devotions'] = array_values(array_filter(
            $review['weekly']['devotions'],
            function ($row) {
                if (! is_array($row)) {
                    return false;
                }
                $haystack = strtolower(
                    (isset($row['title']) ? (string) $row['title'] : '') . ' ' .
                    (isset($row['description']) ? (string) $row['description'] : '')
                );
                return strpos($haystack, 'adoration') === false;
            }
        ));

        $review['weekly']['devotions'][] = array(
            'date' => $date,
            'time' => $range,
            'location' => 'St. Peter',
            'title' => 'Adoration',
            'description' => $source,
        );
        usort($review['weekly']['devotions'], array($this, 'sort_rows'));

        if (! isset($review['candidates']) || ! is_array($review['candidates'])) {
            $review['candidates'] = array();
        }
        $review['candidates']['adoration'] = $weekday_name . ' ' . $range;

        if (! isset($review['source_lines']) || ! is_array($review['source_lines'])) {
            $review['source_lines'] = array();
        }
        $review['source_lines'][] = '[v20] ' . $source;
        $review['source_lines'] = array_values(array_unique($review['source_lines']));

        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function source_text(array $review)
    {
        if (empty($review['source_lines']) || ! is_array($review['source_lines'])) {
            return '';
        }

        $lines = array();
        foreach ($review['source_lines'] as $line) {
            $line = trim((string) $line);
            if ($line === '' || strpos($line, '[debug]') === 0) {
                continue;
            }
            if (strpos($line, '[raw] ') === 0) {
                $line = substr($line, 6);
            } elseif (preg_match('/^\[v\d+[^\]]*\]\s*/i', $line)) {
                $line = preg_replace('/^\[v\d+[^\]]*\]\s*/i', '', $line);
            }
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $lines)));
    }

    private function weekday_in_week($start, $end, $weekday)
    {
        if (! $this->valid_date($start) || ! $this->valid_date($end) || $weekday < 1 || $weekday > 7) {
            return '';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $finish = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        while ($date && $finish && $date <= $finish) {
            if ((int) $date->format('N') === (int) $weekday) {
                return $date->format('Y-m-d');
            }
            $date = $date->modify('+1 day');
        }
        return '';
    }

    private function weekday_number($weekday)
    {
        $map = array(
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
            'sunday' => 7,
        );
        $key = strtolower(trim((string) $weekday));
        return isset($map[$key]) ? $map[$key] : 0;
    }

    private function normalize_time($value)
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace('.', '', $value);
        $value = preg_replace('/\s+/', '', $value);
        if (! preg_match('/^(\d{1,2})(?::([0-5][0-9]))?([ap])m$/i', $value, $m)) {
            return '';
        }
        $minute = ! empty($m[2]) ? $m[2] : '00';
        return ((int) $m[1]) . ':' . $minute . ' ' . strtoupper($m[3]) . 'M';
    }

    private function sort_rows($a, $b)
    {
        $ak = (isset($a['date']) ? $a['date'] : '') . ' ' . (isset($a['time']) ? $a['time'] : '');
        $bk = (isset($b['date']) ? $b['date'] : '') . ' ' . (isset($b['time']) ? $b['time'] : '');
        return strcmp($ak, $bk);
    }

    private function valid_date($date)
    {
        if (! is_string($date) || $date === '') {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
