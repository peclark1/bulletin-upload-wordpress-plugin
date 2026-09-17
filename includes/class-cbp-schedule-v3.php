<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Page-aware extraction wrapper used while tuning the bulletin parser against
 * the real parish PDFs. Smalot's document-level getText() can flatten complex
 * multi-column PDFs in surprising ways, so this wrapper extracts each page
 * independently and preserves page boundaries before handing the text to the
 * existing V2 parser.
 *
 * It also always stores a bounded raw-text diagnostic excerpt in the review
 * transient. That means a "successful" PDF read can no longer leave us blind
 * when none of the expected headings are recognized.
 */
final class CBP_Schedule_V3
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
        // V2 is registered immediately before us. Replace only its extraction
        // handler; all rendering/approval/storage remains in CBP_Schedule.
        remove_action('admin_post_cbp_extract_schedule', array(CBP_Schedule_V2::instance(), 'extract_schedule'));
        add_action('admin_post_cbp_extract_schedule', array($this, 'extract_schedule'));
    }

    public function extract_schedule()
    {
        if (! current_user_can('manage_options')) {
            wp_die(
                esc_html__('You are not allowed to update the parish schedule.', 'church-bulletin-publisher'),
                esc_html__('Access denied', 'church-bulletin-publisher'),
                array('response' => 403)
            );
        }
        check_admin_referer('cbp_extract_schedule');

        $preview = get_transient($this->preview_key());
        if (! is_array($preview) || empty($preview['path']) || ! is_readable($preview['path'])) {
            $this->redirect('error', __('The private preview is missing or expired. Create it again.', 'church-bulletin-publisher'));
        }

        $bulletin_date = isset($preview['date']) ? sanitize_text_field($preview['date']) : '';
        if (! $this->valid_date($bulletin_date)) {
            $this->redirect('error', __('The bulletin date is missing or invalid. Create the preview again.', 'church-bulletin-publisher'));
        }

        $extracted = $this->pdf_text_by_page($preview['path']);
        if (is_wp_error($extracted)) {
            set_transient($this->review_key(), array('error' => $extracted->get_error_message()), self::REVIEW_TTL);
            $this->redirect('error', $extracted->get_error_message());
        }

        try {
            $v2 = CBP_Schedule_V2::instance();
            $method = new ReflectionMethod($v2, 'parse');
            $method->setAccessible(true);
            $parsed = $method->invoke($v2, $extracted['text'], $bulletin_date);
        } catch (Throwable $e) {
            $message = __('The bulletin text was read, but the schedule parser could not process it. No website content has been changed.', 'church-bulletin-publisher') . ' ' . $e->getMessage();
            set_transient($this->review_key(), array('error' => $message), self::REVIEW_TTL);
            $this->redirect('error', $message);
        }

        $debug = $this->debug_lines($extracted['text'], $extracted['pages']);
        $existing = isset($parsed['source_lines']) && is_array($parsed['source_lines']) ? $parsed['source_lines'] : array();
        $parsed['source_lines'] = array_values(array_unique(array_merge($debug, $existing)));

        set_transient($this->review_key(), $parsed, self::REVIEW_TTL);
        $this->redirect('success', __('Website information extracted. Review every proposed item before approving it. Raw parser diagnostics are available at the bottom of the review.', 'church-bulletin-publisher'));
    }

    private function pdf_text_by_page($path)
    {
        if (! extension_loaded('zlib') || ! extension_loaded('iconv')) {
            return new WP_Error('cbp_schedule_php', __('Automatic bulletin extraction needs the PHP zlib and iconv extensions. No website content has been changed.', 'church-bulletin-publisher'));
        }
        if (! class_exists('Smalot\\PdfParser\\Parser')) {
            return new WP_Error('cbp_schedule_parser', __('The bundled PHP PDF parser is missing. No website content has been changed.', 'church-bulletin-publisher'));
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            $pages = $pdf->getPages();
            $parts = array();
            $page_number = 0;
            foreach ($pages as $page) {
                $page_number++;
                $page_text = (string) $page->getText();
                if (trim($page_text) === '') {
                    $parts[] = '[[PAGE ' . $page_number . ' - NO EXTRACTABLE TEXT]]';
                } else {
                    $parts[] = '[[PAGE ' . $page_number . "]]\n" . $page_text;
                }
            }
            $text = implode("\n", $parts);

            // Some PDFs behave better through Document::getText(). Keep that as
            // a fallback, but prefer page-wise text because it preserves order
            // and makes the diagnostics much more useful.
            if (mb_strlen(trim($text)) < 80) {
                $text = (string) $pdf->getText();
            }
        } catch (Throwable $e) {
            return new WP_Error('cbp_schedule_extract', __('The bulletin PDF could not be read by the bundled PHP parser. No website content has been changed.', 'church-bulletin-publisher') . ' ' . $e->getMessage());
        }

        if (trim($text) === '') {
            return new WP_Error('cbp_schedule_empty', __('The bulletin PDF did not yield readable text. It may be image-only. No website content has been changed.', 'church-bulletin-publisher'));
        }

        return array(
            'text' => $text,
            'pages' => isset($pages) && is_array($pages) ? count($pages) : 0,
        );
    }

    private function debug_lines($text, $page_count)
    {
        $raw = preg_split('/\R/u', (string) $text);
        $lines = array();
        foreach ($raw as $line) {
            $line = preg_replace('/\s+/u', ' ', trim($line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $flat = implode(' ', $lines);
        $debug = array(
            '[debug] PDF pages reported by parser: ' . (int) $page_count,
            '[debug] Extracted characters: ' . mb_strlen((string) $text),
            '[debug] Non-empty extracted lines: ' . count($lines),
            '[debug] Contains "Mass": ' . (stripos($flat, 'mass') !== false ? 'yes' : 'no'),
            '[debug] Contains "This week": ' . (stripos($flat, 'this week') !== false ? 'yes' : 'no'),
            '[debug] Contains "Adoration": ' . (stripos($flat, 'adoration') !== false ? 'yes' : 'no'),
            '[debug] Contains "St. Peter" or "St Peter": ' . ((stripos($flat, 'st. peter') !== false || stripos($flat, 'st peter') !== false) ? 'yes' : 'no'),
            '[debug] First extracted text lines follow:',
        );

        // Keep the diagnostic bounded so it remains safe in a transient/admin
        // page while still showing enough of the PDF to reveal encoding/order.
        foreach (array_slice($lines, 0, 160) as $line) {
            $debug[] = '[raw] ' . $line;
        }
        return $debug;
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

    private function redirect($type, $message)
    {
        $url = add_query_arg(array(
            'page' => 'church-bulletin-publisher',
            'cbp_notice' => sanitize_key($type),
            'cbp_message' => $message,
        ), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }
}
