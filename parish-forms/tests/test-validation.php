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

echo "Validation tests passed.\n";
