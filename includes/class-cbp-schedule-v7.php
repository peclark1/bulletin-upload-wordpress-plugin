<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Refines two bulletin idioms that need context across lines:
 *  - parenthetical continuation notes belong to the preceding parish event,
 *    even when the note contains a deadline time of its own;
 *  - the parish's Rosary rule is relative to each day's Mass rather than a
 *    standalone clock time (30 minutes before Mass, except Friday after Mass).
 */
final class CBP_Schedule_V7
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
        // V6 is registered first, so this runs after its Adoration cleanup.
        add_action('shutdown', array($this, 'postprocess_review'));
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
        if (! is_array($review) || empty($review['weekly']) || ! is_array($review['weekly'])) {
            return;
        }

        $preview = get_transient($this->preview_key());
        if (! is_array($preview) || empty($preview['path']) || ! is_readable($preview['path'])) {
            return;
        }

        try {
            $v4 = CBP_Schedule_V4::instance();
            $method = new ReflectionMethod($v4, 'pdf_text_by_page');
            $method->setAccessible(true);
            $extracted = $method->invoke($v4, $preview['path']);
        } catch (Throwable $e) {
            return;
        }
        if (is_wp_error($extracted) || empty($extracted['text'])) {
            return;
        }

        $flat_text = $this->flatten($extracted['text']);
        $review = $this->merge_parenthetical_event_notes($review, $flat_text);
        $review = $this->expand_relative_rosary_rule($review, $flat_text);

        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function merge_parenthetical_event_notes(array $review, $flat_text)
    {
        if (empty($review['weekly']['events']) || ! is_array($review['weekly']['events'])) {
            return $review;
        }

        $events = array_values($review['weekly']['events']);
        $remove = array();

        foreach ($events as $note_index => $note_row) {
            if (! $this->is_continuation_note($note_row)) {
                continue;
            }

            $note_text = $this->row_text($note_row);
            $note_needle = $this->flatten(trim($note_text, " \t\n\r\0\x0B()"));
            $note_pos = $note_needle !== '' ? stripos($flat_text, $note_needle) : false;
            $best_index = null;
            $best_distance = PHP_INT_MAX;

            foreach ($events as $candidate_index => $candidate) {
                if ($candidate_index === $note_index || isset($remove[$candidate_index])) {
                    continue;
                }
                if ((isset($candidate['date']) ? $candidate['date'] : '') !== (isset($note_row['date']) ? $note_row['date'] : '')) {
                    continue;
                }
                if ($this->is_continuation_note($candidate)) {
                    continue;
                }

                $candidate_text = $this->row_text($candidate);
                $candidate_needle = $this->flatten($candidate_text);
                if ($candidate_needle === '') {
                    continue;
                }
                $candidate_pos = stripos($flat_text, $candidate_needle);
                if ($candidate_pos === false || $note_pos === false || $candidate_pos >= $note_pos) {
                    continue;
                }
                $distance = $note_pos - ($candidate_pos + strlen($candidate_needle));
                if ($distance >= 0 && $distance < 220 && $distance < $best_distance) {
                    $best_distance = $distance;
                    $best_index = $candidate_index;
                }
            }

            // Bulletin continuation notes are directly under the event. If PDF
            // extraction changed punctuation enough to defeat text-position
            // matching, prefer the nearest later event on the same date rather
            // than publishing the note as a fake event at its deadline time.
            if ($best_index === null) {
                $note_minutes = $this->clock_minutes(isset($note_row['time']) ? $note_row['time'] : '');
                $best_delta = PHP_INT_MAX;
                foreach ($events as $candidate_index => $candidate) {
                    if ($candidate_index === $note_index || $this->is_continuation_note($candidate)) {
                        continue;
                    }
                    if ((isset($candidate['date']) ? $candidate['date'] : '') !== (isset($note_row['date']) ? $note_row['date'] : '')) {
                        continue;
                    }
                    $candidate_minutes = $this->clock_minutes(isset($candidate['time']) ? $candidate['time'] : '');
                    if ($candidate_minutes === null) {
                        continue;
                    }
                    $delta = $note_minutes === null ? 0 : $candidate_minutes - $note_minutes;
                    if ($delta >= 0 && $delta < $best_delta) {
                        $best_delta = $delta;
                        $best_index = $candidate_index;
                    }
                }
            }

            if ($best_index !== null) {
                $clean_note = trim($note_text);
                if ($clean_note !== '' && $clean_note[0] !== '(') {
                    $clean_note = '(' . $clean_note . ')';
                }
                $existing = isset($events[$best_index]['description']) ? trim($events[$best_index]['description']) : '';
                if ($clean_note !== '' && stripos($existing, trim($clean_note, '()')) === false) {
                    $events[$best_index]['description'] = trim($existing . ' ' . $clean_note);
                }
            }

            // A parenthetical deadline/instruction is never a standalone event.
            $remove[$note_index] = true;
        }

        if (! empty($remove)) {
            $events = array_values(array_filter($events, function ($row, $index) use ($remove) {
                return ! isset($remove[$index]);
            }, ARRAY_FILTER_USE_BOTH));
        }

        usort($events, array($this, 'sort_rows'));
        $review['weekly']['events'] = $events;
        return $review;
    }

    private function expand_relative_rosary_rule(array $review, $flat_text)
    {
        $pattern = '/The\s+Rosary\s+is\s+prayed\s+each\s+day\s+(?:½|1\/2|one[- ]half|half)\s+hour\s+prior\s+to\s+Mass\.?\s+On\s+Friday[’\'s]*\s*,?\s*the\s+Rosary\s+is\s+prayed\s+after\s+Mass\.?/iu';
        if (! preg_match($pattern, $flat_text, $match)) {
            return $review;
        }

        $source = trim($match[0]);
        $masses = isset($review['weekly']['masses']) && is_array($review['weekly']['masses'])
            ? $review['weekly']['masses']
            : array();
        if (empty($masses)) {
            return $review;
        }

        $devotions = isset($review['weekly']['devotions']) && is_array($review['weekly']['devotions'])
            ? $review['weekly']['devotions']
            : array();
        $devotions = array_values(array_filter($devotions, function ($row) {
            $haystack = strtolower(
                (isset($row['title']) ? $row['title'] : '') . ' ' .
                (isset($row['description']) ? $row['description'] : '')
            );
            return strpos($haystack, 'rosary') === false;
        }));

        foreach ($masses as $mass) {
            $date = isset($mass['date']) ? $mass['date'] : '';
            $mass_time = isset($mass['time']) ? $mass['time'] : '';
            $title = isset($mass['title']) ? $mass['title'] : '';
            if (! $this->valid_date($date) || $mass_time === '' || stripos($title, 'no mass') !== false) {
                continue;
            }

            $weekday = (int) DateTimeImmutable::createFromFormat('!Y-m-d', $date)->format('N');
            if ($weekday === 5) {
                $rosary_time = 'After ' . $mass_time . ' Mass';
            } else {
                $rosary_time = $this->minutes_before($mass_time, 30);
                if ($rosary_time === '') {
                    continue;
                }
            }

            $devotions[] = array(
                'date' => $date,
                'time' => $rosary_time,
                'location' => isset($mass['location']) ? $mass['location'] : '',
                'title' => 'Rosary',
                'description' => $source,
            );
        }

        $devotions = $this->dedupe_rows($devotions);
        usort($devotions, array($this, 'sort_rows'));
        $review['weekly']['devotions'] = $devotions;

        if (! isset($review['source_lines']) || ! is_array($review['source_lines'])) {
            $review['source_lines'] = array();
        }
        $review['source_lines'][] = '[v7 rosary] ' . $source;
        $review['source_lines'] = array_values(array_unique($review['source_lines']));

        return $review;
    }

    private function is_continuation_note($row)
    {
        if (! is_array($row)) {
            return false;
        }
        $text = $this->row_text($row);
        return preg_match('/^\s*\(?\s*(?:call|contact)\s+(?:the\s+)?office\s+before\b.*\b(?:location|sign[- ]?up|details?)\b.*\)?\s*$/iu', $text) === 1;
    }

    private function row_text($row)
    {
        if (! is_array($row)) {
            return '';
        }
        $description = isset($row['description']) ? trim((string) $row['description']) : '';
        if ($description !== '') {
            return $description;
        }
        return isset($row['title']) ? trim((string) $row['title']) : '';
    }

    private function flatten($text)
    {
        $text = str_replace(array("\r", "\n", "\t"), ' ', (string) $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string) $text);
    }

    private function minutes_before($time, $minutes)
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})\s+([AP]M)$/i', trim((string) $time), $m)) {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!g:i A', ((int) $m[1]) . ':' . $m[2] . ' ' . strtoupper($m[3]));
        if (! $date) {
            return '';
        }
        return $date->modify('-' . (int) $minutes . ' minutes')->format('g:i A');
    }

    private function clock_minutes($time)
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})\s+([AP]M)$/i', trim((string) $time), $m)) {
            return null;
        }
        $hour = (int) $m[1] % 12;
        if (strtoupper($m[3]) === 'PM') {
            $hour += 12;
        }
        return ($hour * 60) + (int) $m[2];
    }

    private function dedupe_rows(array $rows)
    {
        $seen = array();
        $result = array();
        foreach ($rows as $row) {
            $key = strtolower(
                (isset($row['date']) ? $row['date'] : '') . '|' .
                (isset($row['time']) ? $row['time'] : '') . '|' .
                (isset($row['location']) ? $row['location'] : '') . '|' .
                (isset($row['title']) ? $row['title'] : '')
            );
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $row;
        }
        return $result;
    }

    private function sort_rows($a, $b)
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
}
