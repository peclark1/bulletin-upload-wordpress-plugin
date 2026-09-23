#!/usr/bin/env php
<?php
/**
 * Golden-fixture regression runner for Church Bulletin Publisher.
 *
 * Each fixture contains the original bulletin PDF and a hand-reviewed
 * expected.json. The runner executes the same PDF extraction/parser layers
 * used by the plugin, then compares semantic website data rather than noisy
 * debug/source-line diagnostics.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__, 2);
require $root . '/tests/regression/wp-stubs.php';
require $root . '/vendor/autoload.php';
require $root . '/includes/class-cbp-schedule.php';
require $root . '/includes/class-cbp-site-displays.php';

// Smoke-test the front-end shortcode class structurally. PHP lint alone will
// not catch a method call whose helper was accidentally removed from the class.
$site_display_methods = array(
    'worship_week_shortcode',
    'parish_events_shortcode',
    'weekly',
    'has_week',
    'week_heading',
    'render_rows',
    'worship_location_name',
    'has_mass_intention',
    'event_note',
    'upcoming_rows',
    'enqueue_assets',
    'empty_message',
    'valid_date',
    'format_date',
);
$site_display_reflection = new ReflectionClass('CBP_Site_Displays');
foreach ($site_display_methods as $method) {
    if (! $site_display_reflection->hasMethod($method)) {
        fwrite(STDERR, "CBP_Site_Displays is missing required method: {$method}\\n");
        exit(1);
    }
}

$version_files = glob($root . '/includes/class-cbp-schedule-v*.php');
usort($version_files, function ($a, $b) {
    preg_match('/-v(\d+)\.php$/', $a, $am);
    preg_match('/-v(\d+)\.php$/', $b, $bm);
    return ((int) $am[1]) <=> ((int) $bm[1]);
});
foreach ($version_files as $file) {
    require_once $file;
}

/**
 * Build a tiny deterministic PDF from a UTF-8 text fixture.
 *
 * Original bulletin.pdf fixtures are preferred. This connector-friendly
 * fallback still exercises the real PDF library and parser while keeping the
 * human-reviewed fixture source in text form.
 */
function cbp_pdf_from_text_fixture($text_path)
{
    $text = file_get_contents($text_path);
    if ($text === false) {
        throw new RuntimeException('Could not read text fixture: ' . $text_path);
    }
    $lines = preg_split('/\R/u', $text);
    if (! is_array($lines)) {
        $lines = array($text);
    }

    $pages = array_chunk($lines, 67);
    $objects = array();
    $page_ids = array();
    $next_id = 3;
    foreach ($pages as $page_lines) {
        $page_id = $next_id++;
        $content_id = $next_id++;
        $page_ids[] = $page_id;
        $stream = "BT\n/F1 8 Tf\n10 TL\n36 756 Td\n";
        foreach ($page_lines as $line) {
            $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT', (string) $line);
            if ($encoded === false) {
                $encoded = (string) $line;
            }
            $encoded = str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $encoded);
            $stream .= '(' . $encoded . ") Tj\nT*\n";
        }
        $stream .= "ET\n";
        $objects[$page_id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 99 0 R >> >> /Contents ' . $content_id . ' 0 R >>';
        $objects[$content_id] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    }

    $kids = implode(' ', array_map(function ($id) { return $id . ' 0 R'; }, $page_ids));
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($page_ids) . ' >>';
    $objects[99] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    ksort($objects, SORT_NUMERIC);

    $max_id = max(array_keys($objects));
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = array(0 => 0);
    for ($id = 1; $id <= $max_id; $id++) {
        if (! isset($objects[$id])) {
            continue;
        }
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $objects[$id] . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . ($max_id + 1) . "\n0000000000 65535 f \n";
    for ($id = 1; $id <= $max_id; $id++) {
        $pdf .= isset($offsets[$id]) ? sprintf("%010d 00000 n \n", $offsets[$id]) : "0000000000 00000 f \n";
    }
    $pdf .= "trailer\n<< /Size " . ($max_id + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

    $tmp = tempnam(sys_get_temp_dir(), 'cbp-regression-');
    if ($tmp === false || file_put_contents($tmp, $pdf) === false) {
        throw new RuntimeException('Could not create temporary PDF fixture.');
    }
    return $tmp;
}

function cbp_invoke_private($object, $method, array $args = array())
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $args);
}

