<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PFORM_Notifications
{
    public static function send($submission_id, $definition, $data)
    {
        $settings = PFORM_Plugin::settings();
        // Prayer intentions may contain private family/health circumstances.
        // They must NOT fall back to the recipients configured for other forms.
        $is_prayer = $definition['id'] === 'prayer-request';
        $raw_emails = $is_prayer
            ? (isset($settings['prayer_notification_emails']) ? $settings['prayer_notification_emails'] : '')
            : $settings['notification_emails'];
        $recipients = self::recipients($raw_emails);
        $recipients = apply_filters('pform_notification_recipients', $recipients, $definition['id'], $submission_id);
        if (! $recipients) {
            return false;
        }

        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf('[%s] New %s #%d', $site_name, $definition['title'], $submission_id);
        $headers = array('Content-Type: text/plain; charset=UTF-8');

        if ($is_prayer) {
            // Notification-only email: the intention is available solely in
            // the capability-protected WordPress submission interface.
            $body = sprintf(
                "A new prayer request was submitted.\n\nSubmission ID: %d\nReceived: %s\nReview privately: %s\n\nThe prayer intention is not included in this email for privacy.\n",
                $submission_id,
                wp_date('F j, Y g:i a'),
                PFORM_Admin::submission_url($submission_id)
            );
        } else {
            $body = sprintf("A new %s was submitted.\n\nSubmission ID: %d\nReceived: %s\nAdmin: %s\n\n%s\n", $definition['title'], $submission_id, wp_date('F j, Y g:i a'), PFORM_Admin::submission_url($submission_id), PFORM_Formatter::plain_text($definition, $data));

            $reply_to_field = ! empty($definition['reply_to_field']) ? $definition['reply_to_field'] : 'primary_email';
            if (! empty($data[$reply_to_field]) && is_email($data[$reply_to_field])) {
                $headers[] = 'Reply-To: ' . $data[$reply_to_field];
            }
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
