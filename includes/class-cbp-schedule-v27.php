<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Semantic cleanup for historical bulletin edge cases.
 *
 * Earlier passes deliberately preserve ambiguous source data. The regression
 * suite exposed a small set of cases where the review already contains enough
 * information to make a deterministic correction: Divine Mercy rows presented
 * beside Sunday Masses, memorial services, an off-site Heritage Mass, a blank
 * intention paired with an otherwise-unmatched source intention, and calendar
 * bundles that leaked into the Mass list.
 *
 * This pass does not invent Mass times. It only reclassifies or enriches rows
 * using the dedicated "This week's Mass schedule" source window plus the rows
 * already produced by the parser.
 */
final class CBP_Schedule_V27
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
        add_action('shutdown', array($this, 'postprocess_review'), 280);
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
        if (empty($review['weekly']['masses']) || ! is_array($review['weekly']['masses'])) {
            return;
        }

        $raw = $this->raw_lines($review);
        $window = $this->mass_schedule_window($raw, (string) $review['bulletin_date']);

        $masses = array();
        $special_devotions = array();
        foreach ($review['weekly']['masses'] as $mass) {
            if (! is_array($mass)) {
                continue;
            }
            $title = isset($mass['title']) ? (string) $mass['title'] : '';
            $description = isset($mass['description']) ? trim((string) $mass['description']) : '';

            if (strcasecmp($title, 'No Mass') === 0) {
                $mass['description'] = preg_replace('/\s+\.\s+(NO\s+MASS\b)/iu', ' $1', $description);
                $masses[] = $mass;
                continue;
            }

            if (strcasecmp($title, 'Mass') !== 0) {
                $masses[] = $mass;
                continue;
            }

            if (stripos($description, 'Divine Mercy') !== false) {
                $special_devotions[] = array(
                    'date' => isset($mass['date']) ? (string) $mass['date'] : '',
                    'time' => isset($mass['time']) ? (string) $mass['time'] : '',
                    'location' => 'St. Peter',
                    'title' => 'Divine Mercy Hour',
                    'description' => 'Divine Mercy Hour',
                );
                continue;
            }

            if ($this->looks_like_calendar_bundle($description)) {
                continue;
            }

            if (preg_match('/\bService\b/iu', $description)) {
                $description = trim((string) preg_replace('/\s*\bService\b\s*/iu', ' ', $description));
                $mass['title'] = 'Memorial Service';
                $mass['description'] = $description;
                $masses[] = $mass;
                continue;
            }

            if (empty($mass['location']) && ! empty($mass['date']) && ! empty($mass['time'])) {
                $location = $this->location_for_date_time(
                    $window,
                    (string) $review['bulletin_date'],
                    (string) $mass['date'],
                    (string) $mass['time']
                );
                if ($location !== '') {
                    $mass['location'] = $location;
                }
            }
            $masses[] = $mass;
        }

        // Sunday bulletins sometimes lose a repeated "St. Peter's" line during
        // source de-duplication. If the ordinary Sunday row is otherwise unique,
        // the paired St. Mary's row makes the missing parish unambiguous.
        foreach ($masses as $index => $mass) {
            if (! is_array($mass)
                || (isset($mass['title']) ? (string) $mass['title'] : '') !== 'Mass'
                || ! empty($mass['location'])
                || empty($mass['date'])) {
                continue;
            }
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $mass['date']);
            if (! $dt || (int) $dt->format('N') !== 7) {
                continue;
            }
            $same_date_mary = false;
            $same_date_peter = false;
            foreach ($masses as $other) {
                if (! is_array($other)
                    || (isset($other['title']) ? (string) $other['title'] : '') !== 'Mass'
                    || (isset($other['date']) ? (string) $other['date'] : '') !== (string) $mass['date']) {
                    continue;
                }
                $loc = isset($other['location']) ? (string) $other['location'] : '';
                if ($loc === 'St. Mary’s') {
                    $same_date_mary = true;
                } elseif ($loc === 'St. Peter') {
                    $same_date_peter = true;
                }
            }
            if ($same_date_mary && ! $same_date_peter) {
                $masses[$index]['location'] = 'St. Peter';
            }
        }

        $masses = $this->recover_blank_intentions(
            $masses,
            $window,
            (string) $review['bulletin_date']
        );
        $masses = $this->dedupe_rows($masses);
        $review['weekly']['masses'] = $masses;

        if (! isset($review['candidates']) || ! is_array($review['candidates'])) {
            $review['candidates'] = array();
        }
        $this->repair_weekend_candidates($review, $masses);

        $devotions = isset($review['weekly']['devotions']) && is_array($review['weekly']['devotions'])
            ? $review['weekly']['devotions']
            : array();
        $devotions = array_values(array_filter($devotions, function ($row) {
            if (! is_array($row)) {
                return true;
            }
            $title = isset($row['title']) ? (string) $row['title'] : '';
            return strcasecmp($title, 'Rosary') !== 0 && stripos($title, 'Divine Mercy') === false;
        }));
        foreach ($this->rosary_rows($masses) as $row) {
            $devotions[] = $row;
        }
        foreach ($special_devotions as $row) {
            $devotions[] = $row;
        }
        $review['weekly']['devotions'] = $this->dedupe_rows($devotions);

        if (! isset($review['source_lines']) || ! is_array($review['source_lines'])) {
            $review['source_lines'] = array();
        }
        $review['source_lines'][] = '[v27] Applied semantic Mass/devotion cleanup from dedicated schedule evidence.';
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

    private function looks_like_calendar_bundle($description)
    {
        if ($description === '') {
            return false;
        }
        if (preg_match('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s*,?\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2}\b/iu', $description)) {
            return true;
        }
        $time_count = preg_match_all('/\b(?:1[0-2]|0?\d):[0-5]\d\s*(?:a\.?m\.?|p\.?m\.?)\b/iu', $description, $dummy);
        if ($time_count >= 2 && preg_match('/\b(Bible\s+Study|Ladies\s+lunch|Parish\s+Mission|Rosary|Meeting|Faith\s+Formation|Adoration)\b/iu', $description)) {
            return true;
        }
        return false;
    }

    private function location_for_date_time(array $window, $bulletin_date, $wanted_date, $wanted_time)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) {
            return '';
        }
        $current_date = '';
        $capture = false;
        $lookahead = 0;
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
            if ($capture && $lookahead < 4) {
                $location = $this->location_from_text($line);
                if ($location !== '') {
                    return $location;
                }
                $lookahead++;
            }
        }
        return '';
    }

    private function recover_blank_intentions(array $masses, array $window, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) {
            return $masses;
        }

        $source_intentions = array();
        $current_date = '';
        foreach ($window as $line) {
            $date = $this->date_from_line($line, $bulletin);
            if ($date !== '') {
                $current_date = $date;
                continue;
            }
            if ($current_date === '') {
                continue;
            }
            $location = $this->location_from_text($line);
            if ($location !== '' && strpos($line, ',') !== false) {
                $value = $this->intention_from_source_line($line);
                if ($value !== '' && stripos($value, 'Divine Mercy') === false) {
                    if (! isset($source_intentions[$current_date])) {
                        $source_intentions[$current_date] = array();
                    }
                    $source_intentions[$current_date][] = $value;
                }
            }
        }

        foreach ($masses as $index => $mass) {
            if (! is_array($mass)
                || (isset($mass['title']) ? (string) $mass['title'] : '') !== 'Mass'
                || trim(isset($mass['description']) ? (string) $mass['description'] : '') !== '') {
                continue;
            }
            $date = isset($mass['date']) ? (string) $mass['date'] : '';
            if ($date === '' || empty($source_intentions[$date])) {
                continue;
            }
            $used = array();
            foreach ($masses as $other) {
                if (! is_array($other) || (isset($other['date']) ? (string) $other['date'] : '') !== $date) {
                    continue;
                }
                $desc = trim(isset($other['description']) ? (string) $other['description'] : '');
                if ($desc !== '') {
                    $used[$this->semantic_text($desc)] = true;
                }
            }
            $remaining = array();
            foreach ($source_intentions[$date] as $candidate) {
                if (! isset($used[$this->semantic_text($candidate)])) {
                    $remaining[$this->semantic_text($candidate)] = $candidate;
                }
            }
            if (count($remaining) === 1) {
                $masses[$index]['description'] = reset($remaining);
            }
        }
        return $masses;
    }

    private function intention_from_source_line($line)
    {
        $clean = preg_replace('/^.*?\bSt\.?\s*(?:Peter|Mary)[’\'s]*\s*,\s*/iu', '', $line);
        $clean = preg_replace('/^.*?\b(?:Heritage|Crystal\s+Brook)\s+(?:Senior\s+)?Living\s+Center\s*,\s*/iu', '', $clean);
        $clean = trim((string) $clean, " \t\n\r\0\x0B,");
        $clean = preg_replace('/^\+\s*/u', '† ', $clean);
        return trim((string) $clean);
    }

    private function semantic_text($text)
    {
        $text = trim((string) $text);
        $text = preg_replace('/^\+\s*/u', '† ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return strtolower((string) $text);
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
                if (! $dt || (int) $dt->format('N') !== $target[0] || empty($row['time'])) {
                    continue;
                }
                $times[(string) $row['time']] = true;
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
            if (! is_array($mass) || (isset($mass['title']) ? (string) $mass['title'] : '') !== 'Mass') {
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
        if (! preg_match('/\b(1[0-2]|0?\d):([0-5]\d)\s*([ap])\.?\s*m\.?\b/i', $text, $m)) {
            return '';
        }
        return sprintf('%d:%02d %s', (int) $m[1], (int) $m[2], strtoupper($m[3]) === 'A' ? 'AM' : 'PM');
    }

    private function location_from_text($text)
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

    private function dedupe_rows(array $rows)
    {
        $seen = array();
        $out = array();
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = implode('|', array(
                isset($row['date']) ? (string) $row['date'] : '',
                isset($row['time']) ? (string) $row['time'] : '',
                isset($row['location']) ? (string) $row['location'] : '',
                isset($row['title']) ? (string) $row['title'] : '',
                isset($row['description']) ? (string) $row['description'] : '',
            ));
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
