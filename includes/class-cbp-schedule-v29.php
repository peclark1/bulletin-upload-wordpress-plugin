<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Conservative cleanup for weekly parish-event edge cases.
 *
 * The ordinary event parser intentionally accepts only strongly structured
 * calendar lines. Later historical-recovery passes can nevertheless re-add a
 * duplicate with a less-clean title or attach undated prose to the most recent
 * calendar heading. This pass repairs only deterministic cases and recovers
 * explicit "NO ..." cancellation notices from the dedicated weekly block.
 */
final class CBP_Schedule_V29
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
        add_action('shutdown', array($this, 'postprocess_review'), 300);
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

        $events = isset($review['weekly']['events']) && is_array($review['weekly']['events'])
            ? $review['weekly']['events']
            : array();
        $bulletin_date = (string) $review['bulletin_date'];
        $cleaned = array();

        foreach ($events as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($this->is_dated_prose_leak($row, $bulletin_date)
                || $this->is_first_communion_announcement($row)) {
                continue;
            }
            $cleaned[] = $this->clean_event_row($row);
        }

        foreach ($this->cancellation_rows($this->preview_lines(), $bulletin_date) as $row) {
            $cleaned[] = $row;
        }

        $review['weekly']['events'] = $this->dedupe_rows($cleaned);
        usort($review['weekly']['events'], array($this, 'sort_rows'));

        if (! isset($review['source_lines']) || ! is_array($review['source_lines'])) {
            $review['source_lines'] = array();
        }
        $review['source_lines'][] = '[v29] Applied conservative weekly-event cleanup and cancellation recovery.';
        $review['source_lines'] = array_values(array_unique($review['source_lines']));
        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function clean_event_row(array $row)
    {
        $title = isset($row['title']) ? trim((string) $row['title']) : '';
        $description = isset($row['description']) ? trim((string) $row['description']) : '';

        $heritage_source = $description !== '' ? $description : $title;
        if (preg_match('/^Heritage\s+Living\s+Center\s*:\s*(?:1[0-2]|0?\d):[0-5]\d\s*[ap]\.?m\.?\s+Pray\s+the\s+Rosary\b/iu', $heritage_source)) {
            $row['location'] = 'Heritage Living Center';
            $row['title'] = 'Pray the Rosary';
            return $row;
        }

        if (preg_match('/^(.+?)\s*[-–—]\s*All\s+Men\s+are\s+welcome\b/iu', $title, $matches)) {
            $row['title'] = trim($matches[1]);
        }

        return $row;
    }

    private function is_dated_prose_leak(array $row, $bulletin_date)
    {
        $time = isset($row['time']) ? trim((string) $row['time']) : '';
        $location = isset($row['location']) ? trim((string) $row['location']) : '';
        $title = isset($row['title']) ? trim((string) $row['title']) : '';
        if ($time !== '' || $location !== '' || $title === '') {
            return false;
        }

        $stated_date = $this->date_from_text($title, $bulletin_date);
        $assigned_date = isset($row['date']) ? (string) $row['date'] : '';
        return $stated_date !== '' && $assigned_date !== '' && $stated_date !== $assigned_date;
    }

    private function is_first_communion_announcement(array $row)
    {
        $time = isset($row['time']) ? trim((string) $row['time']) : '';
        $title = isset($row['title']) ? trim((string) $row['title']) : '';
        return $time === '' && preg_match('/^First\s+Communion\s*,\s*\S/iu', $title) === 1;
    }

    private function cancellation_rows(array $lines, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin) {
            return array();
        }
        $week_start = $bulletin->modify('+1 day')->format('Y-m-d');
        $week_end = $bulletin->modify('+7 days')->format('Y-m-d');
        $after_devotion_summary = false;
        $in_calendar = false;
        $current_date = '';
        $rows = array();

        foreach ($lines as $line) {
            if (! $after_devotion_summary) {
                if (preg_match('/^Adoration\s+is\s+held\b/iu', $line)) {
                    $after_devotion_summary = true;
                }
                continue;
            }

            if (preg_match('/^(If\s+you\s+or\s+someone\s+you\s+know\b|St\.?\s*Peter[’\']s\s+Liturgical\s+Schedule\b)/iu', $line)) {
                break;
            }

            $date = $this->date_from_heading($line, $bulletin);
            if ($date !== '') {
                $current_date = $date;
                $in_calendar = $date >= $week_start && $date <= $week_end;
                continue;
            }
            if (! $in_calendar || $current_date === '') {
                continue;
            }
            if (! preg_match('/^NO\s+(.+)$/iu', $line, $matches)) {
                continue;
            }

            $rows[] = array(
                'date' => $current_date,
                'time' => '',
                'location' => '',
                'title' => 'NO ' . trim($matches[1]),
                'description' => trim($line),
            );
        }

        return $rows;
    }

    private function preview_lines()
    {
        $preview = get_transient('cbp_preview_' . get_current_user_id());
        $path = is_array($preview) && ! empty($preview['path']) ? (string) $preview['path'] : '';
        if ($path === '' || ! is_readable($path) || ! class_exists('Smalot\\PdfParser\\Parser')) {
            return array();
        }

        try {
            $parser = new Smalot\PdfParser\Parser();
            $document = $parser->parseFile($path);
            $lines = array();
            foreach ($document->getPages() as $page) {
                $text = str_replace("\0", '', (string) $page->getText());
                $page_lines = preg_split('/\R/u', $text);
                foreach (is_array($page_lines) ? $page_lines : array($text) as $line) {
                    $line = trim((string) preg_replace('/\s+/u', ' ', (string) $line));
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                }
            }
            return $lines;
        } catch (Throwable $e) {
            return array();
        }
    }

    private function date_from_heading($line, DateTimeImmutable $bulletin)
    {
        if (! preg_match('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s*,?\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})\b/iu', $line, $matches)) {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!F j Y', $matches[2] . ' ' . $matches[3] . ' ' . $bulletin->format('Y'));
        if (! $date) {
            return '';
        }
        if ($date < $bulletin->modify('-30 days')) {
            $date = $date->modify('+1 year');
        }
        return $date->format('Y-m-d');
    }

    private function date_from_text($text, $bulletin_date)
    {
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $bulletin || ! preg_match('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})\b/iu', $text, $matches)) {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!F j Y', $matches[1] . ' ' . $matches[2] . ' ' . $bulletin->format('Y'));
        if (! $date) {
            return '';
        }
        if ($date < $bulletin->modify('-30 days')) {
            $date = $date->modify('+1 year');
        }
        return $date->format('Y-m-d');
    }

    private function dedupe_rows(array $rows)
    {
        $selected = array();
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = implode('|', array(
                strtolower(isset($row['date']) ? (string) $row['date'] : ''),
                strtolower(isset($row['location']) ? (string) $row['location'] : ''),
                $this->starting_time($row),
                $this->semantic_title(isset($row['title']) ? (string) $row['title'] : ''),
            ));
            if (! isset($selected[$key]) || $this->row_quality($row) > $this->row_quality($selected[$key])) {
                $selected[$key] = $row;
            }
        }
        return array_values($selected);
    }

    private function semantic_title($title)
    {
        $title = trim((string) $title);
        $title = preg_replace('/^(?:\d{1,2}:\d{2}\s*[AP]M\s*[–—-]\s*\d{1,2}:\d{2}\s*[AP]M|\d{1,2}:\d{2}\s*[AP]M)\s*/iu', '', $title);
        $title = preg_replace('/\s*[-–—]\s*All\s+Men\s+are\s+welcome.*$/iu', '', (string) $title);
        $title = preg_replace('/\s+/u', ' ', (string) $title);
        return strtolower(trim((string) $title, " \t\n\r\0\x0B-–—:;,."));
    }

    private function starting_time(array $row)
    {
        $text = (isset($row['time']) ? (string) $row['time'] : '') . ' ' . (isset($row['title']) ? (string) $row['title'] : '');
        if (! preg_match('/\b(1[0-2]|0?\d):([0-5]\d)\s*([AP])M\b/i', $text, $matches)) {
            return '';
        }
        return sprintf('%d:%02d %sM', (int) $matches[1], (int) $matches[2], strtoupper($matches[3]));
    }

    private function row_quality(array $row)
    {
        $time = isset($row['time']) ? (string) $row['time'] : '';
        $title = isset($row['title']) ? (string) $row['title'] : '';
        $score = 0;
        if (preg_match('/[–—-]/u', $time)) {
            $score += 4;
        }
        if (! preg_match('/^\d{1,2}:\d{2}\s*[AP]M\b/i', $title)) {
            $score += 2;
        }
        if (! empty($row['location'])) {
            $score++;
        }
        return $score;
    }

    private function sort_rows($a, $b)
    {
        $ak = (isset($a['date']) ? (string) $a['date'] : '') . ' '
            . (isset($a['time']) ? (string) $a['time'] : '') . ' '
            . (isset($a['title']) ? (string) $a['title'] : '');
        $bk = (isset($b['date']) ? (string) $b['date'] : '') . ' '
            . (isset($b['time']) ? (string) $b['time'] : '') . ' '
            . (isset($b['title']) ? (string) $b['title'] : '');
        return strcmp($ak, $bk);
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
