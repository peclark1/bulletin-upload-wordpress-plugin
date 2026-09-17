<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Source-line recovery pass for historical layouts.
 *
 * The review already contains the exact [raw] line stream produced by the
 * bundled PDF parser.  Historical fixtures showed that reparsing the PDF in a
 * later shutdown callback can group text differently.  V23 therefore uses the
 * stored raw line stream as the authoritative input for the dedicated Mass
 * schedule, then rebuilds Mass/No Mass/special-liturgy rows and the Rosaries
 * derived from ordinary Masses.
 */
final class CBP_Schedule_V23
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
        add_action('shutdown', array($this, 'postprocess_review'), 240);
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

        $lines = $this->raw_source_lines($review);
        if (empty($lines)) {
            return;
        }

        $parsed = $this->parse_mass_schedule($lines, $review);
        if (count($parsed['masses']) < 3) {
            return;
        }

        if (! isset($review['weekly']) || ! is_array($review['weekly'])) {
            $review['weekly'] = array();
        }
        $review['weekly']['masses'] = $parsed['masses'];

        $devotions = isset($review['weekly']['devotions']) && is_array($review['weekly']['devotions'])
            ? $review['weekly']['devotions']
            : array();
        $devotions = array_values(array_filter($devotions, function ($row) {
            if (! is_array($row)) {
                return true;
            }
            $title = isset($row['title']) ? (string) $row['title'] : '';
            return strcasecmp($title, 'Rosary') !== 0 && strcasecmp($title, 'Divine Mercy Hour') !== 0;
        }));
        foreach ($this->rosary_rows($parsed['masses']) as $row) {
            $devotions[] = $row;
        }
        foreach ($parsed['special_devotions'] as $row) {
            $devotions[] = $row;
        }
        foreach ($this->dated_adoration_rows($lines, $review, $parsed['end_index']) as $row) {
            // A calendar-specific row is stronger than a generic row for the
            // same date, so replace only that date's Adoration entry.
            $devotions = array_values(array_filter($devotions, function ($existing) use ($row) {
                return ! is_array($existing)
                    || strcasecmp(isset($existing['title']) ? (string) $existing['title'] : '', 'Adoration') !== 0
                    || (isset($existing['date']) ? (string) $existing['date'] : '') !== $row['date'];
            }));
            $devotions[] = $row;
        }
        $reconciliation = $this->reconciliation_row($lines, $review);
        if (is_array($reconciliation)) {
            $devotions = array_values(array_filter($devotions, function ($existing) use ($reconciliation) {
                return ! is_array($existing)
                    || strcasecmp(isset($existing['title']) ? (string) $existing['title'] : '', 'Reconciliation') !== 0
                    || (isset($existing['date']) ? (string) $existing['date'] : '') !== $reconciliation['date'];
            }));
            $devotions[] = $reconciliation;
        }
        $review['weekly']['devotions'] = $this->dedupe_rows($devotions);

        if (! isset($review['candidates']) || ! is_array($review['candidates'])) {
            $review['candidates'] = array();
        }
        $this->repair_weekend_candidates($review, $parsed['masses']);
        $this->recover_livestream($review, $lines);

        $review['source_lines'][] = '[v23] Rebuilt liturgies from stored raw parser lines.';
        $review['source_lines'] = array_values(array_unique($review['source_lines']));
        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function raw_source_lines(array $review)
    {
        $raw = array();
        $fallback = array();
        foreach (isset($review['source_lines']) && is_array($review['source_lines']) ? $review['source_lines'] : array() as $line) {
            $line = (string) $line;
            if (strpos($line, '[raw] ') === 0) {
                $value = trim(substr($line, 6));
                if ($value !== '') {
                    $raw[] = $value;
                }
                continue;
            }
            if ($line !== '' && strpos($line, '[debug]') !== 0 && $line[0] !== '[') {
                $fallback[] = trim($line);
            }
        }
        return ! empty($raw) ? $raw : array_values(array_filter($fallback));
    }

    private function parse_mass_schedule(array $lines, array $review)
    {
        $started = false;
        $seen = false;
        $current_date = '';
        $current_label = '';
        $pending = null;
        $masses = array();
        $special = array();
        $end_index = 0;
        $bulletin_date = (string) $review['bulletin_date'];
        $week_start = isset($review['week_start']) ? (string) $review['week_start'] : '';
        $week_end = isset($review['week_end']) ? (string) $review['week_end'] : '';

        foreach ($lines as $index => $line) {
            if (! $started) {
                if (preg_match('/This\s+week[^A-Za-z0-9]?s\s+Mass\s+schedule/i', $line)) {
                    $started = true;
                }
                continue;
            }

            if (preg_match('/^(Reconciliation\b|The\s+Rosary\b|Adoration\s+is\b)/i', $line)) {
                $this->finish_pending($pending, $masses, $special);
                $end_index = $index;
                break;
            }

            $date_info = $this->date_info($line, $bulletin_date);
            if (is_array($date_info)) {
                if ($seen && ! $this->in_range($date_info['date'], $week_start, $week_end)) {
                    $this->finish_pending($pending, $masses, $special);
                    $end_index = $index;
                    break;
                }
                if ($this->in_range($date_info['date'], $week_start, $week_end)) {
                    $this->finish_pending($pending, $masses, $special);
                    $current_date = $date_info['date'];
                    $current_label = $date_info['label'];
                    $seen = true;
                    if (stripos($line, 'NO MASS') !== false) {
                        $masses[] = $this->no_mass_row($current_date, $current_label, $line);
                    } else {
                        $time = $this->time_from_text($line);
                        if ($time !== '') {
                            $pending = array('date' => $current_date, 'time' => $time, 'details' => array());
                        }
                    }
                    continue;
                }
            }

            if (! $seen || $current_date === '') {
                continue;
            }
            if (preg_match('/^(st|nd|rd|th)\.?$/i', $line) || preg_match('/^[,.]$/', $line)) {
                continue;
            }
            if (stripos($line, 'NO MASS') !== false) {
                $this->finish_pending($pending, $masses, $special);
                $masses[] = $this->no_mass_row($current_date, $current_label, $line);
                continue;
            }
            $time = $this->time_from_text($line);
            if ($time !== '') {
                $this->finish_pending($pending, $masses, $special);
                $pending = array('date' => $current_date, 'time' => $time, 'details' => array());
                continue;
            }
            if (is_array($pending)) {
                $pending['details'][] = $line;
            }
        }
        $this->finish_pending($pending, $masses, $special);
        if ($end_index === 0) {
            $end_index = count($lines);
        }
        return array(
            'masses' => $this->dedupe_rows($masses),
            'special_devotions' => $this->dedupe_rows($special),
            'end_index' => $end_index,
        );
    }

    private function finish_pending(&$pending, array &$masses, array &$special)
    {
        if (! is_array($pending)) {
            $pending = null;
            return;
        }
        $details = trim((string) preg_replace('/\s+/u', ' ', implode(' ', $pending['details'])));
        if ($details === '') {
            $pending = null;
            return;
        }
        $row = $this->row_from_details($pending['date'], $pending['time'], $details);
        if (is_array($row)) {
            if (! empty($row['_special_devotion'])) {
                unset($row['_special_devotion']);
                $special[] = $row;
            } else {
                $masses[] = $row;
            }
        }
        $pending = null;
    }

    private function row_from_details($date, $time, $details)
    {
        $location = $this->location($details);
        if (stripos($details, 'Divine Mercy') !== false) {
            return array(
                'date' => $date,
                'time' => $time,
                'location' => $location !== '' ? $location : 'St. Peter',
                'title' => 'Divine Mercy Hour',
                'description' => 'Divine Mercy Hour',
                '_special_devotion' => true,
            );
        }

        if (preg_match('/^(.+?)\s+Funeral\s+at\s+St\.?\s*(Peter|Mary)/iu', $details, $m)) {
            return array(
                'date' => $date,
                'time' => $time,
                'location' => strcasecmp($m[2], 'Mary') === 0 ? 'St. Mary’s' : 'St. Peter',
                'title' => 'Funeral Mass',
                'description' => $this->intention($m[1]),
            );
        }

        $clean = preg_replace('/^St\.?\s*Peter[’\'s]*\s*,?\s*/iu', '', $details);
        $clean = preg_replace('/^St\.?\s*Mary[’\'s]*\s*,?\s*/iu', '', $clean);
        $clean = preg_replace('/^Heritage\s+(?:Senior\s+)?Living\s+Center\s*,?\s*/iu', '', $clean);
        $clean = preg_replace('/^Crystal\s+Brook\s+Senior\s+Living\s+Center\s*,?\s*/iu', '', $clean);
        $clean = trim((string) $clean, " \t\n\r\0\x0B,");

        if (preg_match('/\bService\b/i', $clean)) {
            $clean = trim((string) preg_replace('/\s*\bService\b\s*/i', ' ', $clean));
            return array(
                'date' => $date,
                'time' => $time,
                'location' => $location !== '' ? $location : 'St. Peter',
                'title' => 'Memorial Service',
                'description' => $this->intention($clean),
            );
        }

        return array(
            'date' => $date,
            'time' => $time,
            'location' => $location,
            'title' => 'Mass',
            'description' => $this->intention($clean),
        );
    }

    private function no_mass_row($date, $label, $line)
    {
        $location = $this->location($line);
        if ($location === '') {
            $location = stripos($line, 'Mary') !== false ? 'St. Mary’s' : 'St. Peter';
        }
        $straight = strpos($line, "Peter's") !== false || strpos($line, "Mary's") !== false;
        if ($location === 'St. Mary’s') {
            $name = $straight ? "St. Mary's" : 'St. Mary’s';
        } else {
            $name = $straight ? "St. Peter's" : 'St. Peter’s';
        }
        return array('date' => $date, 'time' => '', 'location' => $location, 'title' => 'No Mass', 'description' => $label . ' NO MASS at ' . $name);
    }

    private function repair_weekend_candidates(array &$review, array $masses)
    {
        $targets = array(
            'st_peter_saturday' => array(6, 'St. Peter'),
            'st_peter_sunday' => array(7, 'St. Peter'),
            'st_mary_sunday' => array(7, 'St. Mary’s'),
        );
        $current = get_option(CBP_Schedule::OPTION, CBP_Schedule::defaults());
        foreach ($targets as $key => $target) {
            $times = array();
            foreach ($masses as $row) {
                if (! is_array($row) || (isset($row['title']) ? $row['title'] : '') !== 'Mass') {
                    continue;
                }
                if ((isset($row['location']) ? $row['location'] : '') !== $target[1]) {
                    continue;
                }
                $dt = ! empty($row['date']) ? DateTimeImmutable::createFromFormat('!Y-m-d', $row['date']) : false;
                if (! $dt || (int) $dt->format('N') !== $target[0]) {
                    continue;
                }
                if (! empty($row['time'])) {
                    $times[$row['time']] = true;
                }
            }
            $times = array_keys($times);
            if (count($times) === 1) {
                $review['candidates'][$key] = $times[0];
            } elseif (count($times) > 1) {
                $existing = isset($current[$key]) ? (string) $current[$key] : '';
                if ($existing !== '' && in_array($existing, $times, true)) {
                    $review['candidates'][$key] = $existing;
                }
            }
        }
    }

    private function rosary_rows(array $masses)
    {
        $rows = array();
        foreach ($masses as $mass) {
            if (! is_array($mass) || (isset($mass['title']) ? $mass['title'] : '') !== 'Mass') {
                continue;
            }
            $location = isset($mass['location']) ? (string) $mass['location'] : '';
            if ($location !== 'St. Peter' && $location !== 'St. Mary’s') {
                continue;
            }
            $date = isset($mass['date']) ? (string) $mass['date'] : '';
            $time = isset($mass['time']) ? (string) $mass['time'] : '';
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d g:i A', $date . ' ' . $time);
            if (! $dt) {
                continue;
            }
            $rosary_time = (int) $dt->format('N') === 5 ? 'After ' . $time . ' Mass' : $dt->modify('-30 minutes')->format('g:i A');
            $rows[] = array(
                'date' => $date,
                'time' => $rosary_time,
                'location' => $location,
                'title' => 'Rosary',
                'description' => 'The Rosary is prayed each day ½ hour prior to Mass. On Friday’s, the Rosary is prayed after Mass.',
            );
        }
        return $rows;
    }

    private function dated_adoration_rows(array $lines, array $review, $start_index)
    {
        $rows = array();
        $date = '';
        $bulletin = (string) $review['bulletin_date'];
        $week_start = isset($review['week_start']) ? (string) $review['week_start'] : '';
        $week_end = isset($review['week_end']) ? (string) $review['week_end'] : '';
        for ($i = (int) $start_index; $i < count($lines); $i++) {
            $info = $this->date_info($lines[$i], $bulletin);
            if (is_array($info)) {
                $date = $this->in_range($info['date'], $week_start, $week_end) ? $info['date'] : '';
            }
            if ($date === '' || stripos($lines[$i], 'Adoration') === false) {
                continue;
            }
            $range = $this->time_range($lines[$i]);
            if (is_array($range)) {
                $rows[] = array('date' => $date, 'time' => $range[0] . '–' . $range[1], 'location' => 'St. Peter', 'title' => 'Adoration', 'description' => $lines[$i]);
            }
        }
        return $this->dedupe_rows($rows);
    }

    private function reconciliation_row(array $lines, array $review)
    {
        foreach ($lines as $line) {
            if (stripos($line, 'Reconciliation') === false || stripos($line, 'Class') !== false) {
                continue;
            }
            $time = '';
            $range = $this->time_range($line);
            if (is_array($range)) {
                $time = $range[0] . '–' . $range[1];
            } else {
                $time = $this->time_from_text($line);
            }
            if ($time === '') {
                continue;
            }
            $week_end = isset($review['week_end']) ? DateTimeImmutable::createFromFormat('!Y-m-d', $review['week_end']) : false;
            if (! $week_end) {
                continue;
            }
            $saturday = $week_end->modify('previous saturday');
            if ((int) $week_end->format('N') === 6) {
                $saturday = $week_end;
            }
            return array('date' => $saturday->format('Y-m-d'), 'time' => $time, 'location' => 'St. Peter', 'title' => 'Reconciliation', 'description' => trim($line));
        }
        return null;
    }

    private function recover_livestream(array &$review, array $lines)
    {
        $text = implode(' ', $lines);
        if (! preg_match('/Sunday\s+Mass\s*&\s*Rosary\s+are\s+live\s+streamed\s+at\s+stpeterpr\.org/i', $text)) {
            return;
        }
        if (! isset($review['weekly']['livestream']) || ! is_array($review['weekly']['livestream'])) {
            $review['weekly']['livestream'] = array();
        }
        foreach ($review['weekly']['livestream'] as $row) {
            $value = is_array($row) ? (isset($row['description']) ? (string) $row['description'] : '') : (string) $row;
            if (stripos($value, 'Sunday Mass & Rosary') !== false) {
                return;
            }
        }
        $review['weekly']['livestream'][] = array('description' => 'Sunday Mass & Rosary are live streamed at stpeterpr.org');
    }

    private function date_info($line, $bulletin_date)
    {
        if (! preg_match('/^[‘\'“”]?\s*(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s*(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})/iu', $line, $m)) {
            return null;
        }
        $base = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $base) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('!F j Y', $m[2] . ' ' . ((int) $m[3]) . ' ' . $base->format('Y'));
        if (! $dt) {
            return null;
        }
        if ($dt < $base->modify('-14 days')) {
            $dt = $dt->modify('+1 year');
        }
        $day = (int) $dt->format('j');
        return array('date' => $dt->format('Y-m-d'), 'label' => $dt->format('l, F j') . $this->suffix($day));
    }

    private function suffix($day)
    {
        if ($day % 100 >= 11 && $day % 100 <= 13) {
            return 'th';
        }
        $last = $day % 10;
        return $last === 1 ? 'st' : ($last === 2 ? 'nd' : ($last === 3 ? 'rd' : 'th'));
    }

    private function time_from_text($text)
    {
        if (! preg_match('/\b(\d{1,2})(?::([0-5][0-9]))\s*(a\.?\s*m\.?|p\.?\s*m\.?)/iu', $text, $m)) {
            return '';
        }
        return ((int) $m[1]) . ':' . $m[2] . ' ' . (stripos($m[3], 'p') === 0 ? 'PM' : 'AM');
    }

    private function time_range($text)
    {
        if (! preg_match('/(\d{1,2}(?::[0-5][0-9])?)\s*(a\.?m\.?|p\.?m\.)?\s*(?:-|–|—|to)\s*(\d{1,2}(?::[0-5][0-9])?)\s*(a\.?m\.?|p\.?m\.?)/iu', $text, $m)) {
            return null;
        }
        $end_ampm = stripos($m[4], 'p') === 0 ? 'PM' : 'AM';
        $start_ampm = ! empty($m[2]) ? (stripos($m[2], 'p') === 0 ? 'PM' : 'AM') : $end_ampm;
        return array($this->format_clock($m[1], $start_ampm), $this->format_clock($m[3], $end_ampm));
    }

    private function format_clock($clock, $ampm)
    {
        if (strpos($clock, ':') === false) {
            $clock .= ':00';
        }
        list($hour, $minute) = explode(':', $clock, 2);
        return ((int) $hour) . ':' . $minute . ' ' . $ampm;
    }

    private function intention($text)
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
        $text = preg_replace('/(^|\s)\+\s*/u', '$1† ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function location($text)
    {
        if (preg_match('/Heritage\s+Senior\s+Living\s+Center/i', $text)) return 'Heritage Senior Living Center';
        if (preg_match('/Heritage\s+Living\s+Center/i', $text)) return 'Heritage Living Center';
        if (preg_match('/Crystal\s+Brook\s+Senior\s+Living\s+Center/i', $text)) return 'Crystal Brook Senior Living Center';
        if (preg_match('/St\.?\s*Mary/i', $text)) return 'St. Mary’s';
        if (preg_match('/St\.?\s*Peter/i', $text)) return 'St. Peter';
        return '';
    }

    private function in_range($date, $start, $end)
    {
        return $start === '' || $end === '' || ($date >= $start && $date <= $end);
    }

    private function dedupe_rows(array $rows)
    {
        $unique = array();
        foreach ($rows as $row) {
            if (! is_array($row)) continue;
            $key = implode('|', array(isset($row['date']) ? $row['date'] : '', isset($row['time']) ? $row['time'] : '', isset($row['location']) ? $row['location'] : '', isset($row['title']) ? $row['title'] : '', isset($row['description']) ? $row['description'] : ''));
            $unique[$key] = $row;
        }
        $rows = array_values($unique);
        usort($rows, function ($a, $b) {
            return strcmp((isset($a['date']) ? $a['date'] : '') . ' ' . (isset($a['time']) ? $a['time'] : '') . ' ' . (isset($a['title']) ? $a['title'] : ''), (isset($b['date']) ? $b['date'] : '') . ' ' . (isset($b['time']) ? $b['time'] : '') . ' ' . (isset($b['title']) ? $b['title'] : ''));
        });
        return $rows;
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
