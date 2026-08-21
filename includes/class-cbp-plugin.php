<?php

if (! defined('ABSPATH')) {
    exit;
}

final class CBP_Plugin
{
    const OPTION = 'cbp_settings';
    const PREVIEW_TTL = 2 * DAY_IN_SECONDS;
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
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_post_cbp_save_covers', array($this, 'save_covers'));
        add_action('admin_post_cbp_create_preview', array($this, 'create_preview'));
        add_action('admin_post_cbp_view_preview', array($this, 'view_preview'));
        add_action('admin_post_cbp_publish', array($this, 'publish'));
        add_action('admin_post_cbp_discard', array($this, 'discard'));
        add_shortcode('church_bulletins', array($this, 'bulletins_shortcode'));
    }

    public static function activate()
    {
        CBP_Storage::ensure_directories();
        if (! get_option(self::OPTION)) {
            add_option(self::OPTION, array('test_mode' => true, 'front_cover' => '', 'back_cover' => ''));
        }

        if (! get_option('cbp_test_page_id')) {
            $page_id = wp_insert_post(array(
                'post_title' => 'Bulletin Publisher Test',
                'post_content' => '<!-- wp:shortcode -->[church_bulletins mode="test"]<!-- /wp:shortcode -->',
                'post_status' => 'draft',
                'post_type' => 'page',
            ));
            if (! is_wp_error($page_id)) {
                add_option('cbp_test_page_id', $page_id);
            }
        }
    }

    public function admin_menu()
    {
        add_menu_page(
            __('Bulletin Publisher', 'church-bulletin-publisher'),
            __('Bulletin Publisher', 'church-bulletin-publisher'),
            'manage_options',
            'church-bulletin-publisher',
            array($this, 'render_admin'),
            'dashicons-media-document',
            58
        );
    }

    public function admin_assets($hook)
    {
        if ($hook !== 'toplevel_page_church-bulletin-publisher') {
            return;
        }
        wp_enqueue_style('cbp-admin', CBP_URL . 'assets/admin.css', array(), CBP_VERSION);
        wp_enqueue_script('cbp-admin', CBP_URL . 'assets/admin.js', array(), CBP_VERSION, true);
    }

    public function render_admin()
    {
        $this->authorize();
        $settings = $this->settings();
        $preview = $this->get_preview();
        $diagnostic = CBP_PDF_Merger::diagnostic();
        $test_page_id = absint(get_option('cbp_test_page_id'));
        ?>
        <div class="wrap cbp-wrap">
            <h1><?php esc_html_e('Bulletin Publisher', 'church-bulletin-publisher'); ?></h1>
            <?php $this->render_notice(); ?>
            <div class="cbp-mode cbp-mode--test">
                <strong><?php esc_html_e('Test mode is active.', 'church-bulletin-publisher'); ?></strong>
                <?php esc_html_e('Publishing writes only to the isolated test directory. The current bulletin page and live files are not changed.', 'church-bulletin-publisher'); ?>
            </div>

            <div class="cbp-grid">
                <section class="cbp-card">
                    <h2><?php esc_html_e('1. Cover templates', 'church-bulletin-publisher'); ?></h2>
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="cbp_save_covers">
                        <?php wp_nonce_field('cbp_save_covers'); ?>
                        <label><?php esc_html_e('Front cover PDF', 'church-bulletin-publisher'); ?>
                            <input type="file" name="front_cover" accept="application/pdf,.pdf">
                        </label>
                        <p class="description"><?php echo $settings['front_cover'] ? esc_html(basename($settings['front_cover'])) : esc_html__('Not uploaded', 'church-bulletin-publisher'); ?></p>
                        <label><?php esc_html_e('Back cover PDF', 'church-bulletin-publisher'); ?>
                            <input type="file" name="back_cover" accept="application/pdf,.pdf">
                        </label>
                        <p class="description"><?php echo $settings['back_cover'] ? esc_html(basename($settings['back_cover'])) : esc_html__('Not uploaded', 'church-bulletin-publisher'); ?></p>
                        <?php submit_button(__('Save cover templates', 'church-bulletin-publisher'), 'secondary'); ?>
                    </form>
                </section>

                <section class="cbp-card">
                    <h2><?php esc_html_e('2. Create private preview', 'church-bulletin-publisher'); ?></h2>
                    <?php if (is_wp_error($diagnostic)) : ?>
                        <div class="notice notice-error inline"><p><?php echo esc_html($diagnostic->get_error_message()); ?></p></div>
                    <?php else : ?>
                        <p class="description"><?php echo esc_html(sprintf(__('PDF merger detected: %s', 'church-bulletin-publisher'), $diagnostic['name'])); ?></p>
                    <?php endif; ?>
                    <form id="cbp-preview-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="cbp_create_preview">
                        <?php wp_nonce_field('cbp_create_preview'); ?>
                        <label><?php esc_html_e('Bulletin Sunday', 'church-bulletin-publisher'); ?>
                            <input type="date" name="bulletin_date" required>
                        </label>
                        <label><?php esc_html_e('Weekly PDF file(s)', 'church-bulletin-publisher'); ?>
                            <input id="cbp-files" type="file" name="components[]" accept="application/pdf,.pdf" multiple>
                        </label>
                        <p class="description"><?php esc_html_e('Choose one PDF or several PDFs directly.', 'church-bulletin-publisher'); ?></p>
                        <label><?php esc_html_e('Or choose an entire weekly folder', 'church-bulletin-publisher'); ?>
                            <input id="cbp-folder" type="file" name="folder_components[]" accept="application/pdf,.pdf" webkitdirectory directory multiple>
                        </label>
                        <p class="description"><?php esc_html_e('Use this option for a dated Google Drive folder. Weekly Pages are placed before Inserts; files within each group use filename order.', 'church-bulletin-publisher'); ?></p>
                        <ol id="cbp-file-list" class="cbp-file-list"></ol>
                        <?php submit_button(__('Create Preview', 'church-bulletin-publisher'), 'primary', 'submit', false, is_wp_error($diagnostic) ? array('disabled' => 'disabled') : array()); ?>
                    </form>
                </section>
            </div>

            <section class="cbp-card cbp-preview">
                <h2><?php esc_html_e('3. Review and publish', 'church-bulletin-publisher'); ?></h2>
                <?php if (! $preview) : ?>
                    <p><?php esc_html_e('No private preview has been created in this browser session.', 'church-bulletin-publisher'); ?></p>
                <?php else : ?>
                    <p><strong><?php echo esc_html($this->format_date($preview['date'])); ?></strong></p>
                    <ol>
                        <?php foreach ($preview['manifest'] as $item) : ?>
                            <li><?php echo esc_html($item); ?></li>
                        <?php endforeach; ?>
                    </ol>
                    <div class="cbp-actions">
                        <a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cbp_view_preview'), 'cbp_view_preview')); ?>"><?php esc_html_e('View PDF Preview', 'church-bulletin-publisher'); ?></a>
                        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                            <input type="hidden" name="action" value="cbp_publish">
                            <?php wp_nonce_field('cbp_publish'); ?>
                            <?php submit_button(__('Publish to Test Area', 'church-bulletin-publisher'), 'secondary', 'submit', false); ?>
                        </form>
                        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                            <input type="hidden" name="action" value="cbp_discard">
                            <?php wp_nonce_field('cbp_discard'); ?>
                            <?php submit_button(__('Discard Preview', 'church-bulletin-publisher'), 'delete', 'submit', false); ?>
                        </form>
                    </div>
                <?php endif; ?>
                <?php if ($test_page_id) : ?>
                    <p><a href="<?php echo esc_url(get_preview_post_link($test_page_id)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open the draft test bulletin page', 'church-bulletin-publisher'); ?></a></p>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    public function save_covers()
    {
        $this->authorize();
        check_admin_referer('cbp_save_covers');
        $settings = $this->settings();
        $directory = trailingslashit(CBP_Storage::private_root()) . 'covers';
        wp_mkdir_p($directory);

        foreach (array('front_cover' => 'front-cover.pdf', 'back_cover' => 'back-cover.pdf') as $field => $filename) {
            if (empty($_FILES[$field]['name'])) {
                continue;
            }
            $saved = $this->save_uploaded_pdf($_FILES[$field], $directory, $filename);
            if (is_wp_error($saved)) {
                $this->redirect('error', $saved->get_error_message());
            }
            $settings[$field] = $saved;
        }
        update_option(self::OPTION, $settings, false);
        $this->redirect('success', __('Cover templates saved.', 'church-bulletin-publisher'));
    }

    public function create_preview()
    {
        $this->authorize();
        check_admin_referer('cbp_create_preview');
        $date = isset($_POST['bulletin_date']) ? sanitize_text_field(wp_unslash($_POST['bulletin_date'])) : '';
        if (! $this->valid_date($date)) {
            $this->redirect('error', __('Choose a valid bulletin date.', 'church-bulletin-publisher'));
        }

        $settings = $this->settings();
        if (! CBP_PDF_Merger::is_pdf($settings['front_cover']) || ! CBP_PDF_Merger::is_pdf($settings['back_cover'])) {
            $this->redirect('error', __('Upload both cover templates before creating a preview.', 'church-bulletin-publisher'));
        }

        $job = CBP_Storage::create_job_directory(get_current_user_id());
        if (is_wp_error($job)) {
            $this->redirect('error', $job->get_error_message());
        }

        $uploads = array_merge(
            $this->normalize_uploads(isset($_FILES['components']) ? $_FILES['components'] : array()),
            $this->normalize_uploads(isset($_FILES['folder_components']) ? $_FILES['folder_components'] : array())
        );
        $components = array();
        foreach ($uploads as $index => $upload) {
            $relative = sanitize_text_field(wp_unslash($upload['name']));
            if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'pdf') {
                continue;
            }
            $saved = $this->save_uploaded_pdf($upload, $job, sprintf('component-%03d.pdf', $index + 1));
            if (is_wp_error($saved)) {
                CBP_Storage::delete_tree($job);
                $this->redirect('error', $saved->get_error_message());
            }
            $components[] = array(
                'path' => $saved,
                'label' => $relative,
                'group' => stripos($relative, 'insert') !== false ? 20 : 10,
            );
        }

        if (! $components) {
            CBP_Storage::delete_tree($job);
            $this->redirect('error', __('Select at least one PDF file or a folder containing PDF files.', 'church-bulletin-publisher'));
        }

        usort($components, function ($a, $b) {
            if ($a['group'] !== $b['group']) {
                return $a['group'] - $b['group'];
            }
            return strnatcasecmp($a['label'], $b['label']);
        });

        $inputs = array($settings['front_cover']);
        $manifest = array(__('Front cover', 'church-bulletin-publisher'));
        foreach ($components as $component) {
            $inputs[] = $component['path'];
            $manifest[] = $component['label'];
        }
        $inputs[] = $settings['back_cover'];
        $manifest[] = __('Back cover', 'church-bulletin-publisher');

        $output = trailingslashit($job) . 'preview.pdf';
        $merged = CBP_PDF_Merger::merge($inputs, $output);
        if (is_wp_error($merged)) {
            CBP_Storage::delete_tree($job);
            $this->redirect('error', $merged->get_error_message());
        }

        $this->discard_preview();
        set_transient($this->preview_key(), array(
            'date' => $date,
            'path' => $output,
            'job' => $job,
            'sha256' => hash_file('sha256', $output),
            'manifest' => $manifest,
        ), self::PREVIEW_TTL);
        $this->redirect('success', __('Private preview created. Review it before publishing.', 'church-bulletin-publisher'));
    }

    public function view_preview()
    {
        $this->authorize();
        check_admin_referer('cbp_view_preview');
        $preview = $this->get_preview();
        if (! $preview || ! CBP_PDF_Merger::is_pdf($preview['path'])) {
            wp_die(
                esc_html__('Preview not found or expired.', 'church-bulletin-publisher'),
                esc_html__('Preview unavailable', 'church-bulletin-publisher'),
                array('response' => 404)
            );
        }
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="bulletin-preview.pdf"');
        header('Content-Length: ' . filesize($preview['path']));
        readfile($preview['path']);
        exit;
    }

    public function publish()
    {
        $this->authorize();
        check_admin_referer('cbp_publish');
        $preview = $this->get_preview();
        if (! $preview || ! CBP_PDF_Merger::is_pdf($preview['path']) || ! hash_equals($preview['sha256'], hash_file('sha256', $preview['path']))) {
            $this->redirect('error', __('The reviewed preview is missing or changed. Build it again.', 'church-bulletin-publisher'));
        }

        // Version 0.1 intentionally publishes only to the isolated test area.
        $year = substr($preview['date'], 0, 4);
        $directory = trailingslashit(CBP_Storage::published_root(true)) . $year;
        if (! wp_mkdir_p($directory)) {
            $this->redirect('error', __('Could not create the test publication directory.', 'church-bulletin-publisher'));
        }
        $stamp = DateTimeImmutable::createFromFormat('!Y-m-d', $preview['date']);
        $filename = $stamp->format('mdy') . 'bulletin.pdf';
        $destination = trailingslashit($directory) . $filename;
        if (file_exists($destination)) {
            $backup_dir = trailingslashit($directory) . 'backups';
            wp_mkdir_p($backup_dir);
            @copy($destination, trailingslashit($backup_dir) . gmdate('Ymd-His') . '-' . $filename);
        }
        $temporary = $destination . '.tmp-' . wp_generate_password(8, false, false);
        if (! copy($preview['path'], $temporary) || ! rename($temporary, $destination)) {
            @unlink($temporary);
            $this->redirect('error', __('Could not publish the test bulletin.', 'church-bulletin-publisher'));
        }
        $this->discard_preview();
        $this->redirect('success', sprintf(__('Published %s to the isolated test area.', 'church-bulletin-publisher'), $filename));
    }

    public function discard()
    {
        $this->authorize();
        check_admin_referer('cbp_discard');
        $this->discard_preview();
        $this->redirect('success', __('Preview discarded.', 'church-bulletin-publisher'));
    }

    public function bulletins_shortcode($attributes)
    {
        $attributes = shortcode_atts(array('mode' => 'live', 'limit' => 60), $attributes, 'church_bulletins');
        $test_mode = $attributes['mode'] === 'test';
        $limit = max(1, min(200, absint($attributes['limit'])));
        $root = CBP_Storage::published_root($test_mode);
        $url = CBP_Storage::published_url($test_mode);
        $files = glob(trailingslashit($root) . '[0-9][0-9][0-9][0-9]/[0-9][0-9][0-9][0-9][0-9][0-9]bulletin.pdf');
        if (! $files) {
            return '<p>' . esc_html__('No bulletins have been published yet.', 'church-bulletin-publisher') . '</p>';
        }
        rsort($files, SORT_STRING);
        $files = array_slice($files, 0, $limit);
        $rows = '';
        foreach ($files as $file) {
            $year = basename(dirname($file));
            $name = basename($file);
            $date = DateTimeImmutable::createFromFormat('!mdy', substr($name, 0, 6));
            if (! $date) {
                continue;
            }
            $href = trailingslashit($url) . rawurlencode($year) . '/' . rawurlencode($name);
            $rows .= '<tr><td><a target="_blank" rel="noopener" href="' . esc_url($href) . '">' . esc_html($date->format('F j, Y')) . '</a></td></tr>';
        }
        return '<table class="church-bulletins"><thead><tr><th>' . esc_html__('Weekly Bulletins', 'church-bulletin-publisher') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private function save_uploaded_pdf($file, $directory, $filename)
    {
        if (! isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name']) || ! is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('cbp_upload', __('A PDF upload did not complete successfully.', 'church-bulletin-publisher'));
        }
        $limit = (int) apply_filters('cbp_max_pdf_bytes', 25 * MB_IN_BYTES);
        if ((int) $file['size'] > $limit || ! CBP_PDF_Merger::is_pdf($file['tmp_name'])) {
            return new WP_Error('cbp_pdf_upload', __('An uploaded file was too large or was not a valid PDF.', 'church-bulletin-publisher'));
        }
        wp_mkdir_p($directory);
        $destination = trailingslashit($directory) . sanitize_file_name($filename);
        if (! move_uploaded_file($file['tmp_name'], $destination)) {
            return new WP_Error('cbp_move_upload', __('WordPress could not save an uploaded PDF.', 'church-bulletin-publisher'));
        }
        @chmod($destination, 0640);
        return $destination;
    }

    private function normalize_uploads($files)
    {
        $normalized = array();
        if (empty($files['name']) || ! is_array($files['name'])) {
            return $normalized;
        }
        foreach (array_keys($files['name']) as $index) {
            $normalized[] = array(
                'name' => ! empty($files['full_path'][$index]) ? $files['full_path'][$index] : $files['name'][$index],
                'type' => isset($files['type'][$index]) ? $files['type'][$index] : '',
                'tmp_name' => isset($files['tmp_name'][$index]) ? $files['tmp_name'][$index] : '',
                'error' => isset($files['error'][$index]) ? $files['error'][$index] : UPLOAD_ERR_NO_FILE,
                'size' => isset($files['size'][$index]) ? $files['size'][$index] : 0,
            );
        }
        return $normalized;
    }

    private function settings()
    {
        return wp_parse_args(get_option(self::OPTION, array()), array('test_mode' => true, 'front_cover' => '', 'back_cover' => ''));
    }

    private function preview_key()
    {
        return 'cbp_preview_' . get_current_user_id();
    }

    private function get_preview()
    {
        $preview = get_transient($this->preview_key());
        return is_array($preview) ? $preview : false;
    }

    private function discard_preview()
    {
        $preview = $this->get_preview();
        if ($preview && ! empty($preview['job'])) {
            CBP_Storage::delete_tree($preview['job']);
        }
        delete_transient($this->preview_key());
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

    private function format_date($date)
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed ? $parsed->format('F j, Y') : $date;
    }

    private function redirect($type, $message)
    {
        $url = add_query_arg(array('page' => 'church-bulletin-publisher', 'cbp_notice' => $type, 'cbp_message' => $message), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    private function render_notice()
    {
        if (empty($_GET['cbp_notice']) || empty($_GET['cbp_message'])) {
            return;
        }
        $type = sanitize_key(wp_unslash($_GET['cbp_notice'])) === 'error' ? 'notice-error' : 'notice-success';
        $message = sanitize_text_field(wp_unslash($_GET['cbp_message']));
        echo '<div class="notice ' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}
