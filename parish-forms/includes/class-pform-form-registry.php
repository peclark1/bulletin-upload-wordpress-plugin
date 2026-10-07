<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Form_Registry
{
    public static function builtins()
    {
        return array(
            'parish-registration' => PFORM_Parish_Registration::definition(),
            'pre-baptismal-questionnaire' => PFORM_Pre_Baptismal_Questionnaire::definition(),
            'confirmation-interest' => PFORM_Confirmation_Interest::definition(),
        );
    }

    public static function builtin($form_id)
    {
        $forms = self::builtins();
        return isset($forms[$form_id]) ? $forms[$form_id] : null;
    }

    public static function all()
    {
        $forms = self::builtins();

        if (class_exists('PFORM_Form_Store')) {
            foreach (PFORM_Form_Store::managed_ids() as $managed_id) {
                unset($forms[$managed_id]);
            }
            foreach (PFORM_Form_Store::published_all() as $form_id => $definition) {
                $forms[$form_id] = $definition;
            }
        }

        return apply_filters('pform_definitions', $forms);
    }

    public static function get($form_id)
    {
        $form_id = sanitize_key($form_id);

        if (class_exists('PFORM_Form_Store')) {
            $post = PFORM_Form_Store::post_for_id($form_id);
            if ($post) {
                return PFORM_Form_Store::published_by_post($post->ID);
            }
        }

        $forms = self::builtins();
        $definition = isset($forms[$form_id]) ? $forms[$form_id] : null;
        return $definition ? apply_filters('pform_definition', $definition, $form_id) : null;
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
