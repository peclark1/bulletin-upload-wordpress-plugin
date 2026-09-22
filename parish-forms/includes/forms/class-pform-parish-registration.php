<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Parish_Registration
{
    public static function definition()
    {
        $sacraments = array(
            'baptism' => __('Baptism', 'parish-forms'),
            'first-communion' => __('First Communion', 'parish-forms'),
            'confirmation' => __('Confirmation', 'parish-forms'),
        );

        return array(
            'id' => 'parish-registration',
            'version' => 1,
            'title' => __('Join Our Parish Family', 'parish-forms'),
            'description' => __('Use this form to register as a new or returning parishioner, or to update your household information. Fields marked with an asterisk are required.', 'parish-forms'),
            'submit_label' => __('Submit Registration', 'parish-forms'),
            'confirmation' => __('Thank you. Your parish registration has been received. The parish office will follow up if any additional information is needed.', 'parish-forms'),
            'sections' => array(
                array(
                    'id' => 'household',
                    'title' => __('Household Information', 'parish-forms'),
                    'fields' => array(
                        self::text('family_name', __('Family Name', 'parish-forms'), true, 'half', 120, 'family-name'),
                        self::textarea('physical_address', __('Physical Address', 'parish-forms'), true, 'half', 300, 'street-address'),
                        self::textarea('mailing_address', __('Mailing Address - if different', 'parish-forms'), false, 'half', 300, 'street-address'),
                        self::radio('marital_status', __('Marital Status', 'parish-forms'), array(
                            'married' => __('Married', 'parish-forms'),
                            'single' => __('Single', 'parish-forms'),
                            'divorced' => __('Divorced', 'parish-forms'),
                            'separated' => __('Separated', 'parish-forms'),
                            'widowed' => __('Widowed', 'parish-forms'),
                        ), false, 'half'),
                        self::radio('marriage_in_church', __('If Married - Marriage in the Church?', 'parish-forms'), array(
                            'yes' => __('Yes', 'parish-forms'),
                            'no' => __('No', 'parish-forms'),
                        ), false, 'half', array('field' => 'marital_status', 'equals' => 'married')),
                        self::date('marriage_date', __('Date of Marriage', 'parish-forms'), false, 'half', true, array('field' => 'marital_status', 'equals' => 'married')),
                    ),
                ),
                array(
                    'id' => 'primary-contact',
                    'title' => __('Primary Contact', 'parish-forms'),
                    'fields' => array(
                        self::text('primary_name', __('Full Name', 'parish-forms'), true, 'half', 150, 'name'),
                        self::date('primary_birth_date', __('Date of Birth', 'parish-forms'), false, 'half', true),
                        self::text('primary_religion', __('Religion', 'parish-forms'), false, 'third', 100),
                        self::text('primary_occupation', __('Occupation', 'parish-forms'), false, 'third', 120, 'organization-title'),
                        self::tel('primary_phone', __('Cell and Home Phone', 'parish-forms'), true, 'phone-wide', 'tel'),
                        self::email('primary_email', __('Email Address', 'parish-forms'), true, 'half', 'email'),
                        self::checkboxes('primary_sacraments', __('Sacraments Received - Primary Contact', 'parish-forms'), $sacraments, 'half'),
                    ),
                ),
                array(
                    'id' => 'spouse',
                    'title' => __('Spouse', 'parish-forms'),
                    'description' => __('Complete this section when marital status is Married.', 'parish-forms'),
                    'condition' => array('field' => 'marital_status', 'equals' => 'married'),
                    'fields' => array(
                        self::text('spouse_name', __('Spouse Full Name', 'parish-forms'), false, 'half', 150, 'name'),
                        self::date('spouse_birth_date', __('Spouse Date of Birth', 'parish-forms'), false, 'half', true),
                        self::text('spouse_religion', __('Spouse Religion', 'parish-forms'), false, 'third', 100),
                        self::text('spouse_occupation', __('Spouse Occupation', 'parish-forms'), false, 'third', 120, 'organization-title'),
                        self::tel('spouse_phone', __('Spouse Cell Phone', 'parish-forms'), false, 'third', 'tel'),
                        self::email('spouse_email', __('Spouse Email Address', 'parish-forms'), false, 'half', 'email'),
                        self::checkboxes('spouse_sacraments', __('Sacraments Received - Spouse', 'parish-forms'), $sacraments, 'half'),
                    ),
                ),
                array(
                    'id' => 'children',
                    'title' => __('Children Living at Home', 'parish-forms'),
                    'description' => __('Add one section for each child living at home.', 'parish-forms'),
                    'fields' => array(
                        array(
                            'id' => 'children',
                            'type' => 'repeater',
                            'label' => __('Children', 'parish-forms'),
                            'item_label' => __('Child', 'parish-forms'),
                            'add_label' => __('Add Another Child', 'parish-forms'),
                            'min_items' => 0,
                            'max_items' => 10,
                            'fields' => array(
                                self::text('full_name', __('Full Name', 'parish-forms'), true, 'full', 150, 'name'),
                                self::date('birth_date', __('Date of Birth', 'parish-forms'), false, 'third', true),
                                self::text('grade', __('Grade', 'parish-forms'), false, 'third', 30),
                                self::radio('gender', __('Gender', 'parish-forms'), array(
                                    'male' => __('Male', 'parish-forms'),
                                    'female' => __('Female', 'parish-forms'),
                                ), false, 'third'),
                                self::checkboxes('sacraments', __('Sacraments Received', 'parish-forms'), $sacraments, 'full'),
                            ),
                        ),
                    ),
                ),
                array(
                    'id' => 'directory',
                    'title' => __('Parish Directory', 'parish-forms'),
                    'fields' => array(
                        self::radio('directory_photo', __('Are you willing to provide an electronic or hard-copy photo of your family for the online parish directory?', 'parish-forms'), array(
                            'yes' => __('Yes', 'parish-forms'),
                            'no' => __('No', 'parish-forms'),
                        ), false, 'full'),
                    ),
                ),
                array(
                    'id' => 'flocknote',
                    'title' => __('Flocknote', 'parish-forms'),
                    'description' => __('We send communications (emails and texts) via Flocknote. To sign up, text SPSM to 84576 or visit stpeterandstmary.flocknote.com.', 'parish-forms'),
                    'fields' => array(),
                ),
                array(
                    'id' => 'involvement',
                    'title' => __('Parish Involvement', 'parish-forms'),
                    'description' => __('Please check any ministries or activities that interest you or a family member.', 'parish-forms'),
                    'fields' => array(
                        self::checkboxes('ministries', __('Ministries and Activities', 'parish-forms'), array(
                            'lector' => __('Lector', 'parish-forms'),
                            'eucharistic-minister' => __('Eucharistic Minister', 'parish-forms'),
                            'choir-music' => __('Choir/Music Ministry', 'parish-forms'),
                            'mass-server' => __('Mass Server', 'parish-forms'),
                            'religious-education' => __('Religious Education', 'parish-forms'),
                            'youth-ministry' => __('Youth Ministry', 'parish-forms'),
                            'parish-guild' => __('Parish Guild', 'parish-forms'),
                            'knights-of-columbus' => __('Knights of Columbus', 'parish-forms'),
                            'greeter' => __('Greeter', 'parish-forms'),
                            'usher' => __('Usher', 'parish-forms'),
                            'bible-study' => __('Bible Study', 'parish-forms'),
                            'volunteer' => __('Volunteer Opportunities', 'parish-forms'),
                            'prayer-groups' => __('Prayer Groups', 'parish-forms'),
                            'small-faith-groups' => __('Small Faith Sharing/Study Groups', 'parish-forms'),
                            'st-vincent-depaul' => __('St. Vincent DePaul', 'parish-forms'),
                            'homebound-communion' => __('Communion to Nursing Homes/Homebound', 'parish-forms'),
                        ), 'ministries'),
                        self::textarea('other_interests', __('Other Interests or Skills', 'parish-forms'), false, 'other-interests', 1000),
                    ),
                ),
                array(
                    'id' => 'stewardship',
                    'title' => __('Stewardship', 'parish-forms'),
                    'fields' => array(
                        self::radio('offertory_preference', __('Offertory Preference', 'parish-forms'), array(
                            'envelopes' => __('Yes - I would like offertory envelopes', 'parish-forms'),
                            'no-envelopes' => __('No - I do not need offertory envelopes', 'parish-forms'),
                            'online-giving' => __('Online Giving Information Requested', 'parish-forms'),
                        ), false, 'full'),
                    ),
                ),
                array(
                    'id' => 'registration',
                    'title' => __('Registration', 'parish-forms'),
                    'description' => __('I/we wish to register as member(s) of St. Peter’s in Park Rapids or St. Mary’s in Two Inlets and participate in the life of the Church.', 'parish-forms'),
                    'fields' => array(
                        self::radio('registration_status', __('Registration Status', 'parish-forms'), array(
                            'new' => __('New Parishioner', 'parish-forms'),
                            'returning' => __('Returning Parishioner', 'parish-forms'),
                            'update' => __('Update Information', 'parish-forms'),
                        ), true, 'half'),
                        self::radio('parish', __('Parish', 'parish-forms'), array(
                            'st-peter' => __('St. Peter the Apostle - Park Rapids', 'parish-forms'),
                            'st-mary' => __('St. Mary’s - Two Inlets', 'parish-forms'),
                        ), true, 'half'),
                        self::text('typed_name', __('Typed Name', 'parish-forms'), true, 'half', 150, 'name'),
                        self::date('registration_date', __('Date', 'parish-forms'), true, 'half', true),
                        array(
                            'id' => 'registration_consent',
                            'type' => 'consent',
                            'label' => __('I/we wish to register as member(s) and participate in the life of the Church.', 'parish-forms'),
                            'required' => true,
                            'width' => 'full',
                        ),
                    ),
                ),
            ),
        );
    }

    private static function text($id, $label, $required, $width, $max_length, $autocomplete = '')
    {
        return array('id' => $id, 'type' => 'text', 'label' => $label, 'required' => $required, 'width' => $width, 'max_length' => $max_length, 'autocomplete' => $autocomplete);
    }

    private static function textarea($id, $label, $required, $width, $max_length, $autocomplete = '')
    {
        return array('id' => $id, 'type' => 'textarea', 'label' => $label, 'required' => $required, 'width' => $width, 'max_length' => $max_length, 'autocomplete' => $autocomplete);
    }

    private static function email($id, $label, $required, $width, $autocomplete = '')
    {
        return array('id' => $id, 'type' => 'email', 'label' => $label, 'required' => $required, 'width' => $width, 'max_length' => 254, 'autocomplete' => $autocomplete);
    }

    private static function tel($id, $label, $required, $width, $autocomplete = '')
    {
        return array('id' => $id, 'type' => 'tel', 'label' => $label, 'required' => $required, 'width' => $width, 'max_length' => 60, 'autocomplete' => $autocomplete);
    }

    private static function date($id, $label, $required, $width, $not_future = false, $condition = null)
    {
        $field = array('id' => $id, 'type' => 'date', 'label' => $label, 'required' => $required, 'width' => $width, 'not_future' => $not_future);
        if ($condition) {
            $field['condition'] = $condition;
        }
        return $field;
    }

    private static function radio($id, $label, $options, $required, $width, $condition = null)
    {
        $field = array('id' => $id, 'type' => 'radio', 'label' => $label, 'options' => $options, 'required' => $required, 'width' => $width);
        if ($condition) {
            $field['condition'] = $condition;
        }
        return $field;
    }

    private static function checkboxes($id, $label, $options, $width)
    {
        return array('id' => $id, 'type' => 'checkboxes', 'label' => $label, 'options' => $options, 'required' => false, 'width' => $width);
    }
}
