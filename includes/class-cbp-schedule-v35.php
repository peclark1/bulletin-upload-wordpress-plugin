<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Repairs live-PDF date inheritance mistakes introduced while recovering
 * October 11 off-site/after-Mass events.
 *
 * The real PDF column order can place an event line under the wrong previously
 * seen day heading even though the primary parser already has better dated
 * information. This final pass treats dated worship rows as authoritative for
 * off-site Liturgies of the Word and uses actual parish Mass dates to resolve
 * duplicate "... after Mass" events.
 */
final class CBP_Schedule_V35
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
        add_action('shutdown', array($this, 'postprocess_review'), 360);
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

        $masses = isset($review['weekly']['masses']) && is_array($review['weekly']['masses'])
            ? array_values($review['weekly']['masses'])
            : array();
        $events = isset($review['weekly']['events']) && is_array($review['weekly']['events'])
            ? array_values($review['weekly']['events'])
            : array();

        $events = $this->canonicalize_liturgy_events($events, $masses);
        $events = $this->resolve_after_mass_dates($events, $masses);

        $review['weekly']['events'] = $events;

        if (class_exists('CBP_Schedule_V30')) {
            $review['weekly'] = CBP_Schedule_V30::sort_weekly($review['weekly']);
        }

        if (! isset($review['source_lines']) || ! is_array($review['source_lines'])) {
            $review['source_lines'] = array();
        }
        $review['source_lines'][] = '[v35] Reconciled recovered event dates against authoritative worship rows.';
        $review['source_lines'] = array_values(array_unique($review['source_lines']));

        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function canonicalize_liturgy_events(array $events, array $masses)
    {
        foreach ($masses as $mass) {
            if (! is_array($mass)
                || strcasecmp(trim((string) ($mass['title'] ?? '')), 'Liturgy of the Word') !== 0
                || trim((string) ($mass['date'] ?? '')) === '') {
                continue;
            }

            $wanted = array(
                'date' => (string) ($mass['date'] ?? ''),
                'time' => (string) ($mass['time'] ?? ''),
                'location' => $this->event_location((string) ($mass['location'] ?? '')),
                'title' => 'Liturgy of the Word',
                'description' => '',
            );

            $kept = array();
            foreach ($events as $row) {
                if (! is_array($row) || ! $this->looks_like_same_liturgy($row, $wanted)) {
                    $kept[] = $row;
                }
            }
            $kept[] = $wanted;
            $events = $kept;
        }

        return $this->dedupe_exact_rows($events);
    }

    private function looks_like_same_liturgy(array $row, array $wanted)
    {
        $wanted_location = $this->semantic_text((string) ($wanted['location'] ?? ''));
        if ($wanted_location === '') {
            return false;
        }

        $row_location = $this->semantic_text((string) ($row['location'] ?? ''));
        $title = $this->semantic_text((string) ($row['title'] ?? ''));
        $description = $this->semantic_text((string) ($row['description'] ?? ''));
        $wanted_time = trim((string) ($wanted['time'] ?? ''));
        $row_time = trim((string) ($row['time'] ?? ''));

        $mentions_liturgy = strpos($title, 'liturgy of the word') !== false
            || strpos($description, 'liturgy of the word') !== false;
        $mentions_location = $row_location === $wanted_location
            || strpos($title, $wanted_location) !== false
            || strpos($description, $wanted_location) !== false;
        $location_as_title = $title === $wanted_location && $title !== '';

        if ($mentions_liturgy && $mentions_location) {
            return true;
        }

        // The malformed live row promoted "Heritage Living Center" to the
        // title and kept the 10:00 AM time. This is safe to merge only when it
        // also agrees with the canonical service time.
        return $location_as_title
            && $wanted_time !== ''
            && $row_time !== ''
            && $row_time === $wanted_time;
    }

    private function resolve_after_mass_dates(array $events, array $masses)
    {
        $mass_dates = array();
        foreach ($masses as $mass) {
            if (! is_array($mass)
                || strcasecmp(trim((string) ($mass['title'] ?? '')), 'Mass') !== 0
                || trim((string) ($mass['date'] ?? '')) === '') {
                continue;
            }

            $location = $this->semantic_text((string) ($mass['location'] ?? ''));
            if ($location === '') {
                continue;
            }
            if (! isset($mass_dates[$location])) {
                $mass_dates[$location] = array();
            }
            $mass_dates[$location][] = (string) $mass['date'];
        }
        foreach ($mass_dates as $location => $dates) {
            $mass_dates[$location] = array_values(array_unique($dates));
        }

        $groups = array();
        foreach ($events as $index => $row) {
            if (! is_array($row) || strcasecmp(trim((string) ($row['time'] ?? '')), 'After Mass') !== 0) {
                continue;
            }

            $location = $this->semantic_text((string) ($row['location'] ?? ''));
            $title = $this->semantic_text((string) ($row['title'] ?? ''));
            if ($location === '' || $title === '') {
                continue;
            }

            $key = $location . '|' . $title;
            if (! isset($groups[$key])) {
                $groups[$key] = array();
            }
            $groups[$key][] = $index;
        }

        foreach ($groups as $key => $indexes) {
            list($location) = explode('|', $key, 2);
            $valid_dates = isset($mass_dates[$location]) ? $mass_dates[$location] : array();
            if (empty($valid_dates)) {
                continue;
            }

            $preferred_indexes = array();
            foreach ($indexes as $index) {
                if (in_array((string) ($events[$index]['date'] ?? ''), $valid_dates, true)) {
                    $preferred_indexes[] = $index;
                }
            }

            if (! empty($preferred_indexes)) {
                $keep = $preferred_indexes[0];
                foreach ($indexes as $index) {
                    if ($index !== $keep) {
                        $events[$index]['__cbp_delete_v35'] = true;
                    }
                }
                continue;
            }

            // If the parish has exactly one actual Mass date this week, an
            // "after Mass" notice can be safely placed there even when PDF text
            // order inherited the wrong day heading.
            if (count($valid_dates) === 1 && count($indexes) >= 1) {
                $keep = $indexes[0];
                $events[$keep]['date'] = $valid_dates[0];
                foreach ($indexes as $index) {
                    if ($index !== $keep) {
                        $events[$index]['__cbp_delete_v35'] = true;
                    }
                }
            }
        }

        $result = array();
        foreach ($events as $row) {
            if (! is_array($row) || ! empty($row['__cbp_delete_v35'])) {
                continue;
            }
            unset($row['__cbp_delete_v35']);
            $result[] = $row;
        }

        return $this->dedupe_exact_rows($result);
    }

    private function event_location($location)
    {
        $location = trim((string) $location);
        if (preg_match('/^Heritage\s+(?:Senior\s+)?Living\s+Center$/iu', $location)) {
            return 'Heritage Living Center';
        }
        return $location;
    }

    private function dedupe_exact_rows(array $rows)
    {
        $seen = array();
        $result = array();
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = implode('|', array(
                (string) ($row['date'] ?? ''),
                (string) ($row['time'] ?? ''),
                $this->semantic_text((string) ($row['location'] ?? '')),
                $this->semantic_text((string) ($row['title'] ?? '')),
                $this->semantic_text((string) ($row['description'] ?? '')),
            ));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $row;
        }
        return array_values($result);
    }

    private function semantic_text($text)
    {
        $text = strtolower((string) $text);
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
