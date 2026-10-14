<?php
/**
 * Plugin Name: Church Site Controls
 * Description: Staging-only, narrowly scoped header/hero inspection and reversible hero edits via WordPress Abilities.
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/includes/class-csc-hero.php';
require_once __DIR__ . '/includes/class-csc-controls.php';
add_action('wp_abilities_api_categories_init', array('CSC_Controls', 'register_category'));
add_action('wp_abilities_api_init', array('CSC_Controls', 'register_abilities'));
