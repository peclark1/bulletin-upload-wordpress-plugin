<?php

define('ABSPATH', __DIR__ . '/');

function __($text)
{
    return $text;
}

function sanitize_text_field($value)
{
    return trim(strip_tags((string) $value));
}

function sanitize_textarea_field($value)
{
    return trim(strip_tags((string) $value));
}

function sanitize_email($value)
{
    return filter_var((string) $value, FILTER_SANITIZE_EMAIL);
}

function sanitize_key($value)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}

function sanitize_title($value)
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    return trim($value, '-');
}

class WP_Error
{
    private $code;
    private $message;

    public function __construct($code, $message)
    {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_message()
    {
        return $this->message;
    }
}

function is_wp_error($value)
{
    return $value instanceof WP_Error;
}

function is_email($value)
{
    return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
}

function absint($value)
{
    return abs((int) $value);
}

function wp_date($format)
{
    return date($format);
}

function esc_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function esc_attr($value)
{
    return esc_html($value);
}

function esc_url($value)
{
    return esc_html($value);
}

function esc_textarea($value)
{
    return esc_html($value);
}

function esc_html_e($value)
{
    echo esc_html($value);
}

function esc_html__($value)
{
    return esc_html($value);
}

function sanitize_html_class($value)
{
    return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value);
}

function admin_url($path = '')
{
    return 'https://example.test/wp-admin/' . ltrim($path, '/');
}

function wp_nonce_field($action, $name)
{
    echo '<input type="hidden" name="' . esc_attr($name) . '" value="test-nonce">';
}

function checked($checked, $current = true, $display = true)
{
    $result = (string) $checked === (string) $current ? ' checked="checked"' : '';
    if ($display) {
        echo $result;
    }
    return $result;
}

class PFORM_Plugin
{
    public static function signature($form_id, $timestamp)
    {
        return hash('sha256', $form_id . '|' . $timestamp);
    }
}

require_once dirname(__DIR__) . '/includes/forms/class-pform-parish-registration.php';
require_once dirname(__DIR__) . '/includes/forms/class-pform-pre-baptismal-questionnaire.php';
require_once dirname(__DIR__) . '/includes/forms/class-pform-confirmation-interest.php';
require_once dirname(__DIR__) . '/includes/class-pform-definition-sanitizer.php';
require_once dirname(__DIR__) . '/includes/class-pform-validator.php';
require_once dirname(__DIR__) . '/includes/class-pform-formatter.php';
require_once dirname(__DIR__) . '/includes/class-pform-renderer.php';

function assert_true($condition, $message)
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$definition = PFORM_Parish_Registration::definition();
$valid = array(
    'family_name' => 'Sample',
    'physical_address' => "123 Main St\nPark Rapids, MN 56470",
    'marital_status' => 'married',
    'marriage_in_church' => 'yes',
    'primary_name' => 'Pat Sample',
    'primary_email' => 'pat@example.com',
    'primary_phone' => '218-555-0100',
    'primary_sacraments' => array('baptism', 'confirmation'),
    'spouse_name' => 'Sam Sample',
    'children' => array(
        array(
            'full_name' => 'Alex Sample',
            'birth_date' => '2015-06-15',
            'grade' => '5',
            'gender' => 'male',
            'sacraments' => array('baptism', 'not-allowed'),
        ),
    ),
    'registration_status' => 'new',
    'parish' => 'st-peter',
    'typed_name' => 'Pat Sample',
    'registration_date' => '2026-09-22',
    'registration_consent' => '1',
    'unknown_field' => 'must not be retained',
);

$result = PFORM_Validator::validate($definition, $valid);
assert_true($result['errors'] === array(), 'Valid registration should have no errors.');
assert_true(! isset($result['data']['unknown_field']), 'Unknown fields must be discarded.');
assert_true($result['data']['children'][0]['sacraments'] === array('baptism'), 'Checkbox values must use the definition allowlist.');
assert_true(isset($result['data']['spouse_name']), 'Spouse data should be retained when married.');

$not_married = $valid;
$not_married['marital_status'] = 'single';
$result = PFORM_Validator::validate($definition, $not_married);
assert_true(! isset($result['data']['spouse_name']), 'Spouse data must be discarded when the condition is not met.');
assert_true(! isset($result['data']['marriage_in_church']), 'Marriage data must be discarded when the condition is not met.');

