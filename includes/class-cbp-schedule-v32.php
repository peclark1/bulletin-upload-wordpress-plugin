<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Removes weekly event rows that are actually dated prose announcements.
 *
 * The October 4 live PDF exposed a normalized row where the parser assigned
 * the announcement to October 10, extracted "Heritage Living Center" as the
 * event title, and left the full "We will meet Monday, October 26" sentence in
 * details. V31 only rejected the case where title and details were identical.
 * This pass also rejects the common title-is-a-prefix-of-details shape, while
 * requiring an explicit conflicting date and no extracted location.
 */
final class CBP_Schedule_V32
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
        add_action('shutdown', array($this, 'postprocess_review'), 330);
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

        if (! empty($review['weekly']['events']) && is_array($review['weekly']['events'])) {
            $bulletin_date = (string) $review['bulletin_date'];
            $review['weekly']['events'] = array_values(array_filter(
                $review['weekly']['events'],
                function ($row) use ($bulletin_date) {
                    return ! is_array($row)
                        || ! $this->is_mismatched_dated_event($row, $bulletin_date);
                }
            ));
        }

        if (class_exists('CBP_Schedule_V30') && ! empty($review['weekly']) && is_array($review['weekly'])) {
            $review['weekly'] = CBP_Schedule_V30::sort_weekly($review['weekly']);
        }

        $review['source_lines'] = isset($review['source_lines']) && is_array($review['source_lines'])
            ? $review['source_lines']
            : array();
        $review['source_lines'][] = '[v32] Removed normalized weekly rows whose prose states a conflicting date.';
        $review['source_lines'] = array_values(array_unique($review['source_lines']));

        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function is_mismatched_dated_event(array $row, $bulletin_date)
    {
        $assigned = trim((string) ($row['date'] ?? ''));
        $location = trim((string) ($row['location'] ?? ''));
        $title = trim((string) ($row['title'] ?? ''));
        $description = trim((string) ($row['description'] ?? ''));

        if ($assigned === '' || $location !== '' || $description === '') {
            return false;
        }

        $stated = $this->date_from_text($title . ' ' . $description, $bulletin_date);
        if ($stated === '' || $stated === $assigned) {
            return false;
        }

        $semantic_title = $this->semantic_text($title);
        $semantic_description = $this->semantic_text($description);

        if (strlen($semantic_title) < 12 || strlen($semantic_description) < 12) {
            return false;
        }

        // The event parser often promotes the leading noun phrase from a prose
        // announcement into the title while retaining the whole sentence in
        // details. Require that relationship before removing the row.
        return strpos($semantic_description, $semantic_title) !== false
            || strpos($semantic_title, $semantic_description) !== false;
    }

    private function date_from_text($text, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin
            || ! preg_match('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(?:st|nd|rd|th)?\b/iu', (string) $text, $m)) {
            return '';
        }

        $date = DateTimeImmutable::createFromFormat(
            '!F j Y',
            $m[1] . ' ' . $m[2] . ' ' . $bulletin->format('Y')
        );
        if (! $date) {
            return '';
        }
        if ($date < $bulletin->modify('-30 days')) {
            $date = $date->modify('+1 year');
        }
        return $date->format('Y-m-d');
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
