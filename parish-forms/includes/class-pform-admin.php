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

    public function render_submissions()
    {
        $this->authorize();
        $submission_id = isset($_GET['submission']) ? absint($_GET['submission']) : 0;
        if ($submission_id) {
            $this->render_detail($submission_id);
            return;
        }

        $form_id = isset($_GET['form_id']) ? sanitize_key(wp_unslash($_GET['form_id'])) : 'parish-registration';
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
                    <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
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
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=parish-forms')); ?>"><?php esc_html_e('View Active', 'parish-forms'); ?></a>
                    <?php else : ?>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=parish-forms&post_state=trash')); ?>"><?php esc_html_e('View Trash', 'parish-forms'); ?></a>
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
                        <th><?php esc_html_e('Household', 'parish-forms'); ?></th>
                        <th><?php esc_html_e('Primary Contact', 'parish-forms'); ?></th>
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
                            $parish = isset($data['parish']) ? $data['parish'] : '';
                            $parish_labels = isset($definition['sections']) ? PFORM_Form_Registry::fields($definition) : array();
                            $parish_label = isset($parish_labels['parish']['options'][$parish]) ? $parish_labels['parish']['options'][$parish] : $parish;
                            ?>
                            <tr>
                                <td><a href="<?php echo esc_url(self::submission_url($post->ID)); ?>">#<?php echo esc_html($post->ID); ?></a></td>
                                <td><?php echo esc_html(isset($data['family_name']) ? $data['family_name'] : ''); ?></td>
                                <td><a href="<?php echo esc_url(self::submission_url($post->ID)); ?>"><?php echo esc_html(isset($data['primary_name']) ? $data['primary_name'] : __('View submission', 'parish-forms')); ?></a></td>
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
        $definition = PFORM_Form_Registry::get($form_id);
        if (! $definition) {
            wp_die(esc_html__('The form definition for this submission is unavailable.', 'parish-forms'));
        }
        $data = PFORM_Submissions::data($submission_id);
        $status = get_post_meta($submission_id, '_pform_status', true) ?: 'new';
        $is_trash = $post->post_status === 'trash';
        ?>
        <div class="wrap pform-admin">
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=parish-forms' . ($is_trash ? '&post_state=trash' : ''))); ?>">&larr; <?php esc_html_e('Back to submissions', 'parish-forms'); ?></a></p>
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
                            <p class="description"><?php esc_html_e('Separate multiple addresses with commas. Each address receives the complete submitted registration. Leave blank to disable email notifications; submissions will still be stored.', 'parish-forms'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <section class="pform-admin__help">
                <h2><?php esc_html_e('Using the Registration Form', 'parish-forms'); ?></h2>
                <p><?php esc_html_e('Place this shortcode on any page:', 'parish-forms'); ?> <code>[parish_form id="parish-registration"]</code></p>
                <?php if ($page_id && get_post($page_id)) : ?>
                    <p><a class="button" href="<?php echo esc_url(get_edit_post_link($page_id)); ?>"><?php esc_html_e('Edit Draft Registration Page', 'parish-forms'); ?></a></p>
                <?php endif; ?>
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
