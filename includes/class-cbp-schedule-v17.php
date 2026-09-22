<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Per-time Mass-intention fallback.
 *
 * Some bulletin layouts print one date heading followed by multiple Masses on
 * that date (for example, an 11:00 AM funeral and a 5:00 PM parish Mass). The
 * previous fallback split only on date headings, which merged those Masses
 * into one chunk. This pass reads the private preview PDF again and splits the
 * dedicated weekly Mass schedule on EVERY Mass time while carrying the most
 * recent date forward.
 *
 * Existing good intentions are never replaced; this only fills blank or
 * obviously bad intention fields.
 */
final class CBP_Schedule_V17
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
        add_action('shutdown', array($this, 'postprocess_review'), 140);
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

        $preview = get_transient($this->preview_key());
        $path = is_array($preview) && ! empty($preview['path']) ? (string) $preview['path'] : '';
        if ($path === '' || ! is_readable($path) || ! class_exists('Smalot\\PdfParser\\Parser')) {
            return;
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $document = $parser->parseFile($path);
            $text = (string) $document->getText();
        } catch (\Throwable $e) {
            return;
        }

        $candidates = $this->mass_candidates($this->raw_lines($text), $bulletin_date);
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
                $mass['description'] = $existing;
                continue;
            }

            $matches = array_values(array_filter($candidates, function ($candidate) use ($date, $time) {
                return $candidate['date'] === $date
                    && $candidate['time'] === $time
                    && $candidate['intention'] !== '';
            }));

            if (count($matches) > 1 && $location !== '') {
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

    private function raw_lines($text)
    {
        $text = str_replace(array("\r\n", "\r", "\xc2\xa0"), array("\n", "\n", ' '), (string) $text);
        $parts = preg_split('/\n/u', $text);
        if (! is_array($parts)) {
            return array();
        }

        $lines = array();
        foreach ($parts as $line) {
            $line = preg_replace('/[ \t]+/u', ' ', trim((string) $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    /**
     * Build one candidate per time, not one candidate per date heading.
     * The most recent date remains active until the next date heading.
     */
    private function mass_candidates(array $lines, $bulletin_date)
    {
        $start = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/this\s+week[’\'s]*\s+mass\s+schedule/iu', $line)) {
                $start = $index + 1;
                break;
            }
        }
        if ($start === null) {
            return array();
        }

        $candidates = array();
        $current_date = '';
        $current = null;
        $limit = min(count($lines), $start + 140);

        for ($i = $start; $i < $limit; $i++) {
            $line = trim((string) $lines[$i]);
            if ($line === '') {
                continue;
            }

            if (preg_match('/\b(?:reconciliation|the\s+rosary|adoration\s+is\s+held)\b/iu', $line)) {
                $this->append_candidate($candidates, $current);
                break;
            }

            if ($this->is_mass_date_line($line)) {
                $this->append_candidate($candidates, $current);
                $current = null;
                $date = $this->date_from_text($line, $bulletin_date);
                if ($date !== '') {
                    $current_date = $date;
                }

                // Some layouts place the time on the same line as the date.
                $time = $this->time_from_text($line);
                if ($current_date !== '' && $time !== '') {
                    $current = array(
                        'date' => $current_date,
                        'time' => $time,
                        'lines' => array($line),
                    );
                }
                continue;
            }

            if ($current_date === '') {
                continue;
            }

            $time = $this->time_from_text($line);
            if ($time !== '') {
                // A new time under the same date starts a new Mass record.
                $this->append_candidate($candidates, $current);
                $current = array(
                    'date' => $current_date,
                    'time' => $time,
                    'lines' => array($line),
                );
                continue;
            }

            if (is_array($current)) {
                $current['lines'][] = $line;
            }
        }

        $this->append_candidate($candidates, $current);
        return $candidates;
    }

    private function append_candidate(array &$result, $current)
    {
        if (! is_array($current) || empty($current['date']) || empty($current['time'])) {
            return;
        }

        $lines = isset($current['lines']) && is_array($current['lines']) ? $current['lines'] : array();
        $parsed = $this->parse_mass_lines($lines);
        $result[] = array(
            'date' => (string) $current['date'],
            'time' => (string) $current['time'],
            'location' => $parsed['location'],
            'intention' => $parsed['intention'],
        );
    }

    private function parse_mass_lines(array $lines)
    {
        $joined = preg_replace('/\s+/', ' ', trim(implode(' ', $lines)));
        $location = $this->canonical_location($joined);
        $intention = '';

        foreach ($lines as $line) {
            $line = trim((string) $line);

            // Funeral wording: "Patricia ... Funeral at St. Peter's"
            if (preg_match('/^(.+?)\s+funeral(?:\s+mass)?(?:\s+at\s+.+)?$/iu', $line, $m)) {
                $candidate = $this->normalize_intention($m[1]);
                if ($this->plausible_intention($candidate)) {
                    $intention = $candidate;
                    break;
                }
            }

            // Normal parish line: "St. Peter's, + Name" or
            // "St. Mary's, For the People".
            if (preg_match('/\bSt\.?\s*(?:Peter|Mary)(?:[’\']s)?\b\s*,\s*(.+)$/iu', $line, $m)) {
                $candidate = $this->normalize_intention($m[1]);
                if ($this->plausible_intention($candidate)) {
                    $intention = $candidate;
                    break;
                }
            }

            if (preg_match('/(?:^|,)\s*([+†]\s*.+)$/u', $line, $m)) {
                $candidate = $this->normalize_intention($m[1]);
                if ($this->plausible_intention($candidate)) {
                    $intention = $candidate;
                    break;
                }
            }
        }

        return array('location' => $location, 'intention' => $intention);
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

    private function preview_key()
    {
        return 'cbp_preview_' . get_current_user_id();
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
