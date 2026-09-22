<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Front-end views for the approved bulletin data.
 *
 * The review workflow stores one weekly data set, but visitors need two focused
 * views: worship/sacramental information and general parish events.
 */
final class CBP_Site_Displays
{
    private static $instance;

    public static function instance()
    {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_shortcode('church_worship_week', array($this, 'worship_week_shortcode'));
        add_shortcode('church_parish_events', array($this, 'parish_events_shortcode'));
    }

    public function worship_week_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'mode' => 'full',
        ), $atts, 'church_worship_week');
        $mode = sanitize_key($atts['mode']);
        if (! in_array($mode, array('full', 'compact'), true)) {
            $mode = 'full';
        }

        $weekly = $this->weekly();
        if (! $this->has_week($weekly)) {
            return $this->empty_message(__('The approved weekly worship schedule has not been published yet.', 'church-bulletin-publisher'));
        }

        $this->enqueue_assets();
        $masses = isset($weekly['masses']) && is_array($weekly['masses']) ? $weekly['masses'] : array();
        $devotions = isset($weekly['devotions']) && is_array($weekly['devotions']) ? $weekly['devotions'] : array();
        $livestream = isset($weekly['livestream']) && is_array($weekly['livestream']) ? $weekly['livestream'] : array();

        ob_start();
        ?>
        <div class="cbp-site-view cbp-worship-week cbp-site-view--<?php echo esc_attr($mode); ?>">
            <?php echo $this->week_heading($weekly); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <div class="cbp-site-grid cbp-site-grid--two">
                <section class="cbp-site-card">
                    <p class="cbp-site-eyebrow"><?php esc_html_e('MASS', 'church-bulletin-publisher'); ?></p>
                    <h3><?php esc_html_e('This Week’s Masses', 'church-bulletin-publisher'); ?></h3>
                    <?php
                    echo ! empty($masses)
                        ? $this->render_rows($masses, $mode === 'compact' ? 4 : 0, 'mass')
                        : $this->empty_message(__('No dated Masses were approved for this week.', 'church-bulletin-publisher'));
                    ?>
                </section>
                <section class="cbp-site-card cbp-site-card--warm">
                    <p class="cbp-site-eyebrow"><?php esc_html_e('PRAYER & SACRAMENTS', 'church-bulletin-publisher'); ?></p>
                    <h3><?php esc_html_e('Reconciliation, Rosary & Adoration', 'church-bulletin-publisher'); ?></h3>
                    <?php
                    echo ! empty($devotions)
                        ? $this->render_rows($devotions, $mode === 'compact' ? 4 : 0, 'devotion')
                        : $this->empty_message(__('No additional prayer or sacramental times were approved for this week.', 'church-bulletin-publisher'));
                    ?>
                </section>
            </div>
            <?php if ($mode === 'full' && ! empty($livestream)) : ?>
                <div class="cbp-site-note">
                    <strong><?php esc_html_e('Livestream', 'church-bulletin-publisher'); ?></strong>
                    <?php foreach ($livestream as $item) : ?>
                        <?php if (! empty($item['description'])) : ?>
                            <p><?php echo esc_html($item['description']); ?></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function parish_events_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'mode' => 'full',
            'limit' => '',
        ), $atts, 'church_parish_events');
        $mode = sanitize_key($atts['mode']);
        if (! in_array($mode, array('full', 'compact'), true)) {
            $mode = 'full';
        }

        $weekly = $this->weekly();
        if (! $this->has_week($weekly)) {
            return $this->empty_message(__('The approved parish events calendar has not been published yet.', 'church-bulletin-publisher'));
        }

        $events = isset($weekly['events']) && is_array($weekly['events']) ? $weekly['events'] : array();
        // Defensive cleanup for already-approved data: if an event exists as a
        // proper range plus separate endpoint rows, render only the range, and
        // strip parser-only leading connectors such as "& Bible Study".
        if (class_exists('CBP_Schedule_V8')) {
            $events = CBP_Schedule_V8::collapse_range_duplicates($events);
        }
        if ($mode === 'compact') {
            $events = $this->upcoming_rows($events);
        }

        $limit = absint($atts['limit']);
        if ($limit <= 0 && $mode === 'compact') {
            $limit = 5;
        }
        if ($limit > 0) {
            $events = array_slice($events, 0, $limit);
        }

        $this->enqueue_assets();
        ob_start();
        ?>
        <div class="cbp-site-view cbp-parish-events cbp-site-view--<?php echo esc_attr($mode); ?>">
            <?php if ($mode === 'full') : ?>
                <?php echo $this->week_heading($weekly); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endif; ?>
            <?php
            echo ! empty($events)
                ? $this->render_rows($events, 0, 'event')
                : $this->empty_message(__('No parish events were approved for this week.', 'church-bulletin-publisher'));
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

}
