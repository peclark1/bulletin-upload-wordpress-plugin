#!/usr/bin/env php
<?php
/**
 * Extract readable text from candidate bulletin PDFs for human review.
 *
 * This does not create or bless regression expectations. It only exposes the
 * PDF text that the bundled parser sees so fixtures can be reviewed by a human
 * before promotion into tests/fixtures.
 *
 * The generated text is a review artifact only; regression expectations remain
 * hand-reviewed and are never generated automatically from parser output.
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Smalot\PdfParser\Parser;

$source = $root . '/tests/candidate-bulletins';
$out = $root . '/candidate-extracts';

if (! is_dir($source)) {
    fwrite(STDERR, "Candidate directory not found: {$source}\n");
    exit(2);
}

if (! is_dir($out) && ! mkdir($out, 0777, true) && ! is_dir($out)) {
    fwrite(STDERR, "Could not create output directory: {$out}\n");
    exit(2);
}

$files = glob($source . '/*.pdf');
sort($files, SORT_STRING);
if (empty($files)) {
    fwrite(STDERR, "No candidate PDFs found.\n");
    exit(2);
}

$parser = new Parser();
$count = 0;
foreach ($files as $pdf) {
    $base = pathinfo($pdf, PATHINFO_FILENAME);
    try {
        $document = $parser->parseFile($pdf);
        $pages = $document->getPages();
        $text = '';
        foreach ($pages as $index => $page) {
            $text .= "[[PAGE " . ($index + 1) . "]]\n";
            $pageText = $page->getText();
            $pageText = str_replace("\0", '', (string) $pageText);
            if (function_exists('mb_convert_encoding')) {
                $pageText = mb_convert_encoding($pageText, 'UTF-8', 'UTF-8');
            }
            $text .= trim($pageText) . "\n\n";
        }
        file_put_contents($out . '/' . $base . '.txt', $text);
        echo $base . ": " . count($pages) . " page(s), " . strlen($text) . " extracted bytes\n";
        $count++;
    } catch (Throwable $e) {
        fwrite(STDERR, $base . ': extraction failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "Extracted {$count} candidate bulletin(s).\n";
