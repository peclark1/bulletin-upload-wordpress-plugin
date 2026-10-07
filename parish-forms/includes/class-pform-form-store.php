<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Form_Store
{
    const FORM_POST_TYPE = 'pform_form';
    const VERSION_POST_TYPE = 'pform_form_ver';

    public static function register_post_types()
    {
        register_post_type(self::FORM_POST_TYPE, array(
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
        ));

        register_post_type(self::VERSION_POST_TYPE, array(
            'labels' => array(
                'name' => __('Parish Form Versions', 'parish-forms'),
                'singular_name' => __('Parish Form Version', 'parish-forms'),
            ),
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'rewrite' => false,
            'query_var' => false,
            'supports' => array('title'),
        ));
    }

    public static function seed_builtins($definitions)
    {
        foreach ((array) $definitions as $definition) {
            if (! is_array($definition) || empty($definition['id']) || self::exists($definition['id'])) {
                continue;
            }
            $form_id = self::create_form($definition['title'], $definition['id'], $definition);
            if (! is_wp_error($form_id)) {
                self::publish($definition['id']);
            }
        }
    }

    public static function exists($form_id)
    {
        return (bool) self::form_post($form_id);
    }

    public static function create_blank($title)
    {
        $title = sanitize_text_field($title);
        if ($title === '') {
            return new WP_Error('pform_missing_title', __('A form title is required.', 'parish-forms'));
        }

        $form_id = self::unique_form_id(sanitize_title($title));
        $definition = array(
            'id' => $form_id,
            'version' => 1,
            'title' => $title,
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
                    'fields' => array(
                        array(
                            'id' => 'name',
                            'type' => 'text',
                            'label' => __('Name', 'parish-forms'),
                            'required' => true,
                            'width' => 'full',
                            'max_length' => 180,
                            'autocomplete' => 'name',
                        ),
                    ),
                ),
            ),
        );

        return self::create_form($title, $form_id, $definition);
    }

    public static function create_form($title, $form_id, $definition)
    {
        $form_id = self::unique_form_id($form_id);
        $clean = PFORM_Definition_Sanitizer::sanitize($definition, $form_id);
        if (is_wp_error($clean)) {
            return $clean;
        }

        $post_id = wp_insert_post(array(
            'post_type' => self::FORM_POST_TYPE,
            'post_status' => 'private',
            'post_title' => $clean['title'],
        ), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta($post_id, '_pform_form_id', $form_id);
        update_post_meta($post_id, '_pform_draft_definition', $clean);
        update_post_meta($post_id, '_pform_published_version', 0);
        update_post_meta($post_id, '_pform_published_version_post', 0);
        update_post_meta($post_id, '_pform_has_draft_changes', '1');
        update_post_meta($post_id, '_pform_retired', '0');

        return $form_id;
    }

    public static function save_draft($form_id, $definition)
    {
        $post = self::form_post($form_id);
        if (! $post) {
            return new WP_Error('pform_unknown_form', __('The form could not be found.', 'parish-forms'));
        }

        $clean = PFORM_Definition_Sanitizer::sanitize($definition, $form_id);
        if (is_wp_error($clean)) {
            return $clean;
        }

        $published_version = absint(get_post_meta($post->ID, '_pform_published_version', true));
        $clean['version'] = max(1, $published_version + 1);
        update_post_meta($post->ID, '_pform_draft_definition', $clean);
        update_post_meta($post->ID, '_pform_has_draft_changes', '1');
        wp_update_post(array('ID' => $post->ID, 'post_title' => $clean['title']));

        return $clean;
    }

    public static function publish($form_id)
    {
        $post = self::form_post($form_id);
        if (! $post) {
            return new WP_Error('pform_unknown_form', __('The form could not be found.', 'parish-forms'));
        }

        $draft = self::get_draft($form_id);
        if (! $draft) {
            return new WP_Error('pform_missing_draft', __('The form does not have a draft to publish.', 'parish-forms'));
        }

        $field_count = 0;
        foreach ((array) $draft['sections'] as $section) {
            $field_count += count(isset($section['fields']) && is_array($section['fields']) ? $section['fields'] : array());
        }
        if ($field_count < 1) {
            return new WP_Error('pform_empty_form', __('Add at least one field before publishing the form.', 'parish-forms'));
        }

        $next_version = absint(get_post_meta($post->ID, '_pform_published_version', true)) + 1;
        $draft['version'] = $next_version;

        $version_post_id = wp_insert_post(array(
            'post_type' => self::VERSION_POST_TYPE,
            'post_status' => 'private',
            'post_title' => sprintf('%s v%d', $draft['title'], $next_version),
        ), true);
        if (is_wp_error($version_post_id)) {
            return $version_post_id;
        }

        update_post_meta($version_post_id, '_pform_form_id', $form_id);
        update_post_meta($version_post_id, '_pform_version', $next_version);
        update_post_meta($version_post_id, '_pform_definition', $draft);

        update_post_meta($post->ID, '_pform_published_version', $next_version);
        update_post_meta($post->ID, '_pform_published_version_post', $version_post_id);
        update_post_meta($post->ID, '_pform_draft_definition', $draft);
        update_post_meta($post->ID, '_pform_has_draft_changes', '0');
        update_post_meta($post->ID, '_pform_retired', '0');
        wp_update_post(array('ID' => $post->ID, 'post_title' => $draft['title']));

        return $draft;
    }

    public static function duplicate($form_id)
    {
        $source = self::get_draft($form_id);
        if (! $source) {
            $source = self::get_published($form_id, true);
        }
        if (! $source) {
            return new WP_Error('pform_unknown_form', __('The form could not be found.', 'parish-forms'));
        }

        $title = sprintf(__('%s Copy', 'parish-forms'), $source['title']);
        $new_id = self::unique_form_id(sanitize_title($title));
        $source['id'] = $new_id;
        $source['version'] = 1;
        $source['title'] = $title;

        return self::create_form($title, $new_id, $source);
    }

    public static function set_retired($form_id, $retired)
    {
        $post = self::form_post($form_id);
        if (! $post) {
            return false;
        }
        update_post_meta($post->ID, '_pform_retired', $retired ? '1' : '0');
        return true;
    }

    public static function restore_version_to_draft($form_id, $version_post_id)
    {
        $post = self::form_post($form_id);
        $version_post = get_post(absint($version_post_id));
        if (! $post || ! $version_post || $version_post->post_type !== self::VERSION_POST_TYPE) {
            return new WP_Error('pform_invalid_version', __('The requested form version could not be found.', 'parish-forms'));
        }
        if (get_post_meta($version_post->ID, '_pform_form_id', true) !== $form_id) {
            return new WP_Error('pform_invalid_version', __('The requested form version does not belong to this form.', 'parish-forms'));
        }

        $definition = get_post_meta($version_post->ID, '_pform_definition', true);
        if (! is_array($definition)) {
            return new WP_Error('pform_invalid_version', __('The requested form version is unavailable.', 'parish-forms'));
        }

        $current_version = absint(get_post_meta($post->ID, '_pform_published_version', true));
        $definition['version'] = max(1, $current_version + 1);
        update_post_meta($post->ID, '_pform_draft_definition', $definition);
        update_post_meta($post->ID, '_pform_has_draft_changes', '1');

        return $definition;
    }

    public static function get_draft($form_id)
    {
        $post = self::form_post($form_id);
        if (! $post) {
            return null;
        }
        $definition = get_post_meta($post->ID, '_pform_draft_definition', true);
        return is_array($definition) ? $definition : null;
    }

    public static function get_published($form_id, $include_retired = false)
    {
        $post = self::form_post($form_id);
        if (! $post) {
            return null;
        }
        if (! $include_retired && get_post_meta($post->ID, '_pform_retired', true) === '1') {
            return null;
        }
        $version_post_id = absint(get_post_meta($post->ID, '_pform_published_version_post', true));
        if (! $version_post_id) {
            return null;
        }
        $definition = get_post_meta($version_post_id, '_pform_definition', true);
        return is_array($definition) ? $definition : null;
    }

    public static function all_published($include_retired = false)
    {
        $definitions = array();
        foreach (self::form_posts() as $post) {
            $form_id = get_post_meta($post->ID, '_pform_form_id', true);
            $definition = self::get_published($form_id, $include_retired);
            if ($definition) {
                $definitions[$form_id] = $definition;
            }
        }
        return $definitions;
    }

    public static function records()
    {
        $records = array();
        foreach (self::form_posts() as $post) {
            $form_id = get_post_meta($post->ID, '_pform_form_id', true);
            if (! $form_id) {
                continue;
            }
            $draft = self::get_draft($form_id);
            $published = self::get_published($form_id, true);
            $records[] = array(
                'post_id' => $post->ID,
                'form_id' => $form_id,
                'title' => $draft && ! empty($draft['title']) ? $draft['title'] : $post->post_title,
                'published_version' => absint(get_post_meta($post->ID, '_pform_published_version', true)),
                'has_draft_changes' => get_post_meta($post->ID, '_pform_has_draft_changes', true) === '1',
                'retired' => get_post_meta($post->ID, '_pform_retired', true) === '1',
                'published' => (bool) $published,
                'modified' => $post->post_modified,
            );
        }

        usort($records, function ($a, $b) {
            return strcasecmp($a['title'], $b['title']);
        });
        return $records;
    }

    public static function versions($form_id)
    {
        $posts = get_posts(array(
            'post_type' => self::VERSION_POST_TYPE,
            'post_status' => 'private',
            'posts_per_page' => -1,
            'orderby' => 'meta_value_num',
            'order' => 'DESC',
            'meta_key' => '_pform_version',
            'meta_query' => array(
                array('key' => '_pform_form_id', 'value' => $form_id),
            ),
        ));

        $versions = array();
        foreach ($posts as $post) {
            $versions[] = array(
                'post_id' => $post->ID,
                'version' => absint(get_post_meta($post->ID, '_pform_version', true)),
                'date' => $post->post_date,
            );
        }
        return $versions;
    }

    public static function form_post($form_id)
    {
        $posts = get_posts(array(
            'post_type' => self::FORM_POST_TYPE,
            'post_status' => 'private',
            'posts_per_page' => 1,
            'meta_key' => '_pform_form_id',
            'meta_value' => sanitize_key($form_id),
        ));
        return $posts ? $posts[0] : null;
    }

    private static function form_posts()
    {
        return get_posts(array(
            'post_type' => self::FORM_POST_TYPE,
            'post_status' => 'private',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ));
    }

    private static function unique_form_id($candidate)
    {
        $base = sanitize_title($candidate);
        if ($base === '') {
            $base = 'parish-form';
        }
        $id = $base;
        $suffix = 2;
        while (self::exists($id)) {
            $id = $base . '-' . $suffix;
            $suffix++;
        }
        return $id;
    }
}
