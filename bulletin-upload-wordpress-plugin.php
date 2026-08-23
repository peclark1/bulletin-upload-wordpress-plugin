<?php
/**
 * Plugin Name: Church Bulletin Publisher
 * Description: Builds private bulletin previews from uploaded PDF components and publishes approved bulletins.
 * Version: 0.3.2
 * Author: St. Mary's and St. Peter the Apostle Parishes
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: church-bulletin-publisher
 */

if (! defined('ABSPATH')) {
    exit;
}

define('CBP_VERSION', '0.3.2');
define('CBP_FILE', __FILE__);
define('CBP_DIR', plugin_dir_path(__FILE__));
define('CBP_URL', plugin_dir_url(__FILE__));

require_once CBP_DIR . 'includes/class-cbp-storage.php';
require_once CBP_DIR . 'includes/class-cbp-pdf-merger.php';
require_once CBP_DIR . 'includes/class-cbp-plugin.php';
require_once CBP_DIR . 'includes/class-cbp-production.php';
require_once CBP_DIR . 'includes/class-cbp-access.php';
require_once CBP_DIR . 'includes/class-cbp-access-v031.php';

register_activation_hook(__FILE__, array('CBP_Plugin', 'activate'));
CBP_Plugin::instance();
CBP_Production::instance();
CBP_Access::instance();
CBP_Access_V031::instance();
