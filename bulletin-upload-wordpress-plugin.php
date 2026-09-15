<?php
/**
 * Plugin Name: Church Bulletin Publisher
 * Description: Builds private bulletin previews, extracts reviewed parish schedules and weekly events, and publishes approved bulletins.
 * Version: 0.4.0-test27
 * Author: St. Mary's and St. Peter the Apostle Parishes
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: church-bulletin-publisher
 */

if (! defined('ABSPATH')) {
    exit;
}

define('CBP_VERSION', '0.4.0-test27');
define('CBP_FILE', __FILE__);
define('CBP_DIR', plugin_dir_path(__FILE__));
define('CBP_URL', plugin_dir_url(__FILE__));

$cbp_autoload = CBP_DIR . 'vendor/autoload.php';
if (is_readable($cbp_autoload)) {
    require_once $cbp_autoload;
}

if (! class_exists('Smalot\\PdfParser\\Parser')) {
    spl_autoload_register(function ($class) {
        $prefix = 'Smalot\\PdfParser\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = CBP_DIR . 'vendor/smalot/pdfparser/src/Smalot/PdfParser/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    });
}

/**
 * Bridge the prototype's existing fixed page colors to the Kadence global
 * palette. This lets the parish experiment in Appearance > Customize without
 * rewriting every Gutenberg block first. New plugin displays use the palette
 * variables directly; this stylesheet handles the older page markup.
 */
function cbp_enqueue_site_palette_bridge()
{
    if (is_admin()) {
        return;
    }

    wp_enqueue_style(
        'cbp-site-palette',
        CBP_URL . 'assets/site-palette.css',
        array(),
        CBP_VERSION
    );
}
add_action('wp_enqueue_scripts', 'cbp_enqueue_site_palette_bridge', 20);

require_once CBP_DIR . 'includes/class-cbp-storage.php';
require_once CBP_DIR . 'includes/class-cbp-pdf-merger.php';
require_once CBP_DIR . 'includes/class-cbp-plugin.php';
require_once CBP_DIR . 'includes/class-cbp-production.php';
require_once CBP_DIR . 'includes/class-cbp-schedule.php';
require_once CBP_DIR . 'includes/class-cbp-schedule-v2.php';
require_once CBP_DIR . 'includes/class-cbp-schedule-v3.php';
require_once CBP_DIR . 'includes/class-cbp-schedule-v4.php';
require_once CBP_DIR . 'includes/class-cbp-schedule-v5.php';
require_once CBP_DIR . 'includes/class-cbp-schedule-v6.php';
require_once CBP_DIR . 'includes/class-cbp-schedule-v7.php';
require_once CBP_DIR . 'includes/class-cbp-schedule-v8.php';
require_once CBP_DIR . 'includes/class-cbp-schedule-v9.php';
require_once CBP_DIR . 'includes/class-cbp-schedule-v10.php';
require_once CBP_DIR . 'includes/class-cbp-workflow-v11.php';
require_once CBP_DIR . 'includes/class-cbp-site-displays.php';
require_once CBP_DIR . 'includes/class-cbp-home-schedule.php';
require_once CBP_DIR . 'includes/class-cbp-bulletin-list.php';
require_once CBP_DIR . 'includes/class-cbp-access.php';
require_once CBP_DIR . 'includes/class-cbp-access-v031.php';

register_activation_hook(__FILE__, array('CBP_Plugin', 'activate'));
register_activation_hook(__FILE__, array('CBP_Schedule', 'activate'));
CBP_Plugin::instance();
CBP_Production::instance();
CBP_Schedule::instance();
CBP_Schedule_V2::instance();
CBP_Schedule_V3::instance();
CBP_Schedule_V4::instance();
CBP_Schedule_V5::instance();
CBP_Schedule_V6::instance();
CBP_Schedule_V7::instance();
CBP_Schedule_V8::instance();
CBP_Schedule_V9::instance();
CBP_Schedule_V10::instance();
CBP_Workflow_V11::instance();
CBP_Site_Displays::instance();
CBP_Home_Schedule::instance();
CBP_Bulletin_List::instance();
CBP_Access::instance();
CBP_Access_V031::instance();
