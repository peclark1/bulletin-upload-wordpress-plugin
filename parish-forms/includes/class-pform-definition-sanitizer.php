<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Definition_Sanitizer
{
    const MAX_SECTIONS = 30;
    const MAX_FIELDS_PER_SECTION = 50;
    const MAX_REPEATER_FIELDS = 20;

    public static function sanitize($raw, $existing_id = '')
    {
        $raw = is_array($raw) ? $raw : array();
        $id = $existing_id ? sanitize_key($existing_id) : sanitize_key(isset($raw['id']) ? $raw['id'] : '');
        if (! $id) {
            $id = 'form-' . strtolower(wp_generate_password(8, false, false));
        }

        $definition = array(
            'id' => $id,
            'version' => max(1, absint(isset($raw['version']) ? $raw['version'] : 1)),
            'title' => self::text(isset($raw['title']) ? $raw['title'] : __('Untitled Form', 'parish-forms'), 160),
            'eyebrow' => self::text(isset($raw['eyebrow']) ? $raw['eyebrow'] : __('Parish Forms', 'parish-forms'), 120),
            'description' => self::textarea(isset($raw['description']) ? $raw['description'] : '', 2000),
            'submit_label' => self::text(isset($raw['submit_label']) ? $raw['submit_label'] : __('Submit', 'parish-forms'), 100),
            'success_title' => self::text(isset($raw['success_title']) ? $raw['success_title'] : __('Submission Received', 'parish-forms'), 160),
            'confirmation' => self::textarea(isset($raw['confirmation']) ? $raw['confirmation'] : __('Thank you. Your form has been received.', 'parish-forms'), 2000),
            'privacy_note' => self::textarea(isset($raw['privacy_note']) ? $raw['privacy_note'] : __('Information submitted through this form is intended for parish-office follow-up.', 'parish-forms'), 1000),
            'reply_to_field' => sanitize_key(isset($raw['reply_to_field']) ? $raw['reply_to_field'] : ''),
            'admin_primary_fields' => self::key_list(isset($raw['admin_primary_fields']) ? $raw['admin_primary_fields'] : array()),
            'admin_contact_fields' => self::key_list(isset($raw['admin_contact_fields']) ? $raw['admin_contact_fields'] : array()),
            'sections' => array(),
        );

        $used_section_ids = array();
        $used_field_ids = array();
        $sections = isset($raw['sections']) && is_array($raw['sections']) ? array_slice($raw['sections'], 0, self::MAX_SECTIONS) : array();
        foreach ($sections as $section_index => $section) {
            if (! is_array($section)) {
                continue;
            }
            $section_id = self::unique_id(
                isset($section['id']) ? $section['id'] : 'section-' . ($section_index + 1),
                $used_section_ids,
                'section-' . ($section_index + 1)
            );
            $used_section_ids[] = $section_id;

            $clean_section = array(
                'id' => $section_id,
                'title' => self::text(isset($section['title']) ? $section['title'] : __('Section', 'parish-forms'), 160),
                'description' => self::textarea(isset($section['description']) ? $section['description'] : '', 1000),
                'fields' => array(),
            );
            $condition = self::condition(isset($section['condition']) ? $section['condition'] : null);
            if ($condition) {
                $clean_section['condition'] = $condition;
            }

            $fields = isset($section['fields']) && is_array($section['fields'])
                ? array_slice($section['fields'], 0, self::MAX_FIELDS_PER_SECTION)
                : array();

            foreach ($fields as $field_index => $field) {
                if (! is_array($field)) {
                    continue;
                }
                $clean = self::field($field, $used_field_ids, 'field-' . ($section_index + 1) . '-' . ($field_index + 1), 0);
                if ($clean) {
                    $used_field_ids[] = $clean['id'];
                    $clean_section['fields'][] = $clean;
                }
            }
            $definition['sections'][] = $clean_section;
        }

        return $definition;
    }

    public static function supported_types()
    {
        return array('text', 'email', 'tel', 'date', 'textarea', 'radio', 'checkboxes', 'consent', 'repeater');
    }

    public static function supported_widths()
    {
        return array('full', 'half', 'third', 'two-thirds', 'phone-wide', 'ministries', 'other-interests');
    }

    private static function field($field, $used_ids, $fallback, $depth)
    {
        $type = sanitize_key(isset($field['type']) ? $field['type'] : 'text');
        if (! in_array($type, self::supported_types(), true)) {
            $type = 'text';
        }
        if ($depth > 0 && $type === 'repeater') {
            $type = 'text';
        }

        $id = self::unique_id(isset($field['id']) ? $field['id'] : $fallback, $used_ids, $fallback);
        $width = sanitize_key(isset($field['width']) ? $field['width'] : 'full');
        if (! in_array($width, self::supported_widths(), true)) {
            $width = 'full';
        }

        $clean = array(
            'id' => $id,
            'type' => $type,
            'label' => self::text(isset($field['label']) ? $field['label'] : __('Field', 'parish-forms'), 240),
            'required' => ! empty($field['required']),
            'width' => $width,
        );

        if (in_array($type, array('text', 'email', 'tel', 'textarea'), true)) {
            $default_max = $type === 'textarea' ? 2000 : ($type === 'email' ? 254 : 180);
            $clean['max_length'] = max(1, min(10000, absint(isset($field['max_length']) ? $field['max_length'] : $default_max)));
        }
        if (in_array($type, array('text', 'email', 'tel'), true)) {
            $clean['autocomplete'] = self::text(isset($field['autocomplete']) ? $field['autocomplete'] : '', 80);
        }
        if ($type === 'date') {
            $clean['not_future'] = ! empty($field['not_future']);
        }
        if (in_array($type, array('radio', 'checkboxes'), true)) {
            $clean['options'] = self::options(isset($field['options']) ? $field['options'] : array());
        }
        $condition = self::condition(isset($field['condition']) ? $field['condition'] : null);
        if ($condition) {
            $clean['condition'] = $condition;
        }

        if ($type === 'repeater') {
            $clean['item_label'] = self::text(isset($field['item_label']) ? $field['item_label'] : __('Item', 'parish-forms'), 100);
            $clean['add_label'] = self::text(isset($field['add_label']) ? $field['add_label'] : __('Add Another', 'parish-forms'), 120);
            $clean['min_items'] = max(0, min(20, absint(isset($field['min_items']) ? $field['min_items'] : 0)));
            $clean['max_items'] = max(1, min(50, absint(isset($field['max_items']) ? $field['max_items'] : 10)));
            if ($clean['min_items'] > $clean['max_items']) {
                $clean['min_items'] = $clean['max_items'];
            }
            $clean['fields'] = array();
            $children = isset($field['fields']) && is_array($field['fields'])
                ? array_slice($field['fields'], 0, self::MAX_REPEATER_FIELDS)
                : array();
            $child_ids = array();
            foreach ($children as $index => $child) {
                if (! is_array($child)) {
                    continue;
                }
                $child_clean = self::field($child, $child_ids, 'item-field-' . ($index + 1), $depth + 1);
                if ($child_clean) {
                    $child_ids[] = $child_clean['id'];
                    $clean['fields'][] = $child_clean;
                }
            }
        }

        return $clean;
    }

    private static function options($raw)
    {
        $clean = array();
        if (! is_array($raw)) {
            return $clean;
        }
        foreach (array_slice($raw, 0, 100, true) as $key => $label) {
            if (is_array($label) || is_object($label)) {
                continue;
            }
            $option_key = sanitize_key(is_string($key) ? $key : $label);
            $option_label = self::text($label, 180);
            if ($option_key && $option_label !== '') {
                $clean[$option_key] = $option_label;
            }
        }
        return $clean;
    }

    private static function condition($raw)
    {
        if (! is_array($raw)) {
            return null;
        }
        $field = sanitize_key(isset($raw['field']) ? $raw['field'] : '');
        $equals = sanitize_key(isset($raw['equals']) ? $raw['equals'] : '');
        return $field && $equals ? array('field' => $field, 'equals' => $equals) : null;
    }

    private static function key_list($raw)
    {
        $values = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw);
        $clean = array();
        foreach ($values as $value) {
            $key = sanitize_key($value);
            if ($key) {
                $clean[] = $key;
            }
        }
        return array_values(array_unique($clean));
    }

    private static function unique_id($candidate, $used, $fallback)
    {
        $base = sanitize_key($candidate);
        if (! $base) {
            $base = sanitize_key($fallback);
        }
        if (! in_array($base, $used, true)) {
            return $base;
        }
        $suffix = 2;
        while (in_array($base . '-' . $suffix, $used, true)) {
            $suffix++;
        }
        return $base . '-' . $suffix;
    }

    private static function text($value, $max)
    {
        $value = sanitize_text_field(is_scalar($value) ? (string) $value : '');
        return self::truncate($value, $max);
    }

    private static function textarea($value, $max)
    {
        $value = sanitize_textarea_field(is_scalar($value) ? (string) $value : '');
        return self::truncate($value, $max);
    }

    private static function truncate($value, $max)
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }
}
