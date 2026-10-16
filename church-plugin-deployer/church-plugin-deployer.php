<?php
/**
 * Plugin Name: Church Plugin Deployer
 * Description: Staging-only, allowlisted, two-step GitHub Actions plugin upgrades via WordPress Abilities.
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/includes/class-cpd.php';
add_action('wp_abilities_api_categories_init', array('CPD', 'register_category'));
add_action('wp_abilities_api_init', array('CPD', 'register_abilities'));
