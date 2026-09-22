<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Validator
{
    public static function validate($definition, $raw)
    {
        $data = array();
        $errors = array();

        foreach ($definition['sections'] as $section) {
            if (! self::condition_met(isset($section['condition']) ? $section['condition'] : null, $raw)) {
                continue;
            }
            foreach ($section['fields'] as $field) {
                if (! self::condition_met(isset($field['condition']) ? $field['condition'] : null, $raw)) {
                    continue;
                }
                $value = isset($raw[$field['id']]) ? $raw[$field['id']] : null;
                if ($field['type'] === 'repeater') {
                    list($clean, $field_errors) = self::repeater($field, $value);
                } else {
                    list($clean, $field_errors) = self::field($field, $value, $field['id']);
                }
                $data[$field['id']] = $clean;
                $errors = array_merge($errors, $field_errors);
            }
        }

        return array('data' => $data, 'errors' => $errors);
    }

    private static function repeater($field, $raw)
    {
        $clean_items = array();
        $errors = array();
        $items = is_array($raw) ? array_values($raw) : array();
        $items = array_slice($items, 0, absint($field['max_items']));

        foreach ($items as $index => $item) {
            if (! is_array($item) || self::item_is_empty($item)) {
                continue;
            }
            $clean_index = count($clean_items);
            $clean_item = array();
            foreach ($field['fields'] as $item_field) {
                $path = $field['id'] . '.' . $clean_index . '.' . $item_field['id'];
                $value = isset($item[$item_field['id']]) ? $item[$item_field['id']] : null;
                list($clean, $field_errors) = self::field($item_field, $value, $path);
                $clean_item[$item_field['id']] = $clean;
                $errors = array_merge($errors, $field_errors);
            }
            $clean_items[] = $clean_item;
        }

        if (count($clean_items) < absint($field['min_items'])) {
            $errors[$field['id']] = sprintf(__('Add at least %d item(s).', 'parish-forms'), absint($field['min_items']));
        }

        return array($clean_items, $errors);
    }

    private static function field($field, $raw, $path)
    {
        $errors = array();
        $required = ! empty($field['required']);
        $type = $field['type'];

        if ($type === 'checkboxes') {
            $values = is_array($raw) ? $raw : array();
            $values = array_filter($values, 'is_scalar');
            $allowed = isset($field['options']) ? array_keys($field['options']) : array();
            $clean = array_values(array_intersect($allowed, array_map('sanitize_key', $values)));
            if ($required && ! $clean) {
                $errors[$path] = self::required_message($field);
            }
            return array($clean, $errors);
        }

        if ($type === 'consent') {
            $clean = $raw === '1' || $raw === 1 ? '1' : '';
            if ($required && ! $clean) {
                $errors[$path] = self::required_message($field);
            }
            return array($clean, $errors);
        }

        $value = is_scalar($raw) ? trim((string) $raw) : '';
        if ($type === 'textarea') {
            $clean = sanitize_textarea_field($value);
        } elseif ($type === 'email') {
            $clean = sanitize_email($value);
        } else {
            $clean = sanitize_text_field($value);
        }

        if ($required && $clean === '') {
            $errors[$path] = self::required_message($field);
            return array($clean, $errors);
        }
        if ($clean === '') {
            return array('', $errors);
        }

        if (isset($field['max_length']) && self::length($clean) > absint($field['max_length'])) {
            $clean = self::substring($clean, 0, absint($field['max_length']));
            $errors[$path] = sprintf(__('Please limit %1$s to %2$d characters.', 'parish-forms'), $field['label'], absint($field['max_length']));
        }

        if ($type === 'email' && ! is_email($clean)) {
            $errors[$path] = sprintf(__('Enter a valid email address for %s.', 'parish-forms'), $field['label']);
        } elseif ($type === 'date' && ! self::valid_date($clean)) {
            $errors[$path] = sprintf(__('Enter a valid date for %s.', 'parish-forms'), $field['label']);
        } elseif ($type === 'date' && ! empty($field['not_future']) && $clean > wp_date('Y-m-d')) {
            $errors[$path] = sprintf(__('%s cannot be in the future.', 'parish-forms'), $field['label']);
        } elseif ($type === 'radio') {
            $allowed = isset($field['options']) ? array_keys($field['options']) : array();
            if (! in_array($clean, $allowed, true)) {
                $clean = '';
                $errors[$path] = sprintf(__('Choose a valid option for %s.', 'parish-forms'), $field['label']);
            }
        }

        return array($clean, $errors);
    }

    private static function condition_met($condition, $values)
    {
        if (! $condition) {
            return true;
        }
        $current = isset($values[$condition['field']]) && is_scalar($values[$condition['field']])
            ? (string) $values[$condition['field']]
            : '';
        return $current === (string) $condition['equals'];
    }

    private static function item_is_empty($item)
    {
        foreach ($item as $value) {
            if (is_array($value)) {
                foreach ($value as $nested) {
                    if (is_scalar($nested) && trim((string) $nested) !== '') {
                        return false;
                    }
                }
            }
            if (is_scalar($value) && trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    private static function valid_date($value)
    {
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }

    private static function required_message($field)
    {
        return sprintf(__('%s is required.', 'parish-forms'), $field['label']);
    }

    private static function length($value)
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private static function substring($value, $start, $length)
    {
        return function_exists('mb_substr') ? mb_substr($value, $start, $length) : substr($value, $start, $length);
    }
}
