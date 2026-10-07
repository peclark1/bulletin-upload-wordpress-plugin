<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Pre_Baptismal_Questionnaire
{
    public static function definition()
    {
        return array(
            'id' => 'pre-baptismal-questionnaire',
            'version' => 1,
            'title' => __('Pre-Baptismal Questionnaire', 'parish-forms'),
            'eyebrow' => __('Preparing for Baptism', 'parish-forms'),
            'description' => __('Please complete this questionnaire before your child’s Baptism. Fields marked with an asterisk are required. Information submitted through this form is kept secure and confidential.', 'parish-forms'),
            'submit_label' => __('Submit Questionnaire', 'parish-forms'),
            'success_title' => __('Questionnaire Received', 'parish-forms'),
            'confirmation' => __('Thank you. Your pre-baptismal questionnaire has been received. The parish office will follow up if any additional information is needed.', 'parish-forms'),
            'privacy_note' => __('Information submitted through this form is intended for Baptism preparation and parish-office follow-up.', 'parish-forms'),
            'reply_to_field' => 'mother_email',
            'admin_primary_fields' => array('child_first_name', 'child_middle_name', 'child_last_name'),
            'admin_contact_fields' => array('mother_name', 'mother_email'),
            'sections' => array(
                array(
                    'id' => 'child',
                    'title' => __('Child Information', 'parish-forms'),
                    'fields' => array(
                        self::text('child_first_name', __('Child’s First Name', 'parish-forms'), true, 'half', 100, 'given-name'),
                        self::text('child_middle_name', __('Child’s Middle Name', 'parish-forms'), true, 'half', 100, 'additional-name'),
                        self::text('child_last_name', __('Child’s Last Name', 'parish-forms'), true, 'half', 100, 'family-name'),
                        self::radio('gender', __('Gender', 'parish-forms'), array(
                            'female' => __('Female', 'parish-forms'),
                            'male' => __('Male', 'parish-forms'),
                        ), true, 'half'),
                        self::date('child_birth_date', __('Child’s Birthday', 'parish-forms'), false, 'half', true),
                        self::text('place_of_birth', __('Place of Birth (city, state/province and country)', 'parish-forms'), true, 'half', 180),
                    ),
                ),
                array(
                    'id' => 'parents',
                    'title' => __('Parent Information', 'parish-forms'),
                    'fields' => array(
                        self::text('mother_name', __('Mother’s Name (First and Last)', 'parish-forms'), true, 'half', 150, 'name'),
                        self::text('mother_maiden_name', __('Mother’s Maiden Name', 'parish-forms'), true, 'half', 120, 'family-name'),
                        self::text('mother_religion', __('Mother’s Religion', 'parish-forms'), true, 'half', 120),
                        self::tel('mother_phone', __('Mother’s Phone', 'parish-forms'), false, 'half', 'tel'),
                        self::email('mother_email', __('Mother’s Email', 'parish-forms'), false, 'half', 'email'),
                        self::text('father_name', __('Father’s Name (First and Last)', 'parish-forms'), false, 'half', 150, 'name'),
                        self::text('father_religion', __('Father’s Religion', 'parish-forms'), false, 'half', 120),
                        self::tel('father_phone', __('Father’s Phone', 'parish-forms'), false, 'half', 'tel'),
                        self::email('father_email', __('Father’s Email', 'parish-forms'), false, 'half', 'email'),
                    ),
                ),
                array(
                    'id' => 'family-contact',
                    'title' => __('Family Contact Information', 'parish-forms'),
                    'fields' => array(
                        self::radio('address_for', __('Address is for', 'parish-forms'), array(
                            'mother' => __('Mother', 'parish-forms'),
                            'father' => __('Father', 'parish-forms'),
                            'both' => __('Both', 'parish-forms'),
                        ), true, 'full'),
                        self::text('street_address', __('Street Address', 'parish-forms'), true, 'full', 180, 'street-address'),
                        self::text('city', __('City', 'parish-forms'), true, 'third', 100, 'address-level2'),
                        self::text('state', __('State', 'parish-forms'), true, 'third', 80, 'address-level1'),
                        self::text('postal_code', __('Postal Zip Code', 'parish-forms'), true, 'third', 20, 'postal-code'),
                    ),
                ),
                array(
                    'id' => 'baptism-planning',
                    'title' => __('Baptism Planning', 'parish-forms'),
                    'fields' => array(
                        self::radio(
                            'offertory_gifts',
                            __('Would your family members or God Parents wish to carry up the Offertory gifts during the Baptismal Mass celebration (if applicable)?', 'parish-forms'),
                            array(
                                'yes' => __('Yes', 'parish-forms'),
                                'no' => __('No', 'parish-forms'),
                            ),
                            true,
                            'full'
                        ),
                        self::text(
                            'reserved_pews',
                            __('How many pews do you anticipate needing reserved for the Baptism? (for family/friends attending)', 'parish-forms'),
                            true,
                            'half',
                            10,
                            'off'
                        ),
                    ),
                ),
            ),
        );
    }

    private static function text($id, $label, $required, $width, $max_length, $autocomplete = '')
    {
        return array(
            'id' => $id,
            'type' => 'text',
            'label' => $label,
            'required' => $required,
            'width' => $width,
            'max_length' => $max_length,
            'autocomplete' => $autocomplete,
        );
    }

    private static function email($id, $label, $required, $width, $autocomplete = '')
    {
        return array(
            'id' => $id,
            'type' => 'email',
            'label' => $label,
            'required' => $required,
            'width' => $width,
            'max_length' => 254,
            'autocomplete' => $autocomplete,
        );
    }

    private static function tel($id, $label, $required, $width, $autocomplete = '')
    {
        return array(
            'id' => $id,
            'type' => 'tel',
            'label' => $label,
            'required' => $required,
            'width' => $width,
            'max_length' => 60,
            'autocomplete' => $autocomplete,
        );
    }

    private static function date($id, $label, $required, $width, $not_future = false)
    {
        return array(
            'id' => $id,
            'type' => 'date',
            'label' => $label,
            'required' => $required,
            'width' => $width,
            'not_future' => $not_future,
        );
    }

    private static function radio($id, $label, $options, $required, $width)
    {
        return array(
            'id' => $id,
            'type' => 'radio',
            'label' => $label,
            'options' => $options,
            'required' => $required,
            'width' => $width,
        );
    }
}
