<?php
/**
 * Plugin Name: Parish Forms
 * Description: Secure, parish-branded forms with private submissions, email notifications, and CSV export.
 * Version: 0.1.0
 * Author: St. Mary's and St. Peter the Apostle Parishes
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: parish-forms
 */

if (! defined('ABSPATH')) {
    exit;
}

define('PFORM_VERSION', '0.1.0');
define('PFORM_FILE', __FILE__);
define('PFORM_DIR', plugin_dir_path(__FILE__));
define('PFORM_URL', plugin_dir_url(__FILE__));

require_once PFORM_DIR . 'includes/forms/class-pform-parish-registration.php';
require_once PFORM_DIR . 'includes/class-pform-form-registry.php';
require_once PFORM_DIR . 'includes/class-pform-validator.php';
require_once PFORM_DIR . 'includes/class-pform-formatter.php';
require_once PFORM_DIR . 'includes/class-pform-submissions.php';
require_once PFORM_DIR . 'includes/class-pform-notifications.php';
require_once PFORM_DIR . 'includes/class-pform-renderer.php';
require_once PFORM_DIR . 'includes/class-pform-admin.php';
require_once PFORM_DIR . 'includes/class-pform-plugin.php';

register_activation_hook(__FILE__, array('PFORM_Plugin', 'activate'));
PFORM_Plugin::instance();
