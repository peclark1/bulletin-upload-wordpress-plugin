<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Finalizes parsed weekly website data before the pending review is saved.
 *
 * This is the canonical place for cross-row reconciliation that needs to see
 * the complete extraction result. It intentionally runs before the transient
 * is written, so the Step 3 review starts with clean data instead of relying
 * on shutdown-time repairs.
 */
final class CBP_Weekly_Normalizer
{
    public static function normalize(array $parsed, $raw_text)
    {
        if (empty($parsed['weekly']) || ! is_array($parsed['weekly'])) {
            return $parsed;
        }

        $weekly = $parsed['weekly'];
        $masses = isset($weekly['masses']) && is_array($weekly['masses'])
            ? array_values($weekly['masses'])
            : array();
        $events = isset($weekly['events']) && is_array($weekly['events'])
            ? array_values($weekly['events'])
            : array();

        $masses = self::normalize_liturgy_mass_rows($masses);
        $events = self::canonicalize_liturgy_events($events, $masses);
        $events = self::recover_after_mass_events(
            $events,
            (string) $raw_text,
            $masses,
            (string) ($parsed['bulletin_date'] ?? ''),
            (string) ($parsed['week_start'] ?? ''),
            (string) ($parsed['week_end'] ?? '')
        );
        $events = self::remove_location_title_duplicates($events);

        $weekly['masses'] = $masses;
        $weekly['events'] = self::dedupe_exact_rows($events);

        if (class_exists('CBP_Schedule_V30')) {
            $weekly = CBP_Schedule_V30::sort_weekly($weekly);
        }

        $parsed['weekly'] = $weekly;
        if (! isset($parsed['source_lines']) || ! is_array($parsed['source_lines'])) {
            $parsed['source_lines'] = array();
        }
        $parsed['source_lines'][] = '[normalize] Reconciled weekly worship/event rows before saving the pending review.';
        $parsed['source_lines'] = array_values(array_unique($parsed['source_lines']));

        return $parsed;
    }

    private static function normalize_liturgy_mass_rows(array $rows)
    {
        foreach ($rows as $index => $row) {
            if (! is_array($row) || strcasecmp(trim((string) ($row['title'] ?? '')), 'Mass') !== 0) {
                continue;
            }

            $description = trim((string) ($row['description'] ?? ''));
            if (stripos($description, 'Liturgy of the Word') === false) {
                continue;
            }

            $rows[$index]['title'] = 'Liturgy of the Word';
            if (self::semantic_text($description) === 'liturgy of the word') {
                $rows[$index]['description'] = '';
            }
        }

        return array_values($rows);
    }

    private static function canonicalize_liturgy_events(array $events, array $masses)
    {
        $canonical = array();

        foreach ($masses as $mass) {
            if (! is_array($mass)
                || strcasecmp(trim((string) ($mass['title'] ?? '')), 'Liturgy of the Word') !== 0
                || trim((string) ($mass['date'] ?? '')) === '') {
                continue;
            }

            $row = array(
                'date' => (string) ($mass['date'] ?? ''),
                'time' => (string) ($mass['time'] ?? ''),
                'location' => self::event_location((string) ($mass['location'] ?? '')),
                'title' => 'Liturgy of the Word',
                'description' => '',
            );

            $canonical[] = $row;
        }

        if (empty($canonical)) {
            return $events;
        }

        $kept = array();
        foreach ($events as $row) {
            if (! is_array($row)) {
                continue;
            }

            $redundant = false;
            foreach ($canonical as $wanted) {
                if (self::is_redundant_liturgy_row($row, $wanted)) {
                    $redundant = true;
                    break;
                }
            }

            if (! $redundant) {
                $kept[] = $row;
            }
        }

        foreach ($canonical as $wanted) {
            $kept[] = $wanted;
        }

        return self::dedupe_exact_rows($kept);
    }

