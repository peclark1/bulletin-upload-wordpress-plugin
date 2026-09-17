<?php
/**
 * Minimal WordPress compatibility layer for the bulletin parser regression suite.
 *
 * This is intentionally tiny: it implements only the WordPress APIs used by
 * the schedule extraction classes while they process a fixture. Production
 * WordPress never loads this file.
 */

if (! defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}
if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        private $code;
        private $message;

        public function __construct($code = '', $message = '')
        {
            $this->code = (string) $code;
            $this->message = (string) $message;
        }

        public function get_error_message()
        {
            return $this->message;
        }
    }
}

$GLOBALS['cbp_regression_transients'] = array();
$GLOBALS['cbp_regression_options'] = array();

function cbp_regression_reset_wordpress_state()
{
    $GLOBALS['cbp_regression_transients'] = array();
    $GLOBALS['cbp_regression_options'] = array();
    $_GET = array();
    $_POST = array();
    $_REQUEST = array();
}

function add_action() { return true; }
function remove_action() { return true; }
function add_filter() { return true; }
function add_shortcode() { return true; }
function do_action() { return true; }
function check_admin_referer() { return true; }
function current_user_can() { return true; }
function is_admin() { return true; }
function get_current_user_id() { return 1; }
function wp_nonce_field() { return ''; }
function submit_button() { return ''; }
function selected() { return ''; }
function admin_url($path = '') { return (string) $path; }
function wp_safe_redirect() { return true; }
function esc_url($value) { return (string) $value; }
function esc_url_raw($value) { return (string) $value; }
function esc_attr($value) { return (string) $value; }
function esc_html($value) { return (string) $value; }
function esc_html_e($value) { echo (string) $value; }
function esc_html__($value) { return (string) $value; }
function __($value) { return (string) $value; }
function wp_unslash($value) { return $value; }
function is_wp_error($value) { return $value instanceof WP_Error; }

function sanitize_key($value)
{
    return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $value));
}

function sanitize_text_field($value)
{
    if (is_array($value)) {
        return $value;
    }
    $value = strip_tags((string) $value);
    $value = preg_replace('/[\r\n\t ]+/u', ' ', trim($value));
    return (string) $value;
}

function wp_parse_args($args, $defaults = array())
{
    return array_merge((array) $defaults, is_array($args) ? $args : array());
}

function shortcode_atts($pairs, $atts)
{
    return array_merge((array) $pairs, is_array($atts) ? $atts : array());
}

function add_query_arg($args, $url = '')
{
    $separator = strpos((string) $url, '?') === false ? '?' : '&';
    return (string) $url . $separator . http_build_query((array) $args);
}

function get_transient($key)
{
    return array_key_exists($key, $GLOBALS['cbp_regression_transients'])
        ? $GLOBALS['cbp_regression_transients'][$key]
        : false;
}

function set_transient($key, $value, $ttl = 0)
{
    $GLOBALS['cbp_regression_transients'][$key] = $value;
    return true;
}

function delete_transient($key)
{
    unset($GLOBALS['cbp_regression_transients'][$key]);
    return true;
}

function get_option($key, $default = false)
{
    return array_key_exists($key, $GLOBALS['cbp_regression_options'])
        ? $GLOBALS['cbp_regression_options'][$key]
        : $default;
}

function add_option($key, $value)
{
    if (! array_key_exists($key, $GLOBALS['cbp_regression_options'])) {
        $GLOBALS['cbp_regression_options'][$key] = $value;
    }
    return true;
}

function update_option($key, $value)
{
    $GLOBALS['cbp_regression_options'][$key] = $value;
    return true;
}

function wp_date($format, $timestamp = null)
{
    if ($timestamp === null) {
        $timestamp = time();
    }
    return gmdate($format, (int) $timestamp);
}

function wp_die($message = '')
{
    throw new RuntimeException((string) $message);
}
