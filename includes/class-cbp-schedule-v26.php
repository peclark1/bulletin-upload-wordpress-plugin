<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Full-PDF historical recovery pass.
 *
 * The diagnostic source stream is intentionally de-duplicated, which can
 * remove repeated date headings and identical NO MASS lines. Historical
 * bulletins expose those losses. This pass reparses the private preview PDF,
 * keeps the complete line order, and only replaces the liturgy list when the
 * reconstructed schedule contains all three ordinary weekend anchors.
 */
final class CBP_Schedule_V26
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
        add_action('shutdown', array($this, 'postprocess_review'), 270);
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

        $lines = $this->preview_lines();
        if (empty($lines)) {
            return;
        }

        $bulletin_date = (string) $review['bulletin_date'];
        $parsed = $this->parse_mass_schedule($lines, $bulletin_date);
        $replace_masses = $this->has_weekend_anchors($parsed['masses']);

        if (! isset($review['weekly']) || ! is_array($review['weekly'])) {
            $review['weekly'] = array();
        }

        if ($replace_masses) {
            $review['weekly']['masses'] = $parsed['masses'];
            if (! isset($review['candidates']) || ! is_array($review['candidates'])) {
                $review['candidates'] = array();
            }
            $this->repair_weekend_candidates($review, $parsed['masses']);
        }

        $devotions = isset($review['weekly']['devotions']) && is_array($review['weekly']['devotions'])
            ? $review['weekly']['devotions']
            : array();

        if ($replace_masses) {
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
        }

        foreach ($this->calendar_adoration_rows($lines, $bulletin_date) as $row) {
            $devotions = array_values(array_filter($devotions, function ($existing) use ($row) {
                return ! is_array($existing)
                    || strcasecmp(isset($existing['title']) ? (string) $existing['title'] : '', 'Adoration') !== 0
                    || (isset($existing['date']) ? (string) $existing['date'] : '') !== $row['date'];
            }));
            $devotions[] = $row;
        }

        $reconciliation = $this->reconciliation_row($lines, $bulletin_date);
        if (is_array($reconciliation)) {
            $devotions = array_values(array_filter($devotions, function ($existing) use ($reconciliation) {
                return ! is_array($existing)
                    || strcasecmp(isset($existing['title']) ? (string) $existing['title'] : '', 'Reconciliation') !== 0
                    || (isset($existing['date']) ? (string) $existing['date'] : '') !== $reconciliation['date'];
            }));
            $devotions[] = $reconciliation;
        }
        $review['weekly']['devotions'] = $this->dedupe_rows($devotions);

        $events = isset($review['weekly']['events']) && is_array($review['weekly']['events'])
            ? $review['weekly']['events']
            : array();
        foreach ($this->historical_event_rows($lines, $bulletin_date) as $row) {
            $events[] = $row;
        }
        $review['weekly']['events'] = $this->dedupe_rows($events);

        if (! isset($review['source_lines']) || ! is_array($review['source_lines'])) {
            $review['source_lines'] = array();
        }
        $review['source_lines'][] = '[v26] Full-PDF historical recovery applied.';
        $review['source_lines'] = array_values(array_unique($review['source_lines']));
        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function preview_lines()
    {
        $preview = get_transient('cbp_preview_' . get_current_user_id());
        $path = is_array($preview) && ! empty($preview['path']) ? (string) $preview['path'] : '';
        if ($path === '' || ! is_readable($path) || ! class_exists('Smalot\\PdfParser\\Parser')) {
            return array();
        }
        try {
            $parser = new Smalot\PdfParser\Parser();
            $document = $parser->parseFile($path);
            $lines = array();
            foreach ($document->getPages() as $page) {
                $text = str_replace("\0", '', (string) $page->getText());
                $page_lines = preg_split('/\R/u', $text);
                foreach (is_array($page_lines) ? $page_lines : array($text) as $line) {
                    $line = trim((string) preg_replace('/\s+/u', ' ', (string) $line));
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                }
            }
            return $lines;
        } catch (Throwable $e) {
            return array();
        }
    }

    private function parse_mass_schedule(array $lines, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) {
            return array('masses' => array(), 'special_devotions' => array());
        }
        $first_allowed = $bulletin->modify('+1 day');
        $last_allowed = $bulletin->modify('+7 days');
        $started = false;
        $current_date = '';
        $current_label = '';
        $pending = null;
        $masses = array();
        $special = array();

        foreach ($lines as $line) {
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

            $date_info = $this->date_info($line, $bulletin);
            if (is_array($date_info)) {
                $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date_info['date']);
                if ($dt && ($dt < $first_allowed || $dt > $last_allowed)) {
                    if ($current_date !== '') {
                        $this->finish_pending($pending, $masses, $special);
                        break;
                    }
                    continue;
                }
                if (! $dt) {
                    continue;
                }
                $this->finish_pending($pending, $masses, $special);
                $current_date = $date_info['date'];
                $current_label = $date_info['label'];
                if (stripos($line, 'NO MASS') !== false) {
                    $masses[] = $this->no_mass_row($current_date, $current_label, $line);
                    continue;
                }
                $time = $this->time_from_text($line);
                if ($time !== '') {
                    $pending = array('date' => $current_date, 'time' => $time, 'details' => array());
                }
                continue;
            }

            if ($current_date === '') {
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
                if (stripos($line, 'Divine Mercy') !== false) {
                    $this->finish_pending($pending, $masses, $special);
                    break;
                }
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
        if (stripos($details, 'Divine Mercy') !== false) {
            return array(
                'date' => $date,
                'time' => $time,
                'location' => 'St. Peter',
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

        $locations = $this->locations_in_text($details);
        if (count($locations) !== 1) {
            return null;
        }
        $location = reset($locations);
        $clean = $this->strip_location_prefix($details);

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

    private function strip_location_prefix($details)
    {
        $clean = $details;
        $clean = preg_replace('/^St\.?\s*Peter[’\'s]*\s*,?\s*/iu', '', $clean);
        $clean = preg_replace('/^St\.?\s*Mary[’\'s]*\s*,?\s*/iu', '', $clean);
        $clean = preg_replace('/^Heritage\s+(?:Senior\s+)?Living\s+Center\s*,?\s*/iu', '', $clean);
        $clean = preg_replace('/^Crystal\s+Brook\s+Senior\s+Living\s+Center\s*,?\s*/iu', '', $clean);
        return trim((string) $clean, " \t\n\r\0\x0B,");
    }

    private function locations_in_text($text)
    {
        $found = array();
        if (preg_match('/\bSt\.?\s*Peter[’\'s]*/iu', $text)) {
            $found['St. Peter'] = 'St. Peter';
        }
        if (preg_match('/\bSt\.?\s*Mary[’\'s]*/iu', $text)) {
            $found['St. Mary’s'] = 'St. Mary’s';
        }
        if (preg_match('/\bHeritage\s+(?:Senior\s+)?Living\s+Center\b/iu', $text)) {
            $found['Heritage Senior Living Center'] = 'Heritage Senior Living Center';
        }
        if (preg_match('/\bCrystal\s+Brook\s+Senior\s+Living\s+Center\b/iu', $text)) {
            $found['Crystal Brook Senior Living Center'] = 'Crystal Brook Senior Living Center';
        }
        return $found;
    }

    private function no_mass_row($date, $label, $line)
    {
        $location = stripos($line, 'Mary') !== false ? 'St. Mary’s' : 'St. Peter';
        $straight = strpos($line, "Peter's") !== false || strpos($line, "Mary's") !== false;
        if ($location === 'St. Mary’s') {
            $name = $straight ? "St. Mary's" : 'St. Mary’s';
        } else {
            $name = $straight ? "St. Peter's" : 'St. Peter’s';
        }
        return array(
            'date' => $date,
            'time' => '',
            'location' => $location,
            'title' => 'No Mass',
            'description' => $label . ' NO MASS at ' . $name,
        );
    }

    private function date_info($line, DateTimeImmutable $bulletin)
    {
        if (! preg_match('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s*,?\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})/iu', $line, $m)) {
            return null;
        }
        $year = (int) $bulletin->format('Y');
        $dt = DateTimeImmutable::createFromFormat('!F j Y', $m[2] . ' ' . $m[3] . ' ' . $year);
        if (! $dt) {
            return null;
        }
        if ($dt < $bulletin->modify('-30 days')) {
            $dt = $dt->modify('+1 year');
        }
        $suffix = $this->ordinal_suffix((int) $dt->format('j'));
        return array(
            'date' => $dt->format('Y-m-d'),
            'label' => $dt->format('l, F j') . $suffix,
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

    private function time_from_text($text)
    {
        if (! preg_match('/\b(1[0-2]|0?\d):([0-5]\d)\s*([ap])\.?\s*m\.?\b/i', $text, $m)) {
            return '';
        }
        return sprintf('%d:%02d %s', (int) $m[1], (int) $m[2], strtoupper($m[3]) === 'A' ? 'AM' : 'PM');
    }

    private function time_range_from_text($text)
    {
        if (! preg_match('/\b(1[0-2]|0?\d):([0-5]\d)\s*([ap])?\.?\s*m?\.?\s*[–—-]\s*(1[0-2]|0?\d):([0-5]\d)\s*([ap])\.?\s*m\.?/iu', $text, $m)) {
            return '';
        }
        $second = strtoupper($m[6]) === 'A' ? 'AM' : 'PM';
        $first = ! empty($m[3]) ? (strtoupper($m[3]) === 'A' ? 'AM' : 'PM') : $second;
        return sprintf('%d:%02d %s–%d:%02d %s', (int) $m[1], (int) $m[2], $first, (int) $m[4], (int) $m[5], $second);
    }

    private function has_weekend_anchors(array $masses)
    {
        $found = array('sat_peter' => false, 'sun_peter' => false, 'sun_mary' => false);
        foreach ($masses as $row) {
            if (! is_array($row) || (isset($row['title']) ? $row['title'] : '') !== 'Mass') {
                continue;
            }
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', isset($row['date']) ? (string) $row['date'] : '');
            if (! $dt) {
                continue;
            }
            $dow = (int) $dt->format('N');
            $location = isset($row['location']) ? (string) $row['location'] : '';
            if ($dow === 6 && $location === 'St. Peter') $found['sat_peter'] = true;
            if ($dow === 7 && $location === 'St. Peter') $found['sun_peter'] = true;
            if ($dow === 7 && $location === 'St. Mary’s') $found['sun_mary'] = true;
        }
        return ! in_array(false, $found, true);
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
                if (! is_array($row) || (isset($row['title']) ? $row['title'] : '') !== 'Mass') continue;
                if ((isset($row['location']) ? $row['location'] : '') !== $target[1]) continue;
                $dt = DateTimeImmutable::createFromFormat('!Y-m-d', isset($row['date']) ? (string) $row['date'] : '');
                if (! $dt || (int) $dt->format('N') !== $target[0]) continue;
                if (! empty($row['time'])) $times[(string) $row['time']] = true;
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
            if (! is_array($mass) || (isset($mass['title']) ? $mass['title'] : '') !== 'Mass') continue;
            $location = isset($mass['location']) ? (string) $mass['location'] : '';
            if ($location !== 'St. Peter' && $location !== 'St. Mary’s') continue;
            $date = isset($mass['date']) ? (string) $mass['date'] : '';
            $time = isset($mass['time']) ? (string) $mass['time'] : '';
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d g:i A', $date . ' ' . $time);
            if (! $dt) continue;
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

    private function calendar_adoration_rows(array $lines, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) return array();
        $last = $bulletin->modify('+7 days');
        $current = '';
        $rows = array();
        foreach ($lines as $line) {
            $date = $this->date_info($line, $bulletin);
            if (is_array($date)) {
                $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date['date']);
                $current = ($dt && $dt >= $bulletin && $dt <= $last) ? $date['date'] : '';
                continue;
            }
            if ($current === '' || stripos($line, 'Adoration') === false || stripos($line, 'SP:') === false) continue;
            $range = $this->time_range_from_text($line);
            if ($range === '') continue;
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

    private function reconciliation_row(array $lines, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) return null;
        foreach ($lines as $line) {
            if (stripos($line, 'Reconciliation') === false || stripos($line, 'Saturday') === false) continue;
            $range = $this->time_range_from_text($line);
            $time = $range !== '' ? $range : $this->time_from_text($line);
            if ($time === '') continue;
            $days = (6 - (int) $bulletin->format('N') + 7) % 7;
            if ($days === 0) $days = 7;
            $date = $bulletin->modify('+' . $days . ' days')->format('Y-m-d');
            return array(
                'date' => $date,
                'time' => $time,
                'location' => 'St. Peter',
                'title' => 'Reconciliation',
                'description' => $line,
            );
        }
        return null;
    }

    private function historical_event_rows(array $lines, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) return array();
        $last = $bulletin->modify('+7 days');
        $current = '';
        $rows = array();
        foreach ($lines as $line) {
            $date = $this->date_info($line, $bulletin);
            if (is_array($date)) {
                $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date['date']);
                $current = ($dt && $dt >= $bulletin && $dt <= $last) ? $date['date'] : '';
                continue;
            }
            if ($current === '') continue;
            $interesting = stripos($line, 'Parish Mission') !== false
                || stripos($line, 'Heritage Living') !== false
                || stripos($line, 'Civil Air Patrol') !== false
                || stripos($line, 'First Communion') !== false
                || stripos($line, 'Funeral') !== false
                || stripos($line, 'Memorial Service') !== false;
            if (! $interesting) continue;
            $time = $this->time_from_text($line);
            $location = '';
            if (preg_match('/^SP\s*:/i', $line) || stripos($line, 'SP & SM') === 0) $location = 'St. Peter';
            if (preg_match('/^SM\s*:/i', $line)) $location = 'St. Mary’s';
            $title = trim((string) preg_replace('/^(SP|SM)\s*:\s*/i', '', $line));
            $rows[] = array(
                'date' => $current,
                'time' => $time,
                'location' => $location,
                'title' => $title,
                'description' => $title,
            );
        }
        return $this->dedupe_rows($rows);
    }

    private function intention($text)
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
        $text = preg_replace('/^\+\s*/u', '† ', $text);
        $text = preg_replace('/(^|\s)&\s*\+\s*/u', '$1& † ', $text);
        return trim((string) preg_replace('/\s+/', ' ', $text), " \t\n\r\0\x0B,");
    }

    private function dedupe_rows(array $rows)
    {
        $out = array();
        $seen = array();
        foreach ($rows as $row) {
            if (! is_array($row)) continue;
            $key = strtolower(implode('|', array(
                isset($row['date']) ? (string) $row['date'] : '',
                isset($row['time']) ? (string) $row['time'] : '',
                isset($row['location']) ? (string) $row['location'] : '',
                isset($row['title']) ? (string) $row['title'] : '',
                isset($row['description']) ? (string) $row['description'] : '',
            )));
            if (isset($seen[$key])) continue;
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