    private static function is_redundant_liturgy_row(array $row, array $wanted)
    {
        $wanted_location = self::semantic_text((string) ($wanted['location'] ?? ''));
        $wanted_time = self::semantic_time((string) ($wanted['time'] ?? ''));

        if ($wanted_location === '') {
            return false;
        }

        $row_location = self::semantic_text((string) ($row['location'] ?? ''));
        $row_title = self::semantic_text((string) ($row['title'] ?? ''));
        $row_description = self::semantic_text((string) ($row['description'] ?? ''));
        $row_time = self::semantic_time((string) ($row['time'] ?? ''));

        $mentions_liturgy = strpos($row_title, 'liturgy of the word') !== false
            || strpos($row_description, 'liturgy of the word') !== false;
        $mentions_location = $row_location === $wanted_location
            || strpos($row_title, $wanted_location) !== false
            || strpos($row_description, $wanted_location) !== false;

        if ($mentions_liturgy && $mentions_location) {
            if ($row_time === '' || $wanted_time === '' || $row_time === $wanted_time) {
                return true;
            }
        }

        // PDF table extraction sometimes promotes the location to the event
        // title and leaves the location cell blank.
        if ($row_location === ''
            && $row_title === $wanted_location
            && $row_time !== ''
            && $wanted_time !== ''
            && $row_time === $wanted_time) {
            return true;
        }

        return false;
    }

    private static function recover_after_mass_events(
        array $events,
        $raw_text,
        array $masses,
        $bulletin_date,
        $week_start,
        $week_end
    ) {
        $mass_dates = self::mass_dates_by_location($masses);
        if (empty($mass_dates)) {
            return $events;
        }

        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $bulletin_date);
        if (! $bulletin) {
            return $events;
        }

        $lines = preg_split('/\r\n|\r|\n/u', (string) $raw_text);
        if (! is_array($lines)) {
            return $events;
        }

        $current_date = '';

        foreach ($lines as $raw_line) {
            $line = preg_replace('/\s+/u', ' ', trim((string) $raw_line));
            if ($line === '') {
                continue;
            }

            $heading_date = self::date_from_heading($line, $bulletin);
            if ($heading_date !== '') {
                $current_date = $heading_date;
                continue;
            }

            if (! preg_match('/^(SP|SM):\s*(.+?)\s+after\s+Mass\b/iu', $line, $m)) {
                continue;
            }

            $location = strtoupper($m[1]) === 'SM' ? 'St. Mary’s' : 'St. Peter';
            $location_key = self::semantic_text($location);
            $valid_dates = isset($mass_dates[$location_key]) ? $mass_dates[$location_key] : array();
            if (empty($valid_dates)) {
                continue;
            }

            $date = '';
            if ($current_date !== ''
                && in_array($current_date, $valid_dates, true)
                && self::date_in_range($current_date, $week_start, $week_end)) {
                $date = $current_date;
            } elseif (count($valid_dates) === 1) {
                $date = $valid_dates[0];
            }

            if ($date === '') {
                continue;
            }

            $title = trim((string) $m[2], " \t\n\r\0\x0B-–—,:;");
            if ($title === '') {
                continue;
            }

            $wanted = array(
                'date' => $date,
                'time' => 'After Mass',
                'location' => $location,
                'title' => $title,
                'description' => '',
            );

            $events = self::replace_semantic_after_mass_event($events, $wanted);
        }