function cbp_extract_fixture($pdf, $bulletin_date, array $current_schedule)
{
    cbp_regression_reset_wordpress_state();

    $preview_key = 'cbp_preview_1';
    $review_key = 'cbp_schedule_review_1';
    $GLOBALS['cbp_regression_transients'][$preview_key] = array(
        'path' => $pdf,
        'date' => $bulletin_date,
    );
    $GLOBALS['cbp_regression_options'][CBP_Schedule::OPTION] = wp_parse_args(
        $current_schedule,
        CBP_Schedule::defaults()
    );

    // V5 is the latest class that owns the primary extraction request. Mirror
    // its extraction path without the HTTP redirect/exit used in wp-admin.
    $v4 = CBP_Schedule_V4::instance();
    $extracted = cbp_invoke_private($v4, 'pdf_text_by_page', array($pdf));
    if (is_wp_error($extracted)) {
        throw new RuntimeException($extracted->get_error_message());
    }

    $v2 = CBP_Schedule_V2::instance();
    $parsed = cbp_invoke_private($v2, 'parse', array($extracted['text'], $bulletin_date));

    $v5 = CBP_Schedule_V5::instance();
    $parsed = cbp_invoke_private($v5, 'fix_ordinal_spacing', array($parsed));
    $parsed = cbp_invoke_private($v5, 'recover_parish_events', array($parsed, $extracted['text'], $bulletin_date));

    $debug = cbp_invoke_private($v4, 'debug_lines', array($extracted));
    $existing = isset($parsed['source_lines']) && is_array($parsed['source_lines'])
        ? $parsed['source_lines']
        : array();
    $parsed['source_lines'] = array_values(array_unique(array_merge($debug, $existing)));
    $GLOBALS['cbp_regression_transients'][$review_key] = $parsed;

    // V6+ are shutdown post-processors. Execute them in numeric order exactly
    // once, with the same request marker they see in wp-admin.
    $_REQUEST['action'] = 'cbp_extract_schedule';
    foreach (range(6, 99) as $version) {
        $class = 'CBP_Schedule_V' . $version;
        if (! class_exists($class) || ! method_exists($class, 'postprocess_review')) {
            continue;
        }
        $class::instance()->postprocess_review();
    }

    $review = get_transient($review_key);
    if (! is_array($review)) {
        throw new RuntimeException('Parser did not leave review data in the expected transient.');
    }
    return $review;
}

function cbp_value($row, $key)
{
    return isset($row[$key]) ? (string) $row[$key] : '';
}

function cbp_row_identity(array $row)
{
    return implode('|', array(
        cbp_value($row, 'date'),
        cbp_value($row, 'time'),
        cbp_value($row, 'location'),
        cbp_value($row, 'title'),
        cbp_value($row, 'description'),
    ));
}

function cbp_sorted_rows(array $rows)
{
    $normalized = array();
    foreach ($rows as $row) {
        if (! is_array($row)) {
            continue;
        }
        $normalized[] = array(
            'date' => cbp_value($row, 'date'),
            'time' => cbp_value($row, 'time'),
            'location' => cbp_value($row, 'location'),
            'title' => cbp_value($row, 'title'),
            'description' => cbp_value($row, 'description'),
        );
    }
    usort($normalized, function ($a, $b) {
        return strcmp(cbp_row_identity($a), cbp_row_identity($b));
    });
    return $normalized;
}

function cbp_rule_matches_row(array $rule, array $row)
{
    foreach ($rule as $key => $expected) {
        if (substr($key, -9) === '_contains') {
            $field = substr($key, 0, -9);
            if (stripos(cbp_value($row, $field), (string) $expected) === false) {
                return false;
            }
            continue;
        }
        if (cbp_value($row, $key) !== (string) $expected) {
            return false;
        }
    }
    return true;
}

