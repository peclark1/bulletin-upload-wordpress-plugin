<?php
/* Isolated PHP regression checks for the staging-only carousel pilot. */
class CSC_Controls {
    const SITE = 'https://stpeterpr.org/design-demo';
    const VERSION = '0.4.2';
    public static function site_identity() {
        return untrailingslashit(home_url()) === self::SITE &&
            untrailingslashit(site_url()) === self::SITE ? self::SITE : false;
    }
    public static function is_staging_site() {
        return self::site_identity() === self::SITE;
    }
}
$GLOBALS['test_home'] = 3498;
$GLOBALS['test_url'] = CSC_Controls::SITE;
$GLOBALS['test_template'] = 'kadence';
$GLOBALS['test_admin'] = false;
$GLOBALS['available_media'] = array(3487, 3449, 3437);
$GLOBALS['styles'] = array();
$GLOBALS['scripts'] = array();
$GLOBALS['shortcodes'] = array();
$count = 0;
function assert_promo($condition, $message) {
    global $count;
    if (!$condition) { fwrite(STDERR, "FAILED: $message\n"); exit(1); }
    $count++;
}
function is_admin() { return $GLOBALS['test_admin']; }
function home_url() { return $GLOBALS['test_url']; }
function site_url() { return $GLOBALS['test_url']; }
function untrailingslashit($value) { return rtrim($value, '/'); }
function get_template() { return $GLOBALS['test_template']; }
function is_page($id) { return $id === $GLOBALS['test_home']; }
function wp_attachment_is_image($id) { return in_array($id, $GLOBALS['available_media'], true); }
function esc_attr($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function esc_url($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function rest_url($path) { return CSC_Controls::SITE . '/wp-json/' . $path; }
function get_option($key, $default = false) { return $GLOBALS['test_live'] ?? $default; }
function wp_get_attachment_image($id, $size, $icon, $attrs) {
    return '<img src="test-' . (int)$id . '.jpg" alt="' . esc_attr($attrs['alt']) . '" class="' . esc_attr($attrs['class']) . '">';
}
function plugin_dir_url($file) { return CSC_Controls::SITE . '/wp-content/plugins/church-site-controls/'; }
function wp_enqueue_style($handle, $url, $deps, $version) { $GLOBALS['styles'][] = array($handle, $url, $version); }
function wp_enqueue_script($handle, $url, $deps, $version, $footer) { $GLOBALS['scripts'][] = array($handle, $url, $version, $footer); }
function add_action($hook, $callback, $priority = 10) { /* WP supplies hooks on site. */ }
function add_shortcode($name, $callback) { $GLOBALS['shortcodes'][$name] = $callback; }

require __DIR__ . '/../includes/class-csc-parish-promotions.php';
require __DIR__ . '/../includes/class-csc-promotions-preview.php';
CSC_Promotions_Preview::register();
assert_promo(isset($GLOBALS['shortcodes']['parish_promotions_preview']), 'WordPress shortcode is registered');
CSC_Promotions_Preview::enqueue();
assert_promo(count($GLOBALS['styles']) === 1 && $GLOBALS['styles'][0][0] === 'csc-promotions-preview', 'carousel CSS enqueued on staging homepage');
assert_promo(count($GLOBALS['scripts']) === 1 && $GLOBALS['scripts'][0][0] === 'csc-promotions-preview' && $GLOBALS['scripts'][0][3] === true, 'carousel JS enqueued in footer on staging homepage');
$html = CSC_Promotions_Preview::shortcode();
assert_promo(substr_count($html, 'class="csc-promo-slide ') === 3, 'three distinct archived poster slides');
assert_promo(strpos($html, 'test-3487.jpg') !== false && strpos($html, 'test-3449.jpg') !== false && strpos($html, 'test-3437.jpg') !== false, 'actual Media Library images used');
assert_promo(strpos($html, 'not current event announcements') !== false, 'archived posters explicitly labeled design-only');
assert_promo(strpos($html, 'aria-disabled="true"') !== false && strpos($html, 'class="csc-promo-action') !== false, 'CTA styling represented but no fake sign-up links');
assert_promo(strpos($html, 'href=') === false, 'prototype does not invent destinations');
assert_promo(strpos($html, 'csc-promo-prev') !== false && strpos($html, 'csc-promo-pause') !== false, 'manual and pause controls present for multiple events');

$GLOBALS['available_media'] = array(3487);
$html = CSC_Promotions_Preview::shortcode();
assert_promo(substr_count($html, 'class="csc-promo-slide ') === 1 && strpos($html, 'csc-promo-prev') === false, 'single active slide renders without slideshow controls');
$GLOBALS['available_media'] = array();
assert_promo(CSC_Promotions_Preview::shortcode() === '', 'no valid posters hides the section');
$GLOBALS['available_media'] = array(3487, 3449, 3437);
$GLOBALS['test_live'] = '1';
$managed = CSC_Promotions_Preview::shortcode();
assert_promo(strpos($managed, 'data-mode="managed"') !== false &&
    strpos($managed, 'parish-promotions/v1/carousel') !== false,
    'opted-in managed mode renders cache-safe feed shell');
assert_promo(strpos($managed, 'Archived Alpha Fall') === false &&
    strpos($managed, 'test-3487.jpg') === false,
    'managed mode never inserts archived demo posters into current promotions');
$GLOBALS['test_live'] = '0';
$GLOBALS['test_url'] = 'https://stpeterpr.org';
$GLOBALS['styles'] = array(); $GLOBALS['scripts'] = array();
CSC_Promotions_Preview::enqueue();
assert_promo(CSC_Promotions_Preview::shortcode() === '' && !$GLOBALS['styles'] && !$GLOBALS['scripts'], 'never renders or enqueues on production');
$GLOBALS['test_url'] = CSC_Controls::SITE;
$GLOBALS['test_home'] = 3862;
assert_promo(CSC_Promotions_Preview::shortcode() === '', 'does not appear on another staging page');
$GLOBALS['test_home'] = 3498;
$GLOBALS['test_admin'] = true;
assert_promo(CSC_Promotions_Preview::shortcode() === '', 'does not appear in WordPress admin');
$GLOBALS['test_admin'] = false;
$GLOBALS['test_template'] = 'other';
assert_promo(CSC_Promotions_Preview::shortcode() === '', 'non-Kadence theme rejected');

$css = file_get_contents(__DIR__ . '/../assets/promotions-preview.css');
$js = file_get_contents(__DIR__ . '/../assets/promotions-preview.js');
assert_promo(strpos($css, 'object-fit: contain') !== false && strpos($css, 'object-fit: cover') === false, 'full original artwork, never crop');
assert_promo(strpos($css, '@media (max-width: 760px)') !== false, 'single-card mobile layout supplied');
assert_promo(strpos($css, 'prefers-reduced-motion: reduce') !== false, 'reduced-motion CSS supplied');
assert_promo(strpos($js, 'IntersectionObserver') !== false && strpos($js, 'setInterval') !== false, 'automatic cycling gated by visible viewport');
assert_promo(strpos($js, 'reducedMotion.matches') !== false, 'autoplay disabled for reduced-motion users');
assert_promo(strpos($js, 'touchend') !== false && strpos($js, 'ArrowLeft') !== false, 'swipe and keyboard controls supplied');
echo "$count Parish Promotions preview tests passed. WordPress rendering still needs staging QA.\n";
