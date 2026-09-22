<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Formatter
{
    public static function rows($definition, $data)
    {
        $rows = array();
        foreach ($definition['sections'] as $section) {
            if (! self::condition_met(isset($section['condition']) ? $section['condition'] : null, $data)) {
                continue;
            }
            $section_rows = array();
            foreach ($section['fields'] as $field) {
                if (! self::condition_met(isset($field['condition']) ? $field['condition'] : null, $data)) {
                    continue;
                }
                $value = isset($data[$field['id']]) ? $data[$field['id']] : null;
                if ($field['type'] === 'repeater') {
                    foreach ((array) $value as $index => $item) {
                        foreach ($field['fields'] as $item_field) {
                            $item_value = isset($item[$item_field['id']]) ? $item[$item_field['id']] : null;
                            $section_rows[] = array(
                                'label' => sprintf('%s %d - %s', $field['item_label'], $index + 1, $item_field['label']),
                                'value' => self::display_value($item_field, $item_value),
                            );
                        }
                    }
                } else {
                    $section_rows[] = array(
                        'label' => $field['label'],
                        'value' => self::display_value($field, $value),
                    );
                }
            }
            if ($section_rows) {
                $rows[] = array('section' => $section['title'], 'fields' => $section_rows);
            }
        }
        return $rows;
    }

    public static function plain_text($definition, $data)
    {
        $lines = array();
        foreach (self::rows($definition, $data) as $section) {
            $lines[] = '';
            $lines[] = strtoupper($section['section']);
            $lines[] = str_repeat('-', self::text_length($section['section']));
            foreach ($section['fields'] as $row) {
                $lines[] = $row['label'] . ': ' . ($row['value'] !== '' ? $row['value'] : '-');
            }
        }
        return trim(implode("\n", $lines));
    }

    public static function display_value($field, $value)
    {
        if ($field['type'] === 'checkboxes') {
            $labels = array();
            foreach ((array) $value as $key) {
                if (isset($field['options'][$key])) {
                    $labels[] = $field['options'][$key];
                }
            }
            return implode('; ', $labels);
        }
        if ($field['type'] === 'radio') {
            return isset($field['options'][$value]) ? $field['options'][$value] : '';
        }
        if ($field['type'] === 'consent') {
            return $value === '1' ? __('Yes', 'parish-forms') : __('No', 'parish-forms');
        }
        return is_scalar($value) ? (string) $value : '';
    }

    private static function text_length($value)
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private static function condition_met($condition, $values)
    {
        if (! $condition) {
            return true;
        }
        return isset($values[$condition['field']])
            && is_scalar($values[$condition['field']])
            && (string) $values[$condition['field']] === (string) $condition['equals'];
    }
}
