<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Version 0.3.1 refinements for Bulletin Publisher accounts.
 *
 * This keeps the narrow role introduced in 0.3.0, fixes the publisher screen
 * card-hiding bug, and permits trusted bulletin publishers to maintain the
 * saved front/back cover templates as part of the same workflow.
 */
final class CBP_Access_V031
{
    private static $instance;

    public static function instance()
    {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Replace the 0.3.0 publisher-only presentation hooks. The original CSS
        // hid the first card and the footer script then removed it, causing the
        // upload card to become the first card and be hidden as well.
        $access = CBP_Access::instance();
        remove_action('admin_head', array($access, 'publisher_screen_css'));
        remove_action('admin_footer-toplevel_page_' . CBP_Access::PAGE, array($access, 'publisher_screen_polish'), 50);

        add_filter('user_has_cap', array($this, 'allow_cover_updates'), 30, 4);
        add_action('admin_head', array($this, 'publisher_screen_css'));
        add_action('admin_footer-toplevel_page_' . CBP_Access::PAGE, array($this, 'publisher_screen_polish'), 50);
    }

    /**
     * The 0.3.0 access layer intentionally excluded cbp_save_covers. For this
     * small trusted publisher group, allow that one additional plugin action.
     * This does not grant manage_options outside the Bulletin Publisher flow.
     */
    public function allow_cover_updates($allcaps, $caps, $args, $user)
    {
        if (empty($allcaps[CBP_Access::CAPABILITY]) || ! is_admin()) {
            return $allcaps;
        }

        global $pagenow;
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if ($pagenow === 'admin-post.php' && $action === 'cbp_save_covers') {
            $allcaps['manage_options'] = true;
        }

        return $allcaps;
    }

    public function publisher_screen_css()
    {
        if (! $this->is_publisher_admin_screen() || ! $this->current_user_is_publisher_only()) {
            return;
        }
        ?>
        <style id="cbp-publisher-role-ui-v031">
            #adminmenumain,
            #wpadminbar,
            #wpfooter,
            #screen-meta-links {
                display: none !important;
            }

            html.wp-toolbar {
                padding-top: 0 !important;
            }

            #wpcontent,
            #wpfooter {
                margin-left: 0 !important;
            }

            #wpcontent {
                padding-left: 0 !important;
            }

            #wpbody-content {
                float: none !important;
                width: auto !important;
                padding-bottom: 30px !important;
            }

            .cbp-wrap {
                max-width: 980px !important;
                margin: 30px auto !important;
                padding: 0 24px !important;
            }

            .cbp-grid {
                grid-template-columns: 1fr !important;
            }

            .cbp-publisher-session {
                margin: -4px 0 18px;
                color: #50575e;
            }
        </style>
        <?php
    }

    public function publisher_screen_polish()
    {
        if (! $this->current_user_is_publisher_only()) {
            return;
        }

        $user = wp_get_current_user();
        $signed_in = sprintf(
            __('Signed in as %s', 'church-bulletin-publisher'),
            $user->display_name ? $user->display_name : $user->user_login
        );
        $logout_url = wp_logout_url(home_url('/'));
        ?>
        <script>
        (function () {
            var cards = document.querySelectorAll('.cbp-grid > .cbp-card');
            if (cards.length > 0) {
                var coverHeading = cards[0].querySelector('h2');
                if (coverHeading) {
                    coverHeading.textContent = '1. Cover templates';
                }
            }

            var previewForm = document.getElementById('cbp-preview-form');
            var previewCard = previewForm ? previewForm.closest('.cbp-card') : null;
            if (previewCard) {
                var previewHeading = previewCard.querySelector('h2');
                if (previewHeading) {
                    previewHeading.textContent = '2. Create private preview';
                }
            }

            var review = document.querySelector('.cbp-preview > h2');
            if (review) {
                review.textContent = '3. Review and publish';
            }

            document.querySelectorAll('.cbp-preview a').forEach(function (link) {
                if (link.textContent.indexOf('draft test bulletin page') !== -1) {
                    var paragraph = link.closest('p');
                    if (paragraph) {
                        paragraph.remove();
                    }
                }
            });

            var title = document.querySelector('.cbp-wrap > h1');
            if (title && !document.querySelector('.cbp-publisher-session')) {
                var session = document.createElement('p');
                session.className = 'cbp-publisher-session';
                session.appendChild(document.createTextNode(<?php echo wp_json_encode($signed_in . ' · '); ?>));
                var logout = document.createElement('a');
                logout.href = <?php echo wp_json_encode($logout_url); ?>;
                logout.textContent = 'Log out';
                session.appendChild(logout);
                title.insertAdjacentElement('afterend', session);
            }
        }());
        </script>
        <?php
    }

    private function current_user_is_publisher_only()
    {
        $user = wp_get_current_user();
        if (! $user || ! $user->exists()) {
            return false;
        }

        return ! empty($user->allcaps[CBP_Access::CAPABILITY]) && empty($user->allcaps['manage_options']);
    }

    private function is_publisher_admin_screen()
    {
        if (! is_admin()) {
            return false;
        }

        global $pagenow;
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $pagenow === 'admin.php' && $page === CBP_Access::PAGE;
    }
}
