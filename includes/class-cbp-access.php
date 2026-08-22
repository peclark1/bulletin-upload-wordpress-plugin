<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Provides a deliberately narrow WordPress role and a friendly entry URL for
 * the people who assemble and publish weekly bulletins.
 *
 * The existing publisher code predates this role and protects its actions with
 * manage_options. Rather than broadening that capability globally, this class
 * maps manage_options only while WordPress is executing the Bulletin Publisher
 * screen or one of its explicitly allowed admin-post actions. The cover-template
 * save action is intentionally excluded and remains administrator-only.
 */
final class CBP_Access
{
    const ROLE = 'bulletin_publisher';
    const CAPABILITY = 'manage_church_bulletins';
    const PAGE = 'church-bulletin-publisher';
    const FRIENDLY_PATH = 'bulletin-publisher';

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
        add_action('init', array($this, 'ensure_role'), 5);
        add_filter('user_has_cap', array($this, 'map_legacy_plugin_capability'), 20, 4);
        add_action('template_redirect', array($this, 'friendly_entrypoint'), 0);
        add_action('admin_init', array($this, 'restrict_publisher_admin'), 1);
        add_filter('show_admin_bar', array($this, 'hide_frontend_admin_bar'));
        add_action('admin_head', array($this, 'publisher_screen_css'));
        add_action('admin_footer-toplevel_page_' . self::PAGE, array($this, 'publisher_screen_polish'), 50);
    }

    /**
     * Idempotently create/repair the narrow publisher role.
     *
     * Running this on init is intentional: WordPress does not necessarily run a
     * plugin activation hook when an already-active plugin is replaced by an
     * uploaded update.
     */
    public function ensure_role()
    {
        $role = get_role(self::ROLE);
        if (! $role) {
            $role = add_role(
                self::ROLE,
                __('Bulletin Publisher', 'church-bulletin-publisher'),
                array(
                    'read' => true,
                    self::CAPABILITY => true,
                )
            );
        }

        if ($role) {
            $role->add_cap('read', true);
            $role->add_cap(self::CAPABILITY, true);
        }
    }

    /**
     * Allow the narrow role through the plugin's existing manage_options checks,
     * but ONLY during this plugin's screen/actions. This is not a global grant.
     */
    public function map_legacy_plugin_capability($allcaps, $caps, $args, $user)
    {
        if (empty($allcaps[self::CAPABILITY]) || ! $this->is_allowed_plugin_request()) {
            return $allcaps;
        }

        $allcaps['manage_options'] = true;
        return $allcaps;
    }

    /**
     * Friendly bookmark: /bulletin-publisher/
     *
     * Logged-out visitors go through normal WordPress login and return here.
     * Authorized users are then sent to the existing, tested publisher screen.
     */
    public function friendly_entrypoint()
    {
        if (! $this->is_friendly_request()) {
            return;
        }

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);

        if (! is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($this->friendly_url()));
            exit;
        }

        if (! $this->current_user_is_authorized()) {
            wp_die(
                esc_html__('You are not allowed to use the Bulletin Publisher.', 'church-bulletin-publisher'),
                esc_html__('Access denied', 'church-bulletin-publisher'),
                array('response' => 403)
            );
        }

        wp_safe_redirect($this->admin_publisher_url());
        exit;
    }

    /**
     * Keep publisher-only accounts out of the rest of wp-admin. They may use the
     * publisher itself and their Profile page (for password/name maintenance).
     * admin-post/admin-ajax must remain available for the publisher workflow.
     */
    public function restrict_publisher_admin()
    {
        if (! $this->current_user_is_publisher_only()) {
            return;
        }

        global $pagenow;

        if ($pagenow === 'admin-post.php' || $pagenow === 'admin-ajax.php' || $pagenow === 'profile.php') {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($pagenow === 'admin.php' && $page === self::PAGE) {
            return;
        }

        wp_safe_redirect($this->admin_publisher_url());
        exit;
    }

    public function hide_frontend_admin_bar($show)
    {
        return $this->current_user_is_publisher_only() ? false : $show;
    }

    /**
     * Make the plugin's wp-admin screen look like a focused application for the
     * narrow role. The cover-template card is hidden; its backend action also
     * remains unavailable because cbp_save_covers is not capability-mapped.
     */
    public function publisher_screen_css()
    {
        if (! $this->is_publisher_admin_screen() || ! $this->current_user_is_publisher_only()) {
            return;
        }
        ?>
        <style id="cbp-publisher-role-ui">
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

            /* Cover templates remain an administrator-only maintenance task. */
            .cbp-grid > .cbp-card:first-child {
                display: none !important;
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
            var grid = document.querySelector('.cbp-grid');
            if (grid && grid.firstElementChild) {
                grid.firstElementChild.remove();
            }

            var previewForm = document.getElementById('cbp-preview-form');
            var previewCard = previewForm ? previewForm.closest('.cbp-card') : null;
            if (previewCard) {
                var heading = previewCard.querySelector('h2');
                if (heading) {
                    heading.textContent = '1. Create private preview';
                }
            }

            var review = document.querySelector('.cbp-preview > h2');
            if (review) {
                review.textContent = '2. Review and publish';
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

    private function is_allowed_plugin_request()
    {
        if (! is_admin()) {
            return false;
        }

        global $pagenow;
        $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash($_REQUEST['page'])) : '';
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';

        if ($pagenow === 'admin.php' && $page === self::PAGE) {
            return true;
        }

        if ($pagenow !== 'admin-post.php') {
            return false;
        }

        // cbp_save_covers is intentionally absent: only administrators may change covers.
        $allowed_actions = array(
            'cbp_create_preview',
            'cbp_get_cover',
            'cbp_upload_chunk',
            'cbp_finalize_browser_preview',
            'cbp_view_preview',
            'cbp_publish',
            'cbp_discard',
            'cbp_publish_live',
        );

        return in_array($action, $allowed_actions, true);
    }

    private function current_user_is_authorized()
    {
        $user = wp_get_current_user();
        if (! $user || ! $user->exists()) {
            return false;
        }

        // Inspect stored capabilities directly so this decision does not depend
        // on the narrowly scoped manage_options mapping above.
        return ! empty($user->allcaps['manage_options']) || ! empty($user->allcaps[self::CAPABILITY]);
    }

    private function current_user_is_publisher_only()
    {
        $user = wp_get_current_user();
        if (! $user || ! $user->exists()) {
            return false;
        }

        return ! empty($user->allcaps[self::CAPABILITY]) && empty($user->allcaps['manage_options']);
    }

    private function is_publisher_admin_screen()
    {
        if (! is_admin()) {
            return false;
        }

        global $pagenow;
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $pagenow === 'admin.php' && $page === self::PAGE;
    }

    private function is_friendly_request()
    {
        if (empty($_SERVER['REQUEST_URI'])) {
            return false;
        }

        $request_path = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        $target_path = wp_parse_url($this->friendly_url(), PHP_URL_PATH);
        if (! is_string($request_path) || ! is_string($target_path)) {
            return false;
        }

        return untrailingslashit(rawurldecode($request_path)) === untrailingslashit(rawurldecode($target_path));
    }

    private function friendly_url()
    {
        return home_url('/' . self::FRIENDLY_PATH . '/');
    }

    private function admin_publisher_url()
    {
        return add_query_arg('page', self::PAGE, admin_url('admin.php'));
    }
}
