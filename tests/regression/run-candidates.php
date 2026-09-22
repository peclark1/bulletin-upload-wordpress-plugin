#!/usr/bin/env php
<?php
/**
 * Regression runner for original candidate bulletin PDFs.
 *
 * Reuses the helper functions from run.php, but scans sidecar
 * *.expected.json files next to the original PDFs under
 * tests/candidate-bulletins/. This lets us exercise the real bulletin PDFs
 * without duplicating multi-megabyte files into tests/fixtures/.
 */

$runner = file_get_contents(__DIR__ . '/run.php');
if ($runner === false) {
    fwrite(STDERR, "Could not read tests/regression/run.php.\n");
    exit(2);
}

$php_start = strpos($runner, '<?php');
$loop_start = strpos($runner, "\n\$fixture_root = \$root . '/tests/fixtures';");
if ($php_start === false || $loop_start === false) {
    fwrite(STDERR, "Could not locate reusable regression helpers in run.php.\n");
    exit(2);
}

$helpers = substr($runner, $php_start + 5, $loop_start - ($php_start + 5));
eval($helpers);

// The historical parser layers intentionally remain PHP 7.4 compatible.
// Keep PHP 8.3 deprecation noise out of the candidate report so semantic
// failures are easy to read; production lint still runs separately.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '1');

$candidate_root = $root . '/tests/candidate-bulletins';
$output_root = $root . '/tests/regression-output';
if (! is_dir($output_root)) {
    @mkdir($output_root, 0777, true);
}

$expected_files = glob($candidate_root . '/*.expected.json');
sort($expected_files, SORT_STRING);

if (empty($expected_files)) {
    echo "No candidate bulletin expectations found; skipping candidate PDF regressions.\n";
    exit(0);
}

$failures = 0;
$passes = 0;
foreach ($expected_files as $json_path) {
    $name = basename($json_path, '.expected.json');
    $pdf_path = $candidate_root . '/' . $name . '.pdf';

    if (! is_readable($pdf_path)) {
        echo $name . "  FAIL\n";
        echo "  missing original PDF: " . basename($pdf_path) . "\n";
        $failures++;
        continue;
    }

    $expected = json_decode(file_get_contents($json_path), true);
    if (! is_array($expected)) {
        echo $name . "  FAIL\n";
        echo "  expected sidecar is not valid JSON\n";
        $failures++;
        continue;
    }

    $actual = null;
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

    if (is_array($actual)) {
        file_put_contents(
            $output_root . '/' . $name . '.actual.json',
            json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
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
}

echo "\n" . ($passes + $failures) . " original candidate PDF fixture(s): {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
