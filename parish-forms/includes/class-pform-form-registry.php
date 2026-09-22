<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Form_Registry
{
    public static function all()
    {
        $forms = array(
            'parish-registration' => PFORM_Parish_Registration::definition(),
        );
        return apply_filters('pform_definitions', $forms);
    }

    public static function get($form_id)
    {
        $forms = self::all();
        return isset($forms[$form_id]) && is_array($forms[$form_id]) ? $forms[$form_id] : null;
    }

    public static function fields($definition)
    {
        $fields = array();
        foreach ($definition['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $fields[$field['id']] = $field;
            }
        }
        return $fields;
    }
}
