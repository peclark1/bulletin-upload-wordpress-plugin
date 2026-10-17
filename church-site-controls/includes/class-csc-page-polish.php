<?php
/**
 * Cutover-specific, staging-only front-end polish.
 *
 * This is intentionally separate from the editable Kadence header/menu options,
 * and applies no global theme-mod changes.
 */
final class CSC_Page_Polish {
    public static function enqueue() {
        if (is_admin() ||
            untrailingslashit(home_url()) !== CSC_Controls::SITE ||
            untrailingslashit(site_url()) !== CSC_Controls::SITE ||
            get_template() !== 'kadence' ||
            !is_page(23)) {
            return;
        }

        $base = plugin_dir_url(dirname(__FILE__) . '/../church-site-controls.php');
        wp_enqueue_style(
            'csc-worship-responsive',
            $base . 'assets/worship-responsive.css',
            array(),
            CSC_Controls::VERSION
        );
    }
}
add_action('wp_enqueue_scripts', array('CSC_Page_Polish', 'enqueue'), 40);
