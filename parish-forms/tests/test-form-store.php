<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['pform_test_posts'] = array();
$GLOBALS['pform_test_meta'] = array();
$GLOBALS['pform_test_next_id'] = 1;

function __($text)
{
    return $text;
}

function sanitize_text_field($value)
{
    return trim(strip_tags((string) $value));
}

function sanitize_textarea_field($value)
{
    return trim(strip_tags((string) $value));
}

function sanitize_email($value)
{
    return filter_var((string) $value, FILTER_SANITIZE_EMAIL);
}

function is_email($value)
{
    return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
}

function sanitize_key($value)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}

function sanitize_title($value)
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    return trim($value, '-');
}

function absint($value)
{
    return abs((int) $value);
}

class WP_Error
{
    private $message;

    public function __construct($code, $message)
    {
        $this->message = $message;
    }

    public function get_error_message()
    {
        return $this->message;
    }
}

function is_wp_error($value)
{
    return $value instanceof WP_Error;
}

function register_post_type($post_type, $args)
{
    return true;
}

function wp_insert_post($args, $wp_error = false)
{
    $id = $GLOBALS['pform_test_next_id']++;
    $post = (object) array(
        'ID' => $id,
        'post_type' => isset($args['post_type']) ? $args['post_type'] : 'post',
        'post_status' => isset($args['post_status']) ? $args['post_status'] : 'draft',
        'post_title' => isset($args['post_title']) ? $args['post_title'] : '',
        'post_date' => sprintf('2026-10-07 12:%02d:00', $id % 60),
        'post_modified' => sprintf('2026-10-07 12:%02d:00', $id % 60),
    );
    $GLOBALS['pform_test_posts'][$id] = $post;
    return $id;
}

function wp_update_post($args)
{
    $id = isset($args['ID']) ? (int) $args['ID'] : 0;
    if (! isset($GLOBALS['pform_test_posts'][$id])) {
        return 0;
    }
    if (isset($args['post_title'])) {
        $GLOBALS['pform_test_posts'][$id]->post_title = $args['post_title'];
    }
    $GLOBALS['pform_test_posts'][$id]->post_modified = '2026-10-07 13:00:00';
    return $id;
}

function update_post_meta($post_id, $key, $value)
{
    if (! isset($GLOBALS['pform_test_meta'][$post_id])) {
        $GLOBALS['pform_test_meta'][$post_id] = array();
    }
    $GLOBALS['pform_test_meta'][$post_id][$key] = $value;
    return true;
}

function get_post_meta($post_id, $key, $single = false)
{
    if (! isset($GLOBALS['pform_test_meta'][$post_id][$key])) {
        return $single ? '' : array();
    }
    return $single
        ? $GLOBALS['pform_test_meta'][$post_id][$key]
        : array($GLOBALS['pform_test_meta'][$post_id][$key]);
}

function get_post($post_id)
{
    return isset($GLOBALS['pform_test_posts'][$post_id]) ? $GLOBALS['pform_test_posts'][$post_id] : null;
}

function get_posts($args)
{
    $posts = array_values($GLOBALS['pform_test_posts']);
    $posts = array_filter($posts, function ($post) use ($args) {
        if (isset($args['post_type']) && $post->post_type !== $args['post_type']) {
            return false;
        }
        if (isset($args['post_status'])) {
            $allowed = (array) $args['post_status'];
            if (! in_array($post->post_status, $allowed, true)) {
                return false;
            }
        }
        if (isset($args['meta_key']) && array_key_exists('meta_value', $args)) {
            if ((string) get_post_meta($post->ID, $args['meta_key'], true) !== (string) $args['meta_value']) {
                return false;
            }
        }
        if (! empty($args['meta_query'])) {
            foreach ($args['meta_query'] as $query) {
                if ((string) get_post_meta($post->ID, $query['key'], true) !== (string) $query['value']) {
                    return false;
                }
            }
        }
        return true;
    });

    $posts = array_values($posts);
    if (isset($args['orderby']) && $args['orderby'] === 'meta_value_num') {
        $meta_key = isset($args['meta_key']) ? $args['meta_key'] : '';
        usort($posts, function ($a, $b) use ($meta_key) {
            return (int) get_post_meta($a->ID, $meta_key, true) <=> (int) get_post_meta($b->ID, $meta_key, true);
        });
    } elseif (isset($args['orderby']) && $args['orderby'] === 'title') {
        usort($posts, function ($a, $b) {
            return strcasecmp($a->post_title, $b->post_title);
        });
    }

    if (isset($args['order']) && strtoupper($args['order']) === 'DESC') {
        $posts = array_reverse($posts);
    }
    if (isset($args['posts_per_page']) && (int) $args['posts_per_page'] > 0) {
        $posts = array_slice($posts, 0, (int) $args['posts_per_page']);
    }
    if (isset($args['fields']) && $args['fields'] === 'ids') {
        return array_map(function ($post) { return $post->ID; }, $posts);
    }
    return $posts;
}

