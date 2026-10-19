<?php
/* Isolated backend regression tests for Parish Promotions on staging. */
class CSC_Controls {
    const SITE = 'https://stpeterpr.org/design-demo';
    const VERSION = '0.4.1';
}
class WP_Error {
    private $message;
    public function __construct($code, $message) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class WP_REST_Response {
    public $data; public $status; public $headers = array();
    public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
    public function header($name, $value) { $this->headers[$name] = $value; }
}
$GLOBALS['url'] = CSC_Controls::SITE;
$GLOBALS['template'] = 'kadence';
$GLOBALS['can_edit'] = true;
$GLOBALS['posts'] = array();
$GLOBALS['meta'] = array();
$GLOBALS['live_mode'] = '0';
$GLOBALS['registered'] = array();
$GLOBALS['hooks'] = array();
$GLOBALS['count'] = 0;
function check_backend($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAILED: $message\n"); exit(1); }
    $GLOBALS['count']++;
}
function home_url() { return $GLOBALS['url']; }
function site_url() { return $GLOBALS['url']; }
function untrailingslashit($s) { return rtrim($s, '/'); }
function get_template() { return $GLOBALS['template']; }
function get_option($key, $default = false) { return $key === CSC_Parish_Promotions::MODE_OPTION ? $GLOBALS['live_mode'] : $default; }
function current_user_can($cap) { return $cap === 'edit_pages' && $GLOBALS['can_edit']; }
function register_post_type($type, $options) { $GLOBALS['registered'][$type] = $options; }
function register_rest_route($ns, $route, $options) { $GLOBALS['registered']['rest'] = array($ns, $route, $options); }
function add_action($name, $callback) { $GLOBALS['hooks'][$name] = $callback; }
function get_posts($options) { return $GLOBALS['posts']; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function get_post($id) {
    foreach ($GLOBALS['posts'] as $post) { if ($post->ID === $id) return $post; }
    return null;
}
function wp_attachment_is_image($id) { return in_array($id, array(3487,3449,3437,5001,5002,5003), true); }
function wp_get_attachment_image_url($id, $size) { return wp_attachment_is_image($id) ? 'https://stpeterpr.org/design-demo/wp-content/uploads/' . $id . '.jpg' : false; }
function wp_get_attachment_image_srcset($id, $size) { return ''; }
function wp_date($format) { return '2026-10-18'; }
function is_admin() { return false; }
function wp_parse_url($url, $part) { return parse_url($url, $part); }
function admin_url($path) { return 'https://stpeterpr.org/design-demo/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function esc_url_raw($url, $protocols = array()) { return preg_match('/^https?:\/\//i', $url) ? $url : ''; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function absint($value) { return abs((int) $value); }
function wp_unslash($value) { return stripslashes($value); }
function esc_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function sanitize_text_field($s) { return trim(strip_tags($s)); }
function sanitize_textarea_field($s) { return trim(strip_tags($s)); }
function wp_die($message, $response = 500) { throw new RuntimeException($message, (int)$response); }
function check_admin_referer($action) {
    if (!isset($_POST['_wpnonce']) || $_POST['_wpnonce'] !== $action) {
        throw new RuntimeException('Nonce mismatch');
    }
}
require __DIR__ . '/../includes/class-csc-parish-promotions.php';

CSC_Parish_Promotions::register_type();
check_backend(isset($GLOBALS['registered'][CSC_Parish_Promotions::TYPE]), 'registers private promotion post type');
$settings = $GLOBALS['registered'][CSC_Parish_Promotions::TYPE];
check_backend($settings['public'] === false && $settings['show_in_rest'] === false && $settings['show_ui'] === false, 'promotion records are not public posts or editable through generic REST');
CSC_Parish_Promotions::rest_routes();
check_backend($GLOBALS['registered']['rest'][0] === 'parish-promotions/v1' &&
    $GLOBALS['registered']['rest'][1] === '/carousel' &&
    $GLOBALS['registered']['rest'][2]['methods'] === 'GET', 'public feed is read-only GET');
check_backend(CSC_Parish_Promotions::enabled() === false, 'prototype mode remains default');
check_backend(CSC_Parish_Promotions::valid_window('2026-10-18', '2026-10-18'), 'display end date is inclusive');
check_backend(!CSC_Parish_Promotions::valid_window('2026-10-20', '2026-10-19'), 'reversed dates rejected');
check_backend(!CSC_Parish_Promotions::valid_window('2026-02-30', '2026-10-19'), 'invalid calendar day rejected');
check_backend(!CSC_Parish_Promotions::valid_window('18-10-2026', '2026-10-20'), 'date input must be ISO');
check_backend(!CSC_Parish_Promotions::valid_window('', '2026-10-20'), 'missing date rejected');

$make = static function ($id, $status, $start, $end, $order, $image) {
    $post = (object) array(
        'ID' => $id, 'post_type' => CSC_Parish_Promotions::TYPE,
        'post_title' => 'Promotion ' . $id, 'post_status' => $status,
        'post_modified_gmt' => '2026-10-17 16:00:00',
    );
    $GLOBALS['posts'][] = $post;
    foreach (array(
        'image_id'=>$image, 'start'=>$start, 'end'=>$end, 'sort'=>$order,
        'alt'=>'Alt for ' . $id, 'info_url'=>'https://stpeterpr.org/design-demo/?page_id=22',
        'signup_url'=>'https://stpeterpr.org/design-demo/?page_id=24',
        'info_label'=>'Details', 'signup_label'=>'Register',
    ) as $key=>$value) {
        $GLOBALS['meta'][$id]['_csc_promo_' . $key] = $value;
    }
};
$make(15,'publish','2026-10-01','2026-10-18',200,5001); // end today
$make(11,'publish','2026-10-18','2026-11-30',50,5002); // begins today
$make(12,'draft','2026-10-01','2026-10-30',0,5003); // not yet public
$make(13,'publish','2026-10-19','2026-11-30',0,5003); // future
$make(14,'publish','2026-09-01','2026-10-17',0,5003); // expired
$make(16,'publish','2026-10-01','2026-10-30',0,999); // missing image
$active = CSC_Parish_Promotions::active_records();
check_backend(count($active) === 2 && $active[0]['id'] === 11 && $active[1]['id'] === 15, 'only valid published-in-window posters render, ordered by sort');
$feed = CSC_Parish_Promotions::public_feed();
check_backend($feed['mode'] === 'demo' && count($feed['items']) === 3, 'archived pilot remains until staff switches');
check_backend($feed['items'][0]['info_url'] === '' && $feed['items'][0]['signup_url'] === '', 'historical samples have no invented active buttons');
$GLOBALS['live_mode'] = '1';
$feed = CSC_Parish_Promotions::public_feed();
check_backend($feed['mode'] === 'managed' && count($feed['items']) === 2, 'managed mode uses live staff records rather than archived posters');
check_backend($feed['items'][0]['title'] === 'Promotion 11' && $feed['items'][0]['info_label'] === 'Details' && $feed['items'][0]['signup_label'] === 'Register', 'public feed returns correct staff CTA data');
check_backend(!array_key_exists('start', $feed['items'][0]) && !array_key_exists('id', $feed['items'][0]), 'public feed excludes internal record metadata');
$response = CSC_Parish_Promotions::rest_feed();
check_backend($response->status === 200 && strpos($response->headers['Cache-Control'], 'no-store') !== false, 'date-sensitive endpoint forbids caching');
$GLOBALS['posts'] = array();
check_backend(CSC_Parish_Promotions::public_feed()['items'] === array(), 'no published active entries means no carousel');
$GLOBALS['url'] = 'https://stpeterpr.org';
check_backend(CSC_Parish_Promotions::public_feed()['mode'] === 'off' && !CSC_Parish_Promotions::enabled(), 'production disabled regardless of saved mode');
$GLOBALS['registered'] = array();
CSC_Parish_Promotions::register_type();
check_backend(!isset($GLOBALS['registered'][CSC_Parish_Promotions::TYPE]), 'production never registers private promotion type');
$GLOBALS['url'] = CSC_Controls::SITE;
$GLOBALS['can_edit'] = false;
$_POST = array('_wpnonce' => 'csc_promotion_save');
try { CSC_Parish_Promotions::save(); check_backend(false,'unauthorized save must fail'); }
catch (RuntimeException $e) { check_backend(strpos($e->getMessage(), 'not authorized') !== false, 'unauthorized save rejected'); }
$GLOBALS['can_edit'] = true;
$_POST = array(
    '_wpnonce'=>'csc_promotion_save', 'promotion_id'=>'0',
    'title'=>'Test', 'image_id'=>'5001', 'start'=>'2026-02-30', 'end'=>'2026-10-22',
    'sort'=>'100', 'status'=>'publish', 'info_url'=>'', 'signup_url'=>'',
);
try { CSC_Parish_Promotions::save(); check_backend(false, 'invalid display window must fail'); }
catch (RuntimeException $e) { check_backend(strpos($e->getMessage(), 'start and end') !== false, 'invalid date rejected before write'); }
$_POST['start'] = '2026-10-18';
$_POST['info_url'] = 'javascript:alert(1)';
try { CSC_Parish_Promotions::save(); check_backend(false, 'javascript URL must fail'); }
catch (RuntimeException $e) { check_backend(strpos($e->getMessage(), 'complete') !== false, 'unsafe URL rejected before write'); }
$_POST['info_url'] = '';
$_POST['image_id'] = '12';
try { CSC_Parish_Promotions::save(); check_backend(false, 'non-image must fail'); }
catch (RuntimeException $e) { check_backend(strpos($e->getMessage(), 'Media Library') !== false, 'invalid media ID refused'); }

// A newly created record must return to a blank Add form rather than
// quietly editing it. Existing records must retain a clearly marked edit route.
$created_url = CSC_Parish_Promotions::save_redirect_url(4217, true);
check_backend(strpos($created_url, 'notice=created') !== false &&
    strpos($created_url, 'saved_id=4217') !== false &&
    strpos($created_url, 'edit=') === false,
    'first save returns to blank Add form with edit link to created record');
$updated_url = CSC_Parish_Promotions::save_redirect_url(4215, false);
check_backend(strpos($updated_url, 'notice=updated') !== false &&
    strpos($updated_url, 'edit=4215') !== false &&
    strpos($updated_url, 'saved_id=') === false,
    'saving an edited record keeps existing item in edit mode');

// Guard the UI contract that avoids accidentally overwriting the last save.
$source = file_get_contents(__DIR__ . '/../includes/class-csc-parish-promotions.php');
check_backend(strpos($source, 'class="page-title-action csc-promo-add-new"') !== false &&
    strpos($source, 'Add New Promotion') !== false,
    'Add New action is prominent at the top of the admin screen');
check_backend(strpos($source, 'Save Changes updates this record') !== false &&
    strpos($source, 'After you save, it will reset') !== false,
    'existing vs new promotion forms explicitly describe save behavior');
check_backend(strpos($source, 'Edit this promotion') !== false &&
    strpos($source, "self::save_redirect_url($saved_id, !$id)") !== false,
    'created confirmation links to edit and save POST uses tested redirect helper');

$js = file_get_contents(__DIR__ . '/../assets/promotions-preview.js');
$admin = file_get_contents(__DIR__ . '/../assets/promotions-admin.js');
check_backend(strpos($js, "cache: 'no-store'") !== false && strpos($js, 'Date.now()') !== false, 'browser fetch avoids stale website caches');
check_backend(strpos($js, 'response.json()') !== false && strpos($js, "buildFeed(feed)") !== false, 'carousel hydrates from public managed feed');
check_backend(strpos($js, "root.dataset.mode === 'managed'") !== false, 'fetch errors fail closed rather than display expired promotions');
check_backend(strpos($admin, "wp.media") !== false && strpos($admin, "image.sizes") !== false, 'staff use native image library');
echo $GLOBALS['count'] . " Parish Promotions admin/backend tests passed.\n";
