<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Homepage presentation for the parish schedule.
 *
 * Re-registers [church_mass_schedule] after CBP_Schedule so mode="home"
 * gets the polished two-card layout used on the parish homepage. For weekend
 * Masses, prefer the approved dated weekly calendar so the homepage matches
 * the detailed Mass page immediately after bulletin approval. Fall back to the
 * standing recurring schedule when no upcoming dated weekend Mass is present.
 */
final class CBP_Home_Schedule
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
        add_shortcode('church_mass_schedule', array($this, 'shortcode'));
    }

    public function shortcode($atts)
    {
        $atts = shortcode_atts(array('mode' => 'home'), $atts, 'church_mass_schedule');
        $mode = sanitize_key($atts['mode']);

        if ($mode !== 'home') {
            $html = CBP_Schedule::instance()->schedule_shortcode($atts);
            return str_replace(
                array(
                    'St. Mary’s Mission • Two Inlets',
                    "St. Mary's Mission • Two Inlets",
                    'St. Mary’s Mission',
                    "St. Mary's Mission",
                ),
                'St. Mary’s Two Inlets',
                $html
            );
        }

        $this->promote_pending_schedule();
        $schedule = wp_parse_args(
            get_option(CBP_Schedule::OPTION, array()),
            CBP_Schedule::defaults()
        );
        $schedule = $this->apply_upcoming_weekend_masses($schedule);

        wp_enqueue_style(
            'cbp-site-displays',
            CBP_URL . 'assets/frontend.css',
            array(),
            CBP_VERSION
        );

        ob_start();
        ?>
        <div class="cbp-home-mass-schedule">
            <div class="cbp-home-mass-grid">
                <section class="cbp-home-mass-card">
                    <p class="cbp-home-mass-eyebrow"><?php esc_html_e('PARK RAPIDS', 'church-bulletin-publisher'); ?></p>
                    <h3><?php esc_html_e('St. Peter the Apostle', 'church-bulletin-publisher'); ?></h3>
                    <p class="cbp-home-mass-times">
                        <strong><?php esc_html_e('Saturday', 'church-bulletin-publisher'); ?> • <?php echo esc_html($schedule['st_peter_saturday']); ?></strong><br>
                        <strong><?php esc_html_e('Sunday', 'church-bulletin-publisher'); ?> • <?php echo esc_html($schedule['st_peter_sunday']); ?></strong>
                    </p>
                </section>

                <section class="cbp-home-mass-card cbp-home-mass-card--warm">
                    <p class="cbp-home-mass-eyebrow"><?php esc_html_e('TWO INLETS', 'church-bulletin-publisher'); ?></p>
                    <h3><?php esc_html_e('St. Mary’s Two Inlets', 'church-bulletin-publisher'); ?></h3>
                    <p class="cbp-home-mass-times">
                        <strong><?php esc_html_e('Sunday', 'church-bulletin-publisher'); ?> • <?php echo esc_html($schedule['st_mary_sunday']); ?></strong>
                    </p>
                </section>
            </div>

            <div class="cbp-home-prayer-grid">
                <div class="cbp-home-prayer-item">
                    <strong><?php esc_html_e('Reconciliation • St. Peter', 'church-bulletin-publisher'); ?></strong>
                    <span><?php echo esc_html($schedule['reconciliation']); ?></span>
                </div>
                <div class="cbp-home-prayer-item">
                    <strong><?php esc_html_e('Adoration • St. Peter', 'church-bulletin-publisher'); ?></strong>
                    <span><?php echo esc_html($schedule['adoration']); ?></span>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function apply_upcoming_weekend_masses(array $schedule)
    {
        $weekly = wp_parse_args(
            get_option(CBP_Schedule::WEEKLY_OPTION, array()),
            CBP_Schedule::weekly_defaults()
        );

        if (empty($weekly['masses']) || ! is_array($weekly['masses'])) {
            return $schedule;
        }

        $today = wp_date('Y-m-d');
        $found = array(
            'st_peter_saturday' => false,
            'st_peter_sunday' => false,
            'st_mary_sunday' => false,
        );

        foreach ($weekly['masses'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = isset($row['date']) ? sanitize_text_field($row['date']) : '';
            $time = isset($row['time']) ? sanitize_text_field($row['time']) : '';
            $location = isset($row['location']) ? sanitize_text_field($row['location']) : '';

            if ($time === '' || ! $this->valid_date($date) || $date < $today) {
                continue;
            }

            $timestamp = strtotime($date . ' 12:00:00');
            if (! $timestamp) {
                continue;
            }

            $day = (int) wp_date('N', $timestamp);
            if ($day !== 6 && $day !== 7) {
                continue;
            }

            $where = strtolower(str_replace(array('’', '\''), '', $location));
            $is_peter = strpos($where, 'peter') !== false;
            $is_mary = strpos($where, 'mary') !== false;

            if ($day === 6 && $is_peter && ! $found['st_peter_saturday']) {
                $schedule['st_peter_saturday'] = $time;
                $found['st_peter_saturday'] = true;
                continue;
            }

            if ($day === 7 && $is_peter && ! $found['st_peter_sunday']) {
                $schedule['st_peter_sunday'] = $time;
                $found['st_peter_sunday'] = true;
                continue;
            }

            if ($day === 7 && $is_mary && ! $found['st_mary_sunday']) {
                $schedule['st_mary_sunday'] = $time;
                $found['st_mary_sunday'] = true;
            }
        }

        return $schedule;
    }

    private function valid_date($date)
    {
        if (! is_string($date) || $date === '') {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private function promote_pending_schedule()
    {
        $current = wp_parse_args(
            get_option(CBP_Schedule::OPTION, array()),
            CBP_Schedule::defaults()
        );

        if (empty($current['pending']) || ! is_array($current['pending']) || empty($current['pending_effective_date'])) {
            return;
        }

        $effective = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $current['pending_effective_date']);
        if (! $effective || $effective->format('Y-m-d') !== (string) $current['pending_effective_date']) {
            return;
        }

        if ((string) $current['pending_effective_date'] > wp_date('Y-m-d')) {
            return;
        }

        foreach ($current['pending'] as $key => $value) {
            if (array_key_exists($key, CBP_Schedule::defaults())) {
                $current[$key] = sanitize_text_field($value);
            }
        }
        $current['updated_utc'] = gmdate('c');
        $current['source_bulletin_date'] = isset($current['pending_source_bulletin_date']) ? (string) $current['pending_source_bulletin_date'] : '';
        $current['pending'] = array();
        $current['pending_effective_date'] = '';
        $current['pending_source_bulletin_date'] = '';
        update_option(CBP_Schedule::OPTION, $current, false);
    }
}
