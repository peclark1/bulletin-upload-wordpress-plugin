<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Removes orphaned wording fragments from worship paragraphs that the PDF
 * extractor can split away from their heading, e.g. the "Thursdays from"
 * tail of a multi-line Adoration sentence.
 */
final class CBP_Schedule_V9
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
        // Run after V8 range/title cleanup.
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
        if (! is_array($review) || empty($review['weekly']['events']) || ! is_array($review['weekly']['events'])) {
            return;
        }

        $review['weekly']['events'] = self::filter_worship_fragments($review['weekly']['events']);
        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    public static function filter_worship_fragments(array $rows)
    {
        $rows = array_values(array_filter($rows, function ($row) {
            return ! self::is_worship_fragment($row);
        }));

        if (class_exists('CBP_Schedule_V8')) {
            $rows = CBP_Schedule_V8::collapse_range_duplicates($rows);
        }

        return $rows;
    }

    private static function is_worship_fragment($row)
    {
        if (! is_array($row)) {
            return true;
        }

        $title = isset($row['title']) ? trim((string) $row['title']) : '';
        $description = isset($row['description']) ? trim((string) $row['description']) : '';

        // A complete worship row is already excluded upstream when it still
        // contains its heading word. Keep that safeguard here as well.
        $combined = $title . ' ' . $description;
        if (preg_match('/\b(?:adoration|rosary|reconciliation|confession|mass)\b/iu', $combined)) {
            return true;
        }

        // PDF column extraction can detach the second clause of wording such as:
        // "Adoration ... on Wednesdays ... and Thursdays from 6:00 am to 5:00 pm."
        // The detached event title then becomes only "Thursdays from". Those are
        // grammatical continuations, not standalone parish events.
        $weekday = '(?:mon(?:day)?s?|tue(?:sday)?s?|wed(?:nesday)?s?|thu(?:rsday)?s?|fri(?:day)?s?|sat(?:urday)?s?|sun(?:day)?s?)';
        $fragment_pattern = '/^\s*(?:and\s+)?' . $weekday . '(?:\s+from)?\s*[:;,.-]*\s*$/iu';

        if ($title !== '' && preg_match($fragment_pattern, $title)) {
            return true;
        }
        if ($description !== '' && preg_match($fragment_pattern, $description)) {
            return true;
        }

        return false;
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
