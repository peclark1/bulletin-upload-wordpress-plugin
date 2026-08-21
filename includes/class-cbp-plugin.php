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
        add_action('admin_post_cbp_get_cover', array($this, 'get_cover'));
        add_action('admin_post_cbp_upload_chunk', array($this, 'upload_chunk'));
        add_action('admin_post_cbp_finalize_browser_preview', array($this, 'finalize_browser_preview'));
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
        $browser_merge = is_wp_error(CBP_PDF_Merger::diagnostic());
        $dependencies = array();
        if ($browser_merge) {
            wp_enqueue_script('cbp-pdf-lib', CBP_URL . 'vendor/pdf-lib/pdf-lib.min.js', array(), '1.17.1', true);
            $dependencies[] = 'cbp-pdf-lib';
        }
        wp_enqueue_style('cbp-admin', CBP_URL . 'assets/admin.css', array(), CBP_VERSION);
        wp_enqueue_script('cbp-admin', CBP_URL . 'assets/admin.js', $dependencies, CBP_VERSION, true);
        wp_localize_script('cbp-admin', 'cbpAdmin', array(
            'browserMerge' => $browser_merge,
            'frontCoverUrl' => wp_nonce_url(admin_url('admin-post.php?action=cbp_get_cover&cover=front'), 'cbp_get_cover'),
            'backCoverUrl' => wp_nonce_url(admin_url('admin-post.php?action=cbp_get_cover&cover=back'), 'cbp_get_cover'),
            'chunkUploadUrl' => wp_nonce_url(admin_url('admin-post.php?action=cbp_upload_chunk'), 'cbp_upload_chunk'),
            'finalizeUrl' => admin_url('admin-post.php'),
            'finalizeNonce' => wp_create_nonce('cbp_finalize_browser_preview'),
            'messages' => array(
                'choosePdf' => __('Choose at least one weekly PDF file or an entire folder containing PDF files.', 'church-bulletin-publisher'),
                'building' => __('Building PDF in your browser...', 'church-bulletin-publisher'),
                'uploading' => __('Uploading private preview chunk', 'church-bulletin-publisher'),
                'failed' => __('The browser could not build the PDF preview.', 'church-bulletin-publisher'),
            ),
        ));
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
                        <div class="notice notice-info inline"><p><?php esc_html_e('Browser PDF merger ready. No server PDF utility is required.', 'church-bulletin-publisher'); ?></p></div>
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
                        <p id="cbp-progress" class="description" role="status" aria-live="polite"></p>
                        <?php submit_button(__('Create Preview', 'church-bulletin-publisher'), 'primary', 'submit', false); ?>
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

        if (! empty($_FILES['merged_preview']['name'])) {
            $limit = (int) apply_filters('cbp_max_merged_pdf_bytes', 100 * MB_IN_BYTES);
            $output = $this->save_uploaded_pdf($_FILES['merged_preview'], $job, 'preview.pdf', $limit);
            if (is_wp_error($output)) {
                CBP_Storage::delete_tree($job);
                $this->redirect('error', $output->get_error_message());
            }

            $manifest = array(__('Front cover', 'church-bulletin-publisher'));
            $labels = isset($_POST['component_manifest']) ? json_decode(wp_unslash($_POST['component_manifest']), true) : array();
            if (is_array($labels)) {
                foreach (array_slice($labels, 0, 100) as $label) {
                    if (is_string($label) && $label !== '') {
                        $manifest[] = sanitize_text_field($label);
                    }
                }
            }
            $manifest[] = __('Back cover', 'church-bulletin-publisher');
            $this->store_preview($date, $job, $output, $manifest);
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

        $this->store_preview($date, $job, $output, $manifest);
    }

    public function get_cover()
    {
        $this->authorize();
        check_admin_referer('cbp_get_cover');
        $which = isset($_GET['cover']) ? sanitize_key(wp_unslash($_GET['cover'])) : '';
        $settings = $this->settings();
        $path = $which === 'front' ? $settings['front_cover'] : ($which === 'back' ? $settings['back_cover'] : '');
        if (! CBP_PDF_Merger::is_pdf($path)) {
            wp_die(
                esc_html__('Cover template not found.', 'church-bulletin-publisher'),
                esc_html__('Cover unavailable', 'church-bulletin-publisher'),
                array('response' => 404)
            );
        }
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . ($which === 'front' ? 'front-cover.pdf' : 'back-cover.pdf') . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public function upload_chunk()
    {
        $this->authorize();
        check_admin_referer('cbp_upload_chunk');
        $upload_id = isset($_GET['upload_id']) ? sanitize_key(wp_unslash($_GET['upload_id'])) : '';
        $index = isset($_GET['index']) ? absint($_GET['index']) : -1;
        $total = isset($_GET['total']) ? absint($_GET['total']) : 0;
        if (! preg_match('/^[a-f0-9]{20,64}$/', $upload_id) || $total < 1 || $total > 400 || $index < 0 || $index >= $total) {
            wp_send_json_error(array('message' => __('Invalid preview chunk request.', 'church-bulletin-publisher')), 400);
        }

        $body = file_get_contents('php://input');
        if ($body === false || strlen($body) < 1 || strlen($body) > MB_IN_BYTES) {
            wp_send_json_error(array('message' => __('Preview chunk was empty or too large.', 'church-bulletin-publisher')), 400);
        }

        CBP_Storage::ensure_directories();
        $directory = $this->chunk_directory($upload_id);
        if (! wp_mkdir_p($directory)) {
            wp_send_json_error(array('message' => __('Could not create private chunk storage.', 'church-bulletin-publisher')), 500);
        }
        $path = trailingslashit($directory) . sprintf('chunk-%04d.bin', $index);
        if (file_put_contents($path, $body, LOCK_EX) !== strlen($body)) {
            wp_send_json_error(array('message' => __('Could not store a preview chunk.', 'church-bulletin-publisher')), 500);
        }
        wp_send_json_success(array('index' => $index));
    }

    public function finalize_browser_preview()
    {
        $this->authorize();
        check_admin_referer('cbp_finalize_browser_preview');
        $date = isset($_POST['bulletin_date']) ? sanitize_text_field(wp_unslash($_POST['bulletin_date'])) : '';
        $upload_id = isset($_POST['upload_id']) ? sanitize_key(wp_unslash($_POST['upload_id'])) : '';
        $total = isset($_POST['total']) ? absint($_POST['total']) : 0;
        if (! $this->valid_date($date) || ! preg_match('/^[a-f0-9]{20,64}$/', $upload_id) || $total < 1 || $total > 400) {
            wp_send_json_error(array('message' => __('Invalid preview completion request.', 'church-bulletin-publisher')), 400);
        }

        $chunk_directory = $this->chunk_directory($upload_id);
        $job = CBP_Storage::create_job_directory(get_current_user_id());
        if (is_wp_error($job)) {
            wp_send_json_error(array('message' => $job->get_error_message()), 500);
        }
        $output = trailingslashit($job) . 'preview.pdf';
        $stream = fopen($output, 'wb');
        if (! $stream) {
            CBP_Storage::delete_tree($job);
            wp_send_json_error(array('message' => __('Could not create the private preview file.', 'church-bulletin-publisher')), 500);
        }

        for ($index = 0; $index < $total; $index++) {
            $chunk = trailingslashit($chunk_directory) . sprintf('chunk-%04d.bin', $index);
            $input = is_readable($chunk) ? fopen($chunk, 'rb') : false;
            if (! $input) {
                fclose($stream);
                CBP_Storage::delete_tree($job);
                wp_send_json_error(array('message' => sprintf(__('Preview chunk %d is missing.', 'church-bulletin-publisher'), $index + 1)), 400);
            }
            stream_copy_to_stream($input, $stream);
            fclose($input);
        }
        fclose($stream);

        $limit = (int) apply_filters('cbp_max_merged_pdf_bytes', 100 * MB_IN_BYTES);
        if (filesize($output) > $limit || ! CBP_PDF_Merger::is_pdf($output)) {
            CBP_Storage::delete_tree($job);
            CBP_Storage::delete_tree($chunk_directory);
            wp_send_json_error(array('message' => __('The completed preview was too large or was not a valid PDF.', 'church-bulletin-publisher')), 400);
        }

        CBP_Storage::delete_tree($chunk_directory);
        $this->store_preview($date, $job, $output, $this->manifest_from_request());
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

    private function save_uploaded_pdf($file, $directory, $filename, $limit = null)
    {
        if (! isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name']) || ! is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('cbp_upload', __('A PDF upload did not complete successfully.', 'church-bulletin-publisher'));
        }
        if ($limit === null) {
            $limit = (int) apply_filters('cbp_max_pdf_bytes', 25 * MB_IN_BYTES);
        }
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

    private function chunk_directory($upload_id)
    {
        return trailingslashit(CBP_Storage::private_root()) . 'chunks/' . get_current_user_id() . '/' . $upload_id;
    }

    private function manifest_from_request()
    {
        $manifest = array(__('Front cover', 'church-bulletin-publisher'));
        $labels = isset($_POST['component_manifest']) ? json_decode(wp_unslash($_POST['component_manifest']), true) : array();
        if (is_array($labels)) {
            foreach (array_slice($labels, 0, 100) as $label) {
                if (is_string($label) && $label !== '') {
                    $manifest[] = sanitize_text_field($label);
                }
            }
        }
        $manifest[] = __('Back cover', 'church-bulletin-publisher');
        return $manifest;
    }

    private function store_preview($date, $job, $output, array $manifest)
    {
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
