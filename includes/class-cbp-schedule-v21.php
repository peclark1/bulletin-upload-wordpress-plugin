<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Rebuild the dated liturgy rows from the bulletin's dedicated Mass schedule.
 *
 * Historical bulletins exposed two broad failure modes in earlier passes:
 * ordinary calendar events could leak into the Mass table, while non-Mass
 * liturgies (Divine Mercy Hour / memorial services) could contaminate the
 * recurring weekend proposal.  The dedicated "This week's Mass schedule"
 * block is the strongest source for dated liturgies, so this pass reparses
 * that block directly and uses it as the authoritative set of rows.
 */
final class CBP_Schedule_V21
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
        add_action('shutdown', array($this, 'postprocess_review'), 220);
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

        $preview = get_transient($this->preview_key());
        $path = is_array($preview) && ! empty($preview['path']) ? (string) $preview['path'] : '';
        if ($path === '' || ! is_readable($path) || ! class_exists('Smalot\\PdfParser\\Parser')) {
            return;
        }

        try {
            $parser = new Smalot\PdfParser\Parser();
            $document = $parser->parseFile($path);
            $lines = array();
            foreach ($document->getPages() as $page) {
                $text = str_replace("\0", '', (string) $page->getText());
                $page_lines = preg_split('/\R/u', $text);
                if (! is_array($page_lines)) {
                    continue;
                }
                foreach ($page_lines as $line) {
                    $line = trim((string) preg_replace('/\s+/u', ' ', (string) $line));
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                }
            }
        } catch (Throwable $e) {
            return;
        }

        $parsed = $this->parse_mass_schedule($lines, $review);
        if (empty($parsed['masses']) || count($parsed['masses']) < 3) {
            return;
        }

        if (! isset($review['weekly']) || ! is_array($review['weekly'])) {
            $review['weekly'] = array();
        }
        $review['weekly']['masses'] = $parsed['masses'];

        // Keep non-Rosary devotions from stronger passes, then regenerate the
        // automatic Rosary rows from the corrected Mass list. This prevents a
        // false Divine Mercy "Mass" from also creating a false 2:30 Rosary.
        $devotions = isset($review['weekly']['devotions']) && is_array($review['weekly']['devotions'])
            ? $review['weekly']['devotions']
            : array();
        $devotions = array_values(array_filter($devotions, function ($row) {
            return ! is_array($row) || strcasecmp(isset($row['title']) ? (string) $row['title'] : '', 'Rosary') !== 0;
        }));
        foreach ($this->rosary_rows($parsed['masses']) as $row) {
            $devotions[] = $row;
        }
        foreach ($parsed['devotions'] as $row) {
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
        $review['source_lines'][] = '[v21] Rebuilt dated liturgy rows from dedicated Mass schedule block.';
        $review['source_lines'] = array_values(array_unique($review['source_lines']));

        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function parse_mass_schedule(array $lines, array $review)
    {
        $started = false;
        $seen_entry = false;
        $current_date = '';
        $current_label = '';
        $pending = null;
        $masses = array();
        $devotions = array();

        $week_start = isset($review['week_start']) ? (string) $review['week_start'] : '';
        $week_end = isset($review['week_end']) ? (string) $review['week_end'] : '';
        $bulletin_date = (string) $review['bulletin_date'];

        foreach ($lines as $line) {
            if (! $started) {
                if (preg_match('/This\s+week[’\']?s\s+Mass\s+schedule/i', $line)) {
                    $started = true;
                }
                continue;
            }

            if (preg_match('/^(Reconciliation\b|The\s+Rosary\b|Adoration\b)/i', $line)) {
                $this->finalize_pending($pending, $masses, $devotions);
                break;
            }

            $date_info = $this->date_from_line($line, $bulletin_date);
            if (is_array($date_info)) {
                // Once schedule entries have begun, an out-of-week dated line
                // is the weekly calendar beginning, not another Mass row.
                if ($seen_entry && ! $this->date_in_range($date_info['date'], $week_start, $week_end)) {
                    $this->finalize_pending($pending, $masses, $devotions);
                    break;
                }
                if ($this->date_in_range($date_info['date'], $week_start, $week_end)) {
                    $this->finalize_pending($pending, $masses, $devotions);
                    $current_date = $date_info['date'];
                    $current_label = $date_info['label'];
                    $seen_entry = true;

                    if (stripos($line, 'NO MASS') !== false) {
                        $masses[] = $this->no_mass_row($current_date, $current_label, $line);
                        continue;
                    }

                    $time = $this->extract_time($line);
                    if ($time !== '') {
                        $pending = array('date' => $current_date, 'time' => $time, 'details' => array());
                    }
                    continue;
                }
            }

            if (! $seen_entry || $current_date === '') {
                continue;
            }

            if (preg_match('/^(st|nd|rd|th)\.?$/i', $line) || preg_match('/^[,\.]$/', $line)) {
                continue;
            }

            if (stripos($line, 'NO MASS') !== false) {
                $this->finalize_pending($pending, $masses, $devotions);
                $masses[] = $this->no_mass_row($current_date, $current_label, $line);
                continue;
            }

            $time = $this->extract_time($line);
            if ($time !== '') {
                $this->finalize_pending($pending, $masses, $devotions);
                $pending = array('date' => $current_date, 'time' => $time, 'details' => array());
                continue;
            }

            if (is_array($pending)) {
                $pending['details'][] = $line;
            }
        }

        $this->finalize_pending($pending, $masses, $devotions);

        return array(
            'masses' => $this->dedupe_rows($masses),
            'devotions' => $this->dedupe_rows($devotions),
        );
    }

    private function finalize_pending(&$pending, array &$masses, array &$devotions)
    {
        if (! is_array($pending)) {
            $pending = null;
            return;
        }

        $details = trim((string) preg_replace('/\s+/u', ' ', implode(' ', $pending['details'])));
        $row = $this->liturgy_row($pending['date'], $pending['time'], $details);
        if (is_array($row)) {
            if (isset($row['_devotion']) && $row['_devotion']) {
                unset($row['_devotion']);
                $devotions[] = $row;
            } else {
                $masses[] = $row;
            }
        }
        $pending = null;
    }

    private function liturgy_row($date, $time, $details)
    {
        if ($details === '') {
            return null;
        }

        $location = $this->location_from_text($details);
        $clean = $details;

        if (stripos($details, 'Divine Mercy') !== false) {
            return array(
                'date' => $date,
                'time' => $time,
                'location' => $location !== '' ? $location : 'St. Peter',
                'title' => 'Divine Mercy Hour',
                'description' => $details,
                '_devotion' => true,
            );
        }

        if (preg_match('/^(.+?)\s+Funeral\s+at\s+St\.?\s*(Peter|Mary)[’\'s]*/iu', $details, $m)) {
            return array(
                'date' => $date,
                'time' => $time,
                'location' => strcasecmp($m[2], 'Mary') === 0 ? 'St. Mary’s' : 'St. Peter',
                'title' => 'Funeral Mass',
                'description' => $this->normalize_intention($m[1]),
            );
        }

        // Remove the location prefix before interpreting the intention.
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
                'description' => $this->normalize_intention($clean),
            );
        }

        return array(
            'date' => $date,
            'time' => $time,
            'location' => $location,
            'title' => 'Mass',
            'description' => $this->normalize_intention($clean),
        );
    }

    private function no_mass_row($date, $label, $line)
    {
        $location = $this->location_from_text($line);
        if ($location === '') {
            $location = stripos($line, 'Mary') !== false ? 'St. Mary’s' : 'St. Peter';
        }
        $possessive = $location === 'St. Mary’s' ? 'St. Mary’s' : 'St. Peter’s';
        return array(
            'date' => $date,
            'time' => '',
            'location' => $location,
            'title' => 'No Mass',
            'description' => $label . ' NO MASS at ' . $possessive,
        );
    }

    private function date_from_line($line, $bulletin_date)
    {
        $months = 'January|February|March|April|May|June|July|August|September|October|November|December';
        if (! preg_match('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s*(' . $months . ')\s+(\d{1,2})/i', $line, $m)) {
            return null;
        }

        $base = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $base) {
            return null;
        }
        $year = (int) $base->format('Y');
        $candidate = DateTimeImmutable::createFromFormat('!F j Y', $m[2] . ' ' . ((int) $m[3]) . ' ' . $year);
        if (! $candidate) {
            return null;
        }
        // Bulletins near New Year can describe the following January.
        if ($candidate < $base->modify('-14 days')) {
            $candidate = $candidate->modify('+1 year');
        }

        $day = (int) $candidate->format('j');
        $label = $candidate->format('l, F j') . $this->ordinal_suffix($day);
        return array('date' => $candidate->format('Y-m-d'), 'label' => $label);
    }

    private function ordinal_suffix($day)
    {
        $mod100 = $day % 100;
        if ($mod100 >= 11 && $mod100 <= 13) {
            return 'th';
        }
        switch ($day % 10) {
            case 1: return 'st';
            case 2: return 'nd';
            case 3: return 'rd';
            default: return 'th';
        }
    }

    private function extract_time($text)
    {
        if (! preg_match('/\b(\d{1,2})(?::([0-5][0-9]))\s*(a\.?\s*m\.?|p\.?\s*m\.?)/iu', $text, $m)) {
            return '';
        }
        $hour = (int) $m[1];
        $minute = $m[2];
        $ampm = strtolower($m[3]);
        $ampm = strpos($ampm, 'p') === 0 ? 'PM' : 'AM';
        return $hour . ':' . $minute . ' ' . $ampm;
    }

    private function normalize_intention($text)
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
        $text = preg_replace('/(^|\s)\+\s*/u', '$1† ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return $text;
    }

    private function location_from_text($text)
    {
        if (preg_match('/Heritage\s+Senior\s+Living\s+Center/i', $text)) {
            return 'Heritage Senior Living Center';
        }
        if (preg_match('/Heritage\s+Living\s+Center/i', $text)) {
            return 'Heritage Living Center';
        }
        if (preg_match('/Crystal\s+Brook\s+Senior\s+Living\s+Center/i', $text)) {
            return 'Crystal Brook Senior Living Center';
        }
        if (preg_match('/St\.?\s*Mary/i', $text)) {
            return 'St. Mary’s';
        }
        if (preg_match('/St\.?\s*Peter/i', $text)) {
            return 'St. Peter';
        }
        return '';
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
                $date = isset($row['date']) ? DateTimeImmutable::createFromFormat('!Y-m-d', $row['date']) : false;
                if (! $date || (int) $date->format('N') !== $target[0]) {
                    continue;
                }
                $time = isset($row['time']) ? trim((string) $row['time']) : '';
                if ($time !== '') {
                    $times[$time] = true;
                }
            }
            $times = array_keys($times);
            if (count($times) === 1) {
                $review['candidates'][$key] = $times[0];
                continue;
            }
            $current_value = isset($current[$key]) ? (string) $current[$key] : '';
            if ($current_value !== '' && in_array($current_value, $times, true)) {
                $review['candidates'][$key] = $current_value;
            } elseif (empty($times)) {
                $review['candidates'][$key] = '';
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
            $date = isset($mass['date']) ? (string) $mass['date'] : '';
            $time = isset($mass['time']) ? (string) $mass['time'] : '';
            $location = isset($mass['location']) ? (string) $mass['location'] : '';
            if ($date === '' || $time === '' || $location === '') {
                continue;
            }
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d g:i A', $date . ' ' . $time);
            if (! $dt) {
                continue;
            }
            $is_friday = (int) $dt->format('N') === 5;
            $rosary_time = $is_friday ? 'After ' . $time . ' Mass' : $dt->modify('-30 minutes')->format('g:i A');
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

    private function dedupe_rows(array $rows)
    {
        $unique = array();
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = implode('|', array(
                isset($row['date']) ? $row['date'] : '',
                isset($row['time']) ? $row['time'] : '',
                isset($row['location']) ? $row['location'] : '',
                isset($row['title']) ? $row['title'] : '',
                isset($row['description']) ? $row['description'] : '',
            ));
            $unique[$key] = $row;
        }
        $rows = array_values($unique);
        usort($rows, function ($a, $b) {
            $ak = (isset($a['date']) ? $a['date'] : '') . ' ' . (isset($a['time']) ? $a['time'] : '') . ' ' . (isset($a['title']) ? $a['title'] : '');
            $bk = (isset($b['date']) ? $b['date'] : '') . ' ' . (isset($b['time']) ? $b['time'] : '') . ' ' . (isset($b['title']) ? $b['title'] : '');
            return strcmp($ak, $bk);
        });
        return $rows;
    }

    private function date_in_range($date, $start, $end)
    {
        if ($date === '' || $start === '' || $end === '') {
            return true;
        }
        return $date >= $start && $date <= $end;
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }

    private function preview_key()
    {
        return 'cbp_preview_' . get_current_user_id();
    }
}
