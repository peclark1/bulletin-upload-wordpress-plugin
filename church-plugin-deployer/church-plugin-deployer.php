<?php
/**
 * Plugin Name: Church Plugin Deployer
 * Description: Staging-only verified GitHub updates prepared by MCP and installed by administrator confirmation.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/includes/class-cpd.php';
require_once __DIR__ . '/includes/class-cpd-admin.php';
add_action('wp_abilities_api_categories_init', array('CPD', 'register_category'));
add_action('wp_abilities_api_init', array('CPD', 'register_abilities'));
add_action('admin_menu', array('CPD_Admin', 'register'));
add_action('admin_post_cpd_install_prepared', array('CPD_Admin', 'submit'));
