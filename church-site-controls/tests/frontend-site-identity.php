<?php
/* Verify CSS/JS only load for approved staging or explicit production-root identity. */
$GLOBALS['csc_home'] = 'https://stpeterpr.org/design-demo';
$GLOBALS['csc_site'] = 'https://stpeterpr.org/design-demo';
$GLOBALS['csc_marker'] = '1';
$GLOBALS['csc_theme'] = 'kadence';
$GLOBALS['csc_page'] = 23;
$GLOBALS['csc_admin'] = false;
$GLOBALS['csc_styles'] = array();
$GLOBALS['csc_scripts'] = array();
function home_url() { return $GLOBALS['csc_home']; }
function site_url() { return $GLOBALS['csc_site']; }
function untrailingslashit($v) { return rtrim($v, '/'); }
function get_option($k, $fallback = false) { return $k === 'wpstg_is_staging_site' ? $GLOBALS['csc_marker'] : $fallback; }
function get_template() { return $GLOBALS['csc_theme']; }
function is_admin() { return $GLOBALS['csc_admin']; }
function is_page($page) { return $GLOBALS['csc_page'] === $page; }
function add_action($name, $fn, $priority = 10) { /* fixture */ }
function plugin_dir_url($filename) { return 'https://example.org/wp-content/plugins/church-site-controls/'; }
function wp_enqueue_style($name, $url, $deps, $version) { $GLOBALS['csc_styles'][] = array($name, $version); }
function wp_enqueue_script($name, $url, $deps, $version, $footer) { $GLOBALS['csc_scripts'][] = array($name, $version, $footer); }
require __DIR__ . '/../includes/class-csc-controls.php';
require __DIR__ . '/../includes/class-csc-mega-navigation.php';
require __DIR__ . '/../includes/class-csc-page-polish.php';
function verify($expected_styles, $expected_scripts, $message) {
    $GLOBALS['csc_styles'] = array();
    $GLOBALS['csc_scripts'] = array();
    CSC_Mega_Navigation::enqueue();
    CSC_Page_Polish::enqueue();
    $styles = array_column($GLOBALS['csc_styles'], 0);
    $scripts = array_column($GLOBALS['csc_scripts'], 0);
    if ($styles !== $expected_styles || $scripts !== $expected_scripts) {
        fwrite(STDERR, "FAIL: $message styles=" . json_encode($styles) . ' scripts=' . json_encode($scripts) . "\n");
        exit(1);
    }
}
$all = array('csc-mega-navigation', 'csc-equal-height-cards', 'csc-worship-responsive');
verify($all, array('csc-mega-navigation'), 'original staging identical styling');
$GLOBALS['csc_page'] = 3498;
verify(array('csc-mega-navigation', 'csc-equal-height-cards'), array('csc-mega-navigation'), 'home global layout but no Worship stylesheet');
$GLOBALS['csc_home'] = CSC_Controls::LIVE_SITE;
$GLOBALS['csc_site'] = CSC_Controls::LIVE_SITE;
$GLOBALS['csc_marker'] = null;
verify(array(), array(), 'production disabled without explicit config');
define('CSC_PRODUCTION_SITE_ENABLED', true);
$GLOBALS['csc_marker'] = '1';
verify(array(), array(), 'staging marker disables root even with config');
$GLOBALS['csc_marker'] = null;
verify(array('csc-mega-navigation', 'csc-equal-height-cards'), array('csc-mega-navigation'), 'opted-in root enqueues exactly site assets');
$GLOBALS['csc_page'] = 23;
verify($all, array('csc-mega-navigation'), 'opted-in root Worship responsive styles');
$GLOBALS['csc_site'] = 'https://www.stpeterpr.org';
verify(array(), array(), 'mismatched hostname denies root');
$GLOBALS['csc_site'] = CSC_Controls::LIVE_SITE;
$GLOBALS['csc_home'] = 'https://stpeterpr.org/parish-preview-2026';
verify(array(), array(), 'preview path not authorized by production root config');
$GLOBALS['csc_home'] = CSC_Controls::LIVE_SITE;
$GLOBALS['csc_theme'] = 'twentytwentyfour';
verify(array(), array(), 'non-Kadence theme denied');
$GLOBALS['csc_theme'] = 'kadence';
$GLOBALS['csc_admin'] = true;
verify(array(), array(), 'wp-admin denied');
echo "PASS: 10 checks, exact staging and production-root frontend asset gates, no unauthorized enqueues\n";
