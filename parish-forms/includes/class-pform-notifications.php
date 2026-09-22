<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Notifications
{
    public static function send($submission_id, $definition, $data)
    {
        $settings = PFORM_Plugin::settings();
        $recipients = self::recipients($settings['notification_emails']);
        $recipients = apply_filters('pform_notification_recipients', $recipients, $definition['id'], $submission_id);
        if (! $recipients) {
            return false;
        }

        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf('[%s] New %s #%d', $site_name, $definition['title'], $submission_id);
        $body = sprintf("A new %s was submitted.\n\nSubmission ID: %d\nReceived: %s\nAdmin: %s\n\n%s\n", $definition['title'], $submission_id, wp_date('F j, Y g:i a'), PFORM_Admin::submission_url($submission_id), PFORM_Formatter::plain_text($definition, $data));
        $headers = array('Content-Type: text/plain; charset=UTF-8');

        if (! empty($data['primary_email']) && is_email($data['primary_email'])) {
            $headers[] = 'Reply-To: ' . $data['primary_email'];
        }

        return (bool) wp_mail($recipients, $subject, $body, $headers);
    }

    private static function recipients($raw)
    {
        $emails = preg_split('/[\s,;]+/', (string) $raw);
        $clean = array();
        foreach ($emails as $email) {
            $email = sanitize_email($email);
            if ($email && is_email($email)) {
                $clean[] = $email;
            }
        }
        return array_values(array_unique($clean));
    }
}
