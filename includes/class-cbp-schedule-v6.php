<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Post-processes V5 review data for recurring worship wording that spans
 * multiple weekdays, e.g. "Adoration ... Wednesdays ... and Thursdays ...".
 *
 * V5 owns the extraction request and redirects after storing the review
 * transient. WordPress still runs shutdown callbacks after that redirect, so
 * this layer can safely refine the stored review without replacing the proven
 * extraction path.
 */
final class CBP_Schedule_V6
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
        if (! is_array($review) || empty($review['weekly']) || ! is_array($review['weekly'])) {
            return;
        }

        // Worship items never belong on the general parish-events page.
        if (! empty($review['weekly']['events']) && is_array($review['weekly']['events'])) {
            $review['weekly']['events'] = array_values(array_filter(
                $review['weekly']['events'],
                array($this, 'is_general_event')
            ));
        }

        $preview = get_transient($this->preview_key());
        if (! is_array($preview) || empty($preview['path']) || ! is_readable($preview['path'])) {
            set_transient($this->review_key(), $review, self::REVIEW_TTL);
            return;
        }

        try {
            $v4 = CBP_Schedule_V4::instance();
            $method = new ReflectionMethod($v4, 'pdf_text_by_page');
            $method->setAccessible(true);
            $extracted = $method->invoke($v4, $preview['path']);
        } catch (Throwable $e) {
            set_transient($this->review_key(), $review, self::REVIEW_TTL);
            return;
        }

        if (is_wp_error($extracted) || empty($extracted['text'])) {
            set_transient($this->review_key(), $review, self::REVIEW_TTL);
            return;
        }

        $text = preg_replace('/\s+/u', ' ', (string) $extracted['text']);
        $time = '(\d{1,2}(?::[0-5][0-9])?\s*(?:a\.?\s*m\.?|p\.?\s*m\.?))';
        $pattern = '/Adoration\s+is\s+held\s+at\s+St\.?\s*Peter[’\'s]*\s+on\s+Wednesdays?\s*,?\s*'
            . $time . '\s*(?:-|–|—|to)\s*' . $time
            . '\s*(?:,?\s*and\s+)?Thursdays?\s+(?:from\s+)?'
            . $time . '\s*(?:-|–|—|to)\s*' . $time . '/iu';

        if (! preg_match($pattern, $text, $m)) {
            set_transient($this->review_key(), $review, self::REVIEW_TTL);
            return;
        }

        $wed_start = $this->normalize_time($m[1]);
        $wed_end = $this->normalize_time($m[2]);
        $thu_start = $this->normalize_time($m[3]);
        $thu_end = $this->normalize_time($m[4]);
        $source = trim($m[0]);

        $week_start = isset($review['week_start']) ? $review['week_start'] : '';
        $week_end = isset($review['week_end']) ? $review['week_end'] : '';
        $wed_date = $this->weekday_in_week($week_start, $week_end, 3);
        $thu_date = $this->weekday_in_week($week_start, $week_end, 4);

        if (! isset($review['weekly']['devotions']) || ! is_array($review['weekly']['devotions'])) {
            $review['weekly']['devotions'] = array();
        }

        // Replace any malformed Adoration row produced by the generic parser
        // with the two explicit recurring rows printed in the bulletin.
        $review['weekly']['devotions'] = array_values(array_filter(
            $review['weekly']['devotions'],
            function ($row) {
                $haystack = strtolower(
                    (isset($row['title']) ? $row['title'] : '') . ' ' .
                    (isset($row['description']) ? $row['description'] : '')
                );
                return strpos($haystack, 'adoration') === false;
            }
        ));

        if ($wed_date !== '') {
            $review['weekly']['devotions'][] = array(
                'date' => $wed_date,
                'time' => $wed_start . '–' . $wed_end,
                'location' => 'St. Peter',
                'title' => 'Adoration',
                'description' => $source,
            );
        }
        if ($thu_date !== '') {
            $review['weekly']['devotions'][] = array(
                'date' => $thu_date,
                'time' => $thu_start . '–' . $thu_end,
                'location' => 'St. Peter',
                'title' => 'Adoration',
                'description' => $source,
            );
        }

        usort($review['weekly']['devotions'], array($this, 'sort_rows'));

        if (! isset($review['candidates']) || ! is_array($review['candidates'])) {
            $review['candidates'] = array();
        }
        $review['candidates']['adoration'] = 'Wednesday ' . $wed_start . '–' . $wed_end
            . '; Thursday ' . $thu_start . '–' . $thu_end;

        if (! isset($review['source_lines']) || ! is_array($review['source_lines'])) {
            $review['source_lines'] = array();
        }
        $review['source_lines'][] = '[v6] ' . $source;
        $review['source_lines'] = array_values(array_unique($review['source_lines']));

        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function is_general_event($row)
    {
        if (! is_array($row)) {
            return false;
        }
        $haystack = strtolower(
            (isset($row['title']) ? $row['title'] : '') . ' ' .
            (isset($row['description']) ? $row['description'] : '')
        );
        return preg_match('/\b(?:mass|reconciliation|confession|adoration|rosary)\b/i', $haystack) !== 1;
    }

    private function weekday_in_week($start, $end, $weekday)
    {
        if (! $this->valid_date($start) || ! $this->valid_date($end)) {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $finish = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        while ($date && $finish && $date <= $finish) {
            if ((int) $date->format('N') === (int) $weekday) {
                return $date->format('Y-m-d');
            }
            $date = $date->modify('+1 day');
        }
        return '';
    }

    private function normalize_time($value)
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace('.', '', $value);
        $value = preg_replace('/\s+/', '', $value);
        if (! preg_match('/^(\d{1,2})(?::([0-5][0-9]))?([ap])m$/i', $value, $m)) {
            return strtoupper($value);
        }
        $minute = ! empty($m[2]) ? $m[2] : '00';
        return ((int) $m[1]) . ':' . $minute . ' ' . strtoupper($m[3]) . 'M';
    }

    private function sort_rows($a, $b)
    {
        $ak = (isset($a['date']) ? $a['date'] : '') . ' ' . (isset($a['time']) ? $a['time'] : '');
        $bk = (isset($b['date']) ? $b['date'] : '') . ' ' . (isset($b['time']) ? $b['time'] : '');
        return strcmp($ak, $bk);
    }

    private function valid_date($date)
    {
        if (! is_string($date) || $date === '') {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private function preview_key()
    {
        return 'cbp_preview_' . get_current_user_id();
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
