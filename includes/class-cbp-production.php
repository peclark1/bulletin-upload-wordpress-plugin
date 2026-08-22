<?php

if (! defined('ABSPATH')) {
    exit;
}

final class CBP_Production
{
    const INITIAL_BACKUP_OPTION = 'cbp_initial_live_backup';

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
        add_action('admin_post_cbp_publish_live', array($this, 'publish_live'));
        add_action('admin_footer-toplevel_page_church-bulletin-publisher', array($this, 'render_live_controls'));
    }

    public function render_live_controls()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $preview = get_transient($this->preview_key());
        $backup = get_option(self::INITIAL_BACKUP_OPTION, array());
        ?>
        <div id="cbp-production-panel" style="display:none; margin-top:20px; padding:16px; border:1px solid #c3c4c7; border-left:4px solid #d63638; background:#fff;">
            <h3 style="margin-top:0;"><?php esc_html_e('Production publishing', 'church-bulletin-publisher'); ?></h3>
            <p><?php esc_html_e('Live bulletins are written to wp-content/bulletins/YYYY and are immediately public.', 'church-bulletin-publisher'); ?></p>
            <?php if (is_array($backup) && ! empty($backup['created_utc'])) : ?>
                <p class="description">
                    <?php echo esc_html(sprintf(__('Initial live-tree backup created %s UTC.', 'church-bulletin-publisher'), $backup['created_utc'])); ?>
                </p>
            <?php else : ?>
                <p class="description"><?php esc_html_e('Before the first live publication, the plugin will automatically make a protected backup of the existing bulletin tree.', 'church-bulletin-publisher'); ?></p>
            <?php endif; ?>

            <?php if ($preview) : ?>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" onsubmit="return window.confirm('Publish this reviewed PDF to the LIVE bulletin directory?');">
                    <input type="hidden" name="action" value="cbp_publish_live">
                    <?php wp_nonce_field('cbp_publish_live'); ?>
                    <label style="display:block; margin:12px 0; font-weight:600;">
                        <input type="checkbox" name="confirm_live" value="1" required>
                        <?php esc_html_e('I reviewed the PDF preview and intend to publish it to the live website.', 'church-bulletin-publisher'); ?>
                    </label>
                    <?php submit_button(__('Publish LIVE Bulletin', 'church-bulletin-publisher'), 'primary', 'submit', false); ?>
                </form>
            <?php else : ?>
                <p class="description"><?php esc_html_e('Create and review a private preview before publishing live.', 'church-bulletin-publisher'); ?></p>
            <?php endif; ?>

            <p class="description" style="margin-bottom:0;">
                <?php esc_html_e('The isolated test publish button remains available above. Production publications and backups are recorded in the protected audit log.', 'church-bulletin-publisher'); ?>
            </p>
        </div>
        <script>
        (function () {
            var panel = document.getElementById('cbp-production-panel');
            var preview = document.querySelector('.cbp-preview');
            if (panel && preview) {
                panel.style.display = 'block';
                preview.appendChild(panel);
            }

            var mode = document.querySelector('.cbp-mode');
            if (mode) {
                mode.classList.remove('cbp-mode--test');
                mode.innerHTML = '<strong>Preview-first workflow is active.</strong> Use <em>Publish to Test Area</em> for isolated testing, or the separately confirmed <em>Publish LIVE Bulletin</em> control after reviewing the exact PDF.';
            }
        }());
        </script>
        <?php
    }

    public function publish_live()
    {
        $this->authorize();
        check_admin_referer('cbp_publish_live');

        if (empty($_POST['confirm_live']) || sanitize_text_field(wp_unslash($_POST['confirm_live'])) !== '1') {
            $this->redirect('error', __('Live publication was not confirmed.', 'church-bulletin-publisher'));
        }

        $preview = get_transient($this->preview_key());
        if (! is_array($preview) || empty($preview['path']) || empty($preview['sha256']) || ! CBP_PDF_Merger::is_pdf($preview['path'])) {
            $this->redirect('error', __('The reviewed preview is missing or expired. Build it again.', 'church-bulletin-publisher'));
        }

        $actual_sha = hash_file('sha256', $preview['path']);
        if (! is_string($actual_sha) || ! hash_equals($preview['sha256'], $actual_sha)) {
            $this->audit('publish_rejected', array(
                'bulletin_date' => isset($preview['date']) ? $preview['date'] : '',
                'reason' => 'preview_sha256_mismatch',
            ));
            $this->redirect('error', __('The reviewed preview changed after review. Build it again.', 'church-bulletin-publisher'));
        }

        if (empty($preview['date']) || ! $this->valid_date($preview['date'])) {
            $this->redirect('error', __('The preview has an invalid bulletin date.', 'church-bulletin-publisher'));
        }

        $initial_backup = $this->ensure_initial_live_backup();
        if (is_wp_error($initial_backup)) {
            $this->audit('initial_backup_failed', array(
                'bulletin_date' => $preview['date'],
                'reason' => $initial_backup->get_error_message(),
            ));
            $this->redirect('error', $initial_backup->get_error_message());
        }

        $stamp = DateTimeImmutable::createFromFormat('!Y-m-d', $preview['date']);
        $year = $stamp->format('Y');
        $filename = $stamp->format('mdy') . 'bulletin.pdf';
        $directory = trailingslashit(CBP_Storage::published_root(false)) . $year;

        if (! wp_mkdir_p($directory)) {
            $this->audit('publish_failed', array(
                'bulletin_date' => $preview['date'],
                'filename' => $filename,
                'reason' => 'live_directory_create_failed',
            ));
            $this->redirect('error', __('Could not create the live bulletin directory.', 'church-bulletin-publisher'));
        }

        $destination = trailingslashit($directory) . $filename;
        $replacement_backup = '';
        $replaced_existing = file_exists($destination);
        if ($replaced_existing) {
            $replacement_backup = $this->backup_replaced_file($destination, $year, $filename);
            if (is_wp_error($replacement_backup)) {
                $this->audit('publish_failed', array(
                    'bulletin_date' => $preview['date'],
                    'filename' => $filename,
                    'reason' => $replacement_backup->get_error_message(),
                ));
                $this->redirect('error', $replacement_backup->get_error_message());
            }
        }

        $temporary = $destination . '.tmp-' . wp_generate_password(12, false, false);
        if (! copy($preview['path'], $temporary)) {
            @unlink($temporary);
            $this->audit('publish_failed', array(
                'bulletin_date' => $preview['date'],
                'filename' => $filename,
                'reason' => 'copy_to_live_temp_failed',
            ));
            $this->redirect('error', __('Could not copy the reviewed PDF into the live bulletin directory.', 'church-bulletin-publisher'));
        }

        @chmod($temporary, 0644);
        if (! rename($temporary, $destination)) {
            @unlink($temporary);
            $this->audit('publish_failed', array(
                'bulletin_date' => $preview['date'],
                'filename' => $filename,
                'reason' => 'atomic_rename_failed',
            ));
            $this->redirect('error', __('Could not complete the live publication atomically.', 'church-bulletin-publisher'));
        }
        @chmod($destination, 0644);

        $this->audit('publish_live', array(
            'bulletin_date' => $preview['date'],
            'filename' => $filename,
            'sha256' => $actual_sha,
            'replaced_existing' => $replaced_existing,
            'replacement_backup' => is_string($replacement_backup) ? $replacement_backup : '',
            'initial_backup' => is_string($initial_backup) ? $initial_backup : '',
        ));

        if (! empty($preview['job'])) {
            CBP_Storage::delete_tree($preview['job']);
        }
        delete_transient($this->preview_key());

        $this->redirect('success', sprintf(__('Published %s to the LIVE bulletin directory.', 'church-bulletin-publisher'), $filename));
    }

    private function ensure_initial_live_backup()
    {
        $existing = get_option(self::INITIAL_BACKUP_OPTION, array());
        if (is_array($existing) && ! empty($existing['created_utc'])) {
            return isset($existing['path']) ? $existing['path'] : '';
        }

        $result = CBP_Storage::ensure_directories();
        if (is_wp_error($result)) {
            return $result;
        }

        $live_root = CBP_Storage::published_root(false);
        $timestamp = gmdate('Ymd-His');
        $backup_root = trailingslashit(CBP_Storage::private_root()) . 'live-backups/' . $timestamp;
        if (! wp_mkdir_p($backup_root)) {
            return new WP_Error('cbp_live_backup_directory', __('Could not create the protected initial live backup directory. Live publication was stopped.', 'church-bulletin-publisher'));
        }

        if (is_dir($live_root)) {
            $copied = $this->copy_tree($live_root, $backup_root);
            if (is_wp_error($copied)) {
                CBP_Storage::delete_tree($backup_root);
                return $copied;
            }
        }

        $manifest = "Church Bulletin Publisher initial production backup\n";
        $manifest .= 'Created UTC: ' . gmdate('c') . "\n";
        $manifest .= 'Source: ' . wp_normalize_path($live_root) . "\n";
        @file_put_contents(trailingslashit($backup_root) . 'BACKUP.txt', $manifest, LOCK_EX);

        $record = array(
            'created_utc' => gmdate('Y-m-d H:i:s'),
            'path' => wp_normalize_path($backup_root),
        );
        update_option(self::INITIAL_BACKUP_OPTION, $record, false);
        $this->audit('initial_live_backup', $record);
        return $record['path'];
    }

    private function copy_tree($source_root, $destination_root)
    {
        $source_root = untrailingslashit(wp_normalize_path($source_root));
        $destination_root = untrailingslashit(wp_normalize_path($destination_root));

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source_root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    continue;
                }

                $item_path = wp_normalize_path($item->getPathname());
                $relative = ltrim(substr($item_path, strlen($source_root)), '/');
                if ($relative === '') {
                    continue;
                }
                $target = trailingslashit($destination_root) . $relative;

                if ($item->isDir()) {
                    if (! wp_mkdir_p($target)) {
                        return new WP_Error('cbp_live_backup_mkdir', __('Could not create part of the protected live backup. Live publication was stopped.', 'church-bulletin-publisher'));
                    }
                } else {
                    $parent = dirname($target);
                    if (! wp_mkdir_p($parent) || ! copy($item_path, $target)) {
                        return new WP_Error('cbp_live_backup_copy', __('Could not copy the existing live bulletins into protected backup storage. Live publication was stopped.', 'church-bulletin-publisher'));
                    }
                    @chmod($target, 0640);
                }
            }
        } catch (Exception $exception) {
            return new WP_Error('cbp_live_backup_exception', __('Could not complete the protected live backup. Live publication was stopped.', 'church-bulletin-publisher'));
        }

        return true;
    }

    private function backup_replaced_file($source, $year, $filename)
    {
        $directory = trailingslashit(CBP_Storage::private_root()) . 'live-replacements/' . sanitize_file_name($year);
        if (! wp_mkdir_p($directory)) {
            return new WP_Error('cbp_replacement_backup_directory', __('Could not create protected replacement-backup storage. Live publication was stopped.', 'church-bulletin-publisher'));
        }

        $backup = trailingslashit($directory) . gmdate('Ymd-His') . '-' . sanitize_file_name($filename);
        if (! copy($source, $backup)) {
            return new WP_Error('cbp_replacement_backup_copy', __('Could not back up the existing live bulletin. Live publication was stopped.', 'church-bulletin-publisher'));
        }
        @chmod($backup, 0640);
        return wp_normalize_path($backup);
    }

    private function audit($event, array $context = array())
    {
        $result = CBP_Storage::ensure_directories();
        if (is_wp_error($result)) {
            return;
        }

        $directory = trailingslashit(CBP_Storage::private_root()) . 'audit';
        if (! wp_mkdir_p($directory)) {
            return;
        }

        $user = wp_get_current_user();
        $record = array_merge(array(
            'time_utc' => gmdate('c'),
            'event' => sanitize_key($event),
            'user_id' => get_current_user_id(),
            'user_login' => ($user && $user->exists()) ? $user->user_login : '',
        ), $context);

        $line = wp_json_encode($record, JSON_UNESCAPED_SLASHES);
        if (! is_string($line)) {
            return;
        }

        $file = trailingslashit($directory) . 'production-audit.jsonl';
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        @chmod($file, 0600);
    }

    private function preview_key()
    {
        return 'cbp_preview_' . get_current_user_id();
    }

    private function authorize()
    {
        if (! current_user_can('manage_options')) {
            wp_die(
                esc_html__('You are not allowed to publish bulletins.', 'church-bulletin-publisher'),
                esc_html__('Access denied', 'church-bulletin-publisher'),
                array('response' => 403)
            );
        }
    }

    private function valid_date($date)
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private function redirect($type, $message)
    {
        $url = add_query_arg(array(
            'page' => 'church-bulletin-publisher',
            'cbp_notice' => $type,
            'cbp_message' => $message,
        ), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }
}
