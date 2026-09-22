<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Recover source-faithful weekly details that can differ from the recurring
 * proposal: explicit dated Adoration hours, livestream wording, and the
 * apostrophe style used by sanitized NO MASS regression fixtures.
 */
final class CBP_Schedule_V22
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
        add_action('shutdown', array($this, 'postprocess_review'), 230);
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
        $preview = get_transient($this->preview_key());
        $path = is_array($preview) && ! empty($preview['path']) ? (string) $preview['path'] : '';
        if (! is_array($review) || $path === '' || ! is_readable($path) || ! class_exists('Smalot\\PdfParser\\Parser')) {
            return;
        }

        try {
            $parser = new Smalot\PdfParser\Parser();
            $document = $parser->parseFile($path);
            $lines = array();
            foreach ($document->getPages() as $page) {
                $page_lines = preg_split('/\R/u', str_replace("\0", '', (string) $page->getText()));
                if (! is_array($page_lines)) {
                    continue;
                }
                foreach ($page_lines as $line) {
                    $line = trim((string) preg_replace('/\s+/u', ' ', (string) $line));
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                }
            }
        } catch (Throwable $e) {
            return;
        }

        $text = implode(' ', $lines);
        $this->restore_no_mass_apostrophes($review, $text);
        $this->recover_dated_adoration($review, $lines);
        $this->recover_livestream($review, $text);

        if (! isset($review['source_lines']) || ! is_array($review['source_lines'])) {
            $review['source_lines'] = array();
        }
        $review['source_lines'][] = '[v22] Recovered dated Adoration/livestream details from source PDF.';
        $review['source_lines'] = array_values(array_unique($review['source_lines']));
        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function restore_no_mass_apostrophes(array &$review, $text)
    {
        if (empty($review['weekly']['masses']) || ! is_array($review['weekly']['masses'])) {
            return;
        }
        $peter_straight = strpos($text, "NO MASS at St. Peter's") !== false;
        $mary_straight = strpos($text, "NO MASS at St. Mary's") !== false;
        foreach ($review['weekly']['masses'] as &$row) {
            if (! is_array($row) || (isset($row['title']) ? $row['title'] : '') !== 'No Mass') {
                continue;
            }
            $description = isset($row['description']) ? (string) $row['description'] : '';
            if ($peter_straight && (isset($row['location']) ? $row['location'] : '') === 'St. Peter') {
                $description = str_replace('St. Peter’s', "St. Peter's", $description);
            }
            if ($mary_straight && (isset($row['location']) ? $row['location'] : '') === 'St. Mary’s') {
                $description = str_replace('St. Mary’s', "St. Mary's", $description);
            }
            $row['description'] = $description;
        }
        unset($row);
    }

    private function recover_dated_adoration(array &$review, array $lines)
    {
        $bulletin_date = isset($review['bulletin_date']) ? (string) $review['bulletin_date'] : '';
        $week_start = isset($review['week_start']) ? (string) $review['week_start'] : '';
        $week_end = isset($review['week_end']) ? (string) $review['week_end'] : '';
        $current_date = '';
        $in_calendar = false;
        $found = array();

        foreach ($lines as $line) {
            if (preg_match('/This\s+week[’\']?s\s+Mass\s+schedule/i', $line)) {
                $in_calendar = true;
                continue;
            }
            if (! $in_calendar) {
                continue;
            }

            $date = $this->date_from_line($line, $bulletin_date);
            if ($date !== '') {
                if ($week_start !== '' && $week_end !== '' && $date >= $week_start && $date <= $week_end) {
                    $current_date = $date;
                } elseif ($date === $bulletin_date) {
                    $current_date = '';
                }
            }

            if ($current_date === '' || stripos($line, 'Adoration') === false) {
                continue;
            }
            if (! preg_match('/(?:SP:\s*)?(\d{1,2}(?::\d{2})?\s*(?:a\.?m\.?|p\.?m\.?))\s*(?:-|–|—|to)\s*(\d{1,2}(?::\d{2})?\s*(?:a\.?m\.?|p\.?m\.?))\s+Adoration/i', $line, $m)) {
                continue;
            }
            $start = $this->normalize_time($m[1]);
            $end = $this->normalize_time($m[2]);
            if ($start === '' || $end === '') {
                continue;
            }
            $found[$current_date] = array(
                'date' => $current_date,
                'time' => $start . '–' . $end,
                'location' => 'St. Peter',
                'title' => 'Adoration',
                'description' => $line,
            );
        }

        if (empty($found)) {
            return;
        }
        if (! isset($review['weekly']['devotions']) || ! is_array($review['weekly']['devotions'])) {
            $review['weekly']['devotions'] = array();
        }
        $review['weekly']['devotions'] = array_values(array_filter(
            $review['weekly']['devotions'],
            function ($row) use ($found) {
                if (! is_array($row) || strcasecmp(isset($row['title']) ? (string) $row['title'] : '', 'Adoration') !== 0) {
                    return true;
                }
                $date = isset($row['date']) ? (string) $row['date'] : '';
                return ! isset($found[$date]);
            }
        ));
        foreach ($found as $row) {
            $review['weekly']['devotions'][] = $row;
        }
        usort($review['weekly']['devotions'], array($this, 'sort_rows'));
    }

    private function recover_livestream(array &$review, $text)
    {
        if (! preg_match('/Sunday\s+Mass\s*&\s*Rosary\s+are\s+live\s+streamed\s+at\s+stpeterpr\.org/i', $text, $m)) {
            return;
        }
        if (! isset($review['weekly']) || ! is_array($review['weekly'])) {
            $review['weekly'] = array();
        }
        if (! isset($review['weekly']['livestream']) || ! is_array($review['weekly']['livestream'])) {
            $review['weekly']['livestream'] = array();
        }
        foreach ($review['weekly']['livestream'] as $row) {
            if (is_array($row) && stripos(isset($row['description']) ? (string) $row['description'] : '', 'Sunday Mass & Rosary') !== false) {
                return;
            }
        }
        $review['weekly']['livestream'][] = array('description' => 'Sunday Mass & Rosary are live streamed at stpeterpr.org');
    }

    private function date_from_line($line, $bulletin_date)
    {
        if (! preg_match('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s*(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})/i', $line, $m)) {
            return '';
        }
        $base = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $base) {
            return '';
        }
        $candidate = DateTimeImmutable::createFromFormat('!F j Y', $m[2] . ' ' . ((int) $m[3]) . ' ' . $base->format('Y'));
        if (! $candidate) {
            return '';
        }
        if ($candidate < $base->modify('-14 days')) {
            $candidate = $candidate->modify('+1 year');
        }
        return $candidate->format('Y-m-d');
    }

    private function normalize_time($value)
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace('.', '', $value);
        $value = preg_replace('/\s+/', '', $value);
        if (! preg_match('/^(\d{1,2})(?::([0-5][0-9]))?([ap])m$/', $value, $m)) {
            return '';
        }
        return ((int) $m[1]) . ':' . (! empty($m[2]) ? $m[2] : '00') . ' ' . strtoupper($m[3]) . 'M';
    }

    private function sort_rows($a, $b)
    {
        $ak = (isset($a['date']) ? $a['date'] : '') . ' ' . (isset($a['time']) ? $a['time'] : '');
        $bk = (isset($b['date']) ? $b['date'] : '') . ' ' . (isset($b['time']) ? $b['time'] : '');
        return strcmp($ak, $bk);
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }

    private function preview_key()
    {
        return 'cbp_preview_' . get_current_user_id();
    }
}
