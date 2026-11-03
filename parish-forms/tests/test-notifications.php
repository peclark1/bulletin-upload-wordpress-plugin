<?php

// Isolated test for Parish Forms notification routing and complete email bodies.
define('ABSPATH', __DIR__ . '/');
$GLOBALS['pform_mail_calls'] = array();

function sanitize_email($value) { return filter_var((string) $value, FILTER_SANITIZE_EMAIL); }
function is_email($value) { return (bool) filter_var($value, FILTER_VALIDATE_EMAIL); }
function __($value) { return $value; }
function wp_specialchars_decode($value, $flags = ENT_QUOTES) { return html_entity_decode($value, $flags, 'UTF-8'); }
function get_bloginfo($field) { return 'St. Peter & St. Mary'; }
function wp_date($format) { return 'November 3, 2026 3:08 pm'; }
function apply_filters($hook, $value) { return $value; }
function wp_mail($recipients, $subject, $body, $headers) {
    $GLOBALS['pform_mail_calls'][] = compact('recipients', 'subject', 'body', 'headers');
    return true;
}

class PFORM_Plugin
{
    public static function settings() { return array('notification_emails' => 'office@example.test'); }
}

class PFORM_Admin
{
    public static function submission_url($id) { return 'https://example.test/wp-admin/post.php?post=' . $id; }
}

function check($condition, $message)
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

require_once dirname(__DIR__) . '/includes/class-pform-definition-sanitizer.php';
require_once dirname(__DIR__) . '/includes/class-pform-formatter.php';
require_once dirname(__DIR__) . '/includes/class-pform-notifications.php';

$prayer = PFORM_Definition_Sanitizer::sanitize(array(
    'id' => 'prayer-request',
    'title' => 'Prayer Request',
    'notification_emails' => "father@example.test, deacon@example.test; father@example.test\nnot-an-email",
    'reply_to_field' => 'contact_email',
    'sections' => array(
        array('id' => 'request', 'title' => 'Prayer Request', 'fields' => array(
            array('id' => 'contact_email', 'type' => 'email', 'label' => 'Email'),
            array('id' => 'intention', 'type' => 'textarea', 'label' => 'Prayer intention'),
        )),
    ),
), 'prayer-request');

check($prayer['notification_emails'] === 'father@example.test, deacon@example.test', 'Recipient list should sanitize and deduplicate.');
$data = array('contact_email' => 'requester@example.test', 'intention' => 'Please pray for our family.');
check(PFORM_Notifications::send(42, $prayer, $data), 'Prayer notification should send.');
$mail = $GLOBALS['pform_mail_calls'][0];
check($mail['recipients'] === array('father@example.test', 'deacon@example.test'), 'Prayer request must reach only this form\'s recipients.');
check(strpos($mail['body'], 'Please pray for our family.') !== false, 'The full prayer text must be in the email body.');
check(strpos($mail['body'], 'requester@example.test') !== false, 'Contact details should be in the email body.');
check(strpos($mail['body'], 'wp-admin') === false, 'Prayer email should not direct clergy to a WordPress login.');
check(in_array('Reply-To: requester@example.test', $mail['headers'], true), 'Reply-To should use the requester\'s valid email.');

$registration = $prayer;
$registration['id'] = 'parish-registration';
$registration['title'] = 'Parish Registration';
$registration['notification_emails'] = '';
check(PFORM_Notifications::send(43, $registration, $data), 'Other form notification should send.');
$mail = $GLOBALS['pform_mail_calls'][1];
check($mail['recipients'] === array('office@example.test'), 'Other forms should use the existing global recipients.');
check(strpos($mail['body'], 'wp-admin') !== false, 'Other forms should retain their admin link.');
check(strpos($mail['body'], 'Please pray for our family.') !== false, 'Other forms should also include complete data.');

echo "Notification tests passed.\n";