$invalid = $valid;
$invalid['family_name'] = '';
$invalid['primary_email'] = 'not-an-email';
$invalid['children'][0]['birth_date'] = '2035-01-01';
$invalid['registration_consent'] = '';
$result = PFORM_Validator::validate($definition, $invalid);
assert_true(isset($result['errors']['family_name']), 'Required household name should be rejected.');
assert_true(isset($result['errors']['primary_email']), 'Invalid email should be rejected.');
assert_true(isset($result['errors']['children.0.birth_date']), 'Future child birth date should be rejected.');
assert_true(isset($result['errors']['registration_consent']), 'Required consent should be rejected.');

$malformed = $valid;
$malformed['primary_sacraments'] = array(array('nested'));
$result = PFORM_Validator::validate($definition, $malformed);
assert_true($result['data']['primary_sacraments'] === array(), 'Malformed checkbox input should be discarded without an error or crash.');

$formatted = PFORM_Formatter::plain_text($definition, PFORM_Validator::validate($definition, $valid)['data']);
assert_true(strpos($formatted, 'Pat Sample') !== false, 'Formatted notification should contain submitted data.');
assert_true(strpos($formatted, 'not-allowed') === false, 'Formatted notification must not contain rejected values.');

$html = PFORM_Renderer::render($definition, array('errors' => array(), 'values' => $result['data'], 'success' => false));
assert_true(strpos($html, 'data-pform-condition-field="marital_status"') !== false, 'Renderer should expose conditional spouse behavior.');
assert_true(strpos($html, 'name="pf[children][__INDEX__][full_name]"') !== false, 'Repeater template should preserve its replaceable index.');
assert_true(strpos($html, 'id="pform-children-__INDEX__-full_name"') !== false, 'Repeater template IDs should be unique after client-side replacement.');

$baptism_definition = PFORM_Pre_Baptismal_Questionnaire::definition();
$baptism_valid = array(
    'child_first_name' => 'Jordan',
    'child_middle_name' => 'Lee',
    'child_last_name' => 'Sample',
    'gender' => 'male',
    'child_birth_date' => '2020-05-08',
    'place_of_birth' => 'Park Rapids, MN, USA',
    'mother_name' => 'Pat Sample',
    'mother_maiden_name' => 'Example',
    'mother_religion' => 'Catholic',
    'mother_phone' => '913-555-0100',
    'mother_email' => 'mother@example.com',
    'father_name' => 'Taylor Sample',
    'father_religion' => 'Other Christian denomination',
    'father_phone' => '913-555-0101',
    'father_email' => 'father@example.com',
    'address_for' => 'both',
    'street_address' => '123 Main Street',
    'city' => 'Park Rapids',
    'state' => 'MN',
    'postal_code' => '56470',
    'offertory_gifts' => 'yes',
    'reserved_pews' => '3',
);

$baptism_result = PFORM_Validator::validate($baptism_definition, $baptism_valid);
assert_true($baptism_result['errors'] === array(), 'Valid pre-baptismal questionnaire should have no errors.');
assert_true($baptism_result['data']['address_for'] === 'both', 'Address-for choice should be retained from the allowlist.');

$baptism_invalid = $baptism_valid;
$baptism_invalid['child_first_name'] = '';
$baptism_invalid['child_birth_date'] = '2035-01-01';
$baptism_invalid['mother_email'] = 'bad-email';
$baptism_invalid['address_for'] = 'somewhere-else';
$baptism_invalid['reserved_pews'] = '';
$baptism_result = PFORM_Validator::validate($baptism_definition, $baptism_invalid);
assert_true(isset($baptism_result['errors']['child_first_name']), 'Child first name should be required.');
assert_true(isset($baptism_result['errors']['child_birth_date']), 'Future child birth date should be rejected when supplied.');
assert_true(isset($baptism_result['errors']['mother_email']), 'Invalid mother email should be rejected when supplied.');
assert_true(isset($baptism_result['errors']['address_for']), 'Unknown address-for choices should be rejected.');
assert_true(isset($baptism_result['errors']['reserved_pews']), 'Reserved pew count should be required.');

$baptism_formatted = PFORM_Formatter::plain_text(
    $baptism_definition,
    PFORM_Validator::validate($baptism_definition, $baptism_valid)['data']
);
assert_true(strpos($baptism_formatted, 'Jordan') !== false, 'Baptism notification should contain the child name.');
assert_true(strpos($baptism_formatted, 'Pat Sample') !== false, 'Baptism notification should contain parent information.');

$baptism_html = PFORM_Renderer::render(
    $baptism_definition,
    array('errors' => array(), 'values' => $baptism_valid, 'success' => false)
);
assert_true(strpos($baptism_html, 'Pre-Baptismal Questionnaire') !== false, 'Renderer should display the baptism form title.');
assert_true(strpos($baptism_html, 'name="pf[child_first_name]"') !== false, 'Renderer should include the child first-name field.');
assert_true(strpos($baptism_html, 'name="pf[offertory_gifts]"') !== false, 'Renderer should include the offertory-gifts field.');