function cbp_compare_fixture(array $expected, array $actual)
{
    $errors = array();
    $expect = isset($expected['expected']) && is_array($expected['expected'])
        ? $expected['expected']
        : array();

    if (! empty($expect['candidates']) && is_array($expect['candidates'])) {
        $actual_candidates = isset($actual['candidates']) && is_array($actual['candidates'])
            ? $actual['candidates']
            : array();
        foreach ($expect['candidates'] as $key => $value) {
            $got = isset($actual_candidates[$key]) ? (string) $actual_candidates[$key] : '';
            if ($got !== (string) $value) {
                $errors[] = "candidate {$key}: expected " . json_encode($value, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($got, JSON_UNESCAPED_UNICODE);
            }
        }
    }

    $actual_weekly = isset($actual['weekly']) && is_array($actual['weekly']) ? $actual['weekly'] : array();

    if (isset($expect['masses']) && is_array($expect['masses'])) {
        $wanted = cbp_sorted_rows($expect['masses']);
        $got = cbp_sorted_rows(isset($actual_weekly['masses']) && is_array($actual_weekly['masses']) ? $actual_weekly['masses'] : array());
        if ($wanted !== $got) {
            $errors[] = "Mass rows differ.\n    expected: " . json_encode($wanted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n    actual:   " . json_encode($got, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    foreach (array('devotions_required' => 'devotions', 'events_required' => 'events') as $expect_key => $actual_key) {
        if (empty($expect[$expect_key]) || ! is_array($expect[$expect_key])) {
            continue;
        }
        $rows = isset($actual_weekly[$actual_key]) && is_array($actual_weekly[$actual_key]) ? $actual_weekly[$actual_key] : array();
        foreach ($expect[$expect_key] as $rule) {
            $matched = false;
            foreach ($rows as $row) {
                if (is_array($row) && cbp_rule_matches_row($rule, $row)) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $errors[] = $actual_key . ' missing required row: ' . json_encode($rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
    }

    if (! empty($expect['counts']) && is_array($expect['counts'])) {
        foreach ($expect['counts'] as $key => $count) {
            $got = isset($actual_weekly[$key]) && is_array($actual_weekly[$key]) ? count($actual_weekly[$key]) : 0;
            if ($got !== (int) $count) {
                $errors[] = "{$key} count: expected {$count}, got {$got}";
            }
        }
    }

    if (! empty($expect['livestream_contains'])) {
        $livestream = isset($actual_weekly['livestream']) && is_array($actual_weekly['livestream']) ? $actual_weekly['livestream'] : array();
        $text = '';
        foreach ($livestream as $row) {
            $text .= ' ' . (is_array($row) ? cbp_value($row, 'description') : (string) $row);
        }
        foreach ((array) $expect['livestream_contains'] as $needle) {
            if (stripos($text, (string) $needle) === false) {
                $errors[] = 'livestream missing text: ' . json_encode($needle, JSON_UNESCAPED_UNICODE);
            }
        }
    }

    if (! empty($expect['warning_contains'])) {
        $warning_text = '';
        foreach ((array) ($actual['warnings'] ?? array()) as $warning) {
            if (is_array($warning)) {
                $warning_text .= ' ' . ($warning['message'] ?? '') . ' ' . ($warning['source'] ?? '');
            } else {
                $warning_text .= ' ' . (string) $warning;
            }
        }
        foreach ((array) $expect['warning_contains'] as $needle) {
            if (stripos($warning_text, (string) $needle) === false) {
                $errors[] = 'warnings missing text: ' . json_encode($needle, JSON_UNESCAPED_UNICODE);
            }
        }
    }

    return $errors;
}

$fixture_root = $root . '/tests/fixtures';
$fixture_dirs = array_values(array_filter(glob($fixture_root . '/*'), 'is_dir'));
sort($fixture_dirs, SORT_STRING);

if (empty($fixture_dirs)) {
    fwrite(STDERR, "No regression fixtures found under tests/fixtures.\n");
    exit(2);
}

$failures = 0;
$passes = 0;
foreach ($fixture_dirs as $dir) {
    $json_path = $dir . '/expected.json';
    $pdf_path = $dir . '/bulletin.pdf';
    $text_path = $dir . '/bulletin.txt';
    $name = basename($dir);
    $temp_pdf = '';

    if (! is_readable($json_path)) {
        echo $name . "  FAIL\n";
        echo "  fixture must contain expected.json\n";
        $failures++;
        continue;
    }

    if (! is_readable($pdf_path)) {
        if (is_readable($text_path)) {
            try {
                $temp_pdf = cbp_pdf_from_text_fixture($text_path);
                $pdf_path = $temp_pdf;
            } catch (Throwable $e) {
                echo $name . "  FAIL\n";
                echo "  could not build text-backed PDF fixture: " . $e->getMessage() . "\n";
                $failures++;
                continue;
            }
        } else {
            echo $name . "  FAIL\n";
            echo "  fixture must contain bulletin.pdf (preferred) or bulletin.txt plus expected.json\n";
            $failures++;
            continue;
        }
    }

    $expected = json_decode(file_get_contents($json_path), true);
    if (! is_array($expected)) {
        echo $name . "  FAIL\n";
        echo "  expected.json is not valid JSON\n";
        $failures++;
        continue;
    }

    try {
        $actual = cbp_extract_fixture(
            $pdf_path,
            (string) ($expected['bulletin_date'] ?? ''),
            isset($expected['current_schedule']) && is_array($expected['current_schedule']) ? $expected['current_schedule'] : array()
        );
        $errors = cbp_compare_fixture($expected, $actual);
    } catch (Throwable $e) {
        $errors = array($e->getMessage());
    }

    if (empty($errors)) {
        echo $name . "  PASS\n";
        $passes++;
    } else {
        echo $name . "  FAIL\n";
        foreach ($errors as $error) {
            foreach (explode("\n", $error) as $line) {
                echo '  ' . $line . "\n";
            }
        }
        $failures++;
    }

    if ($temp_pdf !== '' && is_file($temp_pdf)) {
        @unlink($temp_pdf);
    }
}

echo "\n" . ($passes + $failures) . " fixture(s): {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
