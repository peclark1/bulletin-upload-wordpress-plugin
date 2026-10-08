<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Handles future-dated announcements split across adjacent PDF text lines.
 *
 * The October 4 live PDF can split the Heritage Living Center announcement so
 * the event row itself no longer contains "October 26", even though the nearby
 * source text does. Build short adjacent-line windows from stored extraction
 * sources and reject a blank-location weekly event when its title clearly belongs
 * to an announcement whose stated date falls outside the current bulletin week.
 */
final class CBP_Schedule_V33
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
        add_action('shutdown', array($this, 'postprocess_review'), 340);
    }

    public function postprocess_review()
    {
        if (! CBP_Parser_Context::allows_legacy_postprocess()) {
            return;
        }

        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if ($action !== 'cbp_extract_schedule') {
            return;
        }

        $review = get_transient($this->review_key());
        if (! is_array($review)
            || empty($review['bulletin_date'])
            || empty($review['weekly']['events'])
            || ! is_array($review['weekly']['events'])) {
            return;
        }

        $raw = $this->raw_lines($review);
        $windows = $this->future_dated_windows($raw, (string) $review['bulletin_date']);
        if (empty($windows)) {
            return;
        }

        $review['weekly']['events'] = array_values(array_filter(
            $review['weekly']['events'],
            function ($row) use ($windows) {
                return ! is_array($row) || ! $this->matches_future_window($row, $windows);
            }
        ));

        if (class_exists('CBP_Schedule_V30') && ! empty($review['weekly']) && is_array($review['weekly'])) {
            $review['weekly'] = CBP_Schedule_V30::sort_weekly($review['weekly']);
        }

        if (! isset($review['source_lines']) || ! is_array($review['source_lines'])) {
            $review['source_lines'] = array();
        }
        $review['source_lines'][] = '[v33] Removed weekly event rows matched to split-line future announcements.';
        $review['source_lines'] = array_values(array_unique($review['source_lines']));

        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function raw_lines(array $review)
    {
        $raw = array();
        foreach (($review['source_lines'] ?? array()) as $line) {
            $line = trim((string) $line);
            if ($line === '' || preg_match('/^\[v\d+\]/i', $line)) {
                continue;
            }

            // Some parser generations store source excerpts directly, while
            // others prefix diagnostic raw lines with "[raw] ". Accept both
            // shapes so the live WordPress review data is usable here.
            if (strpos($line, '[raw] ') === 0) {
                $line = trim(substr($line, 6));
            }

            if ($line !== '') {
                $raw[] = $line;
            }
        }
        return $raw;
    }

    private function future_dated_windows(array $lines, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) {
            return array();
        }

        $week_start = $bulletin->modify('+1 day')->format('Y-m-d');
        $week_end = $bulletin->modify('+7 days')->format('Y-m-d');
        $windows = array();
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $combined = '';
            for ($span = 0; $span < 4 && ($i + $span) < $count; $span++) {
                $combined = trim($combined . ' ' . (string) $lines[$i + $span]);
                $date = $this->date_from_text($combined, $bulletin_date);
                if ($date === '') {
                    continue;
                }
                if ($date > $week_end) {
                    $normalized = $this->semantic_text($combined);
                    if ($normalized !== '') {
                        $windows[] = $normalized;
                    }
                }
            }
        }

        return array_values(array_unique($windows));
    }

    private function matches_future_window(array $row, array $windows)
    {
        $location = trim((string) ($row['location'] ?? ''));
        $title = $this->semantic_text((string) ($row['title'] ?? ''));
        $description = $this->semantic_text((string) ($row['description'] ?? ''));

        // This fallback is intentionally narrow: the bad live row had no
        // location and a descriptive noun-phrase title. Do not touch normal
        // parish calendar rows that already have an extracted location.
        if ($location !== '' || strlen($title) < 12) {
            return false;
        }

        foreach ($windows as $window) {
            if (strpos($window, $title) === false) {
                continue;
            }

            if ($description === '' || strpos($window, substr($description, 0, min(36, strlen($description)))) !== false) {
                return true;
            }

            // The PDF parser may truncate the details before the date while the
            // raw adjacent-line window still contains the full announcement.
            if (strpos($window, $title) === 0 || strpos($window, ' ' . $title . ' ') !== false) {
                return true;
            }
        }

        return false;
    }

    private function date_from_text($text, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin
            || ! preg_match('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(?:st|nd|rd|th)?\b/iu', (string) $text, $matches)) {
            return '';
        }

        $date = DateTimeImmutable::createFromFormat(
            '!F j Y',
            $matches[1] . ' ' . $matches[2] . ' ' . $bulletin->format('Y')
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