$confirmation_definition = PFORM_Confirmation_Interest::definition();
$confirmation_valid = array(
    'candidate_first_name' => 'Alex',
    'candidate_middle_name' => 'Jordan',
    'candidate_last_name' => 'Sample',
    'candidate_birth_date' => '2012-04-12',
    'candidate_grade' => '8',
    'candidate_school' => 'Sample Middle School',
    'parish' => 'st-peter',
    'parent_name' => 'Pat Sample',
    'parent_relationship' => 'Parent',
    'parent_email' => 'pat@example.com',
    'parent_phone' => '218-555-0110',
    'street_address' => '123 Main Street',
    'city' => 'Park Rapids',
    'state' => 'MN',
    'postal_code' => '56470',
    'baptized' => 'yes',
    'baptism_date' => '2012-06-10',
    'baptism_parish' => 'St. Example',
    'baptism_location' => 'Park Rapids, MN',
    'first_communion' => 'yes',
    'formation_setting' => 'parish-program',
    'formation_notes' => 'Weekly parish formation.',
    'sponsor_status' => 'selected',
    'sponsor_name' => 'Jordan Sponsor',
    'sponsor_relationship' => 'Family friend',
    'questions' => 'No questions at this time.',
    'typed_parent_name' => 'Pat Sample',
    'confirmation_acknowledgment' => '1',
);

$confirmation_result = PFORM_Validator::validate($confirmation_definition, $confirmation_valid);
assert_true($confirmation_result['errors'] === array(), 'Valid Confirmation interest form should have no errors.');
assert_true($confirmation_result['data']['parish'] === 'st-peter', 'Confirmation parish choice should be retained.');
assert_true(isset($confirmation_result['data']['baptism_parish']), 'Baptism details should be retained when candidate is baptized.');
assert_true(isset($confirmation_result['data']['sponsor_name']), 'Sponsor details should be retained when a sponsor is selected.');

$confirmation_no_sponsor = $confirmation_valid;
$confirmation_no_sponsor['sponsor_status'] = 'not-yet';
$confirmation_result = PFORM_Validator::validate($confirmation_definition, $confirmation_no_sponsor);
assert_true(! isset($confirmation_result['data']['sponsor_name']), 'Sponsor details must be discarded until a sponsor is selected.');

$confirmation_not_baptized = $confirmation_valid;
$confirmation_not_baptized['baptized'] = 'no';
$confirmation_result = PFORM_Validator::validate($confirmation_definition, $confirmation_not_baptized);
assert_true(! isset($confirmation_result['data']['baptism_date']), 'Baptism details must be discarded when candidate is not baptized.');

$confirmation_invalid = $confirmation_valid;
$confirmation_invalid['candidate_first_name'] = '';
$confirmation_invalid['candidate_birth_date'] = '2035-01-01';
$confirmation_invalid['parent_email'] = 'not-an-email';
$confirmation_invalid['parish'] = 'invalid';
$confirmation_invalid['confirmation_acknowledgment'] = '';
$confirmation_result = PFORM_Validator::validate($confirmation_definition, $confirmation_invalid);
assert_true(isset($confirmation_result['errors']['candidate_first_name']), 'Candidate first name should be required.');
assert_true(isset($confirmation_result['errors']['candidate_birth_date']), 'Future candidate birth date should be rejected.');
assert_true(isset($confirmation_result['errors']['parent_email']), 'Invalid parent email should be rejected.');
assert_true(isset($confirmation_result['errors']['parish']), 'Unknown parish choices should be rejected.');
assert_true(isset($confirmation_result['errors']['confirmation_acknowledgment']), 'Confirmation acknowledgment should be required.');

$confirmation_formatted = PFORM_Formatter::plain_text(
    $confirmation_definition,
    PFORM_Validator::validate($confirmation_definition, $confirmation_valid)['data']
);
assert_true(strpos($confirmation_formatted, 'Alex') !== false, 'Confirmation notification should contain the candidate name.');
assert_true(strpos($confirmation_formatted, 'Pat Sample') !== false, 'Confirmation notification should contain parent information.');

$confirmation_html = PFORM_Renderer::render(
    $confirmation_definition,
    array('errors' => array(), 'values' => $confirmation_valid, 'success' => false)
);
assert_true(strpos($confirmation_html, 'Confirmation Interest Form') !== false, 'Renderer should display the Confirmation form title.');
assert_true(strpos($confirmation_html, 'name="pf[candidate_first_name]"') !== false, 'Renderer should include candidate fields.');
assert_true(strpos($confirmation_html, 'data-pform-condition-field="sponsor_status"') !== false, 'Renderer should expose conditional sponsor behavior.');

