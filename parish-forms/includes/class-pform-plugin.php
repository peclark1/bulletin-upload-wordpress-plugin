<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Plugin
{
    const OPTION = 'pform_settings';
    const CAPABILITY = 'manage_parish_forms';
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
        add_action('init', array('PFORM_Submissions', 'register_post_type'));
        add_action('init', array($this, 'register_assets'));
        add_action('admin_post_pform_submit', array($this, 'handle_submission'));
        add_action('admin_post_nopriv_pform_submit', array($this, 'handle_submission'));
        add_shortcode('parish_form', array($this, 'shortcode'));
        add_action('admin_init', array($this, 'privacy_policy_content'));
        PFORM_Admin::instance();
    }

    public static function activate()
    {
        PFORM_Submissions::register_post_type();

        $administrator = get_role('administrator');
        if ($administrator) {
            foreach (array(
                self::CAPABILITY,
                'read_private_pform_submissions',
                'edit_pform_submissions',
                'edit_private_pform_submissions',
                'edit_others_pform_submissions',
                'delete_pform_submissions',
                'delete_private_pform_submissions',
                'delete_others_pform_submissions',
            ) as $capability) {
                $administrator->add_cap($capability);
            }
        }

        if (! get_option(self::OPTION)) {
            add_option(self::OPTION, array(
                'notification_emails' => sanitize_email(get_option('admin_email')),
            ));
        }

        if (! get_option('pform_registration_page_id')) {
            $page_id = wp_insert_post(array(
                'post_title' => __('Parish Registration', 'parish-forms'),
                'post_content' => '<!-- wp:shortcode -->[parish_form id="parish-registration"]<!-- /wp:shortcode -->',
                'post_status' => 'draft',
                'post_type' => 'page',
            ));
            if (! is_wp_error($page_id)) {
                add_option('pform_registration_page_id', $page_id);
            }
        }

        flush_rewrite_rules(false);
    }

    public function register_assets()
    {
        wp_register_style('pform-frontend', PFORM_URL . 'assets/frontend.css', array(), PFORM_VERSION);
        wp_register_script('pform-frontend', PFORM_URL . 'assets/frontend.js', array(), PFORM_VERSION, true);
    }

    public function shortcode($attributes)
    {
        $attributes = shortcode_atts(array('id' => 'parish-registration'), $attributes, 'parish_form');
        $form_id = sanitize_key($attributes['id']);
        $definition = PFORM_Form_Registry::get($form_id);
        if (! $definition) {
            return current_user_can(self::CAPABILITY)
                ? '<p>' . esc_html__('Parish Forms: unknown form ID.', 'parish-forms') . '</p>'
                : '';
        }

        wp_enqueue_style('pform-frontend');
        wp_enqueue_script('pform-frontend');

        $state = $this->submission_state($form_id);
        return PFORM_Renderer::render($definition, $state);
    }

    public function handle_submission()
    {
        $form_id = isset($_POST['pform_id']) ? sanitize_key(wp_unslash($_POST['pform_id'])) : '';
        $definition = PFORM_Form_Registry::get($form_id);
        if (! $definition) {
            wp_die(esc_html__('This form is not available.', 'parish-forms'), '', array('response' => 400));
        }

        $return_url = $this->return_url();
        if (! isset($_POST['_pform_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_pform_nonce'])), 'pform_submit_' . $form_id)) {
            $this->redirect_with_errors($return_url, $form_id, array(
                '_form' => __('Your form session expired. Please review the form and submit it again.', 'parish-forms'),
            ), array());
        }

        $raw = isset($_POST['pf']) && is_array($_POST['pf']) ? wp_unslash($_POST['pf']) : array();
        if (! empty($raw['website'])) {
            $this->redirect_success($return_url);
        }

        $preview = PFORM_Validator::validate($definition, $raw);

        if (! $this->valid_started_signature($form_id)) {
            $this->redirect_with_errors($return_url, $form_id, array(
                '_form' => __('Please wait a moment, then submit the form again.', 'parish-forms'),
            ), $preview['data']);
        }

        if ($this->rate_limited($form_id)) {
            $this->redirect_with_errors($return_url, $form_id, array(
                '_form' => __('Too many submissions were received from this connection. Please try again later or contact the parish office.', 'parish-forms'),
            ), $preview['data']);
        }

        $result = $preview;
        if ($result['errors']) {
            $this->redirect_with_errors($return_url, $form_id, $result['errors'], $result['data']);
        }

        $submission_id = PFORM_Submissions::create($definition, $result['data']);
        if (is_wp_error($submission_id)) {
            $this->redirect_with_errors($return_url, $form_id, array(
                '_form' => __('We could not save your registration. Please try again or contact the parish office.', 'parish-forms'),
            ), $result['data']);
        }

        $sent = PFORM_Notifications::send($submission_id, $definition, $result['data']);
        update_post_meta($submission_id, '_pform_email_sent', $sent ? '1' : '0');
        do_action('pform_submission_created', $submission_id, $form_id, $result['data']);
        $this->redirect_success($return_url);
    }

    public function privacy_policy_content()
    {
        if (! function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(
            __('Parish Forms', 'parish-forms'),
            wp_kses_post(__('<p>Parish form submissions may include household contact information, dates of birth, sacramental information, ministry interests, and registration choices. Submissions are stored privately in WordPress for parish-office use and may be sent to configured parish staff email addresses.</p><p>Access is limited to WordPress users granted the Parish Forms management capability. Submissions remain stored until an authorized administrator moves them to the trash or permanently deletes them under the parish records-retention policy.</p>', 'parish-forms'))
        );
    }

    public static function settings()
    {
        return wp_parse_args(get_option(self::OPTION, array()), array(
            'notification_emails' => sanitize_email(get_option('admin_email')),
        ));
    }

    public static function signature($form_id, $timestamp)
    {
        return hash_hmac('sha256', $form_id . '|' . $timestamp, wp_salt('nonce'));
    }

    private function valid_started_signature($form_id)
    {
        $timestamp = isset($_POST['pform_started']) ? absint($_POST['pform_started']) : 0;
        $signature = isset($_POST['pform_signature']) ? sanitize_text_field(wp_unslash($_POST['pform_signature'])) : '';
        $age = time() - $timestamp;
        return $timestamp > 0
            && $age >= 2
            && $age <= 7 * DAY_IN_SECONDS
            && hash_equals(self::signature($form_id, $timestamp), $signature);
    }

    private function rate_limited($form_id)
    {
        $address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $key = 'pform_rate_' . md5($form_id . '|' . $address . '|' . wp_salt('nonce'));
        $count = absint(get_transient($key));
        if ($count >= (int) apply_filters('pform_hourly_submission_limit', 10, $form_id)) {
            return true;
        }
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
        return false;
    }

    private function submission_state($form_id)
    {
        $state_key = isset($_GET['pform_state']) ? sanitize_key(wp_unslash($_GET['pform_state'])) : '';
        $state = array('errors' => array(), 'values' => array(), 'success' => false);
        if ($state_key) {
            $saved = get_transient('pform_state_' . $state_key);
            delete_transient('pform_state_' . $state_key);
            if (is_array($saved) && isset($saved['form_id']) && $saved['form_id'] === $form_id) {
                $state['errors'] = isset($saved['errors']) && is_array($saved['errors']) ? $saved['errors'] : array();
                $state['values'] = isset($saved['values']) && is_array($saved['values']) ? $saved['values'] : array();
            }
        }
        $state['success'] = isset($_GET['pform_status']) && sanitize_key(wp_unslash($_GET['pform_status'])) === 'success';
        return $state;
    }

    private function return_url()
    {
        $fallback = home_url('/');
        $url = wp_get_referer();
        $url = $url ? wp_validate_redirect($url, $fallback) : $fallback;
        return remove_query_arg(array('pform_status', 'pform_state'), $url);
    }

    private function redirect_with_errors($return_url, $form_id, $errors, $values)
    {
        $state_key = strtolower(wp_generate_password(20, false, false));
        set_transient('pform_state_' . $state_key, array(
            'form_id' => $form_id,
            'errors' => $errors,
            'values' => $values,
        ), 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg('pform_state', $state_key, $return_url) . '#parish-form');
        exit;
    }

    private function redirect_success($return_url)
    {
        wp_safe_redirect(add_query_arg('pform_status', 'success', $return_url) . '#parish-form');
        exit;
    }
}
