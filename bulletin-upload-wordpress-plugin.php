<?php
/**
 * Plugin Name: Church Bulletin Publisher
 * Description: Builds private bulletin previews from uploaded PDF components, reviews schedule changes, and publishes approved bulletins.
 * Version: 0.4.0-test3
 * Author: St. Mary's and St. Peter the Apostle Parishes
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: church-bulletin-publisher
 */

if (! defined('ABSPATH')) {
    exit;
}

define('CBP_VERSION', '0.4.0-test3');
define('CBP_FILE', __FILE__);
define('CBP_DIR', plugin_dir_path(__FILE__));
define('CBP_URL', plugin_dir_url(__FILE__));

$cbp_autoload = CBP_DIR . 'vendor/autoload.php';
if (is_readable($cbp_autoload)) {
    require_once $cbp_autoload;
}

/*
 * Shared-host fallback: if Composer's generated loader is present but, for any
 * reason, did not register Smalot PDF Parser, load its PSR-4 classes directly
 * from the bundled vendor tree. This keeps the plugin self-contained on hosts
 * such as HostGator where system packages and shell utilities are unavailable.
 */
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

require_once CBP_DIR . 'includes/class-cbp-storage.php';
require_once CBP_DIR . 'includes/class-cbp-pdf-merger.php';
require_once CBP_DIR . 'includes/class-cbp-plugin.php';
require_once CBP_DIR . 'includes/class-cbp-production.php';
require_once CBP_DIR . 'includes/class-cbp-schedule.php';
require_once CBP_DIR . 'includes/class-cbp-bulletin-list.php';
require_once CBP_DIR . 'includes/class-cbp-access.php';
require_once CBP_DIR . 'includes/class-cbp-access-v031.php';

register_activation_hook(__FILE__, array('CBP_Plugin', 'activate'));
register_activation_hook(__FILE__, array('CBP_Schedule', 'activate'));
CBP_Plugin::instance();
CBP_Production::instance();
CBP_Schedule::instance();
CBP_Bulletin_List::instance();
CBP_Access::instance();
CBP_Access_V031::instance();
