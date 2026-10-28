<?php
/**
 * Front-end responsive parish styling on the explicitly authorized staging
 * installation or production root, with no theme or post writes.
 */
final class CSC_Page_Polish {
    public static function enqueue() {
        if (is_admin() || CSC_Controls::site_identity() === false ||
            get_template() !== 'kadence') {
            return;
        }

        $base = plugin_dir_url(dirname(__FILE__) . '/../church-site-controls.php');

        // Global card-row standard on either authorized parish installation.
        // CSS detects pairs of Gutenberg card groups; other columns are untouched.
        wp_enqueue_style(
            'csc-equal-height-cards',
            $base . 'assets/equal-height-cards.css',
            array(),
            CSC_Controls::VERSION
        );

        // Preserve existing Worship-only responsive refinements.
        if (!is_page(23)) {
            return;
        }

        wp_enqueue_style(
            'csc-worship-responsive',
            $base . 'assets/worship-responsive.css',
            array(),
            CSC_Controls::VERSION
        );
    }
}
add_action('wp_enqueue_scripts', array('CSC_Page_Polish', 'enqueue'), 40);
