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
                    <h3><?php esc_html_e('This Week’s Masses', 'church-bulletin-publisher'); ?></h3>
                    <?php
                    echo ! empty($masses)
                        ? $this->render_rows($masses, $mode === 'compact' ? 4 : 0, 'mass')
                        : $this->empty_message(__('No dated Masses were approved for this week.', 'church-bulletin-publisher'));
                    ?>
                </section>
                <section class="cbp-site-card cbp-site-card--warm">
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
            if ($kind === 'event' && class_exists('CBP_Schedule_V8')) {
                $title = CBP_Schedule_V8::clean_event_title($title);
            }
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
                $display_location = $kind === 'event' ? $location : $this->worship_location_name($location);
                $html .= '<span class="cbp-site-location">' . esc_html($display_location) . '</span>';
            }
            if ($kind === 'mass' && $this->has_mass_intention($title, $description)) {
                $html .= '<span class="cbp-site-intention"><strong>' . esc_html__('Intention:', 'church-bulletin-publisher') . '</strong> ' . esc_html($description) . '</span>';
            }
            if ($kind === 'event') {
                $note = $this->event_note($description);
                if ($note !== '') {
                    $html .= '<span class="cbp-site-detail">' . esc_html($note) . '</span>';
                }
            }
            $html .= '</div></div>';
        }
        if ($current_date !== null) {
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    private function worship_location_name($location)
    {
        $location = trim((string) $location);
        if (strcasecmp($location, 'St. Peter') === 0) {
            return 'St. Peter Park Rapids';
        }
        if (in_array($location, array('St. Mary’s', "St. Mary's", 'St. Mary'), true)) {
            return "St. Mary's Two Inlets";
        }
        return $location;
    }

    private function has_mass_intention($title, $description)
    {
        $title = trim((string) $title);
        $description = trim((string) $description);
        if ($description === '' || strcasecmp($title, 'No Mass') === 0) {
            return false;
        }
        return (bool) preg_match('/\bMass$/i', $title);
    }

    private function event_note($description)
    {
        $description = (string) $description;
        if ($description === '') {
            return '';
        }
        if (preg_match('/\(([^)]*(?:call|contact)[^)]*(?:office|location|sign[- ]?up|details?)[^)]*)\)/iu', $description, $m)) {
            return '(' . trim($m[1]) . ')';
        }
        return '';
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
