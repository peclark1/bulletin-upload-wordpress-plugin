<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One-field prayer intention form. All data is handled by the shared Parish
 * Forms validator, private submission store, and anti-spam submission flow.
 *
 * Do not collect a name, email, phone, or contact information on this form.
 */
final class PFORM_Prayer_Request
{
    public static function definition()
    {
        return array(
            'id' => 'prayer-request',
            'version' => 1,
            'title' => __('Share a Prayer Intention', 'parish-forms'),
            'eyebrow' => __('PRAYER REQUESTS', 'parish-forms'),
            'description' => __('Our parish family is here to pray with you and for you. Please enter your prayer intention below.', 'parish-forms'),
            'submit_label' => __('Submit Prayer Request', 'parish-forms'),
            'success_title' => __('Prayer Request Received', 'parish-forms'),
            'confirmation' => __('Thank you for sharing your prayer intention. Your request has been received by the parish.', 'parish-forms'),
            'privacy_note' => __('Please do not include last names, medical details, or other sensitive personal information. Your request is stored privately for authorized parish staff and is not automatically published. For urgent assistance, please call the parish office.', 'parish-forms'),
            'admin_primary_fields' => array(),
            'admin_contact_fields' => array(),
            'sections' => array(
                array(
                    'id' => 'request',
                    'title' => __('Prayer Request', 'parish-forms'),
                    'fields' => array(
                        array(
                            'id' => 'intention',
                            'type' => 'textarea',
                            'label' => __('Your prayer request', 'parish-forms'),
                            'required' => true,
                            'width' => 'full',
                            'max_length' => 1500,
                        ),
                    ),
                ),
            ),
        );
    }
}
