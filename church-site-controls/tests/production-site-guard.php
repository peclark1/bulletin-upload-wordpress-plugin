<?php
/* Regression: explicit production opt-in and staging audit identity isolation. */
$GLOBALS['home'] = 'https://stpeterpr.org/design-demo';
$GLOBALS['site'] = 'https://stpeterpr.org/design-demo';
$GLOBALS['staging_marker'] = '1';
$GLOBALS['mode'] = '1';
function home_url() { return $GLOBALS['home']; }
function site_url() { return $GLOBALS['site']; }
function untrailingslashit($url) { return rtrim($url, '/'); }
function get_option($name, $default = false) {
    if ($name === 'wpstg_is_staging_site') return $GLOBALS['staging_marker'];
    if ($name === 'csc_parish_promotions_live') return $GLOBALS['mode'];
    return $default;
}
function current_user_can($cap) { return $cap === 'manage_options'; }
require __DIR__ . '/../includes/class-csc-controls.php';
function get_template() { return 'kadence'; }
function add_action($name, $callback) { /* test-only WordPress hook stub */ }
require __DIR__ . '/../includes/class-csc-parish-promotions.php';
function csc_gate_test($truth, $message) {
    if (!$truth) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}
csc_gate_test(CSC_Controls::site_identity() === CSC_Controls::SITE,
    'original staging site is still allowed');
csc_gate_test(CSC_Controls::is_staging_site() === true,
    'stage identification works');
csc_gate_test(CSC_Parish_Promotions::enabled() === true,
    'staging mode remains managed when option is set');

$GLOBALS['home'] = CSC_Controls::LIVE_SITE;
$GLOBALS['site'] = CSC_Controls::LIVE_SITE;
$GLOBALS['staging_marker'] = null;
csc_gate_test(CSC_Controls::site_identity() === false,
    'production blocked without explicit opt-in');

define('CSC_PRODUCTION_SITE_ENABLED', true);
$GLOBALS['staging_marker'] = '1';
csc_gate_test(CSC_Controls::site_identity() === false,
    'staging marker prevents accidental production authorization');

$GLOBALS['staging_marker'] = null;
csc_gate_test(CSC_Controls::site_identity() === CSC_Controls::LIVE_SITE,
    'explicit exact production URL authorized with marker removed');
csc_gate_test(CSC_Controls::is_staging_site() === false,
    'live identity remains distinct from stage identity');
csc_gate_test(CSC_Parish_Promotions::enabled() === false,
    'cloned staging mode option cannot turn production carousel on by itself');
define('CSC_PRODUCTION_PROMOTIONS_ENABLED', true);
csc_gate_test(CSC_Parish_Promotions::enabled() === true,
    'production promotions require separate display authorization');

$GLOBALS['site'] = 'https://www.stpeterpr.org';
csc_gate_test(CSC_Controls::site_identity() === false,
    'mismatched home/site URL denied');
$GLOBALS['site'] = CSC_Controls::LIVE_SITE;
$GLOBALS['home'] = 'https://stpeterpr.org/design-demo-sibling';
csc_gate_test(CSC_Controls::site_identity() === false,
    'unknown sibling URL denied');
echo "PASS: staging unchanged; separate production site/display opt-ins, marker, URL guards\n";
