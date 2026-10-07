<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Admin
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
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_pform_submission_action', array($this, 'submission_action'));
        add_action('admin_post_pform_export_csv', array($this, 'export_csv'));
        add_action('admin_post_pform_form_create', array($this, 'form_create'));
        add_action('admin_post_pform_form_save', array($this, 'form_save'));
        add_action('admin_post_pform_form_duplicate', array($this, 'form_duplicate'));
        add_action('admin_post_pform_form_status', array($this, 'form_status'));
    }

    public function admin_menu()
    {
        add_menu_page(
            __('Parish Forms', 'parish-forms'),
            __('Parish Forms', 'parish-forms'),
            PFORM_Plugin::CAPABILITY,
            'parish-forms',
            array($this, 'render_submissions'),
            'dashicons-feedback',
            59
        );
        add_submenu_page(
            'parish-forms',
            __('Submissions', 'parish-forms'),
            __('Submissions', 'parish-forms'),
            PFORM_Plugin::CAPABILITY,
            'parish-forms',
            array($this, 'render_submissions')
        );
        add_submenu_page(
            'parish-forms',
            __('Forms', 'parish-forms'),
            __('Forms', 'parish-forms'),
            PFORM_Plugin::CAPABILITY,
            'parish-forms-forms',
            array($this, 'render_forms')
        );
        add_submenu_page(
            'parish-forms',
            __('Parish Forms Settings', 'parish-forms'),
            __('Settings', 'parish-forms'),
            PFORM_Plugin::CAPABILITY,
            'parish-forms-settings',
            array($this, 'render_settings')
        );
    }

    public function admin_assets($hook)
    {
        if (strpos($hook, 'parish-forms') === false) {
            return;
        }
        wp_enqueue_style('pform-admin', PFORM_URL . 'assets/admin.css', array(), PFORM_VERSION);
        if (strpos($hook, 'parish-forms-forms') !== false) {
            wp_enqueue_script('pform-form-editor', PFORM_URL . 'assets/form-editor.js', array(), PFORM_VERSION, true);
            wp_enqueue_style('pform-frontend', PFORM_URL . 'assets/frontend.css', array(), PFORM_VERSION);
        }
    }

    public function register_settings()
    {
        register_setting('pform_settings', PFORM_Plugin::OPTION, array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_settings'),
            'default' => array(),
        ));
    }

    public function sanitize_settings($input)
    {
        $raw = isset($input['notification_emails']) ? $input['notification_emails'] : '';
        $emails = preg_split('/[\s,;]+/', (string) $raw);
        $clean = array();
        foreach ($emails as $email) {
            $email = sanitize_email($email);
            if ($email && is_email($email)) {
                $clean[] = $email;
            }
        }
        return array('notification_emails' => implode(', ', array_unique($clean)));
    }

    public function render_forms()
    {
        $this->authorize();
        $form_post_id = isset($_GET['form']) ? absint($_GET['form']) : 0;

        if ($form_post_id) {
            $this->render_form_editor($form_post_id);
            return;
        }

        $forms = PFORM_Form_Store::posts();
        ?>
        <div class="wrap pform-admin pform-form-manager">
            <h1><?php esc_html_e('Parish Forms', 'parish-forms'); ?></h1>
            <?php $this->form_notice(); ?>
            <p><?php esc_html_e('Create and maintain parish forms without editing plugin code. Published changes are versioned so historical submissions keep the definition that was used when they were submitted.', 'parish-forms'); ?></p>

            <div class="pform-form-manager__new">
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="pform_form_create">
                    <?php wp_nonce_field('pform_form_create'); ?>
                    <label for="pform-new-form-title"><strong><?php esc_html_e('New form', 'parish-forms'); ?></strong></label>
                    <input id="pform-new-form-title" type="text" class="regular-text" name="title" placeholder="<?php esc_attr_e('Example: Funeral Planning', 'parish-forms'); ?>" required>
                    <?php submit_button(__('Add New Form', 'parish-forms'), 'primary', 'submit', false); ?>
                </form>
            </div>

            <table class="widefat fixed striped pform-forms-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Form', 'parish-forms'); ?></th>
                        <th><?php esc_html_e('Status', 'parish-forms'); ?></th>
                        <th><?php esc_html_e('Version', 'parish-forms'); ?></th>
                        <th><?php esc_html_e('Shortcode', 'parish-forms'); ?></th>
                        <th><?php esc_html_e('Actions', 'parish-forms'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (! $forms) : ?>
                        <tr><td colspan="5"><?php esc_html_e('No forms found.', 'parish-forms'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($forms as $form_post) : ?>
                            <?php
                            $form_id = PFORM_Form_Store::form_id($form_post->ID);
                            $status = PFORM_Form_Store::status($form_post->ID);
                            $version = absint(get_post_meta($form_post->ID, '_pform_published_version', true));
                            $edit_url = admin_url('admin.php?page=parish-forms-forms&form=' . $form_post->ID);
                            $duplicate_url = wp_nonce_url(
                                admin_url('admin-post.php?action=pform_form_duplicate&form=' . $form_post->ID),
                                'pform_form_duplicate_' . $form_post->ID
                            );
                            $status_operation = $status === 'retired' ? 'restore' : 'retire';
                            $status_url = wp_nonce_url(
                                admin_url('admin-post.php?action=pform_form_status&form=' . $form_post->ID . '&operation=' . $status_operation),
                                'pform_form_status_' . $form_post->ID
                            );
                            ?>
                            <tr>
                                <td>
                                    <strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($form_post->post_title); ?></a></strong>
                                    <div class="row-actions"><span><?php echo esc_html($form_id); ?></span></div>
                                </td>
                                <td><span class="pform-status pform-status--<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span></td>
                                <td><?php echo esc_html($version ? 'v' . $version : __('Not published', 'parish-forms')); ?></td>
                                <td><code>[parish_form id="<?php echo esc_html($form_id); ?>"]</code></td>
                                <td class="pform-form-manager__actions">
                                    <a class="button" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'parish-forms'); ?></a>
                                    <a class="button" href="<?php echo esc_url($duplicate_url); ?>"><?php esc_html_e('Duplicate', 'parish-forms'); ?></a>
                                    <a class="button" href="<?php echo esc_url($status_url); ?>"><?php echo $status === 'retired' ? esc_html__('Restore', 'parish-forms') : esc_html__('Retire', 'parish-forms'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_form_editor($post_id)
    {
        if (! PFORM_Form_Store::is_form_post($post_id)) {
            wp_die(esc_html__('Form not found.', 'parish-forms'), '', array('response' => 404));
        }

        $definition = PFORM_Form_Store::draft($post_id);
        if (! $definition) {
            $definition = PFORM_Form_Store::published_by_post($post_id);
        }
        if (! $definition) {
            wp_die(esc_html__('Form definition not found.', 'parish-forms'));
        }

        $status = PFORM_Form_Store::status($post_id);
        $version = absint(get_post_meta($post_id, '_pform_published_version', true));
        $history = array_reverse(PFORM_Form_Store::history($post_id));
        $preview = ! empty($_GET['preview']);

        if ($preview) {
            ?>
            <div class="wrap pform-admin">
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=parish-forms-forms&form=' . $post_id)); ?>">&larr; <?php esc_html_e('Back to form editor', 'parish-forms'); ?></a></p>
                <h1><?php echo esc_html(sprintf(__('Draft Preview: %s', 'parish-forms'), $definition['title'])); ?></h1>
                <div class="notice notice-info inline"><p><?php esc_html_e('This preview uses the saved draft. The submit button is disabled and no submission will be stored.', 'parish-forms'); ?></p></div>
                <div class="pform-admin-preview">
                    <?php echo PFORM_Renderer::render($definition, array('errors' => array(), 'values' => array(), 'success' => false)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
                <script>document.querySelectorAll('.pform-admin-preview input,.pform-admin-preview textarea,.pform-admin-preview button').forEach(function(el){el.disabled=true;});</script>
            </div>
            <?php
            return;
        }

        $primary_fields = implode(', ', isset($definition['admin_primary_fields']) ? (array) $definition['admin_primary_fields'] : array());
        $contact_fields = implode(', ', isset($definition['admin_contact_fields']) ? (array) $definition['admin_contact_fields'] : array());
        ?>
        <div class="wrap pform-admin pform-form-editor">
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=parish-forms-forms')); ?>">&larr; <?php esc_html_e('Back to forms', 'parish-forms'); ?></a></p>
            <h1><?php echo esc_html($definition['title']); ?></h1>
            <?php $this->form_notice(); ?>

            <div class="pform-admin__meta">
                <span><strong><?php esc_html_e('Form ID:', 'parish-forms'); ?></strong> <code><?php echo esc_html($definition['id']); ?></code></span>
                <span><strong><?php esc_html_e('Status:', 'parish-forms'); ?></strong> <?php echo esc_html(ucfirst($status)); ?></span>
                <span><strong><?php esc_html_e('Published version:', 'parish-forms'); ?></strong> <?php echo esc_html($version ? 'v' . $version : __('None', 'parish-forms')); ?></span>
                <span><strong><?php esc_html_e('Shortcode:', 'parish-forms'); ?></strong> <code>[parish_form id="<?php echo esc_html($definition['id']); ?>"]</code></span>
            </div>

            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" data-pform-editor-form>
                <input type="hidden" name="action" value="pform_form_save">
                <input type="hidden" name="form" value="<?php echo esc_attr($post_id); ?>">
                <input type="hidden" id="pform-definition-json" name="sections_json" value="">
                <?php wp_nonce_field('pform_form_save_' . $post_id); ?>

                <section class="pform-admin__section pform-builder-general">
                    <h2><?php esc_html_e('Form Settings', 'parish-forms'); ?></h2>
                    <div class="pform-builder-grid">
                        <label><?php esc_html_e('Form title', 'parish-forms'); ?><input type="text" name="definition[title]" value="<?php echo esc_attr($definition['title']); ?>" required></label>
                        <label><?php esc_html_e('Eyebrow', 'parish-forms'); ?><input type="text" name="definition[eyebrow]" value="<?php echo esc_attr(isset($definition['eyebrow']) ? $definition['eyebrow'] : ''); ?>"></label>
                        <label class="pform-builder-wide"><?php esc_html_e('Description', 'parish-forms'); ?><textarea rows="3" name="definition[description]"><?php echo esc_textarea(isset($definition['description']) ? $definition['description'] : ''); ?></textarea></label>
                        <label><?php esc_html_e('Submit button', 'parish-forms'); ?><input type="text" name="definition[submit_label]" value="<?php echo esc_attr(isset($definition['submit_label']) ? $definition['submit_label'] : __('Submit', 'parish-forms')); ?>"></label>
                        <label><?php esc_html_e('Success heading', 'parish-forms'); ?><input type="text" name="definition[success_title]" value="<?php echo esc_attr(isset($definition['success_title']) ? $definition['success_title'] : __('Submission Received', 'parish-forms')); ?>"></label>
                        <label class="pform-builder-wide"><?php esc_html_e('Confirmation message', 'parish-forms'); ?><textarea rows="3" name="definition[confirmation]"><?php echo esc_textarea(isset($definition['confirmation']) ? $definition['confirmation'] : ''); ?></textarea></label>
                        <label class="pform-builder-wide"><?php esc_html_e('Privacy/follow-up note', 'parish-forms'); ?><textarea rows="2" name="definition[privacy_note]"><?php echo esc_textarea(isset($definition['privacy_note']) ? $definition['privacy_note'] : ''); ?></textarea></label>
                    </div>
                    <details>
                        <summary><?php esc_html_e('Advanced form settings', 'parish-forms'); ?></summary>
                        <div class="pform-builder-grid pform-builder-advanced">
                            <label><?php esc_html_e('Reply-to field ID', 'parish-forms'); ?><input type="text" name="definition[reply_to_field]" value="<?php echo esc_attr(isset($definition['reply_to_field']) ? $definition['reply_to_field'] : ''); ?>"></label>
                            <label><?php esc_html_e('Admin submission title fields', 'parish-forms'); ?><input type="text" name="definition[admin_primary_fields]" value="<?php echo esc_attr($primary_fields); ?>" placeholder="first_name, last_name"></label>
                            <label><?php esc_html_e('Admin contact fields', 'parish-forms'); ?><input type="text" name="definition[admin_contact_fields]" value="<?php echo esc_attr($contact_fields); ?>" placeholder="email, phone"></label>
                        </div>
                    </details>
                </section>

                <div class="pform-builder-toolbar">
                    <h2><?php esc_html_e('Sections and Fields', 'parish-forms'); ?></h2>
                    <button class="button" type="button" data-add-section><?php esc_html_e('Add Section', 'parish-forms'); ?></button>
                </div>

                <div data-pform-editor></div>
                <script id="pform-editor-definition" type="application/json"><?php echo wp_json_encode($definition, JSON_HEX_TAG | JSON_HEX_AMP); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>

                <div class="pform-builder-publish">
                    <button class="button button-secondary button-large" type="submit" name="operation" value="draft"><?php esc_html_e('Save Draft', 'parish-forms'); ?></button>
                    <a class="button button-secondary button-large" href="<?php echo esc_url(admin_url('admin.php?page=parish-forms-forms&form=' . $post_id . '&preview=1')); ?>"><?php esc_html_e('Preview Saved Draft', 'parish-forms'); ?></a>
                    <button class="button button-primary button-large" type="submit" name="operation" value="publish" onclick="return confirm('<?php echo esc_js(__('Publish these form changes? Existing submissions will keep their historical form version.', 'parish-forms')); ?>');"><?php esc_html_e('Publish Changes', 'parish-forms'); ?></button>
                </div>
            </form>

            <?php if ($history) : ?>
                <section class="pform-admin__section pform-builder-history">
                    <h2><?php esc_html_e('Published Version History', 'parish-forms'); ?></h2>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Version', 'parish-forms'); ?></th><th><?php esc_html_e('Published', 'parish-forms'); ?></th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($history, 0, 10) as $entry) : ?>
                                <tr><td><?php echo esc_html('v' . absint($entry['version'])); ?></td><td><?php echo esc_html(isset($entry['published_at']) ? $entry['published_at'] : ''); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>
        </div>
        <?php
    }

    public function form_create()
    {
        $this->authorize();
        check_admin_referer('pform_form_create');
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $post_id = PFORM_Form_Store::create_blank($title);
        if (is_wp_error($post_id)) {
            wp_die(esc_html($post_id->get_error_message()));
        }
        wp_safe_redirect(admin_url('admin.php?page=parish-forms-forms&form=' . absint($post_id) . '&pform_form_notice=created'));
        exit;
    }

    public function form_save()
    {
        $this->authorize();
        $post_id = isset($_POST['form']) ? absint($_POST['form']) : 0;
        check_admin_referer('pform_form_save_' . $post_id);
        if (! PFORM_Form_Store::is_form_post($post_id)) {
            wp_die(esc_html__('Form not found.', 'parish-forms'), '', array('response' => 404));
        }

        $meta = isset($_POST['definition']) && is_array($_POST['definition']) ? wp_unslash($_POST['definition']) : array();
        $sections_json = isset($_POST['sections_json']) ? wp_unslash($_POST['sections_json']) : '[]';
        $sections = json_decode($sections_json, true);
        if (! is_array($sections)) {
            $sections = array();
        }

        $raw = $meta;
        $raw['id'] = PFORM_Form_Store::form_id($post_id);
        $raw['sections'] = $sections;
        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : 'draft';

        if ($operation === 'publish') {
            $result = PFORM_Form_Store::publish($post_id, $raw);
            $notice = 'published';
        } else {
            $result = PFORM_Form_Store::save_draft($post_id, $raw);
            $notice = 'saved';
        }

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(admin_url('admin.php?page=parish-forms-forms&form=' . $post_id . '&pform_form_notice=' . $notice));
        exit;
    }

    public function form_duplicate()
    {
        $this->authorize();
        $post_id = isset($_GET['form']) ? absint($_GET['form']) : 0;
        check_admin_referer('pform_form_duplicate_' . $post_id);
        $new_post_id = PFORM_Form_Store::duplicate($post_id);
        if (is_wp_error($new_post_id)) {
            wp_die(esc_html($new_post_id->get_error_message()));
        }
        wp_safe_redirect(admin_url('admin.php?page=parish-forms-forms&form=' . absint($new_post_id) . '&pform_form_notice=duplicated'));
        exit;
    }

    public function form_status()
    {
        $this->authorize();
        $post_id = isset($_GET['form']) ? absint($_GET['form']) : 0;
        $operation = isset($_GET['operation']) ? sanitize_key(wp_unslash($_GET['operation'])) : '';
        check_admin_referer('pform_form_status_' . $post_id);

        if ($operation === 'retire') {
            PFORM_Form_Store::retire($post_id);
            $notice = 'retired';
        } elseif ($operation === 'restore') {
            PFORM_Form_Store::restore($post_id);
            $notice = 'restored';
        } else {
            wp_die(esc_html__('Invalid form action.', 'parish-forms'), '', array('response' => 400));
        }

        wp_safe_redirect(admin_url('admin.php?page=parish-forms-forms&pform_form_notice=' . $notice));
        exit;
    }

    private function form_notice()
    {
        $notice = isset($_GET['pform_form_notice']) ? sanitize_key(wp_unslash($_GET['pform_form_notice'])) : '';
        if (! $notice) {
            return;
        }
        $messages = array(
            'created' => __('Form created as a draft.', 'parish-forms'),
            'saved' => __('Draft saved. Published visitors still see the previous published version.', 'parish-forms'),
            'published' => __('Form changes published.', 'parish-forms'),
            'duplicated' => __('Form duplicated as a new draft.', 'parish-forms'),
            'retired' => __('Form retired. Its shortcode will no longer render publicly.', 'parish-forms'),
            'restored' => __('Form restored.', 'parish-forms'),
        );
        if (isset($messages[$notice])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$notice]) . '</p></div>';
        }
    }

    public function render_submissions()
    {
        $this->authorize();
        $submission_id = isset($_GET['submission']) ? absint($_GET['submission']) : 0;
        if ($submission_id) {
            $this->render_detail($submission_id);
            return;
        }

        $forms = PFORM_Form_Registry::all();
        $form_id = isset($_GET['form_id']) ? sanitize_key(wp_unslash($_GET['form_id'])) : 'parish-registration';
        if (! isset($forms[$form_id])) {
            $form_id = 'parish-registration';
        }
        $workflow_status = isset($_GET['workflow_status']) ? sanitize_key(wp_unslash($_GET['workflow_status'])) : '';
        $show_trash = isset($_GET['post_state']) && sanitize_key(wp_unslash($_GET['post_state'])) === 'trash';
        $paged = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $args = array(
            'post_type' => PFORM_Submissions::POST_TYPE,
            'post_status' => $show_trash ? 'trash' : 'private',
            'posts_per_page' => 25,
            'paged' => $paged,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => array(
                array('key' => '_pform_form_id', 'value' => $form_id),
            ),
        );
        if ($workflow_status && in_array($workflow_status, array('new', 'reviewed'), true)) {
            $args['meta_query'][] = array('key' => '_pform_status', 'value' => $workflow_status);
        }
        $query = new WP_Query($args);
        $definition = PFORM_Form_Registry::get($form_id);
        ?>
        <div class="wrap pform-admin">
            <h1><?php esc_html_e('Parish Form Submissions', 'parish-forms'); ?></h1>
            <?php $this->admin_notice(); ?>
            <div class="pform-admin__toolbar">
                <form method="get">
                    <input type="hidden" name="page" value="parish-forms">
                    <label for="pform-form-id"><?php esc_html_e('Form', 'parish-forms'); ?></label>
                    <select id="pform-form-id" name="form_id">
                        <?php foreach ($forms as $available_form_id => $available_definition) : ?>
                            <option value="<?php echo esc_attr($available_form_id); ?>" <?php selected($form_id, $available_form_id); ?>><?php echo esc_html($available_definition['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="pform-workflow-status" class="screen-reader-text"><?php esc_html_e('Filter by status', 'parish-forms'); ?></label>
                    <select id="pform-workflow-status" name="workflow_status">
                        <option value=""><?php esc_html_e('All statuses', 'parish-forms'); ?></option>
                        <option value="new" <?php selected($workflow_status, 'new'); ?>><?php esc_html_e('New', 'parish-forms'); ?></option>
                        <option value="reviewed" <?php selected($workflow_status, 'reviewed'); ?>><?php esc_html_e('Reviewed', 'parish-forms'); ?></option>
                    </select>
                    <?php submit_button(__('Filter', 'parish-forms'), 'secondary', 'submit', false); ?>
                </form>
                <div>
                    <?php if ($show_trash) : ?>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=parish-forms&form_id=' . $form_id)); ?>"><?php esc_html_e('View Active', 'parish-forms'); ?></a>
                    <?php else : ?>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=parish-forms&form_id=' . $form_id . '&post_state=trash')); ?>"><?php esc_html_e('View Trash', 'parish-forms'); ?></a>
                        <?php if ($definition) : ?>
                            <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=pform_export_csv&form_id=' . $form_id), 'pform_export_csv')); ?>"><?php esc_html_e('Export CSV', 'parish-forms'); ?></a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <table class="widefat fixed striped pform-submissions">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'parish-forms'); ?></th>
                        <th><?php esc_html_e('Submission', 'parish-forms'); ?></th>
                        <th><?php esc_html_e('Contact', 'parish-forms'); ?></th>
                        <th><?php esc_html_e('Parish', 'parish-forms'); ?></th>
                        <th><?php esc_html_e('Received', 'parish-forms'); ?></th>
                        <th><?php esc_html_e('Status', 'parish-forms'); ?></th>
                        <th><?php esc_html_e('Email', 'parish-forms'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (! $query->posts) : ?>
                        <tr><td colspan="7"><?php esc_html_e('No submissions found.', 'parish-forms'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($query->posts as $post) : ?>
                            <?php
                            $data = PFORM_Submissions::data($post->ID);
                            $status = get_post_meta($post->ID, '_pform_status', true) ?: 'new';
                            $primary_summary = $this->summary_value($definition, $data, 'admin_primary_fields');
                            $contact_summary = $this->summary_value($definition, $data, 'admin_contact_fields');
                            $parish = isset($data['parish']) ? $data['parish'] : '';
                            $definition_fields = isset($definition['sections']) ? PFORM_Form_Registry::fields($definition) : array();
                            $parish_label = isset($definition_fields['parish']['options'][$parish]) ? $definition_fields['parish']['options'][$parish] : $parish;
                            ?>
                            <tr>
                                <td><a href="<?php echo esc_url(self::submission_url($post->ID)); ?>">#<?php echo esc_html($post->ID); ?></a></td>
                                <td><a href="<?php echo esc_url(self::submission_url($post->ID)); ?>"><?php echo esc_html($primary_summary !== '' ? $primary_summary : __('View submission', 'parish-forms')); ?></a></td>
                                <td><?php echo esc_html($contact_summary); ?></td>
                                <td><?php echo esc_html($parish_label); ?></td>
                                <td><?php echo esc_html(get_the_date('M j, Y g:i a', $post)); ?></td>
                                <td><span class="pform-status pform-status--<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span></td>
                                <td><?php echo get_post_meta($post->ID, '_pform_email_sent', true) === '1' ? esc_html__('Sent', 'parish-forms') : esc_html__('Not sent', 'parish-forms'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php
            $pagination_base = str_replace(
                '999999999',
                '%#%',
                esc_url(add_query_arg('paged', 999999999))
            );
            echo wp_kses_post(paginate_links(array(
                'base' => $pagination_base,
                'format' => '',
                'current' => $paged,
                'total' => max(1, $query->max_num_pages),
                'type' => 'list',
            )));
            ?>
        </div>
        <?php
        wp_reset_postdata();
    }

    private function render_detail($submission_id)
    {
        if (! PFORM_Submissions::is_submission($submission_id)) {
            wp_die(esc_html__('Submission not found.', 'parish-forms'), '', array('response' => 404));
        }
        $post = get_post($submission_id);
        $form_id = get_post_meta($submission_id, '_pform_form_id', true);
        $definition = PFORM_Submissions::definition($submission_id);
        if (! $definition) {
            wp_die(esc_html__('The form definition for this submission is unavailable.', 'parish-forms'));
        }
        $data = PFORM_Submissions::data($submission_id);
        $status = get_post_meta($submission_id, '_pform_status', true) ?: 'new';
        $is_trash = $post->post_status === 'trash';
        ?>
        <div class="wrap pform-admin">
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=parish-forms&form_id=' . $form_id . ($is_trash ? '&post_state=trash' : ''))); ?>">&larr; <?php esc_html_e('Back to submissions', 'parish-forms'); ?></a></p>
            <h1><?php echo esc_html($definition['title'] . ' #' . $submission_id); ?></h1>
            <?php $this->admin_notice(); ?>
            <div class="pform-admin__meta">
                <span><strong><?php esc_html_e('Received:', 'parish-forms'); ?></strong> <?php echo esc_html(get_the_date('F j, Y g:i a', $post)); ?></span>
                <span><strong><?php esc_html_e('Status:', 'parish-forms'); ?></strong> <?php echo esc_html($is_trash ? __('Trash', 'parish-forms') : ucfirst($status)); ?></span>
                <span><strong><?php esc_html_e('Notification:', 'parish-forms'); ?></strong> <?php echo get_post_meta($submission_id, '_pform_email_sent', true) === '1' ? esc_html__('Sent', 'parish-forms') : esc_html__('Not sent', 'parish-forms'); ?></span>
            </div>

            <?php foreach (PFORM_Formatter::rows($definition, $data) as $section) : ?>
                <section class="pform-admin__section">
                    <h2><?php echo esc_html($section['section']); ?></h2>
                    <table class="widefat striped">
                        <tbody>
                            <?php foreach ($section['fields'] as $row) : ?>
                                <tr>
                                    <th scope="row"><?php echo esc_html($row['label']); ?></th>
                                    <td><?php echo nl2br(esc_html($row['value'] !== '' ? $row['value'] : '-')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php endforeach; ?>

            <div class="pform-admin__actions">
                <?php if ($is_trash) : ?>
                    <?php $this->action_form($submission_id, 'restore', __('Restore Submission', 'parish-forms'), 'primary'); ?>
                    <?php $this->action_form($submission_id, 'delete', __('Delete Permanently', 'parish-forms'), 'delete', true); ?>
                <?php else : ?>
                    <?php if ($status !== 'reviewed') : ?>
                        <?php $this->action_form($submission_id, 'reviewed', __('Mark Reviewed', 'parish-forms'), 'primary'); ?>
                    <?php else : ?>
                        <?php $this->action_form($submission_id, 'new', __('Mark New', 'parish-forms'), 'secondary'); ?>
                    <?php endif; ?>
                    <?php $this->action_form($submission_id, 'trash', __('Move to Trash', 'parish-forms'), 'delete'); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function action_form($submission_id, $operation, $label, $button_class, $confirm = false)
    {
        ?>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" <?php echo $confirm ? 'onsubmit="return confirm(\'' . esc_js(__('Permanently delete this submission? This cannot be undone.', 'parish-forms')) . '\');"' : ''; ?>>
            <input type="hidden" name="action" value="pform_submission_action">
            <input type="hidden" name="submission_id" value="<?php echo esc_attr($submission_id); ?>">
            <input type="hidden" name="operation" value="<?php echo esc_attr($operation); ?>">
            <?php wp_nonce_field('pform_submission_action_' . $submission_id); ?>
            <?php submit_button($label, $button_class, 'submit', false); ?>
        </form>
        <?php
    }

    public function submission_action()
    {
        $this->authorize();
        $submission_id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        check_admin_referer('pform_submission_action_' . $submission_id);
        if (! PFORM_Submissions::is_submission($submission_id)) {
            wp_die(esc_html__('Submission not found.', 'parish-forms'), '', array('response' => 404));
        }

        if (in_array($operation, array('new', 'reviewed'), true)) {
            update_post_meta($submission_id, '_pform_status', $operation);
            $url = self::submission_url($submission_id);
        } elseif ($operation === 'trash') {
            wp_trash_post($submission_id);
            $url = admin_url('admin.php?page=parish-forms&pform_notice=trashed');
        } elseif ($operation === 'restore') {
            wp_untrash_post($submission_id);
            $url = self::submission_url($submission_id);
        } elseif ($operation === 'delete' && get_post_status($submission_id) === 'trash') {
            wp_delete_post($submission_id, true);
            $url = admin_url('admin.php?page=parish-forms&post_state=trash&pform_notice=deleted');
        } else {
            wp_die(esc_html__('Invalid submission action.', 'parish-forms'), '', array('response' => 400));
        }

        wp_safe_redirect(add_query_arg('pform_notice', 'updated', $url));
        exit;
    }

    public function render_settings()
    {
        $this->authorize();
        $settings = PFORM_Plugin::settings();
        $page_id = absint(get_option('pform_registration_page_id'));
        $baptism_page_id = absint(get_option('pform_baptism_page_id'));
        ?>
        <div class="wrap pform-admin">
            <h1><?php esc_html_e('Parish Forms Settings', 'parish-forms'); ?></h1>
            <form action="options.php" method="post">
                <?php settings_fields('pform_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="pform-notification-emails"><?php esc_html_e('Notification email addresses', 'parish-forms'); ?></label></th>
                        <td>
                            <textarea id="pform-notification-emails" class="large-text" rows="3" name="<?php echo esc_attr(PFORM_Plugin::OPTION); ?>[notification_emails]"><?php echo esc_textarea($settings['notification_emails']); ?></textarea>
                            <p class="description"><?php esc_html_e('Separate multiple addresses with commas. Each address receives the complete submitted form data. Leave blank to disable email notifications; submissions will still be stored.', 'parish-forms'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <section class="pform-admin__help">
                <h2><?php esc_html_e('Using Parish Forms', 'parish-forms'); ?></h2>
                <p><strong><?php esc_html_e('Parish Registration:', 'parish-forms'); ?></strong> <code>[parish_form id="parish-registration"]</code></p>
                <?php if ($page_id && get_post($page_id)) : ?>
                    <p><a class="button" href="<?php echo esc_url(get_edit_post_link($page_id)); ?>"><?php esc_html_e('Edit Draft Registration Page', 'parish-forms'); ?></a></p>
                <?php endif; ?>
                <p><strong><?php esc_html_e('Pre-Baptismal Questionnaire:', 'parish-forms'); ?></strong> <code>[parish_form id="pre-baptismal-questionnaire"]</code></p>
                <?php if ($baptism_page_id && get_post($baptism_page_id)) : ?>
                    <p><a class="button" href="<?php echo esc_url(get_edit_post_link($baptism_page_id)); ?>"><?php esc_html_e('Edit Draft Pre-Baptismal Page', 'parish-forms'); ?></a></p>
                <?php endif; ?>
                <p><strong><?php esc_html_e('Confirmation Interest Form:', 'parish-forms'); ?></strong> <code>[parish_form id="confirmation-interest"]</code></p>
            </section>
        </div>
        <?php
    }

    public function export_csv()
    {
        $this->authorize();
        check_admin_referer('pform_export_csv');
        $form_id = isset($_GET['form_id']) ? sanitize_key(wp_unslash($_GET['form_id'])) : '';
        $definition = PFORM_Form_Registry::get($form_id);
        if (! $definition) {
            wp_die(esc_html__('Unknown form.', 'parish-forms'), '', array('response' => 400));
        }

        $posts = get_posts(array(
            'post_type' => PFORM_Submissions::POST_TYPE,
            'post_status' => 'private',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => '_pform_form_id',
            'meta_value' => $form_id,
        ));
        $datasets = array();
        foreach ($posts as $post) {
            $datasets[$post->ID] = PFORM_Submissions::data($post->ID);
        }
        $columns = $this->csv_columns($definition, $datasets);

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($form_id . '-' . gmdate('Y-m-d') . '.csv') . '"');
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, array_merge(array('Submission ID', 'Received', 'Status', 'Form'), wp_list_pluck($columns, 'label')));
        foreach ($posts as $post) {
            $data = $datasets[$post->ID];
            $row = array(
                $post->ID,
                get_the_date('Y-m-d H:i:s', $post),
                get_post_meta($post->ID, '_pform_status', true) ?: 'new',
                $definition['title'],
            );
            foreach ($columns as $column) {
                $row[] = $this->csv_safe($this->csv_value($column, $data));
            }
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    private function summary_value($definition, $data, $definition_key)
    {
        if (empty($definition[$definition_key]) || ! is_array($definition[$definition_key])) {
            return '';
        }

        $values = array();
        foreach ($definition[$definition_key] as $field_id) {
            if (! isset($data[$field_id]) || ! is_scalar($data[$field_id])) {
                continue;
            }
            $value = trim((string) $data[$field_id]);
            if ($value !== '') {
                $values[] = $value;
            }
        }
        $separator = $definition_key === 'admin_primary_fields' ? ' ' : ' · ';
        return implode($separator, $values);
    }

    private function csv_columns($definition, $datasets)
    {
        $columns = array();
        foreach ($definition['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['type'] !== 'repeater') {
                    $columns[] = array('label' => $field['label'], 'field' => $field, 'id' => $field['id']);
                    continue;
                }
                $maximum = 0;
                foreach ($datasets as $data) {
                    $maximum = max($maximum, isset($data[$field['id']]) && is_array($data[$field['id']]) ? count($data[$field['id']]) : 0);
                }
                for ($index = 0; $index < $maximum; $index++) {
                    foreach ($field['fields'] as $item_field) {
                        $columns[] = array(
                            'label' => sprintf('%s %d - %s', $field['item_label'], $index + 1, $item_field['label']),
                            'field' => $item_field,
                            'id' => $field['id'],
                            'index' => $index,
                            'item_id' => $item_field['id'],
                        );
                    }
                }
            }
        }
        return $columns;
    }

    private function csv_value($column, $data)
    {
        if (isset($column['index'])) {
            $value = isset($data[$column['id']][$column['index']][$column['item_id']]) ? $data[$column['id']][$column['index']][$column['item_id']] : '';
        } else {
            $value = isset($data[$column['id']]) ? $data[$column['id']] : '';
        }
        return PFORM_Formatter::display_value($column['field'], $value);
    }

    private function csv_safe($value)
    {
        $value = (string) $value;
        return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    }

    private function admin_notice()
    {
        if (empty($_GET['pform_notice'])) {
            return;
        }
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Submission updated.', 'parish-forms') . '</p></div>';
    }

    private function authorize()
    {
        if (! current_user_can(PFORM_Plugin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage parish form submissions.', 'parish-forms'), '', array('response' => 403));
        }
    }

    public static function submission_url($submission_id)
    {
        return admin_url('admin.php?page=parish-forms&submission=' . absint($submission_id));
    }
}
