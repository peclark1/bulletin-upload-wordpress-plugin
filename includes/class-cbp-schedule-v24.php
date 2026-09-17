<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Deterministic liturgy reconstruction for historical bulletin layouts.
 *
 * The older PDFs sometimes place the weekly calendar visually beside the Mass
 * schedule, so the PDF text stream can interleave calendar entries before the
 * Reconciliation/Rosary/Adoration block. This pass rebuilds only the dedicated
 * Mass-schedule window from stored raw parser lines, bounded by the bulletin
 * date and the following week. It also moves Divine Mercy Hour out of Masses,
 * recognizes memorial services, restores off-site Mass locations, and rebuilds
 * Rosary rows from the corrected ordinary Mass list.
 */
final class CBP_Schedule_V24
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
        add_action('shutdown', array($this, 'postprocess_review'), 250);
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

        $parsed = $this->parse_mass_schedule($lines, (string) $review['bulletin_date']);
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

        // Rosary and Divine Mercy rows depend on the corrected liturgy list.
        $devotions = array_values(array_filter($devotions, function ($row) {
            if (! is_array($row)) {
                return true;
            }
            $title = isset($row['title']) ? (string) $row['title'] : '';
            return strcasecmp($title, 'Rosary') !== 0
                && stripos($title, 'Divine Mercy') === false;
        }));

        foreach ($this->rosary_rows($parsed['masses']) as $row) {
            $devotions[] = $row;
        }
        foreach ($parsed['special_devotions'] as $row) {
            $devotions[] = $row;
        }

        // Calendar rows are stronger than generic recurring wording for the
        // specific bulletin week (e.g. 5:30-8:30 instead of 5:30-8:00).
        foreach ($this->calendar_adoration_rows($lines, (string) $review['bulletin_date']) as $row) {
            $devotions = array_values(array_filter($devotions, function ($existing) use ($row) {
                return ! is_array($existing)
                    || strcasecmp(isset($existing['title']) ? (string) $existing['title'] : '', 'Adoration') !== 0
                    || (isset($existing['date']) ? (string) $existing['date'] : '') !== $row['date'];
            }));
            $devotions[] = $row;
        }

        $review['weekly']['devotions'] = $this->dedupe_rows($devotions);

        if (! isset($review['candidates']) || ! is_array($review['candidates'])) {
            $review['candidates'] = array();
        }
        $this->repair_weekend_candidates($review, $parsed['masses']);

        if (! isset($review['source_lines']) || ! is_array($review['source_lines'])) {
            $review['source_lines'] = array();
        }
        $review['source_lines'][] = '[v24] Rebuilt dedicated Mass schedule with historical-layout bounds.';
        $review['source_lines'] = array_values(array_unique($review['source_lines']));
        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function raw_source_lines(array $review)
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

    private function parse_mass_schedule(array $lines, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) {
            return array('masses' => array(), 'special_devotions' => array());
        }
        $last_allowed = $bulletin->modify('+8 days');
        $started = false;
        $seen_date = false;
        $current_date = '';
        $pending = null;
        $masses = array();
        $special = array();

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || preg_match('/^\[\[PAGE\s+\d+\]\]$/i', $line)) {
                continue;
            }
            if (! $started) {
                if (preg_match('/This\s+week[^A-Za-z0-9]?s\s+Mass\s+schedule/i', $line)) {
                    $started = true;
                }
                continue;
            }

            if (preg_match('/^(Reconciliation\b|The\s+Rosary\b|Adoration\s+is\b)/i', $line)) {
                $this->finish_pending($pending, $masses, $special);
                break;
            }

            $date = $this->date_from_line($line, $bulletin);
            if ($date !== '') {
                $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if ($seen_date && $dt && ($dt <= $bulletin || $dt > $last_allowed)) {
                    $this->finish_pending($pending, $masses, $special);
                    break;
                }
                if (! $dt || $dt <= $bulletin || $dt > $last_allowed) {
                    continue;
                }

                $this->finish_pending($pending, $masses, $special);
                $current_date = $date;
                $seen_date = true;

                if (stripos($line, 'NO MASS') !== false) {
                    $masses[] = $this->no_mass_row($current_date, $line);
                    continue;
                }
                $time = $this->time_from_text($line);
                if ($time !== '') {
                    $pending = array('date' => $current_date, 'time' => $time, 'details' => array());
                }
                continue;
            }

            if (! $seen_date || $current_date === '') {
                continue;
            }
            if (preg_match('/^(st|nd|rd|th)\.?$/i', $line) || preg_match('/^[,.]$/', $line)) {
                continue;
            }
            if (stripos($line, 'NO MASS') !== false) {
                $this->finish_pending($pending, $masses, $special);
                $masses[] = $this->no_mass_row($current_date, $line);
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

        return array(
            'masses' => $this->dedupe_rows($masses),
            'special_devotions' => $this->dedupe_rows($special),
        );
    }

    private function finish_pending(&$pending, array &$masses, array &$special)
    {
        if (! is_array($pending)) {
            $pending = null;
            return;
        }
        $details = trim((string) preg_replace('/\s+/u', ' ', implode(' ', $pending['details'])));
        if ($details !== '') {
            $row = $this->row_from_details($pending['date'], $pending['time'], $details);
            if (is_array($row)) {
                if (! empty($row['_special'])) {
                    unset($row['_special']);
                    $special[] = $row;
                } else {
                    $masses[] = $row;
                }
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
                '_special' => true,
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

        // A schedule record should identify a church or another explicit Mass
        // location. Without that, it is probably an interleaved calendar item.
        if ($location === '') {
            return null;
        }

        $clean = $details;
        $clean = preg_replace('/^St\.?\s*Peter[’\'s]*\s*,?\s*/iu', '', $clean);
        $clean = preg_replace('/^St\.?\s*Mary[’\'s]*\s*,?\s*/iu', '', $clean);
        $clean = preg_replace('/^Heritage\s+(?:Senior\s+)?Living\s+Center\s*,?\s*/iu', '', $clean);
        $clean = preg_replace('/^Crystal\s+Brook\s+Senior\s+Living\s+Center\s*,?\s*/iu', '', $clean);
        $clean = trim((string) $clean, " \t\n\r\0\x0B,");

        if (preg_match('/\bService\b/i', $clean)) {
            $clean = trim((string) preg_replace('/\s*\bService\b\s*/i', ' ', $clean));
            return array(
                'date' => $date,
                'time' => $time,
                'location' => $location,
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

    private function no_mass_row($date, $line)
    {
        $location = $this->location($line);
        if ($location === '') {
            $location = stripos($line, 'Mary') !== false ? 'St. Mary’s' : 'St. Peter';
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $label = $dt ? $dt->format('l, F j') . $this->ordinal_suffix((int) $dt->format('j')) : $date;
        $name = $location === 'St. Mary’s' ? 'St. Mary’s' : 'St. Peter’s';
        return array(
            'date' => $date,
            'time' => '',
            'location' => $location,
            'title' => 'No Mass',
            'description' => $label . ' NO MASS at ' . $name,
        );
    }

    private function ordinal_suffix($day)
    {
        if ($day % 100 >= 11 && $day % 100 <= 13) {
            return 'th';
        }
        switch ($day % 10) {
            case 1: return 'st';
            case 2: return 'nd';
            case 3: return 'rd';
            default: return 'th';
        }
    }

    private function date_from_line($line, DateTimeImmutable $bulletin)
    {
        if (! preg_match('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s*,?\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})/iu', $line, $m)) {
            return '';
        }
        $year = (int) $bulletin->format('Y');
        $candidate = DateTimeImmutable::createFromFormat('!F j Y', $m[2] . ' ' . $m[3] . ' ' . $year);
        if (! $candidate) {
            return '';
        }
        if ($candidate < $bulletin->modify('-30 days')) {
            $candidate = $candidate->modify('+1 year');
        }
        return $candidate->format('Y-m-d');
    }

    private function time_from_text($text)
    {
        if (! preg_match('/\b(1[0-2]|0?\d):([0-5]\d)\s*([ap])\.?\s*m\.?\b/i', $text, $m)) {
            return '';
        }
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        $ampm = strtoupper($m[3]) === 'A' ? 'AM' : 'PM';
        return sprintf('%d:%02d %s', $hour, $minute, $ampm);
    }

    private function time_range_from_text($text)
    {
        if (! preg_match('/\b(1[0-2]|0?\d):([0-5]\d)\s*([ap])\.?\s*m\.?\s*[–—-]\s*(1[0-2]|0?\d):([0-5]\d)\s*([ap])\.?\s*m\.?/iu', $text, $m)) {
            return '';
        }
        return sprintf('%d:%02d %s–%d:%02d %s',
            (int) $m[1], (int) $m[2], strtoupper($m[3]) === 'A' ? 'AM' : 'PM',
            (int) $m[4], (int) $m[5], strtoupper($m[6]) === 'A' ? 'AM' : 'PM'
        );
    }

    private function location($text)
    {
        if (preg_match('/\bSt\.?\s*Mary[’\'s]*/iu', $text)) {
            return 'St. Mary’s';
        }
        if (preg_match('/\bSt\.?\s*Peter[’\'s]*/iu', $text)) {
            return 'St. Peter';
        }
        if (preg_match('/\bHeritage\s+(?:Senior\s+)?Living\s+Center\b/iu', $text)) {
            return 'Heritage Senior Living Center';
        }
        if (preg_match('/\bCrystal\s+Brook\s+Senior\s+Living\s+Center\b/iu', $text)) {
            return 'Crystal Brook Senior Living Center';
        }
        return '';
    }

    private function intention($text)
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
        $text = preg_replace('/^\+\s*/u', '† ', $text);
        $text = preg_replace('/(^|\s)&\s*\+\s*/u', '$1& † ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string) $text, " \t\n\r\0\x0B,");
    }

    private function calendar_adoration_rows(array $lines, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) {
            return array();
        }
        $last = $bulletin->modify('+8 days');
        $current = '';
        $rows = array();
        foreach ($lines as $line) {
            $line = trim((string) $line);
            $date = $this->date_from_line($line, $bulletin);
            if ($date !== '') {
                $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                $current = ($dt && $dt >= $bulletin && $dt <= $last) ? $date : '';
                continue;
            }
            if ($current === '' || stripos($line, 'Adoration') === false || stripos($line, 'SP:') === false) {
                continue;
            }
            $range = $this->time_range_from_text($line);
            if ($range === '') {
                continue;
            }
            $rows[] = array(
                'date' => $current,
                'time' => $range,
                'location' => 'St. Peter',
                'title' => 'Adoration',
                'description' => trim((string) preg_replace('/^SP:\s*/i', '', $line)),
            );
        }
        return $this->dedupe_rows($rows);
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
            $rosary_time = (int) $dt->format('N') === 5
                ? 'After ' . $time . ' Mass'
                : $dt->modify('-30 minutes')->format('g:i A');
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
                if (! is_array($row) || (isset($row['title']) ? (string) $row['title'] : '') !== 'Mass') {
                    continue;
                }
                if ((isset($row['location']) ? (string) $row['location'] : '') !== $target[1]) {
                    continue;
                }
                $dt = DateTimeImmutable::createFromFormat('!Y-m-d', isset($row['date']) ? (string) $row['date'] : '');
                if (! $dt || (int) $dt->format('N') !== $target[0]) {
                    continue;
                }
                if (! empty($row['time'])) {
                    $times[(string) $row['time']] = true;
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

    private function dedupe_rows(array $rows)
    {
        $out = array();
        $seen = array();
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = strtolower(implode('|', array(
                isset($row['date']) ? (string) $row['date'] : '',
                isset($row['time']) ? (string) $row['time'] : '',
                isset($row['location']) ? (string) $row['location'] : '',
                isset($row['title']) ? (string) $row['title'] : '',
                isset($row['description']) ? (string) $row['description'] : '',
            )));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }
        return $out;
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
