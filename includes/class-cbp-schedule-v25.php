<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Preserve the bulletin's apostrophe style in canonical NO MASS descriptions.
 *
 * V24 reconstructs these descriptions from date/location data.  The existing
 * gold fixtures intentionally preserve the source bulletin's straight-vs-curly
 * apostrophe style, so this final cosmetic pass restores that source detail
 * without changing any schedule semantics.
 */
final class CBP_Schedule_V25
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
        add_action('shutdown', array($this, 'postprocess_review'), 260);
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
        if (! is_array($review) || empty($review['weekly']['masses']) || ! is_array($review['weekly']['masses'])) {
            return;
        }

        $raw = array();
        foreach (isset($review['source_lines']) && is_array($review['source_lines']) ? $review['source_lines'] : array() as $line) {
            $line = (string) $line;
            if (strpos($line, '[raw] ') === 0 && stripos($line, 'NO MASS') !== false) {
                $raw[] = substr($line, 6);
            }
        }
        if (empty($raw)) {
            return;
        }

        foreach ($review['weekly']['masses'] as &$mass) {
            if (! is_array($mass) || strcasecmp(isset($mass['title']) ? (string) $mass['title'] : '', 'No Mass') !== 0) {
                continue;
            }
            $location = isset($mass['location']) ? (string) $mass['location'] : '';
            $description = isset($mass['description']) ? (string) $mass['description'] : '';
            foreach ($raw as $line) {
                $same_parish = ($location === 'St. Mary’s' && stripos($line, 'Mary') !== false)
                    || ($location === 'St. Peter' && stripos($line, 'Peter') !== false);
                if (! $same_parish) {
                    continue;
                }
                if (strpos($line, "Peter's") !== false || strpos($line, "Mary's") !== false) {
                    $description = str_replace(array('Peter’s', 'Mary’s'), array("Peter's", "Mary's"), $description);
                }
                break;
            }
            $mass['description'] = $description;
        }
        unset($mass);

        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