        return self::dedupe_exact_rows($events);
    }

    private static function mass_dates_by_location(array $masses)
    {
        $result = array();

        foreach ($masses as $mass) {
            if (! is_array($mass)
                || strcasecmp(trim((string) ($mass['title'] ?? '')), 'Mass') !== 0
                || trim((string) ($mass['date'] ?? '')) === '') {
                continue;
            }

            $location = self::semantic_text((string) ($mass['location'] ?? ''));
            if ($location === '') {
                continue;
            }

            if (! isset($result[$location])) {
                $result[$location] = array();
            }
            $result[$location][] = (string) $mass['date'];
        }

        foreach ($result as $location => $dates) {
            $result[$location] = array_values(array_unique($dates));
        }

        return $result;
    }

    private static function replace_semantic_after_mass_event(array $events, array $wanted)
    {
        $wanted_location = self::semantic_text((string) ($wanted['location'] ?? ''));
        $wanted_title = self::semantic_text((string) ($wanted['title'] ?? ''));

        $kept = array();
        foreach ($events as $row) {
            if (! is_array($row)) {
                continue;
            }

            $same = strcasecmp(trim((string) ($row['time'] ?? '')), 'After Mass') === 0
                && self::semantic_text((string) ($row['location'] ?? '')) === $wanted_location
                && self::semantic_text((string) ($row['title'] ?? '')) === $wanted_title;

            if (! $same) {
                $kept[] = $row;
            }
        }

        $kept[] = $wanted;
        return $kept;
    }

    private static function remove_location_title_duplicates(array $events)
    {
        $canonical = array();

        foreach ($events as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = trim((string) ($row['date'] ?? ''));
            $time = self::semantic_time((string) ($row['time'] ?? ''));
            $location = self::semantic_text((string) ($row['location'] ?? ''));
            $title = self::semantic_text((string) ($row['title'] ?? ''));

            if ($date === '' || $time === '' || $location === '' || $title === '' || $location === $title) {
                continue;
            }

            $canonical[$date . '|' . $time . '|' . $location] = true;
        }

        if (empty($canonical)) {
            return array_values($events);
        }

        $result = array();

        foreach ($events as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = trim((string) ($row['date'] ?? ''));
            $time = self::semantic_time((string) ($row['time'] ?? ''));
            $location = self::semantic_text((string) ($row['location'] ?? ''));
            $title = self::semantic_text((string) ($row['title'] ?? ''));
            $description = self::semantic_text((string) ($row['description'] ?? ''));

            if ($date !== ''
                && $time !== ''
                && $location === ''
                && $title !== ''
                && isset($canonical[$date . '|' . $time . '|' . $title])
                && self::description_is_redundant_location_time($description, $title, $time)) {
                continue;
            }

            $result[] = $row;
        }

        return array_values($result);
    }

    private static function description_is_redundant_location_time($description, $title, $time)
    {
        if ($description === '' || $title === '' || $time === '') {
            return $description === '' || $description === $title;
        }

        return $description === trim($title . ' ' . $time)
            || $description === trim($time . ' ' . $title);
    }

    private static function dedupe_exact_rows(array $rows)
    {
        $seen = array();
        $result = array();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = implode('|', array(
                (string) ($row['date'] ?? ''),
                self::semantic_time((string) ($row['time'] ?? '')),
                self::semantic_text((string) ($row['location'] ?? '')),
                self::semantic_text((string) ($row['title'] ?? '')),
                self::semantic_text((string) ($row['description'] ?? '')),
            ));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $row;
        }

        return array_values($result);
    }

    private static function date_from_heading($line, DateTimeImmutable $bulletin)
    {
        if (! preg_match(
            '/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s*,?\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(?:st|nd|rd|th)?\b/iu',
            (string) $line,
            $m
        )) {
            return '';
        }

        $date = DateTimeImmutable::createFromFormat(
            '!F j Y',
            $m[2] . ' ' . $m[3] . ' ' . $bulletin->format('Y')
        );
        if (! $date) {
            return '';
        }

        if ($date < $bulletin->modify('-30 days')) {
            $date = $date->modify('+1 year');
        }

        return $date->format('Y-m-d');
    }

    private static function date_in_range($date, $start, $end)
    {
        if ($start === '' || $end === '') {
            return true;
        }
        return $date >= $start && $date <= $end;
    }

    private static function event_location($location)
    {
        $location = trim((string) $location);

        if (preg_match('/^Heritage\s+(?:Senior\s+)?Living\s+Center$/iu', $location)) {
            return 'Heritage Living Center';
        }
        if (preg_match('/^St\.?\s*Peter[’\'s]*$/iu', $location)) {
            return 'St. Peter';
        }
        if (preg_match('/^St\.?\s*Mary[’\'s]*$/iu', $location)) {
            return 'St. Mary’s';
        }

        return sanitize_text_field($location);
    }

    private static function semantic_time($time)
    {
        return self::semantic_text((string) $time);
    }

    private static function semantic_text($text)
    {
        $text = strtolower((string) $text);
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
