<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * More defensive bulletin extractor for the parish's two-column weekly layout.
 *
 * This class replaces only the extraction handler. The existing CBP_Schedule
 * class continues to render the review UI, approve edits, store options, and
 * provide shortcodes. Keeping extraction isolated makes it easy to tune against
 * real bulletins without disturbing the approval/publishing safeguards.
 */
final class CBP_Schedule_V2
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
        // CBP_Schedule registers first. Replace just its extraction callback.
        remove_action('admin_post_cbp_extract_schedule', array(CBP_Schedule::instance(), 'extract_schedule'));
        add_action('admin_post_cbp_extract_schedule', array($this, 'extract_schedule'));
    }

    public function extract_schedule()
    {
        if (! current_user_can('manage_options')) {
            wp_die(
                esc_html__('You are not allowed to update the parish schedule.', 'church-bulletin-publisher'),
                esc_html__('Access denied', 'church-bulletin-publisher'),
                array('response' => 403)
            );
        }
        check_admin_referer('cbp_extract_schedule');

        $preview = get_transient($this->preview_key());
        if (! is_array($preview) || empty($preview['path']) || ! is_readable($preview['path'])) {
            $this->redirect('error', __('The private preview is missing or expired. Create it again.', 'church-bulletin-publisher'));
        }

        $bulletin_date = isset($preview['date']) ? sanitize_text_field($preview['date']) : '';
        if (! $this->valid_date($bulletin_date)) {
            $this->redirect('error', __('The bulletin date is missing or invalid. Create the preview again.', 'church-bulletin-publisher'));
        }

        $text = $this->pdf_text($preview['path']);
        if (is_wp_error($text)) {
            set_transient($this->review_key(), array('error' => $text->get_error_message()), self::REVIEW_TTL);
            $this->redirect('error', $text->get_error_message());
        }

        $parsed = $this->parse($text, $bulletin_date);
        set_transient($this->review_key(), $parsed, self::REVIEW_TTL);
        $this->redirect('success', __('Website information extracted. Review every proposed item before approving it.', 'church-bulletin-publisher'));
    }

    private function parse($text, $bulletin_date)
    {
        $lines = $this->normalized_lines($text);
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        $week_start = $bulletin->modify('+1 day')->format('Y-m-d');
        $week_end = $bulletin->modify('+7 days')->format('Y-m-d');
        $effective_date = $this->next_weekday_date($bulletin_date, 6);

        $recurring = $this->parse_recurring_schedule($lines);
        $candidates = array();
        foreach ($this->fields() as $key) {
            // Do not silently make a current website value look extracted.
            // A blank value is safe: CBP_Schedule keeps the current value if
            // staff approve a blank field.
            $candidates[$key] = isset($recurring[$key]) ? $recurring[$key] : '';
        }

        $weekly = $this->parse_weekly_calendar($lines, $bulletin_date, $week_start, $week_end);
        $sources = array_merge(
            isset($recurring['_sources']) ? $recurring['_sources'] : array(),
            isset($weekly['_sources']) ? $weekly['_sources'] : array(),
            $this->diagnostic_lines($lines)
        );
        unset($weekly['_sources']);

        return array(
            'bulletin_date' => $bulletin_date,
            'week_start' => $week_start,
            'week_end' => $week_end,
            'effective_date' => $effective_date,
            'candidates' => $candidates,
            'weekly' => $weekly,
            'source_lines' => array_values(array_unique(array_slice($sources, 0, 120))),
        );
    }

    private function parse_recurring_schedule(array $lines)
    {
        $result = array('_sources' => array());
        $start = $this->find_schedule_heading($lines);
        $adoration = array();

        if ($start !== null) {
            $location = '';
            $end = min(count($lines), $start + 46);
            for ($i = $start; $i < $end; $i++) {
                $line = $lines[$i];
                $lower = strtolower($line);

                if ($i > $start + 5 && preg_match('/^(?:alpha|faith formation|adult faith|stewardship|parish financial|knights of columbus)\b/i', $line)) {
                    break;
                }

                if (strpos($lower, 'st. mary') !== false || strpos($lower, 'st mary') !== false || strpos($lower, 'two inlets') !== false) {
                    $location = 'mary';
                }
                if (strpos($lower, 'st. peter') !== false || strpos($lower, 'st peter') !== false || strpos($lower, 'park rapids') !== false) {
                    $location = 'peter';
                }

                $pairs = $this->day_time_pairs($line);
                foreach ($pairs as $pair) {
                    if ($location === 'peter' && $pair['day'] === 'sat' && empty($result['st_peter_saturday'])) {
                        $result['st_peter_saturday'] = $pair['time'];
                        $result['_sources'][] = $line;
                    }
                    if ($location === 'peter' && $pair['day'] === 'sun' && empty($result['st_peter_sunday'])) {
                        $result['st_peter_sunday'] = $pair['time'];
                        $result['_sources'][] = $line;
                    }
                    if ($location === 'mary' && $pair['day'] === 'sun' && empty($result['st_mary_sunday'])) {
                        $result['st_mary_sunday'] = $pair['time'];
                        $result['_sources'][] = $line;
                    }
                }

                if (stripos($line, 'adoration') !== false) {
                    $time = $this->time_expression($line);
                    if ($time !== '') {
                        $day = $this->weekday_word($line);
                        $value = trim(($day !== '' ? $day . ' ' : '') . $time);
                        if (! in_array($value, $adoration, true)) {
                            $adoration[] = $value;
                            $result['_sources'][] = $line;
                        }
                    }
                }
            }
        }

        if (! empty($adoration)) {
            $result['adoration'] = implode('; ', $adoration);
        }

        // Reconciliation is usually immediately below the weekly Mass box rather
        // than in the standing schedule box.
        foreach ($lines as $line) {
            if (! preg_match('/\b(?:reconciliation|confession(?:s)?)\b/i', $line)) {
                continue;
            }
            if ($this->is_probable_contact_or_office_line($line)) {
                continue;
            }
            $time = $this->time_expression($line);
            if ($time === '') {
                continue;
            }
            $day = $this->weekday_word($line);
            $result['reconciliation'] = trim(($day !== '' ? $day . ' ' : '') . $time);
            $result['_sources'][] = $line;
            break;
        }

        // Conservative fallback for weekend Mass values, but retain the
        // day-to-time association instead of taking the first time on a line.
        if (empty($result['st_peter_saturday']) || empty($result['st_peter_sunday']) || empty($result['st_mary_sunday'])) {
            $location = '';
            foreach ($lines as $line) {
                $lower = strtolower($line);
                if ($this->looks_like_event_line($line)) {
                    continue;
                }
                if (strpos($lower, 'st. mary') !== false || strpos($lower, 'st mary') !== false || strpos($lower, 'two inlets') !== false) {
                    $location = 'mary';
                }
                if (strpos($lower, 'st. peter') !== false || strpos($lower, 'st peter') !== false || strpos($lower, 'park rapids') !== false) {
                    $location = 'peter';
                }
                foreach ($this->day_time_pairs($line) as $pair) {
                    if ($location === 'peter' && $pair['day'] === 'sat' && empty($result['st_peter_saturday'])) {
                        $result['st_peter_saturday'] = $pair['time'];
                        $result['_sources'][] = $line;
                    }
                    if ($location === 'peter' && $pair['day'] === 'sun' && empty($result['st_peter_sunday'])) {
                        $result['st_peter_sunday'] = $pair['time'];
                        $result['_sources'][] = $line;
                    }
                    if ($location === 'mary' && $pair['day'] === 'sun' && empty($result['st_mary_sunday'])) {
                        $result['st_mary_sunday'] = $pair['time'];
                        $result['_sources'][] = $line;
                    }
                }
            }
        }

        return $result;
    }

    private function parse_weekly_calendar(array $lines, $bulletin_date, $week_start, $week_end)
    {
        $masses = array();
        $devotions = array();
        $events = array();
        $livestream = array();
        $sources = array();

        $heading = $this->find_weekly_mass_heading($lines);
        if ($heading === null) {
            return array(
                'masses' => array(),
                'devotions' => array(),
                'events' => array(),
                'livestream' => array(),
                '_sources' => array(),
            );
        }

        $mass_end = min(count($lines), $heading + 45);
        for ($i = $heading + 1; $i < $mass_end; $i++) {
            if (preg_match('/\b(?:reconciliation|the rosary|adoration is held)\b/i', $lines[$i])) {
                $mass_end = $i;
                break;
            }
        }

        // Mass entries are commonly split over two or three extracted PDF lines:
        // date/time, then parish/location, then intention. Parse them as chunks.
        $i = $heading + 1;
        while ($i < $mass_end) {
            $date = $this->explicit_date_from_line($lines[$i], $bulletin_date);
            if ($date === '') {
                $i++;
                continue;
            }

            $chunk = array($lines[$i]);
            $j = $i + 1;
            while ($j < $mass_end && count($chunk) < 4) {
                if ($this->explicit_date_from_line($lines[$j], $bulletin_date) !== '') {
                    break;
                }
                $chunk[] = $lines[$j];
                $j++;
            }
            $combined = implode(' ', $chunk);

            if ($date >= $week_start && $date <= $week_end) {
                $no_mass = stripos($combined, 'no mass') !== false;
                $time = $this->time_expression($combined);
                if ($no_mass || $time !== '') {
                    $location = $this->location_from_line($combined);
                    $title = $no_mass ? 'No Mass' : (stripos($combined, 'funeral') !== false ? 'Funeral Mass' : 'Mass');
                    $masses[] = array(
                        'date' => $date,
                        'time' => $no_mass ? '' : $time,
                        'location' => $location,
                        'title' => $title,
                        'description' => sanitize_text_field($combined),
                    );
                    foreach ($chunk as $used) {
                        $sources[] = $used;
                    }
                }
            }
            $i = max($j, $i + 1);
        }

        // The devotional lines immediately follow the weekly Mass box.
        $calendar_start = $mass_end;
        $calendar_end = min(count($lines), $heading + 90);
        for ($i = $calendar_start; $i < $calendar_end; $i++) {
            if (preg_match('/\bsunday\s+mass\s*(?:&|and)\s+rosary\s+are\b/i', $lines[$i])) {
                $calendar_end = min(count($lines), $i + 3);
                break;
            }
            if (preg_match('/\bif you or someone you know has been the victim\b/i', $lines[$i]) || preg_match('/^st\.?\s*peter[’\'s]*\s+liturgical\s+schedule/i', $lines[$i])) {
                $calendar_end = $i;
                break;
            }
        }

        for ($i = $calendar_start; $i < min($calendar_end, $calendar_start + 12); $i++) {
            $line = $lines[$i];
            if (preg_match('/\breconciliation|confession/i', $line)) {
                $devotions[] = $this->devotion_item($line, 'Reconciliation', $bulletin_date, $week_start, $week_end);
                $sources[] = $line;
            } elseif (preg_match('/\brosary\b/i', $line) && stripos($line, 'live stream') === false && stripos($line, 'livestream') === false) {
                $devotions[] = $this->devotion_item($line, 'Rosary', $bulletin_date, $week_start, $week_end);
                $sources[] = $line;
            } elseif (preg_match('/\badoration\b/i', $line)) {
                $devotions[] = $this->devotion_item($line, 'Adoration', $bulletin_date, $week_start, $week_end);
                $sources[] = $line;
            }
        }
        $devotions = array_values(array_filter($devotions));

        // Parse the day-by-day left-column parish calendar. A date heading starts
        // a group; all SP:/SM:/Noon items beneath it belong to that day until the
        // next date heading.
        $current_date = '';
        for ($i = $calendar_start; $i < $calendar_end; $i++) {
            $line = $lines[$i];

            if (preg_match('/\bsunday\s+mass\s*(?:&|and)\s+rosary\s+are\b/i', $line)) {
                $combined = $line;
                if (isset($lines[$i + 1])) {
                    $combined .= ' ' . $lines[$i + 1];
                }
                $livestream[] = array('description' => sanitize_text_field($combined));
                $sources[] = $combined;
                break;
            }

            $date = $this->explicit_date_from_line($line, $bulletin_date);
            if ($date !== '') {
                $current_date = $date;
                // A pure date heading is not itself an event.
                if ($this->time_expression($line) === '' && ! preg_match('/\b(?:SP|SM)\s*:/i', $line)) {
                    continue;
                }
            }

            if ($current_date === '' || $current_date < $week_start || $current_date > $week_end) {
                continue;
            }
            if (preg_match('/\b(?:reconciliation|the rosary|adoration is held)\b/i', $line)) {
                continue;
            }
            if ($this->is_probable_contact_or_office_line($line)) {
                continue;
            }

            $is_prefixed = preg_match('/^(?:SP|SM)\s*:/i', $line) === 1;
            $time = $this->time_expression($line);
            if (! $is_prefixed && $time === '' && stripos($line, 'Noon:') !== 0) {
                // Continuation text belongs to the preceding event, if any.
                if (! empty($events) && preg_match('/^\s*[\(\[]/', $line)) {
                    $last = count($events) - 1;
                    $events[$last]['description'] .= ' ' . sanitize_text_field($line);
                }
                continue;
            }

            $item = $this->calendar_item_from_line($line, $current_date);
            if ($item['title'] === '' && $item['description'] === '') {
                continue;
            }
            if ($this->duplicates_mass($item, $masses)) {
                continue;
            }
            $events[] = $item;
            $sources[] = $line;
        }

        $masses = $this->dedupe_rows($masses);
        $devotions = $this->dedupe_rows($devotions);
        $events = $this->dedupe_rows($events);
        usort($masses, array($this, 'calendar_sort'));
        usort($devotions, array($this, 'calendar_sort'));
        usort($events, array($this, 'calendar_sort'));

        return array(
            'masses' => $masses,
            'devotions' => $devotions,
            'events' => $events,
            'livestream' => array_values(array_unique($livestream, SORT_REGULAR)),
            '_sources' => $sources,
        );
    }

    private function devotion_item($line, $title, $bulletin_date, $week_start, $week_end)
    {
        $date = $this->explicit_date_from_line($line, $bulletin_date);
        if ($date === '') {
            $weekday = $this->weekday_number_from_line($line);
            if ($weekday !== 0) {
                $date = $this->next_weekday_date($bulletin_date, $weekday);
            }
        }
        if ($date !== '' && ($date < $week_start || $date > $week_end)) {
            $date = '';
        }
        return array(
            'date' => $date,
            'time' => $this->time_expression($line),
            'location' => $this->location_from_line($line),
            'title' => $title,
            'description' => sanitize_text_field($line),
        );
    }

    private function calendar_item_from_line($line, $date)
    {
        $original = sanitize_text_field($line);
        $time = $this->time_expression($original);
        $location = $this->location_from_line($original);
        $title = $original;

        $title = preg_replace('/^(?:Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?)[\.,]?\s*/i', '', $title);
        $title = preg_replace('/^(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)[\.,]?\s+\d{1,2}(?:st|nd|rd|th)?[\.,]?\s*/i', '', $title);
        $title = preg_replace('/^(?:SP|SM)\s*:\s*/i', '', $title);
        if (stripos($title, 'Noon:') === 0) {
            $title = preg_replace('/^Noon\s*:\s*/i', '', $title);
        }
        if ($time !== '') {
            $title = $this->remove_time_expression($title);
        }
        $title = trim($title, " \t\n\r\0\x0B-–—:;,.");

        return array(
            'date' => $date,
            'time' => $time,
            'location' => $location,
            'title' => sanitize_text_field($title),
            'description' => $original,
        );
    }

    private function duplicates_mass(array $item, array $masses)
    {
        foreach ($masses as $mass) {
            if ($item['date'] !== $mass['date']) {
                continue;
            }
            if ($item['time'] !== '' && $mass['time'] !== '' && $item['time'] === $mass['time']) {
                return true;
            }
        }
        return false;
    }

    private function find_schedule_heading(array $lines)
    {
        foreach ($lines as $index => $line) {
            if (stripos($line, 'mass') !== false && stripos($line, 'adoration') !== false && stripos($line, 'schedule') !== false) {
                return $index;
            }
        }
        return null;
    }

    private function find_weekly_mass_heading(array $lines)
    {
        foreach ($lines as $index => $line) {
            if (stripos($line, 'this week') !== false && stripos($line, 'mass') !== false && stripos($line, 'schedule') !== false) {
                return $index;
            }
        }
        return null;
    }

    private function diagnostic_lines(array $lines)
    {
        $indexes = array();
        $schedule = $this->find_schedule_heading($lines);
        $weekly = $this->find_weekly_mass_heading($lines);
        if ($weekly !== null) {
            for ($i = max(0, $weekly - 2); $i < min(count($lines), $weekly + 55); $i++) {
                $indexes[$i] = true;
            }
        }
        if ($schedule !== null) {
            for ($i = max(0, $schedule - 3); $i < min(count($lines), $schedule + 30); $i++) {
                $indexes[$i] = true;
            }
        }
        ksort($indexes);
        $result = array();
        foreach (array_keys($indexes) as $i) {
            $result[] = '[raw] ' . $lines[$i];
        }
        return $result;
    }

    private function day_time_pairs($line)
    {
        $time = $this->time_token_pattern();
        $pattern = '/\b(Sat(?:urday)?|Sun(?:day)?)\b[^;|]{0,28}?(' . $time . ')/i';
        preg_match_all($pattern, (string) $line, $matches, PREG_SET_ORDER);
        $pairs = array();
        foreach ($matches as $match) {
            $pairs[] = array(
                'day' => strtolower(substr($match[1], 0, 3)),
                'time' => $this->normalize_time($match[2]),
            );
        }
        return $pairs;
    }

    private function time_token_pattern()
    {
        return '(?:0?[1-9]|1[0-2])(?::[0-5][0-9])?\s*(?:a\.?\s*m\.?|p\.?\s*m\.?)';
    }

    private function normalize_time($time)
    {
        $time = strtolower(trim((string) $time));
        $time = str_replace('.', '', $time);
        $time = preg_replace('/\s+/', ' ', $time);
        if (! preg_match('/^(\d{1,2})(?::([0-5][0-9]))?\s*([ap])m$/i', str_replace(' ', '', $time), $m)) {
            return strtoupper(trim($time));
        }
        $hour = (int) $m[1];
        $minute = isset($m[2]) && $m[2] !== '' ? $m[2] : '00';
        return $hour . ':' . $minute . ' ' . strtoupper($m[3]) . 'M';
    }

    private function time_expression($text)
    {
        $text = (string) $text;
        if (preg_match('/\bnoon\b/i', $text)) {
            return '12:00 PM';
        }

        $token = $this->time_token_pattern();
        if (preg_match('/(' . $token . ')\s*(?:-|–|—|to)\s*(' . $token . ')/i', $text, $m)) {
            return $this->normalize_time($m[1]) . '–' . $this->normalize_time($m[2]);
        }

        // Common bulletin style: "4:00 - 4:30 pm" (AM/PM appears once).
        if (preg_match('/\b(\d{1,2}:[0-5][0-9])\s*(?:-|–|—|to)\s*(' . $token . ')/i', $text, $m)) {
            $second = $this->normalize_time($m[2]);
            $suffix = substr($second, -2);
            return ltrim($m[1], '0') . ' ' . $suffix . '–' . $second;
        }

        if (preg_match('/(' . $token . ')/i', $text, $m)) {
            return $this->normalize_time($m[1]);
        }
        return '';
    }

    private function remove_time_expression($text)
    {
        $token = $this->time_token_pattern();
        $text = preg_replace('/\bnoon\s*:?/i', '', $text);
        $text = preg_replace('/' . $token . '\s*(?:-|–|—|to)\s*' . $token . '/i', '', $text);
        $text = preg_replace('/\b\d{1,2}:[0-5][0-9]\s*(?:-|–|—|to)\s*' . $token . '/i', '', $text);
        $text = preg_replace('/' . $token . '/i', '', $text);
        return $text;
    }

    private function explicit_date_from_line($line, $bulletin_date)
    {
        $line = (string) $line;
        $base = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $base) {
            return '';
        }

        $month_pattern = '(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';
        if (preg_match('/' . $month_pattern . '\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b/i', $line, $m)) {
            $month = $this->month_number($m[1]);
            $day = (int) $m[2];
            $year = (int) $base->format('Y');
            $candidate = DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-' . $day);
            if ($candidate && $candidate < $base->modify('-45 days')) {
                $candidate = $candidate->modify('+1 year');
            }
            return $candidate ? $candidate->format('Y-m-d') : '';
        }

        // A weekday by itself is useful only when the line is functioning as a
        // calendar heading or contains a time/event prefix.
        if (preg_match('/\b(Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?)\b/i', $line, $m)) {
            return $this->next_weekday_date($bulletin_date, $this->weekday_number($m[1]));
        }
        return '';
    }

    private function weekday_number_from_line($line)
    {
        if (preg_match('/\b(Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?)\b/i', (string) $line, $m)) {
            return $this->weekday_number($m[1]);
        }
        return 0;
    }

    private function weekday_word($line)
    {
        if (preg_match('/\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b/i', (string) $line, $m)) {
            return ucfirst(strtolower($m[1]));
        }
        return '';
    }

    private function next_weekday_date($base_date, $target_weekday)
    {
        $base = DateTimeImmutable::createFromFormat('!Y-m-d', $base_date);
        if (! $base) {
            return '';
        }
        $base_weekday = (int) $base->format('N');
        $delta = ($target_weekday - $base_weekday + 7) % 7;
        if ($delta === 0) {
            $delta = 7;
        }
        return $base->modify('+' . $delta . ' days')->format('Y-m-d');
    }

    private function weekday_number($name)
    {
        $key = strtolower(substr(trim($name), 0, 3));
        $map = array('mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7);
        return isset($map[$key]) ? $map[$key] : 7;
    }

    private function month_number($name)
    {
        $key = strtolower(substr(trim($name), 0, 3));
        $map = array('jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12);
        return isset($map[$key]) ? $map[$key] : 1;
    }

    private function location_from_line($line)
    {
        if (preg_match('/\bSM\s*:/i', $line) || stripos($line, 'st. mary') !== false || stripos($line, 'st mary') !== false || stripos($line, 'two inlets') !== false) {
            return 'St. Mary’s';
        }
        if (preg_match('/\bSP\s*:/i', $line) || stripos($line, 'st. peter') !== false || stripos($line, 'st peter') !== false || stripos($line, 'park rapids') !== false) {
            return 'St. Peter';
        }
        if (stripos($line, 'Crystal Brook') !== false) {
            return 'Crystal Brook Senior Living Center';
        }
        if (stripos($line, 'Heritage Living') !== false) {
            return 'Heritage Living Center';
        }
        return '';
    }

    private function dedupe_rows(array $rows)
    {
        $seen = array();
        $result = array();
        foreach ($rows as $row) {
            $key = strtolower(implode('|', array(
                isset($row['date']) ? $row['date'] : '',
                isset($row['time']) ? $row['time'] : '',
                isset($row['location']) ? $row['location'] : '',
                isset($row['title']) ? $row['title'] : '',
            )));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $row;
        }
        return $result;
    }

    private function calendar_sort($a, $b)
    {
        $ak = (isset($a['date']) ? $a['date'] : '') . ' ' . (isset($a['time']) ? $a['time'] : '');
        $bk = (isset($b['date']) ? $b['date'] : '') . ' ' . (isset($b['time']) ? $b['time'] : '');
        return strcmp($ak, $bk);
    }

    private function normalized_lines($text)
    {
        $raw = preg_split('/\R/u', (string) $text);
        $lines = array();
        foreach ($raw as $line) {
            $line = preg_replace('/\s+/u', ' ', trim($line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    private function looks_like_event_line($line)
    {
        return preg_match('/\b(?:meeting|practice|breakfast|bible study|council|quilting|quilt|choir|knights|civil air patrol|class|formation|rehearsal|group|lunch)\b/i', (string) $line) === 1;
    }

    private function is_probable_contact_or_office_line($line)
    {
        return preg_match('/\b(?:office hours?|phone|fax|email|www\.|https?:|bulletin deadline|deadline|address)\b/i', (string) $line) === 1;
    }

    private function fields()
    {
        return array('st_peter_saturday', 'st_peter_sunday', 'st_mary_sunday', 'reconciliation', 'adoration');
    }

    private function diagnostic()
    {
        if (! extension_loaded('zlib')) {
            return new WP_Error('cbp_schedule_zlib', __('Automatic bulletin extraction needs the PHP zlib extension, which is not available on this server. No website content has been changed.', 'church-bulletin-publisher'));
        }
        if (! extension_loaded('iconv')) {
            return new WP_Error('cbp_schedule_iconv', __('Automatic bulletin extraction needs the PHP iconv extension, which is not available on this server. No website content has been changed.', 'church-bulletin-publisher'));
        }
        if (! class_exists('Smalot\\PdfParser\\Parser')) {
            return new WP_Error('cbp_schedule_parser', __('The bundled PHP PDF parser is missing. Rebuild the plugin ZIP with Composer dependencies included. No website content has been changed.', 'church-bulletin-publisher'));
        }
        return true;
    }

    private function pdf_text($path)
    {
        $diagnostic = $this->diagnostic();
        if (is_wp_error($diagnostic)) {
            return $diagnostic;
        }
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            $text = (string) $pdf->getText();
        } catch (\Throwable $e) {
            return new WP_Error('cbp_schedule_extract', __('The bulletin PDF could not be read by the bundled PHP parser. No website content has been changed.', 'church-bulletin-publisher') . ' ' . $e->getMessage());
        }
        if (trim($text) === '') {
            return new WP_Error('cbp_schedule_empty', __('The bulletin PDF did not yield readable text. It may be image-only. No website content has been changed.', 'church-bulletin-publisher'));
        }
        return $text;
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

    private function redirect($type, $message)
    {
        $url = add_query_arg(array(
            'page' => 'church-bulletin-publisher',
            'cbp_notice' => sanitize_key($type),
            'cbp_message' => $message,
        ), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }
}
