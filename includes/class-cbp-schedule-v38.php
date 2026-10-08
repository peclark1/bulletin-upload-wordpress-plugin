<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Agent/API facade for Bulletin Publisher parser operations.
 *
 * These abilities expose only pending-review operations. They never approve
 * the generated website information and never publish a bulletin.
 */
final class CBP_Schedule_V38
{
    const ABILITY_CATEGORY = 'church-bulletin-publisher';

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
        add_action('wp_abilities_api_init', array($this, 'register_abilities'));
        add_action('init', array($this, 'ensure_abilities_registered'), 100);
    }

    public function register_abilities()
    {
        if (! function_exists('wp_register_ability')) {
            return;
        }

        $this->register_ability_compat(
            'church-bulletin-publisher/parser-status',
            array(
                'label' => __('Get Bulletin Publisher parser status', 'church-bulletin-publisher'),
                'description' => __('Returns the installed Bulletin Publisher version, current private preview status, pending review revision, and active extraction pipeline. This is read-only and does not modify or publish anything.', 'church-bulletin-publisher'),
                'category' => self::ABILITY_CATEGORY,
                'execute_callback' => array($this, 'ability_parser_status'),
                'permission_callback' => array($this, 'ability_permission'),
                'output_schema' => array(
                    'type' => 'object',
                    'additionalProperties' => true,
                ),
                'meta' => $this->ability_meta(true, false, true),
            )
        );

        $this->register_ability_compat(
            'church-bulletin-publisher/run-current-preview-extraction',
            array(
                'label' => __('Run Bulletin Publisher extraction on current preview', 'church-bulletin-publisher'),
                'description' => __('Runs the exact same production parser pipeline used by the WordPress review button against the current private bulletin preview. It replaces only the pending parser review and never approves or publishes. The current preview SHA-256 is required. If a review already exists for the same bulletin, its current revision is also required to prevent overwriting newer edits.', 'church-bulletin-publisher'),
                'category' => self::ABILITY_CATEGORY,
                'execute_callback' => array($this, 'ability_run_current_preview_extraction'),
                'permission_callback' => array($this, 'ability_permission'),
                'input_schema' => array(
                    'type' => 'object',
                    'required' => array('expected_preview_sha256'),
                    'properties' => array(
                        'expected_preview_sha256' => array(
                            'type' => 'string',
                            'minLength' => 1,
                            'description' => __('SHA-256 of the current private preview returned by parser-status.', 'church-bulletin-publisher'),
                        ),
                        'expected_review_revision' => array(
                            'type' => 'string',
                            'description' => __('Current pending review revision returned by parser-status or get-review. Required when replacing an existing review for the same bulletin.', 'church-bulletin-publisher'),
                        ),
                    ),
                    'additionalProperties' => false,
                ),
                'output_schema' => array(
                    'type' => 'object',
                    'additionalProperties' => true,
                ),
                'meta' => $this->ability_meta(false, true, true),
            )
        );
    }

    public function ensure_abilities_registered()
    {
        if (class_exists('CBP_Schedule_V34')) {
            CBP_Schedule_V34::instance()->ensure_abilities_registered();
        }
        $this->register_abilities();
    }

    private function register_ability_compat($name, array $args)
    {
        if (function_exists('wp_has_ability') && wp_has_ability($name)) {
            return;
        }

        if (function_exists('doing_action')
            && doing_action('wp_abilities_api_init')
            && function_exists('wp_register_ability')) {
            wp_register_ability($name, $args);
            return;
        }

        if (class_exists('WP_Abilities_Registry')) {
            $registry = WP_Abilities_Registry::get_instance();
            if ($registry && ! $registry->is_registered($name)) {
                $registry->register($name, $args);
                return;
            }
        }

        if (! class_exists('WP_Abilities_Registry')
            && function_exists('wp_register_ability')) {
            wp_register_ability($name, $args);
        }
    }

    public function ability_permission()
    {
        return current_user_can('manage_options');
    }

    public function ability_parser_status()
    {
        $preview = get_transient($this->preview_key());
        $review = get_transient($this->review_key());

        $preview_payload = array(
            'available' => false,
            'date' => '',
            'sha256' => '',
            'readable' => false,
        );

        if (is_array($preview)) {
            $path = isset($preview['path']) ? (string) $preview['path'] : '';
            $preview_payload = array(
                'available' => $path !== '' && is_readable($path),
                'date' => sanitize_text_field((string) ($preview['date'] ?? '')),
                'sha256' => $this->preview_sha256($preview),
                'readable' => $path !== '' && is_readable($path),
            );
        }

        $review_payload = array(
            'available' => false,
            'bulletin_date' => '',
            'revision' => '',
            'pipeline_id' => '',
        );
        if (is_array($review) && ! empty($review['weekly'])) {
            $review_payload = array(
                'available' => true,
                'bulletin_date' => (string) ($review['bulletin_date'] ?? ''),
                'revision' => $this->review_revision($review),
                'pipeline_id' => (string) ($review['_pipeline']['id'] ?? ''),
            );
        }

        return array(
            'plugin_version' => defined('CBP_VERSION') ? (string) CBP_VERSION : '',
            'wordpress_version' => function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '',
            'php_version' => PHP_VERSION,
            'pipeline' => array(
                'id' => 'cbp-production-extraction-v1',
                'shared_browser_and_api_path' => true,
                'legacy_repairs' => 'V6-V33 synchronous',
                'final_normalizer' => class_exists('CBP_Weekly_Normalizer') ? 'CBP_Weekly_Normalizer' : '',
                'legacy_shutdown_hooks_removed_after_run' => true,
            ),
            'preview' => $preview_payload,
            'review' => $review_payload,
            'safety' => array(
                'can_modify_pending_review' => true,
                'can_approve_review' => false,
                'can_publish_bulletin' => false,
            ),
        );
    }

    public function ability_run_current_preview_extraction($input)
    {
        $input = is_array($input) ? $input : array();
        $preview = get_transient($this->preview_key());
        if (! is_array($preview) || empty($preview['path']) || ! is_readable($preview['path'])) {
            return new WP_Error(
                'cbp_preview_missing',
                __('There is no readable private bulletin preview to extract. Create the preview first.', 'church-bulletin-publisher')
            );
        }

        $expected_preview = trim((string) ($input['expected_preview_sha256'] ?? ''));
        $actual_preview = $this->preview_sha256($preview);
        if ($expected_preview === '' || $actual_preview === '' || ! hash_equals($actual_preview, $expected_preview)) {
            return new WP_Error(
                'cbp_stale_preview',
                __('The private bulletin preview changed after it was inspected. Read parser status again before running extraction.', 'church-bulletin-publisher')
            );
        }

        $review = get_transient($this->review_key());
        $preview_date = sanitize_text_field((string) ($preview['date'] ?? ''));
        if (is_array($review)
            && ! empty($review['weekly'])
            && (string) ($review['bulletin_date'] ?? '') === $preview_date) {
            $expected_revision = trim((string) ($input['expected_review_revision'] ?? ''));
            $actual_revision = $this->review_revision($review);
            if ($expected_revision === '' || ! hash_equals($actual_revision, $expected_revision)) {
                return new WP_Error(
                    'cbp_stale_review',
                    __('A pending review already exists for this bulletin. Read parser status or get-review again and provide its current revision before replacing it.', 'church-bulletin-publisher')
                );
            }
        }

        $result = CBP_Schedule_V5::instance()->run_extraction_pipeline('ability');
        if (is_wp_error($result)) {
            return $result;
        }

        $review_payload = CBP_Schedule_V34::instance()->ability_get_review();
        if (is_wp_error($review_payload)) {
            return $review_payload;
        }

        return array(
            'status' => $this->ability_parser_status(),
            'review' => $review_payload,
            'note' => __('Extraction completed into the pending review only. A human must still review and approve before publication.', 'church-bulletin-publisher'),
        );
    }

    private function ability_meta($readonly, $destructive, $idempotent)
    {
        return array(
            'public' => true,
            'show_in_rest' => true,
            'mcp' => array(
                'public' => true,
            ),
            'annotations' => array(
                'readonly' => (bool) $readonly,
                'destructive' => (bool) $destructive,
                'idempotent' => (bool) $idempotent,
            ),
        );
    }

    private function preview_sha256(array $preview)
    {
        $sha = trim((string) ($preview['sha256'] ?? ''));
        if ($sha !== '') {
            return $sha;
        }

        $path = isset($preview['path']) ? (string) $preview['path'] : '';
        if ($path !== '' && is_readable($path)) {
            $calculated = hash_file('sha256', $path);
            return is_string($calculated) ? $calculated : '';
        }

        return '';
    }

    private function review_revision(array $review)
    {
        $relevant = array(
            'bulletin_date' => (string) ($review['bulletin_date'] ?? ''),
            'candidates' => isset($review['candidates']) && is_array($review['candidates']) ? $review['candidates'] : array(),
            'weekly' => isset($review['weekly']) && is_array($review['weekly']) ? $review['weekly'] : array(),
        );
        $json = function_exists('wp_json_encode') ? wp_json_encode($relevant) : json_encode($relevant);
        return hash('sha256', (string) $json);
    }

    private function preview_key()
    {
        return 'cbp_preview_' . get_current_user_id();
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }
}
