<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Semantic-deduplication pass for Mass intention recovery.
 *
 * The review source intentionally contains both parser diagnostics/raw lines
 * and the normalized source lines used by the UI. That means the same Mass
 * can appear more than once. V18 correctly reconstructs the candidates, but
 * deliberately refuses to fill a blank intention when more than one match is
 * present. This pass collapses identical candidates before matching.
 *
 * It still never overwrites an already-good intention. If distinct candidate
 * intentions remain for the same Mass after deduplication, the field stays
 * unchanged for human review.
 */
final class CBP_Schedule_V19
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
        add_action('shutdown', array($this, 'postprocess_review'), 180);
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

        $bulletin_date = isset($review['bulletin_date']) ? (string) $review['bulletin_date'] : '';
        if ($bulletin_date === '') {
            return;
        }

        try {
            $v18 = CBP_Schedule_V18::instance();
            $source_method = new ReflectionMethod($v18, 'source_lines');
            $source_method->setAccessible(true);
            $candidate_method = new ReflectionMethod($v18, 'candidates_from_source_lines');
            $candidate_method->setAccessible(true);
            $normalize_intention_method = new ReflectionMethod($v18, 'normalize_intention');
            $normalize_intention_method->setAccessible(true);
            $good_method = new ReflectionMethod($v18, 'good_existing_intention');
            $good_method->setAccessible(true);
            $normalize_time_method = new ReflectionMethod($v18, 'normalize_time');
            $normalize_time_method->setAccessible(true);
            $location_method = new ReflectionMethod($v18, 'canonical_location');
            $location_method->setAccessible(true);

            $lines = $source_method->invoke($v18, $review);
            $candidates = $candidate_method->invoke($v18, $lines, $bulletin_date);
        } catch (Throwable $e) {
            return;
        }

        if (! is_array($candidates) || empty($candidates)) {
            return;
        }

        // The same schedule appears in both [raw] diagnostics and normalized
        // review lines. Collapse exact semantic duplicates before matching.
        $unique = array();
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $key = strtolower(implode('|', array(
                isset($candidate['date']) ? trim((string) $candidate['date']) : '',
                isset($candidate['time']) ? trim((string) $candidate['time']) : '',
                isset($candidate['location']) ? trim((string) $candidate['location']) : '',
                isset($candidate['intention']) ? trim((string) $candidate['intention']) : '',
            )));
            if ($key !== '') {
                $unique[$key] = $candidate;
            }
        }
        $candidates = array_values($unique);

        foreach ($review['weekly']['masses'] as &$mass) {
            if (! is_array($mass)) {
                continue;
            }

            $existing = isset($mass['description'])
                ? (string) $normalize_intention_method->invoke($v18, $mass['description'])
                : '';

            if ((bool) $good_method->invoke($v18, $existing)) {
                $mass['description'] = $existing;
                continue;
            }

            $date = isset($mass['date']) ? trim((string) $mass['date']) : '';
            $time = isset($mass['time'])
                ? (string) $normalize_time_method->invoke($v18, $mass['time'])
                : '';
            $location = isset($mass['location'])
                ? (string) $location_method->invoke($v18, $mass['location'])
                : '';

            if ($date === '' || $time === '') {
                continue;
            }

            $matches = array_values(array_filter($candidates, function ($candidate) use ($date, $time) {
                return isset($candidate['date'], $candidate['time'], $candidate['intention'])
                    && $candidate['date'] === $date
                    && $candidate['time'] === $time
                    && trim((string) $candidate['intention']) !== '';
            }));

            if ($location !== '') {
                $same_location = array_values(array_filter($matches, function ($candidate) use ($location) {
                    $candidate_location = isset($candidate['location']) ? (string) $candidate['location'] : '';
                    return $candidate_location === '' || $candidate_location === $location;
                }));
                if (! empty($same_location)) {
                    $matches = $same_location;
                }
            }

            // If multiple source copies survive but all agree on the same
            // intention, that agreement is safe to use. Distinct intentions
            // remain unresolved for human review.
            $intentions = array();
            foreach ($matches as $candidate) {
                $value = trim((string) $candidate['intention']);
                if ($value !== '') {
                    $intentions[strtolower($value)] = $value;
                }
            }

            if (count($intentions) === 1) {
                $mass['description'] = reset($intentions);
            } else {
                $mass['description'] = $existing;
            }
        }
        unset($mass);

        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
