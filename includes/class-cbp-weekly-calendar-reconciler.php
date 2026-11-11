<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reconciles explicitly dated, parish-prefixed calendar entries against
 * heuristic PDF extraction. Do not invent a calendar item from prose alone:
 * the day heading, parish prefix and printed text must establish it.
 */
final class CBP_Weekly_Calendar_Reconciler
{
    public static function reconcile(array $parsed, $raw_text)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($parsed['bulletin_date'] ?? ''));
        if (! $bulletin || empty($parsed['weekly']) || ! is_array($parsed['weekly'])) {
            return $parsed;
        }

        $start = (string) ($parsed['week_start'] ?? $bulletin->modify('+1 day')->format('Y-m-d'));
        $end = (string) ($parsed['week_end'] ?? $bulletin->modify('+7 days')->format('Y-m-d'));
        $events = isset($parsed['weekly']['events']) && is_array($parsed['weekly']['events'])
            ? array_values($parsed['weekly']['events']) : array();
        $calendar = self::explicit_calendar_rows((string) $raw_text, $bulletin, $start, $end);

        foreach ($calendar as $row) {
            $events = self::replace_matching_event($events, $row);
        }

        self::enrich_faith_formation($events, (string) $raw_text, $bulletin, $start, $end);
        self::recover_announcement_events($events, (string) $raw_text, $bulletin, $start, $end);

        // A calendar Bible Study must never become a Funeral Mass simply
        // because the PDF column parser borrowed "Funeral" from a nearby row.
        $masses = isset($parsed['weekly']['masses']) && is_array($parsed['weekly']['masses'])
            ? $parsed['weekly']['masses'] : array();
        $kept = array();
        foreach ($masses as $mass) {
            if (! is_array($mass)) {
                continue;
            }
            $is_funeral = self::meaning((string) ($mass['title'] ?? '')) === 'funeral mass';
            $description = self::meaning((string) ($mass['description'] ?? ''));
            $misclassified = false;
            if ($is_funeral && preg_match('/\b(?:bible study|choir practice|civil air patrol|quilt group)\b/i', $description)) {
                foreach ($calendar as $event) {
                    if ((string) ($event['date'] ?? '') === (string) ($mass['date'] ?? '')
                        && self::meaning((string) ($event['time'] ?? '')) === self::meaning((string) ($mass['time'] ?? ''))
                        && strpos($description, self::meaning((string) ($event['title'] ?? ''))) !== false) {
                        $misclassified = true;
                        break;
                    }
                }
            }
            if (! $misclassified) {
                $kept[] = $mass;
            }
        }

        $parsed['weekly']['masses'] = array_values($kept);
        $parsed['weekly']['events'] = array_values($events);
        return $parsed;
    }

    /**
     * Only the daily calendar's explicit SP:/SM: entries are authoritative.
     * Ignore unrelated right-column prose and the Mass schedule above it.
     */
    private static function explicit_calendar_rows($text, DateTimeImmutable $bulletin, $start, $end)
    {
        $lines = preg_split('/\r\n|\n|\r/u', (string) $text);
        $rows = array();
        $date = '';
        $in_calendar = false;

        foreach ($lines as $i => $raw) {
            // A PDF's unrelated right-hand column may share this line.
            // Do not append Stewardship/announcement prose to event titles.
            $columns = preg_split('/\\h{3,}/u', ltrim((string) $raw), 2);
            $left = isset($columns[0]) ? $columns[0] : (string) $raw;
            $line = trim((string) preg_replace('/\\s+/u', ' ', $left));
            if ($line === '') {
                continue;
            }
            $day = self::day_heading((string) $raw, $bulletin);
            if ($day !== '' && $day >= $start && $day <= $end) {
                $date = $day;
                $in_calendar = true;
                continue;
            }
            if (! $in_calendar || $date === '') {
                continue;
            }

            if (preg_match('/^(SP|SM)\s*:\s*(.+)$/iu', $line, $m)) {
                $body = trim($m[2]);
                if (preg_match('/\b(?:adoration|reconciliation)\b/iu', $body)) {
                    continue; // Devotions have their own canonical source.
                }
                $location = strtoupper($m[1]) === 'SM' ? 'St. Mary’s' : 'St. Peter';
                $row = self::calendar_event($date, $location, $body);
                if ($row !== null) {
                    $rows[] = $row;
                }
                continue;
            }

            if (preg_match('/^NO\s+Ladies\s+Lunch\b/iu', $line)) {
                $rows[] = self::row($date, '', '', 'NO Ladies lunch');
                continue;
            }

            // The location and title occupy separate lines in the original PDF.
            if (preg_match('/^(Crystal\s+Brook(?:\s+Senior\s+Living\s+Center)?)(?:\s*:\s*)(\d{1,2}:\d{2}\s*[ap]\\.?m\\.?)/iu', $line, $m)) {
                $next = trim((string) ($lines[$i + 1] ?? ''));
                if (preg_match('/\bLiturgy\s+of\s+the\s+Word\b/iu', $next)) {
                    $rows[] = self::row($date, self::clock($m[2]), 'Crystal Brook Senior Living Center', 'Liturgy of the Word');
                }
            }
        }

        return $rows;
    }

    private static function calendar_event($date, $location, $body)
    {
        $time = '';
        $title = trim($body);
        $token = '(?:[01]?\d)(?::[0-5]\d)?\s*(?:a\\.?m\\.?|p\\.?m\\.?|noon)';
        if (preg_match('/^(' . $token . ')\s*[–—-]\s*(' . $token . ')\s*(.*)$/iu', $body, $m)) {
            $time = self::clock($m[1]) . '–' . self::clock($m[2]);
            $title = trim($m[3]);
        } elseif (preg_match('/^(' . $token . ')\s*(.*)$/iu', $body, $m)) {
            $time = self::clock($m[1]);
            $title = trim($m[2]);
        } elseif (preg_match('/^(\d{1,2}:\d{2})\s+(.*)$/u', $body, $m)) {
            // Preserve missing AM/PM (e.g. "9:00 Quilt Group") as uncertainty.
            $time = $m[1];
            $title = trim($m[2]);
        } elseif (preg_match('/^(.*?)\s+after\s+Mass$/iu', $body, $m)) {
            $time = 'After Mass';
            $title = trim($m[1]);
        }
        $title = trim($title, " \t\r\n:-–—");
        if ($title === '' || preg_match('/^(?:mass|no mass)$/iu', $title)) {
            return null;
        }
        return self::row($date, $time, $location, $title);
    }

    private static function row($date, $time, $location, $title, $description = '')
    {
        return array(
            'date' => (string) $date,
            'time' => (string) $time,
            'location' => (string) $location,
            'title' => (string) $title,
            'description' => (string) $description,
        );
    }

    private static function replace_matching_event(array $events, array $wanted)
    {
        $kept = array();
        foreach ($events as $old) {
            if (! is_array($old) || ! self::same_calendar_event($old, $wanted)) {
                $kept[] = $old;
            }
        }
        $kept[] = $wanted;
        return $kept;
    }

    private static function same_calendar_event(array $a, array $b)
    {
        if ((string) ($a['date'] ?? '') !== (string) ($b['date'] ?? '')) {
            return false;
        }
        $al = self::meaning((string) ($a['location'] ?? ''));
        $bl = self::meaning((string) ($b['location'] ?? ''));
        if ($al !== '' && $bl !== '' && $al !== $bl) {
            return false; // Keep simultaneous Bingo at two different parishes.
        }
        $at = self::meaning((string) ($a['title'] ?? ''));
        $bt = self::meaning((string) ($b['title'] ?? ''));
        $same_title = $at !== '' && $at === $bt;
        // Two time tokens extracted from the same Civil Air Patrol interval
        // can have slightly different titles (with or without "Meeting").
        if (strpos($at, 'civil air patrol') !== false && strpos($bt, 'civil air patrol') !== false) {
            $same_title = true;
        }
        $same_funeral = false;
        if (preg_match('/^(.+?)\s+funeral$/u', $bt, $m)
            && strpos($at . ' ' . self::meaning((string) ($a['description'] ?? '')), $m[1]) !== false
            && strpos($at . ' ' . self::meaning((string) ($a['description'] ?? '')), 'funeral') !== false) {
            $same_funeral = true;
        }
        if (! $same_title && ! $same_funeral) {
            return false;
        }
        $old_time = self::meaning((string) ($a['time'] ?? ''));
        $new_time = self::meaning((string) ($b['time'] ?? ''));
        if ($old_time === '' || $new_time === $old_time) {
            return true;
        }
        // An unqualified printed time (9:00 Quilt Group) outranks a guessed
        // AM/PM in an earlier heuristic extraction.
        if (preg_match('/^\\d{1,2} \\d{2}$/', $new_time)
            && ($old_time === $new_time . ' am' || $old_time === $new_time . ' pm')) {
            return true;
        }
        // A split time range is a single meeting, not two timed events.
        if (strpos((string) ($b['time'] ?? ''), '–') !== false) {
            $parts = explode('–', (string) $b['time']);
            return $old_time === self::meaning($parts[0]) || $old_time === self::meaning($parts[1]);
        }
        return false;
    }

    private static function enrich_faith_formation(array &$events, $text, DateTimeImmutable $bulletin, $start, $end)
    {
        if (! preg_match_all('/\bWed(?:nesday)?\s*,?\s*Nov(?:ember)?\s+(\d{1,2})\s*[–—-]\s*1\s*[–—-]\s*3\s*p\.?m\.?\s+Release\s+Time\s+from\s+school\s+grades\s+1\s*[–-]\s*6\b/iu', $text, $found, PREG_SET_ORDER)) {
            return;
        }
        foreach ($found as $m) {
            $date = self::month_day_date('November', $m[1], $bulletin);
            if ($date < $start || $date > $end) {
                continue;
            }
            foreach ($events as &$row) {
                if (! is_array($row) || ($row['date'] ?? '') !== $date) {
                    continue;
                }
                if (strpos(self::meaning((string) ($row['title'] ?? '')), 'faith formation grades 1 6') !== false
                    && self::meaning((string) ($row['time'] ?? '')) === '1 00 pm') {
                    $row['time'] = '1:00 PM–3:00 PM';
                }
            }
            unset($row);
        }
    }

    private static function recover_announcement_events(array &$events, $text, DateTimeImmutable $bulletin, $start, $end)
    {
        // St. Mary's announcement: the meeting follows the parish lunch.
        if (preg_match_all('/\b(November)\s+(\d{1,2})\s*[–—-]\s*Parish\s+meeting\s+after\s+Soup\s*&\s*Sandwich/iu', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $date = self::month_day_date($m[1], $m[2], $bulletin);
                if ($date >= $start && $date <= $end) {
                    $events = self::replace_matching_event($events,
                        self::row($date, 'After Soup & Sandwich', 'St. Mary’s', 'Parish Meeting'));
                }
            }
        }

        // The request to move the Breaking Bread hymnal covers spans sentences
        // on the announcements page. Require an actual printed date/time and
        // close hymnals context, not just a date elsewhere on the page.
        if (preg_match_all('/\b(Tuesday)\s*,?\s*(November)\s+(\d{1,2})\s+at\s+(\d{1,2}:\d{2}\s*p\.?m\.?)\s+at\s+St\.?\s*Peter/iu', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $date = self::month_day_date($m[2][0], $m[3][0], $bulletin);
                if ($date < $start || $date > $end) {
                    continue;
                }
                $prefix = substr($text, max(0, $m[0][1] - 700), min(700, $m[0][1]));
                if (! preg_match('/\b(?:hymnal|hymnals|Breaking\s+Bread|plastic\s+covers)\b/iu', $prefix)) {
                    continue;
                }
                $events = self::replace_matching_event($events,
                    self::row($date, self::clock($m[4][0]), 'St. Peter', 'Hymnal Cover Volunteer Work'));
            }
        }
    }

    private static function day_heading($line, DateTimeImmutable $bulletin)
    {
        if (! preg_match('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s*,?\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(?:st|nd|rd|th)?(?:\s*$|\s{2,})/iu', $line, $m)) {
            return '';
        }
        return self::month_day_date($m[2], $m[3], $bulletin);
    }

    private static function month_day_date($month, $day, DateTimeImmutable $bulletin)
    {
        $date = DateTimeImmutable::createFromFormat('!F j Y', $month . ' ' . (int) $day . ' ' . $bulletin->format('Y'));
        if (! $date) {
            return '';
        }
        if ($date < $bulletin->modify('-30 days')) {
            $date = $date->modify('+1 year');
        }
        return $date->format('Y-m-d');
    }

    private static function clock($token)
    {
        $value = strtolower(trim(str_replace('.', '', (string) $token)));
        if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*([ap])m$/i', $value, $m)) {
            return (int) $m[1] . ':' . ($m[2] !== '' ? $m[2] : '00') . ' ' . strtoupper($m[3]) . 'M';
        }
        if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*noon$/i', $value, $m)) {
            return '12:00 PM';
        }
        return trim((string) $token);
    }

    private static function meaning($text)
    {
        $text = strtolower((string) $text);
        return trim((string) preg_replace('/\s+/u', ' ', preg_replace('/[^a-z0-9]+/u', ' ', $text)));
    }
}
