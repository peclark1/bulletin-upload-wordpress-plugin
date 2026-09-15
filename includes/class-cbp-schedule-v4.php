<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Encoding-normalizing extraction wrapper.
 *
 * Smalot can successfully extract bytes from some parish bulletin PDFs while
 * returning a string that is not valid UTF-8. Any PCRE pattern using the /u
 * modifier then fails, which made test7 report thousands of characters but
 * zero usable lines. This wrapper normalizes the extracted page text before it
 * is handed to the V2 parser and records byte-level diagnostics.
 */
final class CBP_Schedule_V4
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
        remove_action('admin_post_cbp_extract_schedule', array(CBP_Schedule_V3::instance(), 'extract_schedule'));
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

        $debug = $this->debug_lines($extracted);
        $existing = isset($parsed['source_lines']) && is_array($parsed['source_lines']) ? $parsed['source_lines'] : array();
        $parsed['source_lines'] = array_values(array_unique(array_merge($debug, $existing)));

        set_transient($this->review_key(), $parsed, self::REVIEW_TTL);
        $this->redirect('success', __('Website information extracted. Review every proposed item before approving it. Encoding diagnostics are available at the bottom of the review.', 'church-bulletin-publisher'));
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
            $raw_bytes = 0;
            $raw_nuls = 0;
            $page_number = 0;
            $encoding_notes = array();

            foreach ($pages as $page) {
                $page_number++;
                $raw = (string) $page->getText();
                $raw_bytes += strlen($raw);
                $raw_nuls += substr_count($raw, "\0");

                $normalized = $this->normalize_pdf_text($raw, $note);
                $encoding_notes[] = 'page ' . $page_number . ': ' . $note;

                if (trim($normalized) === '') {
                    $parts[] = '[[PAGE ' . $page_number . ' - NO EXTRACTABLE TEXT]]';
                } else {
                    $parts[] = '[[PAGE ' . $page_number . "]]\n" . $normalized;
                }
            }

            $text = implode("\n", $parts);
        } catch (Throwable $e) {
            return new WP_Error('cbp_schedule_extract', __('The bulletin PDF could not be read by the bundled PHP parser. No website content has been changed.', 'church-bulletin-publisher') . ' ' . $e->getMessage());
        }

        if (trim($text) === '') {
            return new WP_Error('cbp_schedule_empty', __('The bulletin PDF did not yield readable text. It may be image-only. No website content has been changed.', 'church-bulletin-publisher'));
        }

        return array(
            'text' => $text,
            'pages' => isset($pages) && is_array($pages) ? count($pages) : 0,
            'raw_bytes' => $raw_bytes,
            'raw_nuls' => $raw_nuls,
            'encoding_notes' => $encoding_notes,
        );
    }

    private function normalize_pdf_text($text, &$note)
    {
        $text = (string) $text;
        $note = 'UTF-8 cleanup';
        if ($text === '') {
            $note = 'empty';
            return '';
        }

        // Detect explicit UTF-16 BOM first.
        $prefix = substr($text, 0, 2);
        if ($prefix === "\xFF\xFE") {
            $converted = @iconv('UTF-16LE', 'UTF-8//IGNORE', substr($text, 2));
            if ($converted !== false) {
                $note = 'converted UTF-16LE BOM to UTF-8';
                $text = $converted;
            }
        } elseif ($prefix === "\xFE\xFF") {
            $converted = @iconv('UTF-16BE', 'UTF-8//IGNORE', substr($text, 2));
            if ($converted !== false) {
                $note = 'converted UTF-16BE BOM to UTF-8';
                $text = $converted;
            }
        } else {
            // UTF-16 without a BOM usually has NULs concentrated in every other
            // byte. Examine a bounded sample before normal UTF-8 cleanup.
            $sample = substr($text, 0, 4096);
            $len = strlen($sample);
            $even_nuls = 0;
            $odd_nuls = 0;
            for ($i = 0; $i < $len; $i++) {
                if ($sample[$i] === "\0") {
                    if (($i % 2) === 0) {
                        $even_nuls++;
                    } else {
                        $odd_nuls++;
                    }
                }
            }
            $pairs = max(1, (int) floor($len / 2));
            if ($odd_nuls > ($pairs * 0.20) && $odd_nuls > ($even_nuls * 3)) {
                $converted = @iconv('UTF-16LE', 'UTF-8//IGNORE', $text);
                if ($converted !== false) {
                    $note = 'detected/converted UTF-16LE to UTF-8';
                    $text = $converted;
                }
            } elseif ($even_nuls > ($pairs * 0.20) && $even_nuls > ($odd_nuls * 3)) {
                $converted = @iconv('UTF-16BE', 'UTF-8//IGNORE', $text);
                if ($converted !== false) {
                    $note = 'detected/converted UTF-16BE to UTF-8';
                    $text = $converted;
                }
            }
        }

        // Strip malformed UTF-8 bytes. ASCII text is preserved, which is enough
        // for all schedule headings/dates/times even when a decorative glyph has
        // a bad byte mapping.
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if ($clean !== false) {
            $text = $clean;
        }

        $text = str_replace("\0", '', $text);
        $text = str_replace(array("\xC2\xA0", "\r\n", "\r"), array(' ', "\n", "\n"), $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        return (string) $text;
    }

    private function debug_lines(array $extracted)
    {
        $text = isset($extracted['text']) ? (string) $extracted['text'] : '';
        // Do not use /u here. Diagnostics must still work even if a future PDF
        // manages to return another malformed byte sequence.
        $raw = preg_split('/\r\n|\r|\n/', $text);
        $lines = array();
        if (is_array($raw)) {
            foreach ($raw as $line) {
                $line = preg_replace('/[ \t\x0B\f]+/', ' ', trim((string) $line));
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        $flat = implode(' ', $lines);
        $debug = array(
            '[debug] PDF pages reported by parser: ' . (int) $extracted['pages'],
            '[debug] Raw extracted bytes before cleanup: ' . (int) $extracted['raw_bytes'],
            '[debug] Raw NUL bytes before cleanup: ' . (int) $extracted['raw_nuls'],
            '[debug] Clean extracted bytes: ' . strlen($text),
            '[debug] Non-empty extracted lines: ' . count($lines),
            '[debug] Contains "Mass": ' . (stripos($flat, 'mass') !== false ? 'yes' : 'no'),
            '[debug] Contains "This week": ' . (stripos($flat, 'this week') !== false ? 'yes' : 'no'),
            '[debug] Contains "Adoration": ' . (stripos($flat, 'adoration') !== false ? 'yes' : 'no'),
            '[debug] Contains "St. Peter" or "St Peter": ' . ((stripos($flat, 'st. peter') !== false || stripos($flat, 'st peter') !== false) ? 'yes' : 'no'),
        );

        if (! empty($extracted['encoding_notes'])) {
            foreach ($extracted['encoding_notes'] as $note) {
                $debug[] = '[debug] Encoding ' . $note;
            }
        }

        $debug[] = '[debug] First extracted text lines follow:';
        foreach (array_slice($lines, 0, 180) as $line) {
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
