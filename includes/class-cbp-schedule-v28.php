<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Narrow source-line recovery for the last two historical regression gaps.
 *
 * V27 leaves only two deterministic omissions in the selected historical set:
 * a blank intention when a duplicate time line was removed from diagnostics,
 * and a blank off-site location whose time line ends in "a.m.".  This pass
 * fills only blank fields and requires unambiguous evidence from the dedicated
 * weekly Mass-schedule source window.
 */
final class CBP_Schedule_V28
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
        add_action('shutdown', array($this, 'postprocess_review'), 290);
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
        if (! is_array($review)
            || empty($review['bulletin_date'])
            || empty($review['weekly']['masses'])
            || ! is_array($review['weekly']['masses'])) {
            return;
        }

        $raw = $this->raw_lines($review);
        $window = $this->mass_schedule_window($raw, (string) $review['bulletin_date']);
        if (empty($window)) {
            return;
        }

        $source_intentions = $this->source_intentions_by_date($window, (string) $review['bulletin_date']);

        foreach ($review['weekly']['masses'] as $index => $mass) {
            if (! is_array($mass) || strcasecmp(isset($mass['title']) ? (string) $mass['title'] : '', 'Mass') !== 0) {
                continue;
            }

            $date = isset($mass['date']) ? (string) $mass['date'] : '';
            $time = isset($mass['time']) ? (string) $mass['time'] : '';
            if ($date === '' || $time === '') {
                continue;
            }

            if (trim(isset($mass['location']) ? (string) $mass['location'] : '') === '') {
                $locations = $this->locations_for_date_time(
                    $window,
                    (string) $review['bulletin_date'],
                    $date,
                    $time
                );
                if (count($locations) === 1) {
                    $review['weekly']['masses'][$index]['location'] = reset($locations);
                }
            }

            if (trim(isset($mass['description']) ? (string) $mass['description'] : '') === ''
                && ! empty($source_intentions[$date])) {
                $used = array();
                foreach ($review['weekly']['masses'] as $other) {
                    if (! is_array($other)
                        || (isset($other['date']) ? (string) $other['date'] : '') !== $date) {
                        continue;
                    }
                    $description = trim(isset($other['description']) ? (string) $other['description'] : '');
                    if ($description !== '') {
                        $used[$this->semantic_text($description)] = true;
                    }
                }

                $remaining = array();
                foreach ($source_intentions[$date] as $candidate) {
                    $key = $this->semantic_text($candidate);
                    if ($key !== '' && ! isset($used[$key])) {
                        $remaining[$key] = $candidate;
                    }
                }
                if (count($remaining) === 1) {
                    $review['weekly']['masses'][$index]['description'] = reset($remaining);
                }
            }
        }

        if (! isset($review['source_lines']) || ! is_array($review['source_lines'])) {
            $review['source_lines'] = array();
        }
        $review['source_lines'][] = '[v28] Filled only unambiguous blank Mass fields from dedicated schedule lines.';
        $review['source_lines'] = array_values(array_unique($review['source_lines']));
        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function raw_lines(array $review)
    {
        $raw = array();
        foreach (isset($review['source_lines']) && is_array($review['source_lines']) ? $review['source_lines'] : array() as $line) {
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

    private function mass_schedule_window(array $lines, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) {
            return array();
        }
        $started = false;
        $window = array();
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if (! $started) {
                if (preg_match('/This\s+week[^A-Za-z0-9]?s\s+Mass\s+schedule/i', $line)) {
                    $started = true;
                }
                continue;
            }
            if (preg_match('/^(Reconciliation\b|The\s+Rosary\b|Adoration\s+is\b)/iu', $line)) {
                break;
            }
            $date = $this->date_from_line($line, $bulletin);
            if ($date !== '') {
                $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if ($dt && $dt <= $bulletin) {
                    break;
                }
            }
            $window[] = $line;
        }
        return $window;
    }

    private function source_intentions_by_date(array $window, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) {
            return array();
        }
        $current_date = '';
        $out = array();
        foreach ($window as $line) {
            $date = $this->date_from_line($line, $bulletin);
            if ($date !== '') {
                $current_date = $date;
                continue;
            }
            if ($current_date === '' || strpos($line, ',') === false) {
                continue;
            }
            if ($this->explicit_mass_location($line) === '') {
                continue;
            }
            $parts = preg_split('/,\s*/u', $line, 2);
            if (! is_array($parts) || count($parts) < 2) {
                continue;
            }
            $candidate = trim((string) $parts[1]);
            if ($candidate === '' || stripos($candidate, 'Divine Mercy') !== false) {
                continue;
            }
            $candidate = preg_replace('/^\+\s*/u', '† ', $candidate);
            if (! isset($out[$current_date])) {
                $out[$current_date] = array();
            }
            $out[$current_date][$this->semantic_text($candidate)] = $candidate;
        }
        foreach ($out as $date => $values) {
            $out[$date] = array_values($values);
        }
        return $out;
    }

    private function locations_for_date_time(array $window, $bulletin_date, $wanted_date, $wanted_time)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) {
            return array();
        }
        $current_date = '';
        $capture = false;
        $lookahead = 0;
        $found = array();
        foreach ($window as $line) {
            $date = $this->date_from_line($line, $bulletin);
            if ($date !== '') {
                $current_date = $date;
                $capture = false;
                $lookahead = 0;
                continue;
            }
            if ($current_date !== $wanted_date) {
                continue;
            }
            $time = $this->time_from_text($line);
            if ($time !== '') {
                $capture = ($time === $wanted_time);
                $lookahead = 0;
                continue;
            }
            if (! $capture || $lookahead >= 4) {
                continue;
            }
            $location = $this->explicit_mass_location($line);
            if ($location !== '') {
                $found[$location] = $location;
            }
            $lookahead++;
        }
        return array_values($found);
    }

    private function date_from_line($line, DateTimeImmutable $bulletin)
    {
        if (! preg_match('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s*,?\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})/iu', $line, $m)) {
            return '';
        }
        $year = (int) $bulletin->format('Y');
        $dt = DateTimeImmutable::createFromFormat('!F j Y', $m[2] . ' ' . $m[3] . ' ' . $year);
        if (! $dt) {
            return '';
        }
        if ($dt < $bulletin->modify('-30 days')) {
            $dt = $dt->modify('+1 year');
        }
        return $dt->format('Y-m-d');
    }

    private function time_from_text($text)
    {
        // Do not require a word boundary after the optional terminal period;
        // lines such as ", 10:00 a.m." end with punctuation, not a word char.
        if (! preg_match('/\b(1[0-2]|0?\d):([0-5]\d)\s*([ap])\.?\s*m\.?(?:\s|$)/i', $text, $m)) {
            return '';
        }
        return sprintf('%d:%02d %s', (int) $m[1], (int) $m[2], strtoupper($m[3]) === 'A' ? 'AM' : 'PM');
    }

    private function explicit_mass_location($text)
    {
        if (preg_match('/\bSt\.?\s*Peter[’\'s]*/iu', $text)) {
            return 'St. Peter';
        }
        if (preg_match('/\bSt\.?\s*Mary[’\'s]*/iu', $text)) {
            return 'St. Mary’s';
        }
        if (preg_match('/\bHeritage\s+(?:Senior\s+)?Living\s+Center\b/iu', $text)) {
            return 'Heritage Senior Living Center';
        }
        if (preg_match('/\bCrystal\s+Brook\s+Senior\s+Living\s+Center\b/iu', $text)) {
            return 'Crystal Brook Senior Living Center';
        }
        return '';
    }

    private function semantic_text($text)
    {
        $text = trim((string) $text);
        $text = preg_replace('/^\+\s*/u', '† ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return strtolower((string) $text);
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
