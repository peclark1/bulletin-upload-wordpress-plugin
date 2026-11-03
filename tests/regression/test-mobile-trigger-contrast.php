<?php
/**
 * Keep the Kadence mobile menu trigger visible against both light headers
 * and dark image/hero backgrounds. The CSS is part of the globally enqueued
 * site palette bridge, but is scoped to Kadence's actual #mobile-toggle.
 */
$mobile_css = file_get_contents($root . '/assets/site-palette.css');
if ($mobile_css === false) {
    fwrite(STDERR, "Could not load mobile header styles.\n");
    exit(1);
}
$required = array(
    '.site-header #mobile-toggle.menu-toggle-open',
    'min-width: 44px',
    'min-height: 44px',
    'background-color: #243b63 !important',
    'color: #ffffff !important',
    'border: 2px solid #ffffff !important',
    ':focus-visible',
);
foreach ($required as $selector_or_property) {
    if (strpos($mobile_css, $selector_or_property) === false) {
        fwrite(STDERR, "Mobile menu contrast is missing: " . $selector_or_property . "\n");
        exit(1);
    }
}

// Explicitly check the fixed color pair against WCAG contrast thresholds,
// since the trigger appears over unpredictable backgrounds and imagery.
$contrast_luminance = function ($hex) {
    $hex = ltrim($hex, '#');
    $rgb = array();
    for ($i = 0; $i < 6; $i += 2) {
        $c = hexdec(substr($hex, $i, 2)) / 255;
        $rgb[] = $c <= .04045 ? $c / 12.92 : pow(($c + .055) / 1.055, 2.4);
    }
    return .2126 * $rgb[0] + .7152 * $rgb[1] + .0722 * $rgb[2];
};
$light = $contrast_luminance('#ffffff');
$dark = $contrast_luminance('#243b63');
if (($light + .05) / ($dark + .05) < 4.5) {
    fwrite(STDERR, "Mobile menu trigger contrast ratio fell below 4.5:1.\n");
    exit(1);
}
