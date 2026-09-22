<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Review-safety improvements for the real parish bulletin workflow.
 *
 * - derives proposed weekend recurring Mass times only from ordinary Mass rows
 *   already extracted from the dedicated "This week's Mass schedule" section
 * - captures Mass intentions into the weekly Mass Details field
 * - flags weekday/date disagreements instead of silently correcting them
 * - highlights recurring schedule changes for explicit human review
 */
final class CBP_Schedule_V12
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
        add_action('shutdown', array($this, 'postprocess_review'), 40);
        add_action('admin_footer-toplevel_page_church-bulletin-publisher', array($this, 'render_review_enhancements'), 95);
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

        $warnings = isset($review['warnings']) && is_array($review['warnings']) ? $review['warnings'] : array();
        $lines = $this->review_source_lines($review);

        if (! empty($review['weekly']['masses']) && is_array($review['weekly']['masses'])) {
            $review['weekly']['masses'] = $this->capture_mass_intentions($review['weekly']['masses']);
            $this->repair_recurring_mass_candidates($review, $warnings);
        }

        $bulletin_date = isset($review['bulletin_date']) ? (string) $review['bulletin_date'] : '';
        if ($bulletin_date !== '' && ! empty($lines)) {
            $warnings = array_merge($warnings, $this->weekday_date_warnings($lines, $bulletin_date));
        }

        $review['warnings'] = $this->dedupe_warnings($warnings);
        set_transient($this->review_key(), $review, self::REVIEW_TTL);
    }

    private function capture_mass_intentions(array $masses)
    {
        foreach ($masses as &$mass) {
            if (! is_array($mass)) {
                continue;
            }

            $title = isset($mass['title']) ? trim((string) $mass['title']) : '';
            if (strcasecmp($title, 'No Mass') === 0) {
                continue;
            }

            $description = isset($mass['description']) ? trim((string) $mass['description']) : '';
            if ($description === '') {
                continue;
            }

            $intention = $this->intention_from_mass_text($description, $title);
            if ($intention !== '') {
                $mass['description'] = $intention;
            }
        }
        unset($mass);

        return $masses;
    }

    private function intention_from_mass_text($text, $title)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        if (stripos((string) $title . ' ' . $text, 'funeral') !== false) {
            $remainder = $this->strip_mass_prefix($text);
            if (preg_match('/^(.+?)\s+funeral(?:\s+mass)?(?:\s+at\s+st\.?\s*(?:peter|mary)(?:[’\']s)?)?\s*$/iu', $remainder, $m)) {
                $name = $this->clean_edge_punctuation($m[1]);
                return $this->plausible_intention($name) ? $name : '';
            }
        }

        if (preg_match('/([+†])\s*([^|]+?)\s*$/u', $text, $m)) {
            $value = trim($m[1] . ' ' . trim($m[2]));
            return $this->plausible_intention($value) ? $value : '';
        }

        if (preg_match('/\bSt\.?\s*(?:Peter|Mary)(?:[’\']s)?\b\s*[,;:\-–—]*\s*(.+?)\s*$/iu', $text, $m)) {
            $value = $this->clean_edge_punctuation($m[1]);
            if ($this->plausible_intention($value)) {
                return $value;
            }
        }

        return '';
    }

    private function strip_mass_prefix($text)
    {
        $text = trim((string) $text);
        $text = preg_replace('/^(?:Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?)[\s,.-]*/iu', '', $text);
        $text = preg_replace('/^(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?\s+\d{1,2}(?:st|nd|rd|th)?[\s,.-]*/iu', '', $text);
        $text = preg_replace('/^\d{1,2}(?::\d{2})?\s*(?:a\.?m\.?|p\.?m\.?)\s*[,;:–—-]*\s*/iu', '', $text);
        return trim((string) $text);
    }

    private function clean_edge_punctuation($value)
    {
        $value = trim((string) $value);
        return trim((string) preg_replace('/^[\s,;:\-–—]+|[\s,;:\-–—]+$/u', '', $value));
    }

    private function plausible_intention($value)
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 120) {
            return false;
        }
        if (preg_match('/\b(?:adoration|reconciliation|rosary|liturgical schedule|lector|usher|greeter|stewardship)\b/iu', $value)) {
            return false;
        }
        return true;
    }

    private function repair_recurring_mass_candidates(array &$review, array &$warnings)
    {
        if (empty($review['effective_date']) || ! $this->valid_date($review['effective_date'])) {
            return;
        }

        $saturday = (string) $review['effective_date'];
        $sat = DateTimeImmutable::createFromFormat('!Y-m-d', $saturday);
        if (! $sat) {
            return;
        }
        $sunday = $sat->modify('+1 day')->format('Y-m-d');
        $masses = isset($review['weekly']['masses']) && is_array($review['weekly']['masses']) ? $review['weekly']['masses'] : array();

        $targets = array(
            'st_peter_saturday' => array('label' => 'St. Peter Saturday Mass', 'date' => $saturday, 'location' => 'peter'),
            'st_peter_sunday' => array('label' => 'St. Peter Sunday Mass', 'date' => $sunday, 'location' => 'peter'),
            'st_mary_sunday' => array('label' => 'St. Mary’s Sunday Mass', 'date' => $sunday, 'location' => 'mary'),
        );

        $current = wp_parse_args(get_option(CBP_Schedule::OPTION, array()), CBP_Schedule::defaults());
        if (! isset($review['candidates']) || ! is_array($review['candidates'])) {
            $review['candidates'] = array();
        }
        if (! isset($review['recurring_sources']) || ! is_array($review['recurring_sources'])) {
            $review['recurring_sources'] = array();
        }

        foreach ($targets as $key => $target) {
            $matches = array();
            foreach ($masses as $mass) {
                if (! is_array($mass) || ! $this->is_ordinary_mass($mass)) {
                    continue;
                }
                $date = isset($mass['date']) ? (string) $mass['date'] : '';
                $location = $this->canonical_location(isset($mass['location']) ? $mass['location'] : '');
                $time = isset($mass['time']) ? $this->normalize_time($mass['time']) : '';
                if ($date === $target['date'] && $location === $target['location'] && $time !== '') {
                    $mass['time'] = $time;
                    $matches[] = $mass;
                }
            }

            if (count($matches) === 1) {
                $proposed = $matches[0]['time'];
                $old_candidate = isset($review['candidates'][$key]) ? $this->normalize_time($review['candidates'][$key]) : '';
                $review['candidates'][$key] = $proposed;
                $review['recurring_sources'][$key] = 'ordinary weekend Mass in this bulletin';

                if ($old_candidate !== '' && $old_candidate !== $proposed) {
                    $warnings[] = array(
                        'type' => 'parser_correction',
                        'message' => sprintf('%s was initially parsed as %s; the dedicated weekly Mass schedule supports %s.', $target['label'], $old_candidate, $proposed),
                        'source' => $this->mass_source_summary($matches[0]),
                    );
                }

                $current_value = isset($current[$key]) ? $this->normalize_time($current[$key]) : '';
                if ($current_value !== '' && $current_value !== $proposed) {
                    $warnings[] = array(
                        'type' => 'schedule_change',
                        'message' => sprintf('%s is currently %s on the website; this bulletin proposes %s. Confirm whether this is a standing schedule change or only a one-week exception.', $target['label'], $current_value, $proposed),
                        'source' => $this->mass_source_summary($matches[0]),
                    );
                }
            } elseif (count($matches) > 1) {
                $warnings[] = array(
                    'type' => 'ambiguous_schedule',
                    'message' => sprintf('More than one ordinary Mass could establish %s. The recurring value was not changed automatically.', $target['label']),
                    'source' => $target['date'],
                );
            }
        }
    }

    private function is_ordinary_mass(array $mass)
    {
        $title = isset($mass['title']) ? trim((string) $mass['title']) : '';
        $description = isset($mass['description']) ? trim((string) $mass['description']) : '';
        $combined = $title . ' ' . $description;

        if (preg_match('/\b(?:funeral|wedding|nuptial|no mass|memorial service)\b/iu', $combined)) {
            return false;
        }

        return $title === '' || strcasecmp($title, 'Mass') === 0;
    }

    private function mass_source_summary(array $mass)
    {
        return trim(implode(' • ', array_filter(array(
            isset($mass['date']) ? $mass['date'] : '',
            isset($mass['time']) ? $mass['time'] : '',
            isset($mass['location']) ? $mass['location'] : '',
            isset($mass['description']) ? $mass['description'] : '',
        ))));
    }

    private function canonical_location($location)
    {
        $location = trim((string) $location);
        if (preg_match('/\bSt\.?\s*Peter\b/iu', $location)) {
            return 'peter';
        }
        if (preg_match('/\bSt\.?\s*Mary/iu', $location)) {
            return 'mary';
        }
        return '';
    }

    private function normalize_time($time)
    {
        $time = strtoupper(preg_replace('/\s+/', ' ', trim((string) $time)));
        if (! preg_match('/^(\d{1,2})(?::(\d{2}))?\s*([AP]M)$/', $time, $m)) {
            return $time;
        }
        $minute = isset($m[2]) && $m[2] !== '' ? $m[2] : '00';
        return ((int) $m[1]) . ':' . $minute . ' ' . $m[3];
    }

    private function weekday_date_warnings(array $lines, $bulletin_date)
    {
        $warnings = array();
        $base = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $base) {
            return $warnings;
        }

        $weekday_pattern = '(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|Mon|Tue|Tues|Wed|Thu|Thur|Thurs|Fri|Sat|Sun)';
        $month_pattern = '(January|February|March|April|May|June|July|August|September|Sept|Sep|October|Oct|November|Nov|December|Dec)';

        foreach ($lines as $line) {
            if (! preg_match('/\b' . $weekday_pattern . '\.?\s*,?\s+' . $month_pattern . '\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b/iu', (string) $line, $m)) {
                continue;
            }

            $claimed = $this->weekday_number($m[1]);
            $month = $this->month_number($m[2]);
            $day = (int) $m[3];
            $year = (int) $base->format('Y');
            $candidate = DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-' . $day);
            if (! $candidate) {
                continue;
            }

            if ($candidate < $base->modify('-60 days')) {
                $candidate = $candidate->modify('+1 year');
            } elseif ($candidate > $base->modify('+300 days')) {
                $candidate = $candidate->modify('-1 year');
            }

            $actual = (int) $candidate->format('N');
            if ($claimed === $actual) {
                continue;
            }

            $source = trim((string) $line);
            $warnings[] = array(
                'type' => 'date_mismatch',
                'message' => sprintf('Date/day mismatch: “%s” says %s, but %s is %s. Review the events under this heading before approving.', $source, $this->weekday_name($claimed), $candidate->format('F j, Y'), $candidate->format('l')),
                'source' => $source,
            );
        }

        return $warnings;
    }

    private function review_source_lines(array $review)
    {
        $lines = array();
        if (empty($review['source_lines']) || ! is_array($review['source_lines'])) {
            return $lines;
        }

        foreach ($review['source_lines'] as $line) {
            $line = trim((string) $line);
            if ($line === '' || strpos($line, '[debug]') === 0) {
                continue;
            }
            if (strpos($line, '[raw] ') === 0) {
                $line = substr($line, 6);
            }
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return array_values(array_unique($lines));
    }

    private function dedupe_warnings(array $warnings)
    {
        $seen = array();
        $result = array();
        foreach ($warnings as $warning) {
            if (! is_array($warning)) {
                continue;
            }
            $message = isset($warning['message']) ? trim((string) $warning['message']) : '';
            if ($message === '') {
                continue;
            }
            $key = strtolower($message);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = array(
                'type' => isset($warning['type']) ? sanitize_key($warning['type']) : 'review',
                'message' => sanitize_text_field($message),
                'source' => isset($warning['source']) ? sanitize_text_field($warning['source']) : '',
            );
        }
        return $result;
    }

    public function render_review_enhancements()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $review = get_transient($this->review_key());
        if (! is_array($review)) {
            return;
        }
        $warnings = isset($review['warnings']) && is_array($review['warnings']) ? $review['warnings'] : array();
        ?>
        <?php if (! empty($warnings)) : ?>
        <div id="cbp-parser-review-warnings" class="notice notice-warning inline" style="display:none; margin:14px 0 20px; padding:10px 14px;">
            <p><strong><?php esc_html_e('Parser review warnings', 'church-bulletin-publisher'); ?></strong></p>
            <ul style="margin-left:20px; list-style:disc;">
                <?php foreach ($warnings as $warning) : ?>
                    <li style="margin-bottom:8px;">
                        <?php echo esc_html(isset($warning['message']) ? $warning['message'] : ''); ?>
                        <?php if (! empty($warning['source'])) : ?>
                            <br><small><?php echo esc_html(sprintf(__('Source: %s', 'church-bulletin-publisher'), $warning['source'])); ?></small>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p style="margin-bottom:0;"><strong><?php esc_html_e('Warnings do not change the bulletin automatically. Correct or confirm the proposed values before approval.', 'church-bulletin-publisher'); ?></strong></p>
        </div>
        <?php endif; ?>
        <script>
        (function () {
            'use strict';
            var panel = document.querySelector('.cbp-schedule-review');
            if (!panel) {
                return;
            }

            var warningBox = document.getElementById('cbp-parser-review-warnings');
            if (warningBox) {
                warningBox.style.display = 'block';
                var firstHeading = panel.querySelector('h3');
                if (firstHeading) {
                    firstHeading.parentNode.insertBefore(warningBox, firstHeading);
                } else {
                    panel.insertBefore(warningBox, panel.firstChild);
                }
            }

            var headings = panel.querySelectorAll('h3');
            Array.prototype.forEach.call(headings, function (heading) {
                var text = (heading.textContent || '').toLowerCase();
                if (text.indexOf('recurring parish schedule') !== -1) {
                    var note = heading.nextElementSibling;
                    if (note && note.classList.contains('description')) {
                        note.textContent = 'Weekend Mass values are proposed from ordinary Saturday/Sunday Masses in this bulletin and must be reviewed. A changed time may be a standing change or a one-week exception; nothing is applied until approval.';
                    }
                }
                if (text.indexOf('this week') !== -1 && text.indexOf('masses') !== -1) {
                    var node = heading.nextElementSibling;
                    while (node && node.tagName !== 'DIV') {
                        node = node.nextElementSibling;
                    }
                    var table = node ? node.querySelector('table') : null;
                    if (table) {
                        var headers = table.querySelectorAll('thead th');
                        if (headers.length >= 5) {
                            headers[4].textContent = 'Intention / details';
                        }
                    }
                }
            });
        }());
        </script>
        <?php
    }

    private function weekday_number($name)
    {
        $key = strtolower(substr(trim((string) $name), 0, 3));
        $map = array('mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7);
        return isset($map[$key]) ? $map[$key] : 0;
    }

    private function weekday_name($number)
    {
        $map = array(1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday');
        return isset($map[$number]) ? $map[$number] : '';
    }

    private function month_number($name)
    {
        $key = strtolower(substr(trim((string) $name), 0, 3));
        $map = array('jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12);
        return isset($map[$key]) ? $map[$key] : 0;
    }

    private function valid_date($date)
    {
        if (! is_string($date) || $date === '') {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