function assert_store($condition, $message)
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

require_once dirname(__DIR__) . '/includes/forms/class-pform-parish-registration.php';
require_once dirname(__DIR__) . '/includes/forms/class-pform-pre-baptismal-questionnaire.php';
require_once dirname(__DIR__) . '/includes/forms/class-pform-confirmation-interest.php';
require_once dirname(__DIR__) . '/includes/class-pform-definition-sanitizer.php';
require_once dirname(__DIR__) . '/includes/class-pform-form-store.php';

$builtins = array(
    'parish-registration' => PFORM_Parish_Registration::definition(),
    'pre-baptismal-questionnaire' => PFORM_Pre_Baptismal_Questionnaire::definition(),
    'confirmation-interest' => PFORM_Confirmation_Interest::definition(),
);

PFORM_Form_Store::seed_builtins($builtins);
$records = PFORM_Form_Store::records();
assert_store(count($records) === 3, 'All built-in forms should be seeded once.');
assert_store(PFORM_Form_Store::get_published('parish-registration')['version'] === 1, 'Seeded form should publish as version 1.');

PFORM_Form_Store::seed_builtins($builtins);
assert_store(count(PFORM_Form_Store::records()) === 3, 'Seeding should be idempotent.');

$draft = PFORM_Form_Store::get_draft('parish-registration');
$draft['title'] = 'Parish Registration Updated';
$draft['sections'][0]['fields'][0]['label'] = 'Household Name';
$saved = PFORM_Form_Store::save_draft('parish-registration', $draft);
assert_store(! is_wp_error($saved), 'A valid form draft should save.');
assert_store($saved['version'] === 2, 'Draft after version 1 should target version 2.');
assert_store(PFORM_Form_Store::get_published('parish-registration')['title'] !== 'Parish Registration Updated', 'Saving a draft must not change the published form.');

$published = PFORM_Form_Store::publish('parish-registration');
assert_store(! is_wp_error($published), 'A saved draft should publish.');
assert_store($published['version'] === 2, 'Publishing should create version 2.');
assert_store(PFORM_Form_Store::get_published('parish-registration')['title'] === 'Parish Registration Updated', 'Published definition should reflect draft changes.');
assert_store(count(PFORM_Form_Store::versions('parish-registration')) === 2, 'Published form should retain immutable version history.');

PFORM_Form_Store::set_retired('parish-registration', true);
assert_store(PFORM_Form_Store::get_published('parish-registration') === null, 'Retired form should not be available publicly.');
assert_store(PFORM_Form_Store::get_published('parish-registration', true)['version'] === 2, 'Retired form should remain available to administrators.');
PFORM_Form_Store::set_retired('parish-registration', false);

$duplicate_id = PFORM_Form_Store::duplicate('parish-registration');
assert_store(! is_wp_error($duplicate_id), 'Published form should duplicate.');
assert_store($duplicate_id !== 'parish-registration', 'Duplicate should receive a new form ID.');
assert_store(PFORM_Form_Store::get_published($duplicate_id, true) === null, 'Duplicate should begin as an unpublished draft.');
assert_store(strpos(PFORM_Form_Store::get_draft($duplicate_id)['title'], 'Copy') !== false, 'Duplicate title should identify it as a copy.');

$versions = PFORM_Form_Store::versions('parish-registration');
$oldest = end($versions);
$restored = PFORM_Form_Store::restore_version_to_draft('parish-registration', $oldest['post_id']);
assert_store(! is_wp_error($restored), 'Older version should restore to draft.');
assert_store($restored['version'] === 3, 'Restored version should target the next publish version.');
assert_store(PFORM_Form_Store::get_published('parish-registration')['version'] === 2, 'Restoring to draft must not change the live version.');

$empty_id = PFORM_Form_Store::create_blank('Empty Test Form');
$empty = PFORM_Form_Store::get_draft($empty_id);
$empty['sections'][0]['fields'] = array();
PFORM_Form_Store::save_draft($empty_id, $empty);
$empty_publish = PFORM_Form_Store::publish($empty_id);
assert_store(is_wp_error($empty_publish), 'A form with no fields must not publish.');

echo "Form store tests passed.\n";
