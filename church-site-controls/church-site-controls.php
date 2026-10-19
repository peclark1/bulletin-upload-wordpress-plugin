<?php
/**
 * Plugin Name: Church Site Controls
 * Description: Staging-only, reversible Kadence appearance, menu and hero edits via WordPress Abilities.
 * Version: 0.3.6
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/includes/class-csc-hero.php';
require_once __DIR__ . '/includes/class-csc-global.php';
require_once __DIR__ . '/includes/class-csc-navigation.php';
require_once __DIR__ . '/includes/class-csc-controls.php';
require_once __DIR__ . '/includes/class-csc-mega-navigation.php';
require_once __DIR__ . '/includes/class-csc-page-polish.php';
add_action('wp_abilities_api_categories_init', array('CSC_Controls', 'register_category'));
add_action('wp_abilities_api_init', array('CSC_Controls', 'register_abilities'));
