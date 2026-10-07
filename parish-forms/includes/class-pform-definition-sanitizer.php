<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Definition_Sanitizer
{
    private static $types = array('text', 'email', 'tel', 'date', 'textarea', 'radio', 'checkboxes', 'consent', 'repeater');
    private static $widths = array('full', 'half', 'third', 'two-thirds', 'phone-wide', 'ministries', 'other-interests');

    public static function sanitize($raw, $form_id = '')
    {
        if (! is_array($raw)) {
            return new WP_Error('pform_invalid_definition', __('The form definition is invalid.', 'parish-forms'));
        }

        $id = $form_id ? sanitize_key($form_id) : sanitize_key(isset($raw['id']) ? $raw['id'] : '');
        $title = self::text(isset($raw['title']) ? $raw['title'] : '', 180);
        if ($title === '') {
            return new WP_Error('pform_missing_title', __('A form title is required.', 'parish-forms'));
        }

        $definition = array(
            'id' => $id,
            'version' => isset($raw['version']) ? max(1, absint($raw['version'])) : 1,
            'title' => $title,
            'eyebrow' => self::text(isset($raw['eyebrow']) ? $raw['eyebrow'] : '', 180),
            'description' => self::textarea(isset($raw['description']) ? $raw['description'] : '', 3000),
            'submit_label' => self::text(isset($raw['submit_label']) ? $raw['submit_label'] : __('Submit Form', 'parish-forms'), 180),
            'success_title' => self::text(isset($raw['success_title']) ? $raw['success_title'] : __('Submission Received', 'parish-forms'), 180),
            'confirmation' => self::textarea(isset($raw['confirmation']) ? $raw['confirmation'] : __('Thank you. Your submission has been received.', 'parish-forms'), 3000),
            'privacy_note' => self::textarea(isset($raw['privacy_note']) ? $raw['privacy_note'] : __('Information submitted through this form is intended for parish-office follow-up.', 'parish-forms'), 3000),
            'reply_to_field' => sanitize_key(isset($raw['reply_to_field']) ? $raw['reply_to_field'] : ''),
            'admin_primary_fields' => self::key_list(isset($raw['admin_primary_fields']) ? $raw['admin_primary_fields'] : array()),
            'admin_contact_fields' => self::key_list(isset($raw['admin_contact_fields']) ? $raw['admin_contact_fields'] : array()),
            'sections' => array(),
        );

        $sections = isset($raw['sections']) && is_array($raw['sections']) ? array_slice(array_values($raw['sections']), 0, 50) : array();
        $section_ids = array();
        $field_ids = array();

        foreach ($sections as $section_index => $section) {
            if (! is_array($section)) {
                continue;
            }
            $section_id = self::unique_id(
                isset($section['id']) ? $section['id'] : '',
                'section-' . ($section_index + 1),
                $section_ids
            );
            $section_ids[] = $section_id;

            $clean_section = array(
                'id' => $section_id,
                'title' => self::text(isset($section['title']) ? $section['title'] : '', 180),
                'description' => self::textarea(isset($section['description']) ? $section['description'] : '', 2000),
                'fields' => array(),
            );
            if ($clean_section['title'] === '') {
                $clean_section['title'] = sprintf(__('Section %d', 'parish-forms'), $section_index + 1);
            }

            $condition = self::condition(isset($section['condition']) ? $section['condition'] : null);
            if ($condition) {
                $clean_section['condition'] = $condition;
            }

            $fields = isset($section['fields']) && is_array($section['fields']) ? array_slice(array_values($section['fields']), 0, 100) : array();
            foreach ($fields as $field_index => $field) {
                $clean_field = self::field($field, $field_index, $field_ids, false);
                if (is_wp_error($clean_field)) {
                    return $clean_field;
                }
                if ($clean_field) {
                    $field_ids[] = $clean_field['id'];
                    $clean_section['fields'][] = $clean_field;
                }
            }
            $definition['sections'][] = $clean_section;
        }

        if (! $definition['sections']) {
            return new WP_Error('pform_missing_sections', __('Add at least one section to the form.', 'parish-forms'));
        }

        self::prune_conditions($definition);
        self::prune_summary_fields($definition);

        return $definition;
    }

    private static function field($raw, $index, $used_ids, $nested)
    {
        if (! is_array($raw)) {
            return null;
        }

        $type = isset($raw['type']) ? sanitize_key($raw['type']) : 'text';
        if (! in_array($type, self::$types, true) || ($nested && $type === 'repeater')) {
            return new WP_Error('pform_invalid_field_type', __('A form field has an unsupported type.', 'parish-forms'));
        }

        $id = self::unique_id(
            isset($raw['id']) ? $raw['id'] : '',
            'field-' . ($index + 1),
            $used_ids
        );

        $field = array(
            'id' => $id,
            'type' => $type,
            'label' => self::text(isset($raw['label']) ? $raw['label'] : '', 240),
            'required' => ! empty($raw['required']),
            'width' => self::width(isset($raw['width']) ? $raw['width'] : 'full'),
        );
        if ($field['label'] === '') {
            return new WP_Error('pform_missing_field_label', __('Every form field needs a label.', 'parish-forms'));
        }

        $condition = self::condition(isset($raw['condition']) ? $raw['condition'] : null);
        if ($condition) {
            $field['condition'] = $condition;
        }

        if (in_array($type, array('text', 'email', 'tel', 'textarea'), true)) {
            $default_max = $type === 'textarea' ? 2000 : ($type === 'email' ? 254 : 180);
            $field['max_length'] = self::bounded_int(isset($raw['max_length']) ? $raw['max_length'] : $default_max, 1, 5000, $default_max);
            if ($type !== 'textarea') {
                $field['autocomplete'] = self::text(isset($raw['autocomplete']) ? $raw['autocomplete'] : '', 80);
            }
        }

        if ($type === 'date') {
            $field['not_future'] = ! empty($raw['not_future']);
        }

        if ($type === 'radio' || $type === 'checkboxes') {
            $field['options'] = self::options(isset($raw['options']) ? $raw['options'] : array());
            if (! $field['options']) {
                return new WP_Error('pform_missing_options', sprintf(__('Add at least one choice for “%s”.', 'parish-forms'), $field['label']));
            }
        }

        if ($type === 'repeater') {
            $field['item_label'] = self::text(isset($raw['item_label']) ? $raw['item_label'] : __('Item', 'parish-forms'), 120);
            $field['add_label'] = self::text(isset($raw['add_label']) ? $raw['add_label'] : __('Add Another', 'parish-forms'), 180);
            $field['min_items'] = self::bounded_int(isset($raw['min_items']) ? $raw['min_items'] : 0, 0, 25, 0);
            $field['max_items'] = self::bounded_int(isset($raw['max_items']) ? $raw['max_items'] : 10, 1, 25, 10);
            if ($field['min_items'] > $field['max_items']) {
                $field['min_items'] = $field['max_items'];
            }
            $field['fields'] = array();
            $nested_ids = array();
            $nested_fields = isset($raw['fields']) && is_array($raw['fields']) ? array_slice(array_values($raw['fields']), 0, 50) : array();
            foreach ($nested_fields as $nested_index => $nested_field) {
                $clean_nested = self::field($nested_field, $nested_index, $nested_ids, true);
                if (is_wp_error($clean_nested)) {
                    return $clean_nested;
                }
                if ($clean_nested) {
                    $nested_ids[] = $clean_nested['id'];
                    unset($clean_nested['condition']);
                    $field['fields'][] = $clean_nested;
                }
            }
            if (! $field['fields']) {
                return new WP_Error('pform_empty_repeater', sprintf(__('Add at least one field inside “%s”.', 'parish-forms'), $field['label']));
            }
        }

        return $field;
    }

    private static function prune_conditions(&$definition)
    {
        $fields = array();
        foreach ($definition['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $fields[$field['id']] = $field;
            }
        }

        foreach ($definition['sections'] as &$section) {
            $section_field_ids = array();
            foreach ($section['fields'] as $section_field) {
                $section_field_ids[] = $section_field['id'];
            }
            if (isset($section['condition'])
                && (! self::valid_condition($section['condition'], $fields)
                    || in_array($section['condition']['field'], $section_field_ids, true))) {
                unset($section['condition']);
            }
            foreach ($section['fields'] as &$field) {
                if (isset($field['condition']) && ! self::valid_condition($field['condition'], $fields, $field['id'])) {
                    unset($field['condition']);
                }
            }
            unset($field);
        }
        unset($section);
    }

    private static function valid_condition($condition, $fields, $self_id = '')
    {
        $source = isset($condition['field']) ? $condition['field'] : '';
        if ($source === '' || $source === $self_id || ! isset($fields[$source])) {
            return false;
        }
        if (! in_array($fields[$source]['type'], array('radio', 'text', 'email', 'tel'), true)) {
            return false;
        }
        if ($fields[$source]['type'] === 'radio'
            && isset($fields[$source]['options'])
            && ! isset($fields[$source]['options'][$condition['equals']])) {
            return false;
        }
        return true;
    }

    private static function prune_summary_fields(&$definition)
    {
        $top_level = array();
        $email_fields = array();
        $text_fields = array();
        foreach ($definition['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $top_level[$field['id']] = true;
                if ($field['type'] === 'email') {
                    $email_fields[] = $field['id'];
                }
                if (in_array($field['type'], array('text', 'email', 'tel'), true)) {
                    $text_fields[] = $field['id'];
                }
            }
        }

        $definition['admin_primary_fields'] = array_values(array_filter(
            $definition['admin_primary_fields'],
            function ($id) use ($top_level) { return isset($top_level[$id]); }
        ));
        $definition['admin_contact_fields'] = array_values(array_filter(
            $definition['admin_contact_fields'],
            function ($id) use ($top_level) { return isset($top_level[$id]); }
        ));

        if (! $definition['admin_primary_fields'] && $text_fields) {
            $definition['admin_primary_fields'] = array($text_fields[0]);
        }
        if (! $definition['admin_contact_fields'] && $email_fields) {
            $definition['admin_contact_fields'] = array($email_fields[0]);
        }
        if (! in_array($definition['reply_to_field'], $email_fields, true)) {
            $definition['reply_to_field'] = $email_fields ? $email_fields[0] : '';
        }
    }

    private static function condition($raw)
    {
        if (! is_array($raw)) {
            return null;
        }
        $field = sanitize_key(isset($raw['field']) ? $raw['field'] : '');
        $equals = self::text(isset($raw['equals']) ? $raw['equals'] : '', 240);
        if ($field === '' || $equals === '') {
            return null;
        }
        return array('field' => $field, 'equals' => $equals);
    }

    private static function options($raw)
    {
        if (! is_array($raw)) {
            return array();
        }
        $options = array();
        $count = 0;
        foreach ($raw as $value => $label) {
            if ($count >= 100) {
                break;
            }
            if (is_int($value) && is_array($label)) {
                $value = isset($label['value']) ? $label['value'] : '';
                $label = isset($label['label']) ? $label['label'] : '';
            }
            if (! is_scalar($label)) {
                continue;
            }
            $clean_label = self::text($label, 240);
            $clean_value = sanitize_key($value);
            if ($clean_value === '') {
                $clean_value = sanitize_title($clean_label);
            }
            if ($clean_value !== '' && $clean_label !== '') {
                $base = $clean_value;
                $suffix = 2;
                while (isset($options[$clean_value])) {
                    $clean_value = $base . '-' . $suffix;
                    $suffix++;
                }
                $options[$clean_value] = $clean_label;
                $count++;
            }
        }
        return $options;
    }

    private static function key_list($raw)
    {
        $values = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw);
        $clean = array();
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $key = sanitize_key($value);
            if ($key !== '' && ! in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }
        return $clean;
    }

    private static function unique_id($raw, $fallback, $used)
    {
        $id = sanitize_key($raw);
        if ($id === '') {
            $id = sanitize_key($fallback);
        }
        $base = $id;
        $suffix = 2;
        while (in_array($id, $used, true)) {
            $id = $base . '-' . $suffix;
            $suffix++;
        }
        return $id;
    }

    private static function width($raw)
    {
        $width = sanitize_key($raw);
        return in_array($width, self::$widths, true) ? $width : 'full';
    }

    private static function bounded_int($value, $minimum, $maximum, $default)
    {
        if (! is_numeric($value)) {
            return $default;
        }
        return max($minimum, min($maximum, (int) $value));
    }

    private static function text($value, $max)
    {
        $clean = sanitize_text_field(is_scalar($value) ? (string) $value : '');
        return self::truncate($clean, $max);
    }

    private static function textarea($value, $max)
    {
        $clean = sanitize_textarea_field(is_scalar($value) ? (string) $value : '');
        return self::truncate($clean, $max);
    }

    private static function truncate($value, $max)
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }
}
