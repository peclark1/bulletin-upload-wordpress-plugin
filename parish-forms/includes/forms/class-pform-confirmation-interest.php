<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Confirmation_Interest
{
    public static function definition()
    {
        return array(
            'id' => 'confirmation-interest',
            'version' => 1,
            'title' => __('Confirmation Interest Form', 'parish-forms'),
            'eyebrow' => __('Preparing for Confirmation', 'parish-forms'),
            'description' => __('Use this form to tell us about a young person interested in preparing for Confirmation at St. Peter the Apostle or St. Mary’s Two Inlets. Parish staff will follow up with current requirements, dates, and next steps. Fields marked with an asterisk are required.', 'parish-forms'),
            'submit_label' => __('Submit Confirmation Interest Form', 'parish-forms'),
            'success_title' => __('Confirmation Interest Form Received', 'parish-forms'),
            'confirmation' => __('Thank you. We received your Confirmation interest form. Parish staff will follow up with current preparation information and next steps.', 'parish-forms'),
            'privacy_note' => __('Information submitted through this form is intended for Confirmation preparation and parish-office follow-up.', 'parish-forms'),
            'reply_to_field' => 'parent_email',
            'admin_primary_fields' => array('candidate_first_name', 'candidate_middle_name', 'candidate_last_name'),
            'admin_contact_fields' => array('parent_name', 'parent_email'),
            'sections' => array(
                array(
                    'id' => 'candidate',
                    'title' => __('Candidate Information', 'parish-forms'),
                    'fields' => array(
                        self::text('candidate_first_name', __('First Name', 'parish-forms'), true, 'half', 100, 'given-name'),
                        self::text('candidate_middle_name', __('Middle Name', 'parish-forms'), false, 'half', 100, 'additional-name'),
                        self::text('candidate_last_name', __('Last Name', 'parish-forms'), true, 'half', 100, 'family-name'),
                        self::date('candidate_birth_date', __('Date of Birth', 'parish-forms'), true, 'half', true),
                        self::text('candidate_grade', __('Current Grade', 'parish-forms'), true, 'half', 40),
                        self::text('candidate_school', __('School', 'parish-forms'), false, 'half', 150, 'organization'),
                        self::radio('parish', __('Parish', 'parish-forms'), array(
                            'st-peter' => __('St. Peter the Apostle - Park Rapids', 'parish-forms'),
                            'st-mary' => __('St. Mary’s - Two Inlets', 'parish-forms'),
                            'not-sure' => __('Not sure / please help us determine this', 'parish-forms'),
                        ), true, 'full'),
                    ),
                ),
                array(
                    'id' => 'parent',
                    'title' => __('Parent or Guardian Information', 'parish-forms'),
                    'fields' => array(
                        self::text('parent_name', __('Parent/Guardian Name', 'parish-forms'), true, 'half', 150, 'name'),
                        self::text('parent_relationship', __('Relationship to Candidate', 'parish-forms'), true, 'half', 80),
                        self::email('parent_email', __('Email Address', 'parish-forms'), true, 'half', 'email'),
                        self::tel('parent_phone', __('Phone Number', 'parish-forms'), true, 'half', 'tel'),
                        self::text('second_parent_name', __('Second Parent/Guardian Name', 'parish-forms'), false, 'half', 150, 'name'),
                        self::email('second_parent_email', __('Second Parent/Guardian Email', 'parish-forms'), false, 'half', 'email'),
                        self::tel('second_parent_phone', __('Second Parent/Guardian Phone', 'parish-forms'), false, 'half', 'tel'),
                    ),
                ),
                array(
                    'id' => 'address',
                    'title' => __('Family Contact Information', 'parish-forms'),
                    'fields' => array(
                        self::text('street_address', __('Street Address', 'parish-forms'), true, 'full', 180, 'street-address'),
                        self::text('city', __('City', 'parish-forms'), true, 'third', 100, 'address-level2'),
                        self::text('state', __('State', 'parish-forms'), true, 'third', 80, 'address-level1'),
                        self::text('postal_code', __('Postal Zip Code', 'parish-forms'), true, 'third', 20, 'postal-code'),
                    ),
                ),
                array(
                    'id' => 'sacraments',
                    'title' => __('Sacramental Background', 'parish-forms'),
                    'description' => __('This information helps the parish office determine what records or preparation may be needed.', 'parish-forms'),
                    'fields' => array(
                        self::radio('baptized', __('Has the candidate been baptized?', 'parish-forms'), array(
                            'yes' => __('Yes', 'parish-forms'),
                            'no' => __('No', 'parish-forms'),
                            'unsure' => __('Not sure', 'parish-forms'),
                        ), true, 'half'),
                        self::date('baptism_date', __('Baptism Date', 'parish-forms'), false, 'half', true, array('field' => 'baptized', 'equals' => 'yes')),
                        self::text('baptism_parish', __('Church or Parish of Baptism', 'parish-forms'), false, 'half', 180, '', array('field' => 'baptized', 'equals' => 'yes')),
                        self::text('baptism_location', __('City and State of Baptism', 'parish-forms'), false, 'half', 150, '', array('field' => 'baptized', 'equals' => 'yes')),
                        self::radio('first_communion', __('Has the candidate received First Communion?', 'parish-forms'), array(
                            'yes' => __('Yes', 'parish-forms'),
                            'no' => __('No', 'parish-forms'),
                            'unsure' => __('Not sure', 'parish-forms'),
                        ), true, 'half'),
                    ),
                ),
                array(
                    'id' => 'formation',
                    'title' => __('Faith Formation and Sponsor', 'parish-forms'),
                    'description' => __('These answers help us understand where the candidate is in the faith journey. They do not determine eligibility by themselves.', 'parish-forms'),
                    'fields' => array(
                        self::radio('formation_setting', __('Current Faith Formation', 'parish-forms'), array(
                            'parish-program' => __('Parish faith formation program', 'parish-forms'),
                            'catholic-school' => __('Catholic school', 'parish-forms'),
                            'home-family' => __('Home or family catechesis', 'parish-forms'),
                            'other' => __('Other formation', 'parish-forms'),
                            'none' => __('Not currently participating in a formal program', 'parish-forms'),
                        ), true, 'full'),
                        self::textarea('formation_notes', __('Faith Formation Details or Notes', 'parish-forms'), false, 'full', 1000),
                        self::radio('sponsor_status', __('Has the candidate chosen a Confirmation sponsor?', 'parish-forms'), array(
                            'selected' => __('Yes', 'parish-forms'),
                            'not-yet' => __('Not yet', 'parish-forms'),
                            'need-help' => __('We would like help understanding the sponsor requirements', 'parish-forms'),
                        ), true, 'full'),
                        self::text('sponsor_name', __('Sponsor Name', 'parish-forms'), false, 'half', 150, 'name', array('field' => 'sponsor_status', 'equals' => 'selected')),
                        self::text('sponsor_relationship', __('Sponsor Relationship to Candidate', 'parish-forms'), false, 'half', 100, '', array('field' => 'sponsor_status', 'equals' => 'selected')),
                    ),
                ),
                array(
                    'id' => 'follow-up',
                    'title' => __('Questions and Follow-Up', 'parish-forms'),
                    'fields' => array(
                        self::textarea('questions', __('Questions, Circumstances, or Other Information You Would Like Us to Know', 'parish-forms'), false, 'full', 2000),
                        self::text('typed_parent_name', __('Parent/Guardian Typed Name', 'parish-forms'), true, 'half', 150, 'name'),
                        array(
                            'id' => 'confirmation_acknowledgment',
                            'type' => 'consent',
                            'label' => __('I understand that submitting this form begins the parish contact and preparation process. Parish staff will confirm current requirements, dates, and eligibility.', 'parish-forms'),
                            'required' => true,
                            'width' => 'full',
                        ),
                    ),
                ),
            ),
        );
    }

    private static function text($id, $label, $required, $width, $max_length, $autocomplete = '', $condition = null)
    {
        $field = array(
            'id' => $id,
            'type' => 'text',
            'label' => $label,
            'required' => $required,
            'width' => $width,
            'max_length' => $max_length,
            'autocomplete' => $autocomplete,
        );
        if ($condition) {
            $field['condition'] = $condition;
        }
        return $field;
    }

    private static function textarea($id, $label, $required, $width, $max_length)
    {
        return array(
            'id' => $id,
            'type' => 'textarea',
            'label' => $label,
            'required' => $required,
            'width' => $width,
            'max_length' => $max_length,
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

    private static function date($id, $label, $required, $width, $not_future = false, $condition = null)
    {
        $field = array(
            'id' => $id,
            'type' => 'date',
            'label' => $label,
            'required' => $required,
            'width' => $width,
            'not_future' => $not_future,
        );
        if ($condition) {
            $field['condition'] = $condition;
        }
        return $field;
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
