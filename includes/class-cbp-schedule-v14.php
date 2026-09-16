<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Conservative Mass-intention repair pass.
 *
 * V13 added recovery from the dedicated weekly Mass schedule, but on some
 * layouts it could leave otherwise valid weekday intentions blank. This pass
 * never overwrites a good intention. It only fills blank/suspicious details
 * from the source schedule and normalizes the parish PDF's leading '+' marker
 * to a dagger.
 */
final class CBP_Schedule_V14
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
        add_action('shutdown', array($this, 'postprocess_review'), 80);
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
        if (! is_array($review) || empty($review['weekly']['masses']) || ! is_array($review['weekly']['masses'])) {
            return;
        }

        $bulletin_date = isset($review['bulletin_date']) ? (string) $review['bulletin_date'] : '';
        if (! $this->valid_date($bulletin_date)) {
            return;
        }

        $intentions = $this->scan_mass_intentions($this->source_lines($review), $bulletin_date);

        foreach ($review['weekly']['masses'] as &$mass) {
            if (! is_array($mass)) {
                continue;
            }

            $existing = isset($mass['description']) ? $this->normalize_intention($mass['description']) : '';
            if ($this->good_existing_intention($existing)) {
                $mass['description'] = $existing;
                continue;
            }

            $date = isset($mass['date']) ? (string) $mass['date'] : '';
            $time = isset($mass['time']) ? $this->normalize_time($mass['time']) : '';
            $location = $this->canonical_location(isset($mass['location']) ? $mass['location'] : '');
            $key = $this->key($date, $time, $location);

            if ($key !== '' && isset($intentions[$key]) && $intentions[$key] !== '') {
                $mass['description'] = $intentions[$key];
            } else {
                $mass['description'] = $existing;
            }
        }
        unset($mass);

        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function scan_mass_intentions(array $lines, $bulletin_date)
    {
        $start = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/this\s+week[’\'s]*\s+mass\s+schedule/iu', $line)) {
                $start = $i + 1;
                break;
            }
        }
        if ($start === null) {
            return array();
        }

        $map = array();
        $current_date = '';
        $current_time = '';
        $current_location = '';

        $limit = min(count($lines), $start + 100);
        for ($i = $start; $i < $limit; $i++) {
            $line = trim((string) $lines[$i]);
            if ($line === '') {
                continue;
            }
            if (preg_match('/\b(?:reconciliation|the\s+rosary|adoration\s+is\s+held)\b/iu', $line)) {
                break;
            }

            $date = $this->date_from_text($line, $bulletin_date);
            if ($date !== '') {
                $current_date = $date;
                $current_time = $this->time_from_text($line);
                $current_location = '';
                continue;
            }

            if ($current_date !== '' && $current_time === '') {
                $time = $this->time_from_text($line);
                if ($time !== '') {
                    $current_time = $time;
                    continue;
                }
            }

            if ($current_date === '' || $current_time === '') {
                continue;
            }

            $location = '';
            if (preg_match('/\bSt\.?\s*Peter(?:[’\']s)?\b/iu', $line)) {
                $location = 'peter';
            } elseif (preg_match('/\bSt\.?\s*Mary(?:[’\']s)?\b/iu', $line)) {
                $location = 'mary';
            }
            if ($location !== '') {
                $current_location = $location;
            }

            $intention = '';

            if (preg_match('/^(.+?)\s+funeral(?:\s+mass)?(?:\s+at\s+.+)?$/iu', $line, $m)) {
                $intention = $this->clean_intention($m[1]);
            } elseif (preg_match('/\bSt\.?\s*(?:Peter|Mary)(?:[’\']s)?\b\s*,\s*(.+)$/iu', $line, $m)) {
                $intention = $this->clean_intention($m[1]);
            } elseif (preg_match('/(?:^|,)\s*([+†]\s*.+)$/u', $line, $m)) {
                $intention = $this->clean_intention($m[1]);
            }

            $intention = $this->normalize_intention($intention);
            if ($intention === '' || ! $this->plausible_intention($intention)) {
                continue;
            }

            $key = $this->key($current_date, $current_time, $current_location);
            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $intention;
            }
        }

        return $map;
    }

    private function good_existing_intention($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }

        // Reject the exact failure mode seen in test29/test30 where a date/time
        // fragment was left in the intention field instead of the intention.
        if (preg_match('/^(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b.*\b\d{1,2}(?::\d{2})?\s*(?:a\.?m\.?|p\.?m\.?)\.?$/iu', $value)) {
            return false;
        }

        return $this->plausible_intention($value);
    }

    private function key($date, $time, $location)
    {
        $date = trim((string) $date);
        $time = $this->normalize_time($time);
        $location = trim((string) $location);
        if ($date === '' || $time === '') {
            return '';
        }
        return strtolower($date . '|' . $time . '|' . $location);
    }

    private function source_lines(array $review)
    {
        if (empty($review['source_lines']) || ! is_array($review['source_lines'])) {
            return array();
        }

        $lines = array();
        foreach ($review['source_lines'] as $line) {
            $line = trim((string) $line);
            if ($line === '' || strpos($line, '[debug]') === 0) {
                continue;
            }
            if (strpos($line, '[raw] ') === 0) {
                $line = substr($line, 6);
            }
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    private function date_from_text($text, $bulletin_date)
    {
        $month_pattern = '(January|February|March|April|May|June|July|August|September|Sept|Sep|October|Oct|November|Nov|December|Dec)';
        if (! preg_match('/\b' . $month_pattern . '\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b/iu', (string) $text, $m)) {
            return '';
        }

        $base = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $base) {
            return '';
        }

        $month = $this->month_number($m[1]);
        $day = (int) $m[2];
        $year = (int) $base->format('Y');
        $candidate = DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-' . $day);
        if (! $candidate) {
            return '';
        }

        if ($candidate < $base->modify('-45 days')) {
            $candidate = $candidate->modify('+1 year');
        } elseif ($candidate > $base->modify('+320 days')) {
            $candidate = $candidate->modify('-1 year');
        }
        return $candidate->format('Y-m-d');
    }

    private function time_from_text($text)
    {
        if (! preg_match('/\b(0?[1-9]|1[0-2])(?::([0-5][0-9]))?\s*(a\.?m\.?|p\.?m\.?)\b/iu', (string) $text, $m)) {
            return '';
        }
        $minute = isset($m[2]) && $m[2] !== '' ? $m[2] : '00';
        $suffix = strtoupper(substr(preg_replace('/[^apm]/i', '', $m[3]), 0, 1)) . 'M';
        return ((int) $m[1]) . ':' . $minute . ' ' . $suffix;
    }

    private function normalize_time($time)
    {
        $time = strtoupper(preg_replace('/\s+/', ' ', trim((string) $time)));
        if (! preg_match('/^(\d{1,2})(?::(\d{2}))?\s*([AP]M)$/', $time, $m)) {
            return $time;
        }
        $minute = isset($m[2]) && $m[2] !== '' ? $m[2] : '00';
        return ((int) $m[1]) . ':' . $minute . ' ' . $m[3];
    }

    private function normalize_intention($value)
    {
        $value = $this->clean_intention($value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/^\+\s*/u', '† ', $value);
        $value = preg_replace('/(&\s*)\+\s*/u', '$1† ', $value);
        return trim((string) $value);
    }

    private function clean_intention($value)
    {
        $value = preg_replace('/\s+/', ' ', trim((string) $value));
        return trim((string) $value, " \t\n\r\0\x0B,;:–—-");
    }

    private function plausible_intention($value)
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 140) {
            return false;
        }
        if (preg_match('/\b(?:reconciliation|adoration|rosary|liturgical schedule|lector|usher|greeter)\b/iu', $value)) {
            return false;
        }
        return true;
    }

    private function canonical_location($location)
    {
        $location = trim((string) $location);
        if (preg_match('/\bSt\.?\s*Peter\b/iu', $location)) {
            return 'peter';
        }
        if (preg_match('/\bSt\.?\s*Mary/iu', $location)) {
            return 'mary';
        }
        return '';
    }

    private function month_number($name)
    {
        $key = strtolower(substr(trim((string) $name), 0, 3));
        $map = array(
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
            'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
            'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        );
        return isset($map[$key]) ? $map[$key] : 1;
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
