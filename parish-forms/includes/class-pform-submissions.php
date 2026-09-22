<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Submissions
{
    const POST_TYPE = 'pform_submission';

    public static function register_post_type()
    {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => __('Parish Form Submissions', 'parish-forms'),
                'singular_name' => __('Parish Form Submission', 'parish-forms'),
            ),
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'rewrite' => false,
            'query_var' => false,
            'supports' => array(),
            'capability_type' => array('pform_submission', 'pform_submissions'),
            'map_meta_cap' => true,
            'capabilities' => array(
                'create_posts' => 'do_not_allow',
            ),
        ));
    }

    public static function create($definition, $data)
    {
        $post_id = wp_insert_post(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'private',
            'post_title' => __('Parish Form Submission', 'parish-forms'),
            'post_content' => '',
        ), true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        wp_update_post(array(
            'ID' => $post_id,
            'post_title' => sprintf('%s #%d', $definition['title'], $post_id),
        ));
        update_post_meta($post_id, '_pform_form_id', $definition['id']);
        update_post_meta($post_id, '_pform_schema_version', absint($definition['version']));
        update_post_meta($post_id, '_pform_data', $data);
        update_post_meta($post_id, '_pform_status', 'new');

        return $post_id;
    }

    public static function data($post_id)
    {
        $data = get_post_meta($post_id, '_pform_data', true);
        return is_array($data) ? $data : array();
    }

    public static function is_submission($post_id)
    {
        return get_post_type($post_id) === self::POST_TYPE;
    }
}
