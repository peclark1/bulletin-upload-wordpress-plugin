<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Final cleanup for location-as-title companion rows whose details contain
 * only the same location plus the same time.
 *
 * Example live-PDF failure:
 *   date=2026-10-14, time=10:00 AM, location="", title="Heritage Living Center",
 *   description="Heritage Living Center: 10:00 am"
 *
 * alongside the canonical row:
 *   date=2026-10-14, time=10:00 AM, location="Heritage Living Center",
 *   title="Liturgy of the Word"
 *
 * This pass removes only the redundant companion. Any extra independent text
 * causes the row to be preserved.
 */
final class CBP_Schedule_V37
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
        add_action('shutdown', array($this, 'postprocess_review'), 380);
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

        $review['weekly']['events'] = $this->remove_location_time_detail_duplicates($events);

        if (class_exists('CBP_Schedule_V30')) {
            $review['weekly'] = CBP_Schedule_V30::sort_weekly($review['weekly']);
        }

        if (! isset($review['source_lines']) || ! is_array($review['source_lines'])) {
            $review['source_lines'] = array();
        }
        $review['source_lines'][] = '[v37] Removed redundant location-as-event rows whose details repeated only the same location and time.';
        $review['source_lines'] = array_values(array_unique($review['source_lines']));

        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function remove_location_time_detail_duplicates(array $events)
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

            $canonical[$date . '|' . $this->semantic_time($time) . '|' . $location] = true;
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
            $semantic_time = $this->semantic_time($time);

            if ($date !== ''
                && $time !== ''
                && $location === ''
                && $title !== ''
                && isset($canonical[$date . '|' . $semantic_time . '|' . $title])
                && $this->description_is_only_location_and_time($description, $title, $semantic_time)) {
                continue;
            }

            $result[] = $row;
        }

        return array_values($result);
    }

    private function description_is_only_location_and_time($description, $title, $time)
    {
        $description = trim((string) $description);
        $title = trim((string) $title);
        $time = trim((string) $time);

        if ($description === '' || $title === '' || $time === '') {
            return false;
        }

        return $description === trim($title . ' ' . $time)
            || $description === trim($time . ' ' . $title);
    }

    private function semantic_time($time)
    {
        return $this->semantic_text((string) $time);
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
