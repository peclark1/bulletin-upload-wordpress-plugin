<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Mass-intention recovery for bulletin layouts where PDF text extraction splits
 * a single Mass entry across several lines.
 *
 * V12 can extract intentions that remain attached to the parsed Mass row. This
 * pass goes back to the dedicated "This week's Mass schedule" source block,
 * rebuilds each Mass entry as a chunk, and matches it back to the dated Mass
 * row. This recovers cases such as the Sunday intention being separated from
 * its date/time by the PDF extractor.
 */
final class CBP_Schedule_V13
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
        // Run after the V12 review-safety/intention pass.
        add_action('shutdown', array($this, 'postprocess_review'), 60);
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

        $lines = $this->source_lines($review);
        $source_masses = $this->parse_mass_section($lines, $bulletin_date);
        if (empty($source_masses)) {
            // Still normalize any already-captured deceased markers.
            $review['weekly']['masses'] = $this->normalize_existing_intentions($review['weekly']['masses']);
            set_transient($this->review_key(), $review, self::REVIEW_TTL);
            return;
        }

        $review['weekly']['masses'] = $this->apply_source_intentions($review['weekly']['masses'], $source_masses);
        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function apply_source_intentions(array $masses, array $source_masses)
    {
        foreach ($masses as &$mass) {
            if (! is_array($mass)) {
                continue;
            }

            $title = isset($mass['title']) ? trim((string) $mass['title']) : '';
            if (strcasecmp($title, 'No Mass') === 0) {
                continue;
            }

            $date = isset($mass['date']) ? (string) $mass['date'] : '';
            $time = isset($mass['time']) ? $this->normalize_time($mass['time']) : '';
            $location = $this->canonical_location(isset($mass['location']) ? $mass['location'] : '');
            if ($date === '' || $time === '') {
                continue;
            }

            $matches = array();
            foreach ($source_masses as $source) {
                if ($source['date'] !== $date || $source['time'] !== $time || $source['intention'] === '') {
                    continue;
                }
                $matches[] = $source;
            }

            if (count($matches) > 1 && $location !== '') {
                $same_location = array_values(array_filter($matches, function ($source) use ($location) {
                    return $source['location'] !== '' && $source['location'] === $location;
                }));
                if (count($same_location) === 1) {
                    $matches = $same_location;
                }
            }

            if (count($matches) === 1) {
                $mass['description'] = $this->normalize_intention($matches[0]['intention']);
            } elseif (isset($mass['description'])) {
                $mass['description'] = $this->normalize_intention($mass['description']);
            }
        }
        unset($mass);

        return $masses;
    }

    private function normalize_existing_intentions(array $masses)
    {
        foreach ($masses as &$mass) {
            if (is_array($mass) && isset($mass['description'])) {
                $mass['description'] = $this->normalize_intention($mass['description']);
            }
        }
        unset($mass);
        return $masses;
    }

    private function parse_mass_section(array $lines, $bulletin_date)
    {
        $start = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/this\s+week[’\'s]*\s+mass\s+schedule/i', $line)) {
                $start = $index + 1;
                break;
            }
        }
        if ($start === null) {
            return array();
        }

        $chunks = array();
        $current = array();
        $limit = min(count($lines), $start + 80);
        for ($i = $start; $i < $limit; $i++) {
            $line = trim((string) $lines[$i]);
            if ($line === '') {
                continue;
            }
            if (preg_match('/\b(?:reconciliation|the\s+rosary|adoration\s+is\s+held)\b/i', $line)) {
                break;
            }

            if ($this->is_mass_date_line($line)) {
                if (! empty($current)) {
                    $chunks[] = $current;
                }
                $current = array($line);
            } elseif (! empty($current)) {
                $current[] = $line;
            }
        }
        if (! empty($current)) {
            $chunks[] = $current;
        }

        $result = array();
        foreach ($chunks as $chunk) {
            $parsed = $this->parse_mass_chunk($chunk, $bulletin_date);
            if ($parsed !== null) {
                $result[] = $parsed;
            }
        }
        return $result;
    }

    private function parse_mass_chunk(array $chunk, $bulletin_date)
    {
        $joined = preg_replace('/\s+/', ' ', trim(implode(' ', $chunk)));
        $date = $this->date_from_text($joined, $bulletin_date);
        $time = $this->time_from_text($joined);
        if ($date === '' || $time === '') {
            return null;
        }

        $location = '';
        if (preg_match('/\bSt\.?\s*Peter(?:[’\']s)?\b/iu', $joined)) {
            $location = 'peter';
        } elseif (preg_match('/\bSt\.?\s*Mary(?:[’\']s)?\b/iu', $joined)) {
            $location = 'mary';
        }

        $intention = '';
        foreach ($chunk as $line) {
            $line = trim((string) $line);

            // Funeral wording normally carries the deceased name before
            // "Funeral" and the church after it.
            if (preg_match('/^(.+?)\s+funeral(?:\s+mass)?(?:\s+at\s+.+)?$/iu', $line, $m)) {
                $candidate = $this->clean_intention($m[1]);
                if ($this->plausible_intention($candidate)) {
                    $intention = $candidate;
                    break;
                }
            }

            // Normal parish Mass entries use "St. Peter's, intention" or
            // "St. Mary's, intention" on the line following the date/time.
            if (preg_match('/\bSt\.?\s*(?:Peter|Mary)(?:[’\']s)?\b\s*,\s*(.+)$/iu', $line, $m)) {
                $candidate = $this->clean_intention($m[1]);
                if ($this->plausible_intention($candidate)) {
                    $intention = $candidate;
                    break;
                }
            }

            // Off-site Masses can use another location name followed by a
            // deceased marker. Capture only when the marker is explicit.
            if (preg_match('/(?:^|,)\s*([+†]\s*.+)$/u', $line, $m)) {
                $candidate = $this->clean_intention($m[1]);
                if ($this->plausible_intention($candidate)) {
                    $intention = $candidate;
                    break;
                }
            }
        }

        return array(
            'date' => $date,
            'time' => $time,
            'location' => $location,
            'intention' => $this->normalize_intention($intention),
            'source' => $joined,
        );
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
        $value = $this->clean_intention($value);
        if ($value === '') {
            return '';
        }

        // The parish PDF commonly extracts its cross/dagger glyph as '+'.
        // Normalize only markers at the beginning of an intention or after an
        // ampersand so ordinary plus signs elsewhere are left alone.
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
        if (preg_match('/^(?:a\.?m\.?|p\.?m\.?)$/iu', $value)) {
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
