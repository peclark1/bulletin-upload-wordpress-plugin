<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Normalized-source Mass-intention fallback.
 *
 * Earlier passes deliberately leave already-good intentions alone. If an
 * intention is still blank or obviously bad, this final pass walks the
 * normalized source lines already stored in the review data. Those are the
 * same lines shown under "Show bulletin lines used for extraction" in the
 * admin UI, and they are often cleaner than the raw PDF text.
 *
 * The parser carries the active date forward, starts a new candidate at each
 * Mass time, and pairs it with the following parish/intention or funeral line.
 */
final class CBP_Schedule_V18
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
        add_action('shutdown', array($this, 'postprocess_review'), 160);
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

        $candidates = $this->candidates_from_source_lines($this->source_lines($review), $bulletin_date);
        if (empty($candidates)) {
            return;
        }

        foreach ($review['weekly']['masses'] as &$mass) {
            if (! is_array($mass)) {
                continue;
            }

            $existing = isset($mass['description']) ? $this->normalize_intention($mass['description']) : '';
            if ($this->good_existing_intention($existing)) {
                $mass['description'] = $existing;
                continue;
            }

            $date = isset($mass['date']) ? trim((string) $mass['date']) : '';
            $time = isset($mass['time']) ? $this->normalize_time($mass['time']) : '';
            $location = $this->canonical_location(isset($mass['location']) ? $mass['location'] : '');
            if ($date === '' || $time === '') {
                continue;
            }

            $matches = array_values(array_filter($candidates, function ($candidate) use ($date, $time) {
                return $candidate['date'] === $date
                    && $candidate['time'] === $time
                    && $candidate['intention'] !== '';
            }));

            if ($location !== '' && count($matches) > 1) {
                $same_location = array_values(array_filter($matches, function ($candidate) use ($location) {
                    return $candidate['location'] === '' || $candidate['location'] === $location;
                }));
                if (count($same_location) === 1) {
                    $matches = $same_location;
                }
            }

            if (count($matches) === 1) {
                $mass['description'] = $matches[0]['intention'];
            } else {
                $mass['description'] = $existing;
            }
        }
        unset($mass);

        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function candidates_from_source_lines(array $lines, $bulletin_date)
    {
        $result = array();
        $current_date = '';
        $pending = null;

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if ($this->is_mass_date_line($line)) {
                $current_date = $this->date_from_text($line, $bulletin_date);
                $pending = null;
                continue;
            }

            if ($current_date === '') {
                continue;
            }

            $time = $this->time_from_text($line);
            if ($time !== '') {
                $pending = array(
                    'date' => $current_date,
                    'time' => $time,
                    'location' => '',
                    'intention' => '',
                );
                continue;
            }

            if (! is_array($pending)) {
                continue;
            }

            $parsed = $this->parse_intention_line($line);
            if ($parsed === null) {
                continue;
            }

            $pending['location'] = $parsed['location'];
            $pending['intention'] = $parsed['intention'];
            if ($pending['intention'] !== '') {
                $result[] = $pending;
            }
            $pending = null;
        }

        return $result;
    }

    private function parse_intention_line($line)
    {
        $line = trim((string) $line);

        if (preg_match('/^(.+?)\s+funeral(?:\s+mass)?(?:\s+at\s+(.+))?$/iu', $line, $m)) {
            $intention = $this->normalize_intention($m[1]);
            if ($this->plausible_intention($intention)) {
                return array(
                    'location' => isset($m[2]) ? $this->canonical_location($m[2]) : '',
                    'intention' => $intention,
                );
            }
        }

        if (preg_match('/\b(St\.?\s*(?:Peter|Mary)(?:[’\']s)?)\b\s*,\s*(.+)$/iu', $line, $m)) {
            $intention = $this->normalize_intention($m[2]);
            if ($this->plausible_intention($intention)) {
                return array(
                    'location' => $this->canonical_location($m[1]),
                    'intention' => $intention,
                );
            }
        }

        if (preg_match('/(?:^|,)\s*([+†]\s*.+)$/u', $line, $m)) {
            $intention = $this->normalize_intention($m[1]);
            if ($this->plausible_intention($intention)) {
                return array('location' => '', 'intention' => $intention);
            }
        }

        return null;
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

    private function is_mass_date_line($line)
    {
        $weekday = '(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|Mon|Tue|Tues|Wed|Thu|Thur|Thurs|Fri|Sat|Sun)';
        $month = '(?:January|February|March|April|May|June|July|August|September|Sept|Sep|October|Oct|November|Nov|December|Dec)';
        return preg_match('/^\s*' . $weekday . '\.?\s*,?\s+' . $month . '\.?\s+\d{1,2}(?:st|nd|rd|th)?\b/iu', (string) $line) === 1;
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
        $value = preg_replace('/\s+/', ' ', trim((string) $value));
        $value = trim((string) $value, " \t\n\r\0\x0B,;:–—-");
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/^\+\s*/u', '† ', $value);
        $value = preg_replace('/(&\s*)\+\s*/u', '$1† ', $value);
        return trim((string) $value);
    }

    private function good_existing_intention($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }
        if (preg_match('/^(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b.*\b\d{1,2}(?::\d{2})?\s*(?:a\.?m\.?|p\.?m\.?)\.?$/iu', $value)) {
            return false;
        }
        return $this->plausible_intention($value);
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
