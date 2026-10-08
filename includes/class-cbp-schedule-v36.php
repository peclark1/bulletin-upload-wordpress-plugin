<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Removes a narrow class of duplicate event rows left after canonical recovery.
 *
 * Some PDF layouts can produce both:
 * - a correct canonical row with date + time + location + event title, and
 * - a second row at the same date/time where the location text was promoted
 *   into the event title and the location field was left blank.
 *
 * This pass is intentionally generic and conservative: it only removes the
 * blank-location companion when its title exactly matches the canonical
 * location and it carries no independent descriptive content.
 */
final class CBP_Schedule_V36
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
        add_action('shutdown', array($this, 'postprocess_review'), 370);
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

        $events = isset($review['weekly']['events']) && is_array($review['weekly']['events'])
            ? array_values($review['weekly']['events'])
            : array();

        $review['weekly']['events'] = $this->remove_location_title_duplicates($events);

        if (class_exists('CBP_Schedule_V30')) {
            $review['weekly'] = CBP_Schedule_V30::sort_weekly($review['weekly']);
        }

        if (! isset($review['source_lines']) || ! is_array($review['source_lines'])) {
            $review['source_lines'] = array();
        }
        $review['source_lines'][] = '[v36] Removed blank-location duplicates whose event title was the canonical location.';
        $review['source_lines'] = array_values(array_unique($review['source_lines']));

        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function remove_location_title_duplicates(array $events)
    {
        $canonical = array();

        foreach ($events as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = trim((string) ($row['date'] ?? ''));
            $time = trim((string) ($row['time'] ?? ''));
            $location = $this->semantic_text((string) ($row['location'] ?? ''));
            $title = $this->semantic_text((string) ($row['title'] ?? ''));

            if ($date === '' || $time === '' || $location === '' || $title === '' || $location === $title) {
                continue;
            }

            $key = $date . '|' . $time . '|' . $location;
            $canonical[$key] = true;
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
            $time = trim((string) ($row['time'] ?? ''));
            $location = $this->semantic_text((string) ($row['location'] ?? ''));
            $title = $this->semantic_text((string) ($row['title'] ?? ''));
            $description = $this->semantic_text((string) ($row['description'] ?? ''));

            if ($date !== ''
                && $time !== ''
                && $location === ''
                && $title !== ''
                && ($description === '' || $description === $title)
                && isset($canonical[$date . '|' . $time . '|' . $title])) {
                continue;
            }

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
