<?php
/**
 * Progressive desktop mega menus for the explicitly authorized parish site
 * (original staging or opted-in production root). No menu or theme writes.
 */
final class CSC_Mega_Navigation {
    public static function enqueue() {
        if (is_admin() || CSC_Controls::site_identity() === false ||
            get_template() !== 'kadence') {
            return;
        }
        $base = plugin_dir_url(dirname(__FILE__) . '/../church-site-controls.php');
        $version = CSC_Controls::VERSION;
        wp_enqueue_style('csc-mega-navigation', $base . 'assets/mega-navigation.css', array(), $version);
        wp_enqueue_script('csc-mega-navigation', $base . 'assets/mega-navigation.js', array(), $version, true);
    }
}
add_action('wp_enqueue_scripts', array('CSC_Mega_Navigation', 'enqueue'), 30);