$sanitized_registration = PFORM_Definition_Sanitizer::sanitize(PFORM_Parish_Registration::definition(), 'parish-registration');
assert_true(! is_wp_error($sanitized_registration), 'Parish Registration definition should survive editor sanitization.');
assert_true(isset($sanitized_registration['sections'][2]['condition']), 'Section-level spouse condition should survive sanitization.');
assert_true($sanitized_registration['sections'][3]['fields'][0]['type'] === 'repeater', 'Repeatable children group should survive sanitization.');
assert_true(count($sanitized_registration['sections'][3]['fields'][0]['fields']) === 5, 'Nested child fields should survive sanitization.');

$sanitized_baptism = PFORM_Definition_Sanitizer::sanitize(PFORM_Pre_Baptismal_Questionnaire::definition(), 'pre-baptismal-questionnaire');
assert_true(! is_wp_error($sanitized_baptism), 'Baptism definition should survive editor sanitization.');
assert_true($sanitized_baptism['reply_to_field'] === 'mother_email', 'Baptism reply-to field should be preserved.');

$sanitized_confirmation = PFORM_Definition_Sanitizer::sanitize(PFORM_Confirmation_Interest::definition(), 'confirmation-interest');
assert_true(! is_wp_error($sanitized_confirmation), 'Confirmation definition should survive editor sanitization.');
$confirmation_fields = array();
foreach ($sanitized_confirmation['sections'] as $section) {
    foreach ($section['fields'] as $field) {
        $confirmation_fields[$field['id']] = $field;
    }
}
assert_true(isset($confirmation_fields['sponsor_name']['condition']), 'Confirmation sponsor condition should survive sanitization.');

$editor_definition = array(
    'title' => 'Volunteer Interest',
    'eyebrow' => 'Get Involved',
    'description' => 'Tell us how you would like to help.',
    'submit_label' => 'Send Interest Form',
    'success_title' => 'Thank You',
    'confirmation' => 'We will be in touch.',
    'privacy_note' => 'For parish follow-up.',
    'reply_to_field' => 'email',
    'admin_primary_fields' => array('name'),
    'admin_contact_fields' => array('email'),
    'sections' => array(
        array(
            'id' => 'contact',
            'title' => 'Contact',
            'fields' => array(
                array('id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'width' => 'half', 'max_length' => 150),
                array('id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true, 'width' => 'half', 'max_length' => 254),
                array(
                    'id' => 'area',
                    'type' => 'radio',
                    'label' => 'Area',
                    'required' => true,
                    'width' => 'full',
                    'options' => array('liturgy' => 'Liturgy', 'hospitality' => 'Hospitality'),
                ),
                array(
                    'id' => 'details',
                    'type' => 'textarea',
                    'label' => 'Details',
                    'required' => false,
                    'width' => 'full',
                    'max_length' => 1000,
                    'condition' => array('field' => 'area', 'equals' => 'hospitality'),
                    'unsafe_html' => '<script>alert(1)</script>',
                ),
            ),
        ),
    ),
    'unexpected' => 'discard me',
);
$editor_sanitized = PFORM_Definition_Sanitizer::sanitize($editor_definition, 'volunteer-interest');
assert_true(! is_wp_error($editor_sanitized), 'A staff-created form should sanitize successfully.');
assert_true($editor_sanitized['id'] === 'volunteer-interest', 'The stored form ID should be authoritative.');
assert_true(! isset($editor_sanitized['unexpected']), 'Unknown top-level definition keys should be discarded.');
assert_true(! isset($editor_sanitized['sections'][0]['fields'][3]['unsafe_html']), 'Unknown field properties should be discarded.');
assert_true($editor_sanitized['sections'][0]['fields'][3]['condition']['equals'] === 'hospitality', 'Valid simple conditional logic should be retained.');

$bad_options = $editor_definition;
$bad_options['sections'][0]['fields'][2]['options'] = array();
$bad_result = PFORM_Definition_Sanitizer::sanitize($bad_options, 'bad-options');
assert_true(is_wp_error($bad_result), 'Choice fields without options should be rejected.');

$preview_html = PFORM_Renderer::render(
    $editor_sanitized,
    array('errors' => array(), 'values' => array(), 'success' => false),
    true
);
assert_true(strpos($preview_html, 'pform-preview') !== false, 'Preview rendering should use the non-submitting preview container.');
assert_true(strpos($preview_html, 'action="') === false, 'Preview rendering must not expose a submitting form action.');

echo "Validation tests passed.\n";
