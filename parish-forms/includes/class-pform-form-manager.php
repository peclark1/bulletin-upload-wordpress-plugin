<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Form_Manager
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
        add_action('admin_post_pform_form_save', array($this, 'save_form'));
        add_action('admin_post_pform_form_duplicate', array($this, 'duplicate_form'));
        add_action('admin_post_pform_form_retire', array($this, 'retire_form'));
        add_action('admin_post_pform_form_restore_version', array($this, 'restore_version'));
    }

    public function admin_menu()
    {
        add_submenu_page(
            'parish-forms',
            __('Forms', 'parish-forms'),
            __('Forms', 'parish-forms'),
            PFORM_Plugin::CAPABILITY,
            'parish-forms-manager',
            array($this, 'render')
        );
    }

    public function admin_assets($hook)
    {
        if (strpos($hook, 'parish-forms-manager') === false) {
            return;
        }

        wp_enqueue_style('pform-admin', PFORM_URL . 'assets/admin.css', array(), PFORM_VERSION);
        wp_enqueue_style('pform-frontend', PFORM_URL . 'assets/frontend.css', array(), PFORM_VERSION);
        wp_enqueue_script('pform-form-editor', PFORM_URL . 'assets/admin-form-editor.js', array(), PFORM_VERSION, true);
        wp_enqueue_script('pform-frontend', PFORM_URL . 'assets/frontend.js', array(), PFORM_VERSION, true);
    }

    public function render()
    {
        $this->authorize();

        $form_id = isset($_GET['form_id']) ? sanitize_key(wp_unslash($_GET['form_id'])) : '';
        $is_new = isset($_GET['new']) && sanitize_key(wp_unslash($_GET['new'])) === '1';
        if ($form_id || $is_new) {
            $this->render_editor($form_id, $is_new);
            return;
        }

        $this->render_list();
    }

    private function render_list()
    {
        $records = PFORM_Form_Store::records();
        ?>
        <div class="wrap pform-admin pform-manager">
            <h1 class="wp-heading-inline"><?php esc_html_e('Parish Forms', 'parish-forms'); ?></h1>
            <a class="page-title-action" href="<?php echo esc_url(admin_url('admin.php?page=parish-forms-manager&new=1')); ?>"><?php esc_html_e('Add New Form', 'parish-forms'); ?></a>
            <?php $this->notice(); ?>
            <p><?php esc_html_e('Create and maintain parish forms without changing plugin code. Published forms continue to use the same secure validation, private submission storage, staff notifications, and CSV export.', 'parish-forms'); ?></p>

            <table class="widefat fixed striped pform-manager__table">
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
                <?php if (! $records) : ?>
                    <tr><td colspan="5"><?php esc_html_e('No forms have been created yet.', 'parish-forms'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($records as $record) : ?>
                        <tr>
                            <td>
                                <strong><a href="<?php echo esc_url($this->editor_url($record['form_id'])); ?>"><?php echo esc_html($record['title']); ?></a></strong>
                                <div class="row-actions">
                                    <span><code><?php echo esc_html($record['form_id']); ?></code></span>
                                </div>
                            </td>
                            <td><?php echo wp_kses_post($this->status_badge($record)); ?></td>
                            <td><?php echo $record['published_version'] ? esc_html('v' . $record['published_version']) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><code>[parish_form id="<?php echo esc_attr($record['form_id']); ?>"]</code></td>
                            <td>
                                <div class="pform-manager__actions">
                                    <a class="button" href="<?php echo esc_url($this->editor_url($record['form_id'])); ?>"><?php esc_html_e('Edit', 'parish-forms'); ?></a>
                                    <a class="button" href="<?php echo esc_url(add_query_arg('preview', '1', $this->editor_url($record['form_id']))); ?>"><?php esc_html_e('Preview', 'parish-forms'); ?></a>
                                    <?php $this->small_action_form('pform_form_duplicate', $record['form_id'], __('Duplicate', 'parish-forms')); ?>
                                    <?php $this->small_action_form('pform_form_retire', $record['form_id'], $record['retired'] ? __('Restore', 'parish-forms') : __('Retire', 'parish-forms'), array('retired' => $record['retired'] ? '0' : '1'), $record['retired'] ? '' : __('Retire this form? Its shortcode will stop rendering for visitors until restored.', 'parish-forms')); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_editor($form_id, $is_new)
    {
        $preview = isset($_GET['preview']) && sanitize_key(wp_unslash($_GET['preview'])) === '1';
        $record = null;

        if ($is_new) {
            $definition = $this->new_definition();
            $versions = array();
        } else {
            $post = PFORM_Form_Store::form_post($form_id);
            if (! $post) {
                wp_die(esc_html__('Form not found.', 'parish-forms'), '', array('response' => 404));
            }
            $definition = PFORM_Form_Store::get_draft($form_id);
            if (! $definition) {
                $definition = PFORM_Form_Store::get_published($form_id, true);
            }
            $versions = PFORM_Form_Store::versions($form_id);
            foreach (PFORM_Form_Store::records() as $candidate) {
                if ($candidate['form_id'] === $form_id) {
                    $record = $candidate;
                    break;
                }
            }
        }

        $state_key = isset($_GET['pform_state']) ? sanitize_key(wp_unslash($_GET['pform_state'])) : '';
        if ($state_key) {
            $saved_state = get_transient('pform_manager_editor_state_' . $state_key);
            delete_transient('pform_manager_editor_state_' . $state_key);
            if (is_array($saved_state)
                && isset($saved_state['form_id'])
                && $saved_state['form_id'] === $form_id
                && isset($saved_state['definition'])
                && is_array($saved_state['definition'])) {
                $definition = $saved_state['definition'];
            }
        }

        wp_localize_script('pform-form-editor', 'PFORM_EDITOR_DATA', array(
            'definition' => $definition,
            'isNew' => $is_new,
            'labels' => array(
                'confirmRemoveSection' => __('Remove this section and all fields inside it?', 'parish-forms'),
                'confirmRemoveField' => __('Remove this field?', 'parish-forms'),
                'confirmPublish' => __('Publish these changes to the live form? A new immutable form version will be created.', 'parish-forms'),
                'optionHelp' => __('One choice per line. Use “value | Label” to set a stable stored value, or enter just the label.', 'parish-forms'),
            ),
        ));
        ?>
        <div class="wrap pform-admin pform-manager">
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=parish-forms-manager')); ?>">&larr; <?php esc_html_e('Back to Forms', 'parish-forms'); ?></a></p>
            <h1><?php echo esc_html($is_new ? __('New Parish Form', 'parish-forms') : $definition['title']); ?></h1>
            <?php $this->notice(); ?>

            <?php if (! $is_new && $record) : ?>
                <div class="pform-admin__meta">
                    <span><strong><?php esc_html_e('Form ID:', 'parish-forms'); ?></strong> <code><?php echo esc_html($form_id); ?></code></span>
                    <span><strong><?php esc_html_e('Status:', 'parish-forms'); ?></strong> <?php echo wp_kses_post($this->status_badge($record)); ?></span>
                    <span><strong><?php esc_html_e('Published version:', 'parish-forms'); ?></strong> <?php echo $record['published_version'] ? esc_html('v' . $record['published_version']) : esc_html__('Not published', 'parish-forms'); ?></span>
                    <span><strong><?php esc_html_e('Shortcode:', 'parish-forms'); ?></strong> <code>[parish_form id="<?php echo esc_attr($form_id); ?>"]</code></span>
                </div>
            <?php else : ?>
                <div class="notice notice-info inline"><p><?php esc_html_e('The permanent Form ID and shortcode will be assigned when you save this new form.', 'parish-forms'); ?></p></div>
            <?php endif; ?>
            <p class="description"><?php esc_html_e('Save Draft keeps your changes private. The public form changes only when you choose Publish Changes.', 'parish-forms'); ?></p>

            <form id="pform-form-editor-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="pform_form_save">
                <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
                <input type="hidden" name="operation" id="pform-editor-operation" value="save">
                <input type="hidden" name="definition_json" id="pform-definition-json" value="">
                <?php wp_nonce_field('pform_form_save', '_pform_form_nonce'); ?>

                <div id="pform-form-editor" class="pform-form-editor"></div>

                <div class="pform-form-editor__footer">
                    <button type="submit" class="button button-secondary" data-pform-operation="save"><?php esc_html_e('Save Draft', 'parish-forms'); ?></button>
                    <button type="submit" class="button" data-pform-operation="preview"><?php esc_html_e('Save Draft & Preview', 'parish-forms'); ?></button>
                    <button type="submit" class="button button-primary" data-pform-operation="publish"><?php esc_html_e('Publish Changes', 'parish-forms'); ?></button>
                </div>
            </form>

            <?php if ($preview && ! $is_new) : ?>
                <section class="pform-manager__preview">
                    <h2><?php esc_html_e('Draft Preview', 'parish-forms'); ?></h2>
                    <p><?php esc_html_e('This preview uses the saved draft. The public form is unchanged until you publish.', 'parish-forms'); ?></p>
                    <?php echo PFORM_Renderer::render($definition, array('errors' => array(), 'values' => array(), 'success' => false), true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </section>
            <?php endif; ?>

            <?php if ($versions) : ?>
                <section class="pform-admin__section pform-manager__versions">
                    <h2><?php esc_html_e('Published Version History', 'parish-forms'); ?></h2>
                    <p><?php esc_html_e('Published versions are immutable. Restoring a version copies it into the draft editor; it does not change the live form until you publish again.', 'parish-forms'); ?></p>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Version', 'parish-forms'); ?></th><th><?php esc_html_e('Published', 'parish-forms'); ?></th><th><?php esc_html_e('Action', 'parish-forms'); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($versions as $version) : ?>
                            <tr>
                                <td><?php echo esc_html('v' . $version['version']); ?></td>
                                <td><?php echo esc_html(mysql2date('M j, Y g:i a', $version['date'])); ?></td>
                                <td>
                                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                                        <input type="hidden" name="action" value="pform_form_restore_version">
                                        <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
                                        <input type="hidden" name="version_post_id" value="<?php echo esc_attr($version['post_id']); ?>">
                                        <?php wp_nonce_field('pform_form_restore_version_' . $form_id); ?>
                                        <button class="button" type="submit"><?php esc_html_e('Restore to Draft', 'parish-forms'); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>
        </div>
        <?php
    }

    public function save_form()
    {
        $this->authorize();
        check_admin_referer('pform_form_save', '_pform_form_nonce');

        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : 'save';
        if (! in_array($operation, array('save', 'preview', 'publish'), true)) {
            $operation = 'save';
        }

        $json = isset($_POST['definition_json']) ? wp_unslash($_POST['definition_json']) : '';
        $raw = json_decode($json, true);
        if (! is_array($raw)) {
            $this->error_redirect(__('The form editor data could not be read. Please try again.', 'parish-forms'));
        }

        $form_id = isset($_POST['form_id']) ? sanitize_key(wp_unslash($_POST['form_id'])) : '';
        if ($form_id === '') {
            $title = isset($raw['title']) ? sanitize_text_field($raw['title']) : '';
            $created = PFORM_Form_Store::create_blank($title);
            if (is_wp_error($created)) {
                $this->error_redirect($created->get_error_message());
            }
            $form_id = $created;
        }

        $saved = PFORM_Form_Store::save_draft($form_id, $raw);
        if (is_wp_error($saved)) {
            $this->error_redirect($saved->get_error_message(), $form_id, $raw);
        }

        if ($operation === 'publish') {
            $published = PFORM_Form_Store::publish($form_id);
            if (is_wp_error($published)) {
                $this->error_redirect($published->get_error_message(), $form_id);
            }
            $notice = 'published';
        } else {
            $notice = 'saved';
        }

        $url = $this->editor_url($form_id);
        $url = add_query_arg('pform_notice', $notice, $url);
        if ($operation === 'preview') {
            $url = add_query_arg('preview', '1', $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    public function duplicate_form()
    {
        $this->authorize();
        $form_id = isset($_POST['form_id']) ? sanitize_key(wp_unslash($_POST['form_id'])) : '';
        check_admin_referer('pform_form_duplicate_' . $form_id);
        $new_id = PFORM_Form_Store::duplicate($form_id);
        if (is_wp_error($new_id)) {
            $this->error_redirect($new_id->get_error_message());
        }
        wp_safe_redirect(add_query_arg('pform_notice', 'duplicated', $this->editor_url($new_id)));
        exit;
    }

    public function retire_form()
    {
        $this->authorize();
        $form_id = isset($_POST['form_id']) ? sanitize_key(wp_unslash($_POST['form_id'])) : '';
        $retired = isset($_POST['retired']) && sanitize_key(wp_unslash($_POST['retired'])) === '1';
        check_admin_referer('pform_form_retire_' . $form_id);
        PFORM_Form_Store::set_retired($form_id, $retired);
        wp_safe_redirect(add_query_arg('pform_notice', $retired ? 'retired' : 'restored', admin_url('admin.php?page=parish-forms-manager')));
        exit;
    }

    public function restore_version()
    {
        $this->authorize();
        $form_id = isset($_POST['form_id']) ? sanitize_key(wp_unslash($_POST['form_id'])) : '';
        $version_post_id = isset($_POST['version_post_id']) ? absint($_POST['version_post_id']) : 0;
        check_admin_referer('pform_form_restore_version_' . $form_id);
        $result = PFORM_Form_Store::restore_version_to_draft($form_id, $version_post_id);
        if (is_wp_error($result)) {
            $this->error_redirect($result->get_error_message(), $form_id);
        }
        wp_safe_redirect(add_query_arg('pform_notice', 'version-restored', $this->editor_url($form_id)));
        exit;
    }

    private function new_definition()
    {
        return array(
            'id' => '',
            'version' => 1,
            'title' => __('New Parish Form', 'parish-forms'),
            'eyebrow' => __('Parish Forms', 'parish-forms'),
            'description' => '',
            'submit_label' => __('Submit Form', 'parish-forms'),
            'success_title' => __('Submission Received', 'parish-forms'),
            'confirmation' => __('Thank you. Your submission has been received.', 'parish-forms'),
            'privacy_note' => __('Information submitted through this form is intended for parish-office follow-up.', 'parish-forms'),
            'notification_emails' => '',
            'reply_to_field' => '',
            'admin_primary_fields' => array(),
            'admin_contact_fields' => array(),
            'sections' => array(
                array(
                    'id' => 'section-1',
                    'title' => __('New Section', 'parish-forms'),
                    'description' => '',
                    'fields' => array(),
                ),
            ),
        );
    }

    private function status_badge($record)
    {
        if ($record['retired']) {
            return '<span class="pform-status pform-status--retired">' . esc_html__('Retired', 'parish-forms') . '</span>';
        }
        if (! $record['published']) {
            return '<span class="pform-status pform-status--draft">' . esc_html__('Draft', 'parish-forms') . '</span>';
        }
        if ($record['has_draft_changes']) {
            return '<span class="pform-status pform-status--changes">' . esc_html__('Published + Draft Changes', 'parish-forms') . '</span>';
        }
        return '<span class="pform-status pform-status--published">' . esc_html__('Published', 'parish-forms') . '</span>';
    }

    private function small_action_form($action, $form_id, $label, $extra = array(), $confirm = '')
    {
        ?>
        <form class="pform-manager__inline-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" <?php if ($confirm) : ?>onsubmit="return confirm('<?php echo esc_js($confirm); ?>');"<?php endif; ?>>
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
            <?php foreach ($extra as $name => $value) : ?>
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
            <?php endforeach; ?>
            <?php wp_nonce_field($action . '_' . $form_id); ?>
            <button class="button" type="submit"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    private function editor_url($form_id)
    {
        return admin_url('admin.php?page=parish-forms-manager&form_id=' . rawurlencode($form_id));
    }

    private function notice()
    {
        $notice = isset($_GET['pform_notice']) ? sanitize_key(wp_unslash($_GET['pform_notice'])) : '';
        $messages = array(
            'saved' => __('Draft saved. The public form has not changed.', 'parish-forms'),
            'published' => __('Form changes published.', 'parish-forms'),
            'duplicated' => __('Form duplicated as a new draft.', 'parish-forms'),
            'retired' => __('Form retired. Its shortcode no longer renders for visitors.', 'parish-forms'),
            'restored' => __('Form restored and available to visitors again.', 'parish-forms'),
            'version-restored' => __('Published version restored to the draft editor. Publish when you are ready to make it live.', 'parish-forms'),
        );
        if (isset($messages[$notice])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$notice]) . '</p></div>';
        }

        $error_key = isset($_GET['pform_error']) ? sanitize_key(wp_unslash($_GET['pform_error'])) : '';
        if ($error_key) {
            $message = get_transient('pform_manager_error_' . $error_key);
            delete_transient('pform_manager_error_' . $error_key);
            if ($message) {
                echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            }
        }
    }

    private function error_redirect($message, $form_id = '', $definition = null)
    {
        $key = strtolower(wp_generate_password(18, false, false));
        set_transient('pform_manager_error_' . $key, sanitize_text_field($message), 10 * MINUTE_IN_SECONDS);
        $url = $form_id ? $this->editor_url($form_id) : admin_url('admin.php?page=parish-forms-manager');

        $args = array('pform_error' => $key);
        if ($form_id && is_array($definition)) {
            $state_key = strtolower(wp_generate_password(18, false, false));
            set_transient('pform_manager_editor_state_' . $state_key, array(
                'form_id' => $form_id,
                'definition' => $definition,
            ), 10 * MINUTE_IN_SECONDS);
            $args['pform_state'] = $state_key;
        }

        wp_safe_redirect(add_query_arg($args, $url));
        exit;
    }

    private function authorize()
    {
        if (! current_user_can(PFORM_Plugin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage parish forms.', 'parish-forms'), '', array('response' => 403));
        }
    }
}
