<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Form_Registry
{
    public static function builtin_all()
    {
        return array(
            'parish-registration' => PFORM_Parish_Registration::definition(),
            'pre-baptismal-questionnaire' => PFORM_Pre_Baptismal_Questionnaire::definition(),
            'confirmation-interest' => PFORM_Confirmation_Interest::definition(),
        );
    }

    public static function all($include_retired = false)
    {
        $forms = array();
        foreach (self::builtin_all() as $form_id => $definition) {
            if (! PFORM_Form_Store::exists($form_id)) {
                $forms[$form_id] = $definition;
            }
        }

        foreach (PFORM_Form_Store::all_published($include_retired) as $form_id => $definition) {
            $forms[$form_id] = $definition;
        }

        return apply_filters('pform_definitions', $forms);
    }

    public static function get($form_id, $include_retired = false)
    {
        $form_id = sanitize_key($form_id);
        if (PFORM_Form_Store::exists($form_id)) {
            return PFORM_Form_Store::get_published($form_id, $include_retired);
        }

        $forms = self::builtin_all();
        return isset($forms[$form_id]) && is_array($forms[$form_id]) ? $forms[$form_id] : null;
    }

    public static function fields($definition)
    {
        $fields = array();
        if (empty($definition['sections']) || ! is_array($definition['sections'])) {
            return $fields;
        }
        foreach ($definition['sections'] as $section) {
            if (empty($section['fields']) || ! is_array($section['fields'])) {
                continue;
            }
            foreach ($section['fields'] as $field) {
                $fields[$field['id']] = $field;
            }
        }
        return $fields;
    }
}
