<?php
/**
 * WordPress administrator confirmation screen for previously prepared updates.
 * This handler never accepts an arbitrary ZIP, URL, repository or plugin path.
 */
final class CPD_Admin {
    const SLUG = 'church-plugin-deployer';
    const ACTION = 'cpd_install_prepared';

    public static function register() {
        if (is_wp_error(CPD::guard())) { return; }
        add_management_page(
            'Church Plugin Deployer',
            'Church Plugin Deployer',
            'manage_options',
            self::SLUG,
            array(__CLASS__, 'page')
        );
    }

    /**
     * Return only unexpired records belonging to the current administrator.
     * Option names are hashes of the temporary MCP token; never show raw tokens.
     */
    public static function prepared() {
        $authorized = CPD::guard();
        if (is_wp_error($authorized)) { return $authorized; }
        global $wpdb;
        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 50",
                $wpdb->esc_like(CPD::STATE_PREFIX) . '%'
            )
        );
        $results = array();
        $targets = CPD::targets();
        foreach ((array)$names as $name) {
            if (!is_string($name) || !preg_match('/\Acpd_ready_[a-f0-9]{64}\z/', $name)) { continue; }
            $r = get_option($name, false);
            if (!is_array($r) || ($r['status'] ?? '') !== 'ready' ||
                ($r['prepared_by'] ?? 0) !== get_current_user_id() ||
                !is_numeric($r['expires'] ?? null) || (int)$r['expires'] <= time() ||
                !isset($targets[$r['plugin'] ?? ''])) { continue; }
            $results[] = array('option' => $name, 'record' => $r);
        }
        return $results;
    }

    public static function page() {
        $authorized = CPD::guard();
        if (is_wp_error($authorized)) {
            wp_die(esc_html($authorized->get_error_message()));
            return;
        }
        $items = self::prepared();
        if (is_wp_error($items)) {
            wp_die(esc_html($items->get_error_message()));
            return;
        }
        $notice_key = 'cpd_admin_notice_' . get_current_user_id();
        $notice = get_transient($notice_key);
        if ($notice !== false) { delete_transient($notice_key); }
        echo '<div class="wrap">';
        echo '<h1>Church Plugin Deployer</h1>';
        echo '<p>Staging only: <code>' . esc_html(CPD::SITE) . '</code>. ';
        echo 'Only verified GitHub builds prepared through the WordPress MCP connection are listed here.</p>';
        if (is_array($notice) && isset($notice['type'], $notice['message']) &&
            in_array($notice['type'], array('success', 'error'), true)) {
            echo '<div class="notice notice-' . esc_attr($notice['type']) . '"><p>' .
                esc_html($notice['message']) . '</p></div>';
        }
        if (!$items) {
            echo '<div class="notice notice-info"><p>No valid prepared updates for this administrator. ';
            echo 'Ask ChatGPT to prepare a newer, tested GitHub build, then refresh this page.</p></div>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th scope="col">Plugin</th><th scope="col">Installed → prepared</th>';
        echo '<th scope="col">GitHub build</th><th scope="col">Verification</th>';
        echo '<th scope="col">Approval</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $name = $item['option'];
            $r = $item['record'];
            $target = CPD::targets()[$r['plugin']];
            $current = CPD::installed($r['plugin']);
            $fresh = !is_wp_error($current) && $current['active'] &&
                $current['version'] === ($r['from_version'] ?? null);
            $expires = max(0, (int)$r['expires'] - time());
            echo '<tr><td><strong>' . esc_html($target['name']) . '</strong></td>';
            echo '<td>' . esc_html($r['from_version'] ?? '?') . ' → ' .
                esc_html($r['to_version'] ?? '?') . '</td>';
            echo '<td>Run ' . esc_html($r['run_id'] ?? '?') .
                '<br><code>' . esc_html(substr((string)($r['commit'] ?? ''), 0, 12)) . '</code></td>';
            echo '<td>Prepared package SHA-256: <code>' .
                esc_html(substr((string)($r['sha256'] ?? ''), 0, 16)) .
                '…</code><br>Recovery ZIP SHA-256: <code>' .
                esc_html(substr((string)($r['backup_sha256'] ?? ''), 0, 16)) .
                '…</code><br>Expires in ' . esc_html(ceil($expires / 60)) . ' minutes</td>';
            echo '<td>';
            if (!$fresh) {
                echo '<strong>Stale installation — reprepare required</strong>';
            } else {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
                echo '<input type="hidden" name="prepared" value="' . esc_attr($name) . '">';
                wp_nonce_field(self::ACTION . '_' . $name, 'cpd_nonce');
                submit_button('Install Prepared Update', 'primary', 'submit', false);
                echo '</form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><strong>Before installing:</strong> verify the plugin, versions, GitHub run, and recovery copy. ';
        echo 'This replaces executable WordPress plugin files and may interrupt the staging site if the update fails. ';
        echo 'A recovery ZIP is preserved, but automatic rollback is not implemented.</p>';
        echo '</div>';
    }

    /**
     * Pure request validation and shared deployment invocation. The nonce is
     * scoped to one hashed preparation record; the server checks everything
     * again before the native WordPress upgrader writes any files.
     */
    public static function apply_request($method, $post) {
        $authorized = CPD::guard();
        if (is_wp_error($authorized)) { return $authorized; }
        if ($method !== 'POST' || !is_array($post)) {
            return new WP_Error('cpd_method', 'An authenticated POST is required.');
        }
        $key = isset($post['prepared']) && is_string($post['prepared']) ?
            wp_unslash($post['prepared']) : '';
        if (!is_string($key) || !preg_match('/\Acpd_ready_[a-f0-9]{64}\z/', $key)) {
            return new WP_Error('cpd_record', 'Invalid prepared update identifier.');
        }
        $nonce = isset($post['cpd_nonce']) && is_string($post['cpd_nonce']) ?
            wp_unslash($post['cpd_nonce']) : '';
        if (!is_string($nonce) || !wp_verify_nonce($nonce, self::ACTION . '_' . $key)) {
            return new WP_Error('cpd_nonce', 'Expired or invalid WordPress installation confirmation.');
        }
        return CPD_Package::deploy_prepared($key);
    }

    public static function submit() {
        $result = self::apply_request($_SERVER['REQUEST_METHOD'] ?? '', $_POST);
        $message = is_wp_error($result) ? $result->get_error_message() :
            'Plugin update completed; independently verify the installed plugin version and site behavior.';
        $type = is_wp_error($result) ? 'error' : 'success';
        if (current_user_can('manage_options')) {
            set_transient('cpd_admin_notice_' . get_current_user_id(),
                array('type' => $type, 'message' => $message), 180);
        }
        wp_safe_redirect(admin_url('tools.php?page=' . self::SLUG));
        exit;
    }
}
