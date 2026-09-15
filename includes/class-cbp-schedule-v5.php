<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Small post-processing layer for the real parish bulletin layout.
 *
 * V4 solved the PDF encoding problem. V5 keeps that extraction path, then
 * applies two bulletin-specific correctness fixes before the review is shown:
 *  - collapse spaced ordinals such as "15 th" to "15th";
 *  - recover ordinary parish events that share a Mass time and events that
 *    contain more than one time on the same printed line.
 */
final class CBP_Schedule_V5
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
        remove_action('admin_post_cbp_extract_schedule', array(CBP_Schedule_V4::instance(), 'extract_schedule'));
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

        try {
            $v4 = CBP_Schedule_V4::instance();
            $extract_method = new ReflectionMethod($v4, 'pdf_text_by_page');
            $extract_method->setAccessible(true);
            $extracted = $extract_method->invoke($v4, $preview['path']);
        } catch (Throwable $e) {
            $message = __('The bulletin PDF could not be read by the bundled PHP parser. No website content has been changed.', 'church-bulletin-publisher') . ' ' . $e->getMessage();
            set_transient($this->review_key(), array('error' => $message), self::REVIEW_TTL);
            $this->redirect('error', $message);
        }

        if (is_wp_error($extracted)) {
            set_transient($this->review_key(), array('error' => $extracted->get_error_message()), self::REVIEW_TTL);
            $this->redirect('error', $extracted->get_error_message());
        }

        try {
            $v2 = CBP_Schedule_V2::instance();
            $parse_method = new ReflectionMethod($v2, 'parse');
            $parse_method->setAccessible(true);
            $parsed = $parse_method->invoke($v2, $extracted['text'], $bulletin_date);
        } catch (Throwable $e) {
            $message = __('The bulletin text was read, but the schedule parser could not process it. No website content has been changed.', 'church-bulletin-publisher') . ' ' . $e->getMessage();
            set_transient($this->review_key(), array('error' => $message), self::REVIEW_TTL);
            $this->redirect('error', $message);
        }

        $parsed = $this->fix_ordinal_spacing($parsed);
        $parsed = $this->recover_parish_events($parsed, $extracted['text'], $bulletin_date);

        // Keep V4's useful encoding diagnostics.
        try {
            $debug_method = new ReflectionMethod($v4, 'debug_lines');
            $debug_method->setAccessible(true);
            $debug = $debug_method->invoke($v4, $extracted);
        } catch (Throwable $e) {
            $debug = array('[debug] Encoding diagnostics unavailable: ' . $e->getMessage());
        }

        $existing = isset($parsed['source_lines']) && is_array($parsed['source_lines']) ? $parsed['source_lines'] : array();
        $parsed['source_lines'] = array_values(array_unique(array_merge($debug, $existing)));

        set_transient($this->review_key(), $parsed, self::REVIEW_TTL);
        $this->redirect('success', __('Website information extracted. Review every proposed item before approving it.', 'church-bulletin-publisher'));
    }

    private function fix_ordinal_spacing($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->fix_ordinal_spacing($item);
            }
            return $value;
        }
        if (! is_string($value)) {
            return $value;
        }
        return preg_replace('/\b(\d{1,2})\s+(st|nd|rd|th)\b/i', '$1$2', $value);
    }

    private function recover_parish_events(array $parsed, $text, $bulletin_date)
    {
        if (empty($parsed['weekly']) || ! is_array($parsed['weekly'])) {
            return $parsed;
        }
        if (! isset($parsed['weekly']['events']) || ! is_array($parsed['weekly']['events'])) {
            $parsed['weekly']['events'] = array();
        }

        $week_start = isset($parsed['week_start']) ? $parsed['week_start'] : '';
        $week_end = isset($parsed['week_end']) ? $parsed['week_end'] : '';
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        if (! is_array($lines)) {
            return $parsed;
        }

        $current_date = '';
        $in_weekly_area = false;
        $limit = 0;

        foreach ($lines as $raw_line) {
            $line = preg_replace('/[ \t\x0B\f]+/', ' ', trim((string) $raw_line));
            if ($line === '') {
                continue;
            }
            $line = preg_replace('/\b(\d{1,2})\s+(st|nd|rd|th)\b/i', '$1$2', $line);

            if (! $in_weekly_area) {
                if (stripos($line, 'this week') !== false && stripos($line, 'mass') !== false && stripos($line, 'schedule') !== false) {
                    $in_weekly_area = true;
                }
                continue;
            }

            $limit++;
            if ($limit > 120 || preg_match('/^st\.?\s*peter[’\'s]*\s+liturgical\s+schedule/i', $line)) {
                break;
            }

            $line_date = $this->date_from_line($line, $bulletin_date);
            if ($line_date !== '') {
                $current_date = $line_date;
            }

            if ($current_date === '' || ($week_start !== '' && $current_date < $week_start) || ($week_end !== '' && $current_date > $week_end)) {
                continue;
            }

            if (! $this->looks_like_parish_event($line)) {
                continue;
            }
            if (preg_match('/\b(?:mass|reconciliation|confession|adoration|rosary)\b/i', $line)) {
                continue;
            }

            $items = $this->event_items_from_line($line, $current_date);
            foreach ($items as $item) {
                if (! $this->event_exists($parsed['weekly']['events'], $item)) {
                    $parsed['weekly']['events'][] = $item;
                }
            }
        }

        usort($parsed['weekly']['events'], array($this, 'sort_items'));
        return $parsed;
    }

    private function looks_like_parish_event($line)
    {
        return preg_match('/\b(?:meeting|practice|breakfast|bible study|council|quilting|quilt|choir|knights|civil air patrol|class|formation|rehearsal|group|lunch|meal|peter[’\']s table)\b/i', (string) $line) === 1;
    }

    private function event_items_from_line($line, $date)
    {
        $segments = preg_split('/\s*;\s*/', $line);
        $usable = array();
        if (is_array($segments) && count($segments) > 1) {
            foreach ($segments as $segment) {
                if ($this->all_times($segment)) {
                    $usable[] = $segment;
                }
            }
        }
        if (empty($usable)) {
            $usable = array($line);
        }

        $items = array();
        $inherited_location = $this->location_from_line($line);
        foreach ($usable as $segment) {
            $times = $this->all_times($segment);
            if (empty($times)) {
                continue;
            }
            $title = $this->event_title($segment);
            if ($title === '' && count($usable) > 1) {
                $title = $this->event_title($line);
            }
            foreach ($times as $time) {
                $items[] = array(
                    'date' => $date,
                    'time' => $time,
                    'location' => $this->location_from_line($segment) ?: $inherited_location,
                    'title' => sanitize_text_field($title),
                    'description' => sanitize_text_field($line),
                );
            }
        }
        return $items;
    }

    private function event_title($line)
    {
        $title = (string) $line;
        $title = preg_replace('/^(?:Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?)[\.,]?\s*/i', '', $title);
        $title = preg_replace('/^(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)[\.,]?\s+\d{1,2}(?:st|nd|rd|th)?[\.,]?\s*/i', '', $title);
        $title = preg_replace('/^(?:SP|SM)\s*:\s*/i', '', $title);
        $title = preg_replace('/\b(?:0?[1-9]|1[0-2])(?::[0-5][0-9])?\s*(?:a\.?\s*m\.?|p\.?\s*m\.?)\b/i', '', $title);
        $title = preg_replace('/\s+(?:&|and)\s+/i', ' ', $title);
        $title = preg_replace('/\s+/', ' ', $title);
        return trim($title, " \t\n\r\0\x0B-–—:;,.");
    }

    private function all_times($text)
    {
        $token = '(?:0?[1-9]|1[0-2])(?::[0-5][0-9])?\s*(?:a\.?\s*m\.?|p\.?\s*m\.?)';
        preg_match_all('/\b(' . $token . ')\b/i', (string) $text, $matches);
        $result = array();
        if (! empty($matches[1])) {
            foreach ($matches[1] as $match) {
                $normalized = $this->normalize_time($match);
                if ($normalized !== '' && ! in_array($normalized, $result, true)) {
                    $result[] = $normalized;
                }
            }
        }
        return $result;
    }

    private function normalize_time($time)
    {
        $time = strtolower(trim((string) $time));
        $time = str_replace('.', '', $time);
        $time = preg_replace('/\s+/', '', $time);
        if (! preg_match('/^(\d{1,2})(?::([0-5][0-9]))?([ap])m$/i', $time, $m)) {
            return strtoupper(trim($time));
        }
        $minute = isset($m[2]) && $m[2] !== '' ? $m[2] : '00';
        return ((int) $m[1]) . ':' . $minute . ' ' . strtoupper($m[3]) . 'M';
    }

    private function date_from_line($line, $bulletin_date)
    {
        $base = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $base) {
            return '';
        }
        $months = '(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';
        if (preg_match('/' . $months . '\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b/i', (string) $line, $m)) {
            $map = array('jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12);
            $mon = $map[strtolower(substr($m[1], 0, 3))];
            $candidate = DateTimeImmutable::createFromFormat('!Y-n-j', $base->format('Y') . '-' . $mon . '-' . (int) $m[2]);
            if ($candidate && $candidate < $base->modify('-45 days')) {
                $candidate = $candidate->modify('+1 year');
            }
            return $candidate ? $candidate->format('Y-m-d') : '';
        }
        return '';
    }

    private function location_from_line($line)
    {
        if (preg_match('/\bSM\s*:/i', $line) || stripos($line, 'st. mary') !== false || stripos($line, 'st mary') !== false) {
            return 'St. Mary’s';
        }
        if (preg_match('/\bSP\s*:/i', $line) || stripos($line, 'st. peter') !== false || stripos($line, 'st peter') !== false) {
            return 'St. Peter';
        }
        return '';
    }

    private function event_exists(array $events, array $candidate)
    {
        foreach ($events as $event) {
            if ((isset($event['date']) ? $event['date'] : '') !== $candidate['date']) {
                continue;
            }
            if ((isset($event['time']) ? $event['time'] : '') !== $candidate['time']) {
                continue;
            }
            $a = strtolower(trim((string) (isset($event['title']) ? $event['title'] : '')));
            $b = strtolower(trim((string) $candidate['title']));
            if ($a === $b || ($a !== '' && $b !== '' && (strpos($a, $b) !== false || strpos($b, $a) !== false))) {
                return true;
            }
        }
        return false;
    }

    private function sort_items($a, $b)
    {
        $ak = (isset($a['date']) ? $a['date'] : '') . ' ' . (isset($a['time']) ? $a['time'] : '');
        $bk = (isset($b['date']) ? $b['date'] : '') . ' ' . (isset($b['time']) ? $b['time'] : '');
        return strcmp($ak, $bk);
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
