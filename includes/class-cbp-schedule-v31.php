<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Repairs October 4 bulletin edge cases:
 * - split-line off-site Mass intentions;
 * - prose announcements with a stated date different from the weekly row.
 */
final class CBP_Schedule_V31
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
        add_action('shutdown', array($this, 'postprocess_review'), 320);
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
        if (! is_array($review) || empty($review['bulletin_date'])) {
            return;
        }

        $raw = $this->raw_lines($review);
        if (! empty($raw) && ! empty($review['weekly']['masses']) && is_array($review['weekly']['masses'])) {
            $records = $this->mass_records(
                $this->mass_schedule_window($raw),
                (string) $review['bulletin_date']
            );
            foreach ($review['weekly']['masses'] as $index => $mass) {
                if (! is_array($mass) || strcasecmp($mass['title'] ?? '', 'Mass') !== 0) {
                    continue;
                }
                $key = $this->mass_key($mass);
                if ($key !== '' && isset($records[$key]) && count($records[$key]) === 1) {
                    $intention = trim((string) ($records[$key][0]['description'] ?? ''));
                    if ($intention !== '') {
                        $review['weekly']['masses'][$index]['description'] = $intention;
                    }
                }
            }
        }

        if (! empty($review['weekly']['events']) && is_array($review['weekly']['events'])) {
            $bulletin_date = (string) $review['bulletin_date'];
            $future_sources = $this->future_dated_source_lines($raw, $bulletin_date);
            $review['weekly']['events'] = array_values(array_filter(
                $review['weekly']['events'],
                function ($row) use ($bulletin_date, $future_sources) {
                    if (! is_array($row)) {
                        return true;
                    }
                    if ($this->is_mismatched_dated_prose($row, $bulletin_date)) {
                        return false;
                    }
                    return ! $this->matches_future_dated_source($row, $future_sources);
                }
            ));
        }

        if (class_exists('CBP_Schedule_V30') && ! empty($review['weekly']) && is_array($review['weekly'])) {
            $review['weekly'] = CBP_Schedule_V30::sort_weekly($review['weekly']);
        }

        $review['source_lines'] = isset($review['source_lines']) && is_array($review['source_lines'])
            ? $review['source_lines']
            : array();
        $review['source_lines'][] = '[v31] Recovered split-line Mass intentions and removed mismatched dated prose.';
        $review['source_lines'] = array_values(array_unique($review['source_lines']));
        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function raw_lines(array $review)
    {
        $raw = array();
        foreach (($review['source_lines'] ?? array()) as $line) {
            $line = (string) $line;
            if (strpos($line, '[raw] ') === 0) {
                $value = trim(substr($line, 6));
                if ($value !== '') {
                    $raw[] = $value;
                }
            }
        }
        return $raw;
    }

    private function mass_schedule_window(array $lines)
    {
        $started = false;
        $window = array();
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if (! $started) {
                if (preg_match('/This\s+week(?:[\'’]s|s)\s+Mass\s+schedule/iu', $line)) {
                    $started = true;
                }
                continue;
            }
            if (preg_match('/^(Reconciliation\b|The\s+Rosary\b|Adoration\s+is\b)/iu', $line)) {
                break;
            }
            $window[] = $line;
        }
        return $window;
    }

    private function mass_records(array $lines, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) {
            return array();
        }

        $records = array();
        $current_date = '';
        $pending = null;

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || preg_match('/^(st|nd|rd|th)\.?$/iu', $line) || preg_match('/^[,.]$/u', $line)) {
                continue;
            }

            $date = $this->date_from_line($line, $bulletin);
            if ($date !== '') {
                $this->store_pending($pending, $records);
                $current_date = $date;
                $time = $this->time_from_text($line);
                $pending = $time === '' ? null : array(
                    'date' => $date,
                    'time' => $time,
                    'location' => '',
                    'description' => '',
                );
                continue;
            }

            if ($current_date === '') {
                continue;
            }

            $time = $this->time_from_text($line);
            if ($time !== '') {
                $this->store_pending($pending, $records);
                $pending = array(
                    'date' => $current_date,
                    'time' => $time,
                    'location' => '',
                    'description' => '',
                );
                continue;
            }

            if (! is_array($pending)) {
                continue;
            }

            $location = $this->location_from_text($line);
            if ($location !== '') {
                $pending['location'] = $location;
                $intention = $this->intention_on_location_line($line, $location);
                if ($intention !== '') {
                    $pending['description'] = $intention;
                }
                continue;
            }

            if ($pending['location'] !== '' && $pending['description'] === '') {
                $pending['description'] = $this->normalize_intention($line);
            }
        }

        $this->store_pending($pending, $records);
        return $records;
    }

    private function store_pending(&$pending, array &$records)
    {
        if (! is_array($pending)) {
            $pending = null;
            return;
        }
        $key = $this->mass_key($pending);
        if ($key !== '' && trim((string) ($pending['description'] ?? '')) !== '') {
            $records[$key] = $records[$key] ?? array();
            $records[$key][] = $pending;
        }
        $pending = null;
    }

    private function mass_key(array $row)
    {
        $date = trim((string) ($row['date'] ?? ''));
        $time = trim((string) ($row['time'] ?? ''));
        $location = trim((string) ($row['location'] ?? ''));
        return ($date === '' || $time === '' || $location === '')
            ? ''
            : strtolower($date . '|' . $time . '|' . $location);
    }

    private function intention_on_location_line($line, $location)
    {
        $patterns = array(
            'St. Peter' => '/^.*?St\.?\s*Peter[’\'s]*\s*,\s*/iu',
            'St. Mary’s' => '/^.*?St\.?\s*Mary[’\'s]*\s*,\s*/iu',
            'Heritage Senior Living Center' => '/^.*?Heritage\s+(?:Senior\s+)?Living\s+Center\s*,\s*/iu',
            'Crystal Brook Senior Living Center' => '/^.*?Crystal\s+Brook\s+Senior\s+Living\s+Center\s*,\s*/iu',
        );
        if (! isset($patterns[$location]) || ! preg_match($patterns[$location], $line)) {
            return '';
        }
        return $this->normalize_intention(preg_replace($patterns[$location], '', $line, 1));
    }

    private function normalize_intention($text)
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text), " ,");
        $text = preg_replace('/\+\s*/u', '† ', $text);
        return trim((string) $text);
    }

    private function location_from_text($text)
    {
        if (preg_match('/\bSt\.?\s*Peter[’\'s]*/iu', $text)) return 'St. Peter';
        if (preg_match('/\bSt\.?\s*Mary[’\'s]*/iu', $text)) return 'St. Mary’s';
        if (preg_match('/\bHeritage\s+(?:Senior\s+)?Living\s+Center\b/iu', $text)) return 'Heritage Senior Living Center';
        if (preg_match('/\bCrystal\s+Brook\s+Senior\s+Living\s+Center\b/iu', $text)) return 'Crystal Brook Senior Living Center';
        return '';
    }

    private function is_mismatched_dated_prose(array $row, $bulletin_date)
    {
        $title = trim((string) ($row['title'] ?? ''));
        $description = trim((string) ($row['description'] ?? ''));
        $location = trim((string) ($row['location'] ?? ''));
        $text = trim($title . ' ' . $description);
        $stated = $this->date_from_text($text, $bulletin_date);
        $assigned = (string) ($row['date'] ?? '');

        if ($stated === '' || $assigned === '' || $stated === $assigned) {
            return false;
        }

        // A raw prose sentence can contain a clock time for its future date.
        // Do not let that time make the sentence look like a current-week row.
        $same_prose = $title !== ''
            && $description !== ''
            && $this->semantic_text($title) === $this->semantic_text($description);

        return $location === '' && $same_prose;
    }

    private function future_dated_source_lines(array $lines, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) {
            return array();
        }
        $week_start = $bulletin->modify('+1 day')->format('Y-m-d');
        $week_end = $bulletin->modify('+7 days')->format('Y-m-d');
        $future = array();

        foreach ($lines as $line) {
            $date = $this->date_from_text((string) $line, $bulletin_date);
            if ($date === '' || ($date >= $week_start && $date <= $week_end)) {
                continue;
            }
            $normalized = $this->semantic_text($line);
            if ($normalized !== '') {
                $future[] = $normalized;
            }
        }
        return array_values(array_unique($future));
    }

    private function matches_future_dated_source(array $row, array $future_sources)
    {
        if (trim((string) ($row['time'] ?? '')) !== '' || empty($future_sources)) {
            return false;
        }

        $title = $this->semantic_text((string) ($row['title'] ?? ''));
        $description = $this->semantic_text((string) ($row['description'] ?? ''));
        $candidate = $title !== '' ? $title : $description;
        if (strlen($candidate) < 12) {
            return false;
        }

        $prefix = substr($candidate, 0, min(32, strlen($candidate)));
        foreach ($future_sources as $source) {
            if (strpos($source, $candidate) !== false
                || strpos($candidate, $source) !== false
                || ($prefix !== '' && strpos($source, $prefix) !== false)) {
                return true;
            }
        }
        return false;
    }

    private function semantic_text($text)
    {
        $text = strtolower((string) $text);
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function date_from_line($line, DateTimeImmutable $bulletin)
    {
        if (! preg_match('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s*,?\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})/iu', $line, $m)) {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!F j Y', $m[2] . ' ' . $m[3] . ' ' . $bulletin->format('Y'));
        if (! $date) return '';
        if ($date < $bulletin->modify('-30 days')) $date = $date->modify('+1 year');
        return $date->format('Y-m-d');
    }

    private function date_from_text($text, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin || ! preg_match('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(?:st|nd|rd|th)?\b/iu', $text, $m)) {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!F j Y', $m[1] . ' ' . $m[2] . ' ' . $bulletin->format('Y'));
        if (! $date) return '';
        if ($date < $bulletin->modify('-30 days')) $date = $date->modify('+1 year');
        return $date->format('Y-m-d');
    }

    private function time_from_text($text)
    {
        if (! preg_match('/\b(1[0-2]|0?\d):([0-5]\d)\s*([ap])\.?\s*m\.?(?:\s|$|,)/iu', (string) $text, $m)) {
            return '';
        }
        return sprintf('%d:%02d %s', (int) $m[1], (int) $m[2], strtoupper($m[3]) === 'A' ? 'AM' : 'PM');
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
