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
                        ? $this->render_rows($masses, $mode === 'compact' ? 4 : 0, 'mass') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        : $this->empty_message(__('No dated Masses were approved for this week.', 'church-bulletin-publisher')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    ?>
                </section>
                <section class="cbp-site-card cbp-site-card--warm">
                    <p class="cbp-site-eyebrow"><?php esc_html_e('PRAYER & SACRAMENTS', 'church-bulletin-publisher'); ?></p>
                    <h3><?php esc_html_e('Reconciliation, Rosary & Adoration', 'church-bulletin-publisher'); ?></h3>
                    <?php
                    echo ! empty($devotions)
                        ? $this->render_rows($devotions, $mode === 'compact' ? 4 : 0, 'devotion') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        : $this->empty_message(__('No additional prayer or sacramental times were approved for this week.', 'church-bulletin-publisher')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
                ? $this->render_rows($events, 0, 'event') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                : $this->empty_message(__('No parish events were approved for this week.', 'church-bulletin-publisher')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function weekly()
    {
        return wp_parse_args(
            get_option(CBP_Schedule::WEEKLY_OPTION, array()),
            CBP_Schedule::weekly_defaults()
        );
    }

    private function has_week(array $weekly)
    {
        return ! empty($weekly['week_start']) && ! empty($weekly['week_end']);
    }

    private function week_heading(array $weekly)
    {
        $start = $this->format_date($weekly['week_start'], 'F j');
        $end = $this->format_date($weekly['week_end'], 'F j, Y');
        if ($start === '' || $end === '') {
            return '';
        }
        return '<p class="cbp-site-week">' . esc_html(sprintf(__('Week of %1$s–%2$s', 'church-bulletin-publisher'), $start, $end)) . '</p>';
    }

    private function render_rows(array $rows, $limit, $kind)
    {
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $html = '<div class="cbp-site-days">';
        $current_date = null;
        foreach ($rows as $row) {
            $date = isset($row['date']) ? sanitize_text_field($row['date']) : '';
            if ($date !== $current_date) {
                if ($current_date !== null) {
                    $html .= '</div>';
                }
                $html .= '<div class="cbp-site-day">';
                if ($this->valid_date($date)) {
                    $html .= '<h4>' . esc_html($this->format_date($date, 'l, F j')) . '</h4>';
                } else {
                    $html .= '<h4>' . esc_html__('Any day', 'church-bulletin-publisher') . '</h4>';
                }
                $current_date = $date;
            }

            $time = isset($row['time']) ? sanitize_text_field($row['time']) : '';
            $location = isset($row['location']) ? sanitize_text_field($row['location']) : '';
            $title = isset($row['title']) ? sanitize_text_field($row['title']) : '';
            $description = isset($row['description']) ? sanitize_text_field($row['description']) : '';

            $html .= '<div class="cbp-site-item cbp-site-item--' . esc_attr($kind) . '">';
            if ($time !== '') {
                $html .= '<div class="cbp-site-time">' . esc_html($time) . '</div>';
            }
            $html .= '<div class="cbp-site-item-body">';
            if ($title !== '') {
                $html .= '<strong class="cbp-site-title">' . esc_html($title) . '</strong>';
            } elseif ($description !== '') {
                $html .= '<strong class="cbp-site-title">' . esc_html($description) . '</strong>';
            }
            if ($location !== '') {
                $html .= '<span class="cbp-site-location">' . esc_html($location) . '</span>';
            }
            $html .= '</div></div>';
        }
        if ($current_date !== null) {
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    private function upcoming_rows(array $rows)
    {
        $today = wp_date('Y-m-d');
        $future = array();
        foreach ($rows as $row) {
            $date = isset($row['date']) ? $row['date'] : '';
            if ($this->valid_date($date) && $date >= $today) {
                $future[] = $row;
            }
        }
        return ! empty($future) ? $future : $rows;
    }

    private function enqueue_assets()
    {
        wp_enqueue_style(
            'cbp-site-displays',
            CBP_URL . 'assets/frontend.css',
            array(),
            CBP_VERSION
        );
    }

    private function empty_message($message)
    {
        return '<p class="cbp-site-empty">' . esc_html($message) . '</p>';
    }

    private function valid_date($date)
    {
        if (! is_string($date) || $date === '') {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private function format_date($date, $format)
    {
        if (! $this->valid_date($date)) {
            return '';
        }
        $timestamp = strtotime($date . ' 12:00:00');
        return wp_date($format, $timestamp);
    }
}
