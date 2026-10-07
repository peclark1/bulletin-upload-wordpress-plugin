<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Form_Store
{
    const POST_TYPE = 'pform_form';

    public static function register_post_type()
    {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => __('Parish Forms', 'parish-forms'),
                'singular_name' => __('Parish Form', 'parish-forms'),
            ),
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'rewrite' => false,
            'query_var' => false,
            'supports' => array('title'),
            'capability_type' => 'post',
        ));
    }

    public static function seed_builtin($definition)
    {
        if (! is_array($definition) || empty($definition['id'])) {
            return 0;
        }
        $existing = self::post_for_id($definition['id']);
        if ($existing) {
            return $existing->ID;
        }

        $clean = PFORM_Definition_Sanitizer::sanitize($definition, $definition['id']);
        $post_id = wp_insert_post(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'private',
            'post_title' => $clean['title'],
            'post_content' => '',
        ), true);
        if (is_wp_error($post_id)) {
            return 0;
        }

        update_post_meta($post_id, '_pform_form_id', $clean['id']);
        update_post_meta($post_id, '_pform_form_status', 'published');
        update_post_meta($post_id, '_pform_published_version', 1);
        $clean['version'] = 1;
        update_post_meta($post_id, '_pform_published_definition', $clean);
        update_post_meta($post_id, '_pform_draft_definition', $clean);
        update_post_meta($post_id, '_pform_history', array(
            array(
                'version' => 1,
                'published_at' => current_time('mysql'),
                'definition' => $clean,
            ),
        ));

        return $post_id;
    }

    public static function create_blank($title = '')
    {
        $title = sanitize_text_field($title);
        if (! $title) {
            $title = __('Untitled Form', 'parish-forms');
        }
        $form_id = self::available_form_id(sanitize_title($title) ?: 'form');
        $definition = PFORM_Definition_Sanitizer::sanitize(array(
            'id' => $form_id,
            'title' => $title,
            'eyebrow' => __('Parish Forms', 'parish-forms'),
            'description' => '',
            'submit_label' => __('Submit', 'parish-forms'),
            'success_title' => __('Submission Received', 'parish-forms'),
            'confirmation' => __('Thank you. Your form has been received.', 'parish-forms'),
            'privacy_note' => __('Information submitted through this form is intended for parish-office follow-up.', 'parish-forms'),
            'sections' => array(
                array(
                    'id' => 'section-1',
                    'title' => __('Form Information', 'parish-forms'),
                    'description' => '',
                    'fields' => array(
                        array(
                            'id' => 'name',
                            'type' => 'text',
                            'label' => __('Name', 'parish-forms'),
                            'required' => true,
                            'width' => 'full',
                            'max_length' => 150,
                            'autocomplete' => 'name',
                        ),
                    ),
                ),
            ),
        ), $form_id);

        $post_id = wp_insert_post(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'private',
            'post_title' => $title,
            'post_content' => '',
        ), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta($post_id, '_pform_form_id', $form_id);
        update_post_meta($post_id, '_pform_form_status', 'draft');
        update_post_meta($post_id, '_pform_published_version', 0);
        update_post_meta($post_id, '_pform_draft_definition', $definition);
        update_post_meta($post_id, '_pform_history', array());

        return $post_id;
    }

    public static function duplicate($post_id)
    {
        $source = self::draft($post_id);
        if (! $source) {
            $source = self::published_by_post($post_id);
        }
        if (! $source) {
            return new WP_Error('pform_missing_definition', __('Form definition not found.', 'parish-forms'));
        }

        $title = sprintf(__('%s Copy', 'parish-forms'), $source['title']);
        $new_id = self::available_form_id(sanitize_title($title) ?: 'form-copy');
        $copy = $source;
        $copy['id'] = $new_id;
        $copy['title'] = $title;
        $copy['version'] = 1;
        $copy = PFORM_Definition_Sanitizer::sanitize($copy, $new_id);

        $new_post_id = wp_insert_post(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'private',
            'post_title' => $title,
            'post_content' => '',
        ), true);
        if (is_wp_error($new_post_id)) {
            return $new_post_id;
        }

        update_post_meta($new_post_id, '_pform_form_id', $new_id);
        update_post_meta($new_post_id, '_pform_form_status', 'draft');
        update_post_meta($new_post_id, '_pform_published_version', 0);
        update_post_meta($new_post_id, '_pform_draft_definition', $copy);
        update_post_meta($new_post_id, '_pform_history', array());

        return $new_post_id;
    }

    public static function save_draft($post_id, $raw_definition)
    {
        $post = get_post($post_id);
        if (! $post || $post->post_type !== self::POST_TYPE) {
            return new WP_Error('pform_invalid_form', __('Form not found.', 'parish-forms'));
        }

        $form_id = get_post_meta($post_id, '_pform_form_id', true);
        $clean = PFORM_Definition_Sanitizer::sanitize($raw_definition, $form_id);
        $current_version = absint(get_post_meta($post_id, '_pform_published_version', true));
        $clean['version'] = max(1, $current_version + 1);

        update_post_meta($post_id, '_pform_draft_definition', $clean);
        wp_update_post(array('ID' => $post_id, 'post_title' => $clean['title']));

        $published = get_post_meta($post_id, '_pform_published_definition', true);
        update_post_meta($post_id, '_pform_form_status', is_array($published) ? 'published' : 'draft');

        return $clean;
    }

    public static function publish($post_id, $raw_definition)
    {
        $draft = self::save_draft($post_id, $raw_definition);
        if (is_wp_error($draft)) {
            return $draft;
        }

        $version = absint(get_post_meta($post_id, '_pform_published_version', true)) + 1;
        $draft['version'] = $version;
        update_post_meta($post_id, '_pform_draft_definition', $draft);
        update_post_meta($post_id, '_pform_published_definition', $draft);
        update_post_meta($post_id, '_pform_published_version', $version);
        update_post_meta($post_id, '_pform_form_status', 'published');

        $history = get_post_meta($post_id, '_pform_history', true);
        $history = is_array($history) ? $history : array();
        $history[] = array(
            'version' => $version,
            'published_at' => current_time('mysql'),
            'definition' => $draft,
        );
        if (count($history) > 100) {
            $history = array_slice($history, -100);
        }
        update_post_meta($post_id, '_pform_history', $history);

        return $draft;
    }

    public static function retire($post_id)
    {
        if (! self::is_form_post($post_id)) {
            return false;
        }
        update_post_meta($post_id, '_pform_form_status', 'retired');
        return true;
    }

    public static function restore($post_id)
    {
        if (! self::is_form_post($post_id)) {
            return false;
        }
        $published = get_post_meta($post_id, '_pform_published_definition', true);
        update_post_meta($post_id, '_pform_form_status', is_array($published) ? 'published' : 'draft');
        return true;
    }

    public static function draft($post_id)
    {
        $definition = get_post_meta($post_id, '_pform_draft_definition', true);
        return is_array($definition) ? $definition : null;
    }

    public static function published_by_post($post_id)
    {
        if (get_post_meta($post_id, '_pform_form_status', true) === 'retired') {
            return null;
        }
        $definition = get_post_meta($post_id, '_pform_published_definition', true);
        return is_array($definition) ? $definition : null;
    }

    public static function published($form_id)
    {
        $post = self::post_for_id($form_id);
        return $post ? self::published_by_post($post->ID) : null;
    }

    public static function published_all()
    {
        $forms = array();
        foreach (self::posts() as $post) {
            $definition = self::published_by_post($post->ID);
            if ($definition && ! empty($definition['id'])) {
                $forms[$definition['id']] = $definition;
            }
        }
        return $forms;
    }

    public static function posts($include_retired = true)
    {
        $posts = get_posts(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'private',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ));
        if ($include_retired) {
            return $posts;
        }
        return array_values(array_filter($posts, function ($post) {
            return get_post_meta($post->ID, '_pform_form_status', true) !== 'retired';
        }));
    }

    public static function post_for_id($form_id)
    {
        $posts = get_posts(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'private',
            'posts_per_page' => 1,
            'meta_key' => '_pform_form_id',
            'meta_value' => sanitize_key($form_id),
        ));
        return $posts ? $posts[0] : null;
    }

    public static function managed_ids()
    {
        $ids = array();
        foreach (self::posts() as $post) {
            $id = get_post_meta($post->ID, '_pform_form_id', true);
            if ($id) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public static function history($post_id)
    {
        $history = get_post_meta($post_id, '_pform_history', true);
        return is_array($history) ? $history : array();
    }

    public static function status($post_id)
    {
        return get_post_meta($post_id, '_pform_form_status', true) ?: 'draft';
    }

    public static function form_id($post_id)
    {
        return sanitize_key(get_post_meta($post_id, '_pform_form_id', true));
    }

    public static function is_form_post($post_id)
    {
        return get_post_type($post_id) === self::POST_TYPE;
    }

    private static function available_form_id($base)
    {
        $base = sanitize_key($base) ?: 'form';
        $candidate = $base;
        $suffix = 2;
        while (self::post_for_id($candidate) || PFORM_Form_Registry::builtin($candidate)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }
        return $candidate;
    }
}
