<?php
/**
 * Staging-only enhancement: safe, progressive desktop mega menus over Kadence's
 * existing classic menu links. No menu item writes or new theme settings.
 */
final class CSC_Mega_Navigation {
    public static function enqueue() {
        if (untrailingslashit(home_url()) !== CSC_Controls::SITE ||
            untrailingslashit(site_url()) !== CSC_Controls::SITE ||
            get_template() !== 'kadence' || is_admin()) {
            return;
        }
        $base = plugin_dir_url(dirname(__FILE__) . '/../church-site-controls.php');
        $version = CSC_Controls::VERSION;
        wp_enqueue_style('csc-mega-navigation', $base . 'assets/mega-navigation.css', array(), $version);
        wp_enqueue_script('csc-mega-navigation', $base . 'assets/mega-navigation.js', array(), $version, true);
    }
}
add_action('wp_enqueue_scripts', array('CSC_Mega_Navigation', 'enqueue'), 30);
