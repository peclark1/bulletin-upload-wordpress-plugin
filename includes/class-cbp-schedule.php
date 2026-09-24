<?php

if (! defined('ABSPATH')) {
    exit;
}

final class CBP_Schedule
{
    const OPTION = 'cbp_mass_schedule';
    const WEEKLY_OPTION = 'cbp_weekly_calendar';
    const REVIEW_TTL = 2 * DAY_IN_SECONDS;

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
        add_action('admin_footer-toplevel_page_church-bulletin-publisher', array($this, 'render_review_panel'));
        add_action('admin_post_cbp_extract_schedule', array($this, 'extract_schedule'));
        add_action('admin_post_cbp_apply_schedule', array($this, 'apply_schedule'));
        add_shortcode('church_mass_schedule', array($this, 'schedule_shortcode'));
        add_shortcode('church_weekly_calendar', array($this, 'weekly_calendar_shortcode'));
    }

    public static function activate()
    {
        if (! get_option(self::OPTION)) {
            add_option(self::OPTION, self::defaults(), '', false);
        }
        if (! get_option(self::WEEKLY_OPTION)) {
            add_option(self::WEEKLY_OPTION, self::weekly_defaults(), '', false);
        }
    }

    public static function defaults()
    {
        return array(
            'st_peter_saturday' => '5:00 PM',
            'st_peter_sunday' => '8:30 AM',
            'st_mary_sunday' => '11:00 AM',
            'reconciliation' => 'Saturday 8:00 AM & 4:00 PM',
            'adoration' => 'Thursday 6:00 AM–1:00 PM',
            'updated_utc' => '',
            'source_bulletin_date' => '',
            'pending' => array(),
            'pending_effective_date' => '',
            'pending_source_bulletin_date' => '',
        );
    }

    public static function weekly_defaults()
    {
        return array(
            'week_start' => '',
            'week_end' => '',
            'source_bulletin_date' => '',
            'updated_utc' => '',
            'masses' => array(),
            'devotions' => array(),
            'events' => array(),
            'livestream' => array(),
        );
    }

    public function render_review_panel()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $this->promote_pending_schedule();
        $preview = get_transient($this->preview_key());
        if (! is_array($preview) || empty($preview['path'])) {
            return;
        }

        $review = get_transient($this->review_key());
        $current = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        $diagnostic = $this->diagnostic();
        ?>
        <section class="cbp-card cbp-schedule-review" style="margin-top:20px;">
            <h2><?php esc_html_e('4. Review website schedule and weekly calendar', 'church-bulletin-publisher'); ?></h2>
            <p><?php esc_html_e('Extract the recurring Mass schedule plus the dated Masses, prayer times, and parish events printed in the bulletin. Nothing changes on the website until a person reviews and approves the proposed information.', 'church-bulletin-publisher'); ?></p>

            <?php if (is_array($review) && ! empty($review['bulletin_date'])) : ?>
                <div class="notice notice-info inline"><p>
                    <?php
                    echo esc_html(sprintf(
                        __('Bulletin: %1$s. Weekly calendar: %2$s through %3$s. Any approved recurring weekend schedule will become effective %4$s.', 'church-bulletin-publisher'),
                        $this->format_date($review['bulletin_date']),
                        $this->format_date($review['week_start']),
                        $this->format_date($review['week_end']),
                        $this->format_date($review['effective_date'])
                    ));
                    ?>
                </p></div>
            <?php endif; ?>

            <?php if (is_wp_error($diagnostic)) : ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html($diagnostic->get_error_message()); ?></p></div>
            <?php else : ?>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="margin-bottom:18px;">
                    <input type="hidden" name="action" value="cbp_extract_schedule">
                    <?php wp_nonce_field('cbp_extract_schedule'); ?>
                    <?php submit_button(__('Extract Website Information from Preview', 'church-bulletin-publisher'), 'secondary', 'submit', false); ?>
                </form>
            <?php endif; ?>

            <?php if (is_array($review) && ! empty($review['candidates'])) : ?>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="cbp_apply_schedule">
                    <?php wp_nonce_field('cbp_apply_schedule'); ?>
                    <input type="hidden" name="bulletin_date" value="<?php echo esc_attr($review['bulletin_date']); ?>">
                    <input type="hidden" name="week_start" value="<?php echo esc_attr($review['week_start']); ?>">
                    <input type="hidden" name="week_end" value="<?php echo esc_attr($review['week_end']); ?>">
                    <input type="hidden" name="effective_date" value="<?php echo esc_attr($review['effective_date']); ?>">

                    <h3><?php esc_html_e('Recurring parish schedule', 'church-bulletin-publisher'); ?></h3>
                    <p class="description"><?php esc_html_e('These values feed the standing Mass / Reconciliation / Adoration schedule. They are staged until the effective date shown above, so next weekend’s schedule does not replace the current schedule early.', 'church-bulletin-publisher'); ?></p>
                    <table class="widefat striped" style="max-width:1000px; margin:12px 0 22px;">
                        <thead><tr><th><?php esc_html_e('Schedule item', 'church-bulletin-publisher'); ?></th><th><?php esc_html_e('Current website value', 'church-bulletin-publisher'); ?></th><th><?php esc_html_e('Extracted / approved value', 'church-bulletin-publisher'); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($this->fields() as $key => $label) :
                            $candidate = isset($review['candidates'][$key]) ? $review['candidates'][$key] : '';
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($label); ?></strong></td>
                                <td><?php echo esc_html(isset($current[$key]) ? $current[$key] : ''); ?></td>
                                <td><input type="text" class="regular-text" name="schedule[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($candidate); ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php $this->render_weekly_editor(__('This week’s Masses', 'church-bulletin-publisher'), 'masses', isset($review['weekly']['masses']) ? $review['weekly']['masses'] : array(), true); ?>
                    <?php $this->render_weekly_editor(__('Prayer & Sacraments', 'church-bulletin-publisher'), 'devotions', isset($review['weekly']['devotions']) ? $review['weekly']['devotions'] : array(), false); ?>
                    <?php $this->render_weekly_editor(__('This Week at the Parishes', 'church-bulletin-publisher'), 'events', isset($review['weekly']['events']) ? $review['weekly']['events'] : array(), false); ?>

                    <?php if (! empty($review['weekly']['livestream'])) : ?>
                        <h3><?php esc_html_e('Livestream notes', 'church-bulletin-publisher'); ?></h3>
                        <?php foreach ($review['weekly']['livestream'] as $index => $item) : ?>
                            <p><input type="text" class="large-text" name="weekly[livestream][<?php echo esc_attr($index); ?>][description]" value="<?php echo esc_attr(isset($item['description']) ? $item['description'] : ''); ?>"></p>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (! empty($review['source_lines'])) : ?>
                        <details style="margin:18px 0;">
                            <summary><strong><?php esc_html_e('Show bulletin lines used for extraction', 'church-bulletin-publisher'); ?></strong></summary>
                            <pre style="white-space:pre-wrap; background:#f6f7f7; padding:12px; max-height:380px; overflow:auto;"><?php echo esc_html(implode("\n", $review['source_lines'])); ?></pre>
                        </details>
                    <?php endif; ?>

                    <label style="display:block; margin:14px 0; font-weight:600;">
                        <input type="checkbox" name="confirm_schedule" value="1" required>
                        <?php esc_html_e('I reviewed the recurring schedule and weekly calendar and approve them for the website.', 'church-bulletin-publisher'); ?>
                    </label>
                    <?php submit_button(__('Approve Website Update', 'church-bulletin-publisher'), 'primary', 'submit', false); ?>
                </form>
            <?php elseif (is_array($review) && ! empty($review['error'])) : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html($review['error']); ?></p></div>
            <?php endif; ?>
        </section>
        <script>
        (function () {
            var panel = document.querySelector('.cbp-schedule-review');
            var preview = document.querySelector('.cbp-preview');
            if (panel && preview && panel.parentNode !== preview) {
                preview.appendChild(panel);
            }
        }());
        </script>
        <?php
    }

    private function render_weekly_editor($title, $key, array $items, $mass_mode)
    {
        $items = array_values($items);
        // Always give staff two blank rows for quick additions before approval.
        $items[] = array('date' => '', 'time' => '', 'location' => '', 'title' => '', 'description' => '');
        $items[] = array('date' => '', 'time' => '', 'location' => '', 'title' => '', 'description' => '');
        ?>
        <h3><?php echo esc_html($title); ?></h3>
        <p class="description"><?php esc_html_e('Edit any extracted row, check Delete to omit it, or use a blank row to add something the parser missed.', 'church-bulletin-publisher'); ?></p>
        <div style="overflow-x:auto; margin:10px 0 22px;">
        <table class="widefat striped" style="min-width:980px;">
            <thead><tr>
                <th style="width:115px;"><?php esc_html_e('Date', 'church-bulletin-publisher'); ?></th>
                <th style="width:135px;"><?php esc_html_e('Time', 'church-bulletin-publisher'); ?></th>
                <th style="width:150px;"><?php esc_html_e('Location', 'church-bulletin-publisher'); ?></th>
                <th style="width:180px;"><?php echo $mass_mode ? esc_html__('Mass / item', 'church-bulletin-publisher') : esc_html__('Event', 'church-bulletin-publisher'); ?></th>
                <th><?php esc_html_e('Details', 'church-bulletin-publisher'); ?></th>
                <th style="width:65px;"><?php esc_html_e('Delete', 'church-bulletin-publisher'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($items as $index => $item) : ?>
                <tr>
                    <td><input type="date" name="weekly[<?php echo esc_attr($key); ?>][<?php echo esc_attr($index); ?>][date]" value="<?php echo esc_attr(isset($item['date']) ? $item['date'] : ''); ?>"></td>
                    <td><input type="text" style="width:125px" name="weekly[<?php echo esc_attr($key); ?>][<?php echo esc_attr($index); ?>][time]" value="<?php echo esc_attr(isset($item['time']) ? $item['time'] : ''); ?>"></td>
                    <td><input type="text" style="width:140px" name="weekly[<?php echo esc_attr($key); ?>][<?php echo esc_attr($index); ?>][location]" value="<?php echo esc_attr(isset($item['location']) ? $item['location'] : ''); ?>"></td>
                    <td><input type="text" style="width:170px" name="weekly[<?php echo esc_attr($key); ?>][<?php echo esc_attr($index); ?>][title]" value="<?php echo esc_attr(isset($item['title']) ? $item['title'] : ''); ?>"></td>
                    <td><input type="text" class="large-text" name="weekly[<?php echo esc_attr($key); ?>][<?php echo esc_attr($index); ?>][description]" value="<?php echo esc_attr(isset($item['description']) ? $item['description'] : ''); ?>"></td>
                    <td style="text-align:center"><input type="checkbox" name="weekly[<?php echo esc_attr($key); ?>][<?php echo esc_attr($index); ?>][delete]" value="1"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    public function extract_schedule()
    {
        $this->authorize();
        check_admin_referer('cbp_extract_schedule');

        $preview = get_transient($this->preview_key());
        if (! is_array($preview) || empty($preview['path']) || ! is_readable($preview['path'])) {
            $this->redirect('error', __('The private preview is missing or expired. Create it again.', 'church-bulletin-publisher'));
        }

        $bulletin_date = isset($preview['date']) ? sanitize_text_field($preview['date']) : '';
        if (! $this->valid_date($bulletin_date)) {
            $this->redirect('error', __('The bulletin date is missing or invalid. Create the preview again.', 'church-bulletin-publisher'));
        }

        $text = $this->pdf_text($preview['path']);
        if (is_wp_error($text)) {
            set_transient($this->review_key(), array('error' => $text->get_error_message()), self::REVIEW_TTL);
            $this->redirect('error', $text->get_error_message());
        }

        $parsed = $this->parse_schedule($text, $bulletin_date);
        set_transient($this->review_key(), $parsed, self::REVIEW_TTL);
        $this->redirect('success', __('Website information extracted. Review every proposed item before approving it.', 'church-bulletin-publisher'));
    }

    public function apply_schedule()
    {
        $this->authorize();
        check_admin_referer('cbp_apply_schedule');

        if (empty($_POST['confirm_schedule']) || sanitize_text_field(wp_unslash($_POST['confirm_schedule'])) !== '1') {
            $this->redirect('error', __('Website update approval was not confirmed.', 'church-bulletin-publisher'));
        }

        $bulletin_date = isset($_POST['bulletin_date']) ? sanitize_text_field(wp_unslash($_POST['bulletin_date'])) : '';
        $week_start = isset($_POST['week_start']) ? sanitize_text_field(wp_unslash($_POST['week_start'])) : '';
        $week_end = isset($_POST['week_end']) ? sanitize_text_field(wp_unslash($_POST['week_end'])) : '';
        $effective_date = isset($_POST['effective_date']) ? sanitize_text_field(wp_unslash($_POST['effective_date'])) : '';

        if (! $this->valid_date($bulletin_date) || ! $this->valid_date($week_start) || ! $this->valid_date($week_end) || ! $this->valid_date($effective_date)) {
            $this->redirect('error', __('One of the extracted calendar dates is invalid. Extract the bulletin again.', 'church-bulletin-publisher'));
        }

        $incoming = isset($_POST['schedule']) && is_array($_POST['schedule']) ? wp_unslash($_POST['schedule']) : array();
        $current = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        $approved = array();
        foreach ($this->fields() as $key => $label) {
            $value = array_key_exists($key, $incoming) ? sanitize_text_field($incoming[$key]) : '';
            if ($value === '') {
                $value = isset($current[$key]) ? $current[$key] : '';
            }
            $approved[$key] = $value;
        }

        $today = wp_date('Y-m-d');
        if ($effective_date <= $today) {
            foreach ($approved as $key => $value) {
                $current[$key] = $value;
            }
            $current['updated_utc'] = gmdate('c');
            $current['source_bulletin_date'] = $bulletin_date;
            $current['pending'] = array();
            $current['pending_effective_date'] = '';
            $current['pending_source_bulletin_date'] = '';
        } else {
            $current['pending'] = $approved;
            $current['pending_effective_date'] = $effective_date;
            $current['pending_source_bulletin_date'] = $bulletin_date;
        }
        update_option(self::OPTION, $current, false);

        $weekly_incoming = isset($_POST['weekly']) && is_array($_POST['weekly']) ? wp_unslash($_POST['weekly']) : array();
        $weekly = self::weekly_defaults();
        $weekly['week_start'] = $week_start;
        $weekly['week_end'] = $week_end;
        $weekly['source_bulletin_date'] = $bulletin_date;
        $weekly['updated_utc'] = gmdate('c');
        $weekly['masses'] = $this->sanitize_calendar_rows(isset($weekly_incoming['masses']) ? $weekly_incoming['masses'] : array(), $week_start, $week_end);
        $weekly['devotions'] = $this->sanitize_calendar_rows(isset($weekly_incoming['devotions']) ? $weekly_incoming['devotions'] : array(), $week_start, $week_end);
        $weekly['events'] = $this->sanitize_calendar_rows(isset($weekly_incoming['events']) ? $weekly_incoming['events'] : array(), $week_start, $week_end);
        $weekly['livestream'] = $this->sanitize_livestream_rows(isset($weekly_incoming['livestream']) ? $weekly_incoming['livestream'] : array());
        update_option(self::WEEKLY_OPTION, $weekly, false);

        delete_transient($this->review_key());
        do_action('cbp_schedule_updated', $current);
        do_action('cbp_weekly_calendar_updated', $weekly);

        $this->redirect('success', __('Approved. The weekly parish calendar is live, and the recurring weekend schedule is staged until its effective date.', 'church-bulletin-publisher'));
    }

    public function schedule_shortcode($atts)
    {
        $this->promote_pending_schedule();
        $atts = shortcode_atts(array('mode' => 'home'), $atts, 'church_mass_schedule');
        $schedule = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        $mode = sanitize_key($atts['mode']);

        ob_start();
        if ($mode === 'full') {
            ?>
            <div class="cbp-mass-schedule cbp-mass-schedule--full">
                <h3>St. Peter the Apostle</h3>
                <p><strong>Saturday • <?php echo esc_html($schedule['st_peter_saturday']); ?></strong><br><strong>Sunday • <?php echo esc_html($schedule['st_peter_sunday']); ?></strong></p>
                <h3>St. Mary’s Mission • Two Inlets</h3>
                <p><strong>Sunday • <?php echo esc_html($schedule['st_mary_sunday']); ?></strong></p>
                <p><strong>Reconciliation • St. Peter</strong><br><?php echo esc_html($schedule['reconciliation']); ?></p>
                <p><strong>Adoration • St. Peter</strong><br><?php echo esc_html($schedule['adoration']); ?></p>
            </div>
            <?php
        } else {
            ?>
            <div class="cbp-mass-schedule cbp-mass-schedule--home">
                <div><strong>St. Peter the Apostle</strong><br>Saturday • <?php echo esc_html($schedule['st_peter_saturday']); ?><br>Sunday • <?php echo esc_html($schedule['st_peter_sunday']); ?></div>
                <div><strong>St. Mary’s Mission • Two Inlets</strong><br>Sunday • <?php echo esc_html($schedule['st_mary_sunday']); ?></div>
            </div>
            <?php
        }
        return ob_get_clean();
    }

    public function weekly_calendar_shortcode($atts)
    {
        $atts = shortcode_atts(array('mode' => 'full'), $atts, 'church_weekly_calendar');
        $mode = sanitize_key($atts['mode']);
        $weekly = wp_parse_args(get_option(self::WEEKLY_OPTION, array()), self::weekly_defaults());

        if (empty($weekly['week_start']) || empty($weekly['week_end'])) {
            return '<p>' . esc_html__('The weekly parish calendar has not been published yet.', 'church-bulletin-publisher') . '</p>';
        }

        ob_start();
        ?>
        <div class="cbp-weekly-calendar cbp-weekly-calendar--<?php echo esc_attr($mode); ?>">
            <p><strong><?php echo esc_html(sprintf(__('Week of %1$s–%2$s', 'church-bulletin-publisher'), $this->format_date_short($weekly['week_start']), $this->format_date_short($weekly['week_end']))); ?></strong></p>
            <?php if (! empty($weekly['masses'])) : ?>
                <h3><?php esc_html_e('This Week’s Masses', 'church-bulletin-publisher'); ?></h3>
                <?php echo $this->render_calendar_rows($weekly['masses'], $mode === 'compact' ? 4 : 0); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endif; ?>
            <?php if ($mode !== 'compact' && ! empty($weekly['devotions'])) : ?>
                <h3><?php esc_html_e('Prayer & Sacraments', 'church-bulletin-publisher'); ?></h3>
                <?php echo $this->render_calendar_rows($weekly['devotions'], 0); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endif; ?>
            <?php if (! empty($weekly['events'])) : ?>
                <h3><?php echo $mode === 'compact' ? esc_html__('Upcoming This Week', 'church-bulletin-publisher') : esc_html__('This Week at the Parishes', 'church-bulletin-publisher'); ?></h3>
                <?php echo $this->render_calendar_rows($weekly['events'], $mode === 'compact' ? 6 : 0); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endif; ?>
            <?php if ($mode !== 'compact' && ! empty($weekly['livestream'])) : ?>
                <h3><?php esc_html_e('Livestream', 'church-bulletin-publisher'); ?></h3>
                <?php foreach ($weekly['livestream'] as $item) : ?>
                    <p><?php echo esc_html(isset($item['description']) ? $item['description'] : ''); ?></p>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_calendar_rows(array $rows, $limit)
    {
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }
        $html = '<div class="cbp-calendar-list">';
        $last_date = '';
        foreach ($rows as $row) {
            $date = isset($row['date']) ? $row['date'] : '';
            if ($date !== $last_date && $this->valid_date($date)) {
                if ($last_date !== '') {
                    $html .= '</div>';
                }
                $html .= '<div class="cbp-calendar-day"><h4>' . esc_html($this->format_date_day($date)) . '</h4>';
                $last_date = $date;
            } elseif ($last_date === '') {
                $html .= '<div class="cbp-calendar-day">';
                $last_date = '__undated__';
            }
            $time = isset($row['time']) ? $row['time'] : '';
            $location = isset($row['location']) ? $row['location'] : '';
            $title = isset($row['title']) ? $row['title'] : '';
            $description = isset($row['description']) ? $row['description'] : '';
            $meta = trim(implode(' • ', array_filter(array($time, $location))));
            $html .= '<p>';
            if ($meta !== '') {
                $html .= '<strong>' . esc_html($meta) . '</strong>';
                if ($title !== '' || $description !== '') {
                    $html .= '<br>';
                }
            }
            if ($title !== '') {
                $html .= esc_html($title);
            }
            if ($description !== '' && $description !== $title) {
                $html .= ($title !== '' ? ' — ' : '') . esc_html($description);
            }
            $html .= '</p>';
        }
        if ($last_date !== '') {
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    private function promote_pending_schedule()
    {
        $current = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        if (empty($current['pending']) || ! is_array($current['pending']) || empty($current['pending_effective_date'])) {
            return;
        }
        if (! $this->valid_date($current['pending_effective_date']) || $current['pending_effective_date'] > wp_date('Y-m-d')) {
            return;
        }
        foreach ($this->fields() as $key => $label) {
            if (isset($current['pending'][$key]) && $current['pending'][$key] !== '') {
                $current[$key] = sanitize_text_field($current['pending'][$key]);
            }
        }
        $current['updated_utc'] = gmdate('c');
        $current['source_bulletin_date'] = isset($current['pending_source_bulletin_date']) ? $current['pending_source_bulletin_date'] : '';
        $current['pending'] = array();
        $current['pending_effective_date'] = '';
        $current['pending_source_bulletin_date'] = '';
        update_option(self::OPTION, $current, false);
    }

    private function diagnostic()
    {
        if (! extension_loaded('zlib')) {
            return new WP_Error('cbp_schedule_zlib', __('Automatic bulletin extraction needs the PHP zlib extension, which is not available on this server. No website content has been changed.', 'church-bulletin-publisher'));
        }
        if (! extension_loaded('iconv')) {
            return new WP_Error('cbp_schedule_iconv', __('Automatic bulletin extraction needs the PHP iconv extension, which is not available on this server. No website content has been changed.', 'church-bulletin-publisher'));
        }
        if (! class_exists('Smalot\\PdfParser\\Parser')) {
            return new WP_Error('cbp_schedule_parser', __('The bundled PHP PDF parser is missing. Rebuild the plugin ZIP with Composer dependencies included. No website content has been changed.', 'church-bulletin-publisher'));
        }
        return true;
    }

    private function pdf_text($path)
    {
        $diagnostic = $this->diagnostic();
        if (is_wp_error($diagnostic)) {
            return $diagnostic;
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            $text = (string) $pdf->getText();
        } catch (\Throwable $e) {
            return new WP_Error('cbp_schedule_extract', __('The bulletin PDF could not be read by the bundled PHP parser. No website content has been changed.', 'church-bulletin-publisher') . ' ' . $e->getMessage());
        }

        if (trim($text) === '') {
            return new WP_Error('cbp_schedule_empty', __('The bulletin PDF did not yield readable text. It may be image-only. No website content has been changed.', 'church-bulletin-publisher'));
        }

        return $text;
    }

    private function parse_schedule($text, $bulletin_date)
    {
        $lines = $this->normalized_lines($text);
        $bulletin = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        $week_start = $bulletin->modify('+1 day')->format('Y-m-d');
        $week_end = $bulletin->modify('+7 days')->format('Y-m-d');
        $effective_date = $this->next_weekday_date($bulletin_date, 6); // Saturday after the bulletin Sunday.

        $current = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        $recurring = $this->parse_recurring_schedule($lines);
        $candidates = array();
        foreach ($this->fields() as $key => $label) {
            $candidates[$key] = ! empty($recurring[$key]) ? $recurring[$key] : (isset($current[$key]) ? $current[$key] : '');
        }

        $weekly = $this->parse_weekly_calendar($lines, $bulletin_date, $week_start, $week_end);
        $sources = array_merge(
            isset($recurring['_sources']) ? $recurring['_sources'] : array(),
            isset($weekly['_sources']) ? $weekly['_sources'] : array()
        );
        unset($weekly['_sources']);

        return array(
            'bulletin_date' => $bulletin_date,
            'week_start' => $week_start,
            'week_end' => $week_end,
            'effective_date' => $effective_date,
            'candidates' => $candidates,
            'weekly' => $weekly,
            'source_lines' => array_values(array_unique(array_slice($sources, 0, 60))),
        );
    }

    private function normalized_lines($text)
    {
        $raw_lines = preg_split('/\R/u', (string) $text);
        $lines = array();
        foreach ($raw_lines as $line) {
            $line = preg_replace('/\s+/u', ' ', trim($line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    private function parse_recurring_schedule(array $lines)
    {
        $result = array('_sources' => array());
        $start = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/mass\s*(?:&|and)\s*adoration\s*schedule/i', $line) || preg_match('/mass\s+and\s+adoration\s+schedule/i', $line)) {
                $start = $index;
                break;
            }
        }

        if ($start !== null) {
            $location = '';
            $end = min(count($lines), $start + 38);
            for ($i = $start; $i < $end; $i++) {
                $line = $lines[$i];
                $lower = strtolower($line);
                if ($i > $start + 3 && $this->looks_like_section_heading($line) && stripos($line, 'mass') === false && stripos($line, 'adoration') === false && stripos($line, 'reconciliation') === false) {
                    break;
                }
                if (strpos($lower, 'st. mary') !== false || strpos($lower, 'st mary') !== false || strpos($lower, 'two inlets') !== false) {
                    $location = 'mary';
                } elseif (strpos($lower, 'st. peter') !== false || strpos($lower, 'st peter') !== false || strpos($lower, 'park rapids') !== false) {
                    $location = 'peter';
                }

                $times = $this->times_in($line);
                if ($location === 'peter' && preg_match('/\bsat(?:urday)?\.?\b/i', $line) && ! empty($times) && empty($result['st_peter_saturday'])) {
                    $result['st_peter_saturday'] = $times[0];
                    $result['_sources'][] = $line;
                }
                if ($location === 'peter' && preg_match('/\bsun(?:day)?\.?\b/i', $line) && ! empty($times) && empty($result['st_peter_sunday'])) {
                    $result['st_peter_sunday'] = $times[0];
                    $result['_sources'][] = $line;
                }
                if ($location === 'mary' && preg_match('/\bsun(?:day)?\.?\b/i', $line) && ! empty($times) && empty($result['st_mary_sunday'])) {
                    $result['st_mary_sunday'] = $times[0];
                    $result['_sources'][] = $line;
                }
                if ((strpos($lower, 'reconciliation') !== false || strpos($lower, 'confession') !== false) && ! empty($times) && empty($result['reconciliation'])) {
                    $result['reconciliation'] = $this->compact_schedule_line($line, $times);
                    $result['_sources'][] = $line;
                }
                if (strpos($lower, 'adoration') !== false && ! empty($times) && empty($result['adoration'])) {
                    $result['adoration'] = $this->compact_schedule_line($line, $times);
                    $result['_sources'][] = $line;
                }
            }
        }

        // Conservative fallback if the standing schedule box could not be found or parsed.
        foreach ($lines as $index => $line) {
            if ($this->looks_like_event_line($line)) {
                continue;
            }
            $start_context = max(0, $index - 2);
            $context = implode(' | ', array_slice($lines, $start_context, 5));
            $lower = strtolower($context);
            $times = $this->times_in($line);
            if (empty($times)) {
                continue;
            }
            $is_mary = strpos($lower, 'st. mary') !== false || strpos($lower, 'st mary') !== false || strpos($lower, 'two inlets') !== false;
            $is_peter = strpos($lower, 'st. peter') !== false || strpos($lower, 'st peter') !== false || strpos($lower, 'park rapids') !== false;
            if (empty($result['st_mary_sunday']) && $is_mary && preg_match('/\bsun(?:day)?\.?\b/i', $context)) {
                $result['st_mary_sunday'] = $times[0];
                $result['_sources'][] = $context;
            } elseif (empty($result['st_peter_saturday']) && $is_peter && preg_match('/\bsat(?:urday)?\.?\b/i', $context)) {
                $result['st_peter_saturday'] = $times[0];
                $result['_sources'][] = $context;
            } elseif (empty($result['st_peter_sunday']) && $is_peter && preg_match('/\bsun(?:day)?\.?\b/i', $context)) {
                $result['st_peter_sunday'] = $times[0];
                $result['_sources'][] = $context;
            }
        }

        return $result;
    }

    private function parse_weekly_calendar(array $lines, $bulletin_date, $week_start, $week_end)
    {
        $masses = array();
        $devotions = array();
        $events = array();
        $livestream = array();
        $sources = array();
        $mass_indexes = array();
        $devotion_indexes = array();

        $mass_heading = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/this\s+week[’\'s]*\s+mass\s+schedule/i', $line) || (stripos($line, 'this week') !== false && stripos($line, 'mass') !== false && stripos($line, 'schedule') !== false)) {
                $mass_heading = $index;
                break;
            }
        }

        if ($mass_heading !== null) {
            $current_date = '';
            $end = min(count($lines), $mass_heading + 32);
            for ($i = $mass_heading + 1; $i < $end; $i++) {
                $line = $lines[$i];
                $lower = strtolower($line);
                if (preg_match('/reconciliation|confession|rosary|adoration/i', $line)) {
                    break;
                }
                if ($i > $mass_heading + 3 && $this->looks_like_section_heading($line) && stripos($line, 'mass') === false) {
                    break;
                }
                $date = $this->date_from_line($line, $bulletin_date);
                if ($date !== '') {
                    $current_date = $date;
                }
                if ($current_date === '' || $current_date < $week_start || $current_date > $week_end) {
                    continue;
                }
                $has_time = ! empty($this->times_in($line));
                $no_mass = stripos($line, 'no mass') !== false;
                if (! $has_time && ! $no_mass) {
                    continue;
                }
                $item = $this->calendar_item_from_line($line, $current_date, 'Mass');
                if ($no_mass) {
                    $item['time'] = '';
                    $item['title'] = 'No Mass';
                } elseif ($item['title'] === '' || preg_match('/^[A-Z]{2}$/', $item['title'])) {
                    $item['title'] = 'Mass';
                }
                $masses[] = $item;
                $mass_indexes[$i] = true;
                $sources[] = $line;
            }
        }

        // Prayer/sacramental items are often a heading followed by the schedule on the next line.
        foreach ($lines as $index => $line) {
            if (! preg_match('/reconciliation|confession|rosary|adoration/i', $line)) {
                continue;
            }
            if (preg_match('/mass\s*(?:&|and)\s*adoration\s*schedule/i', $line)) {
                continue;
            }
            $combined = $line;
            $used = array($index => true);
            if (empty($this->times_in($combined))) {
                for ($j = 1; $j <= 2 && isset($lines[$index + $j]); $j++) {
                    $combined .= ' ' . $lines[$index + $j];
                    $used[$index + $j] = true;
                    if (! empty($this->times_in($combined))) {
                        break;
                    }
                }
            }
            if (empty($this->times_in($combined))) {
                continue;
            }
            $date = $this->date_from_line($combined, $bulletin_date);
            if ($date === '') {
                $date = $this->date_for_keyword_weekday($combined, $bulletin_date);
            }
            if ($date !== '' && ($date < $week_start || $date > $week_end)) {
                continue;
            }
            $title = 'Prayer';
            if (preg_match('/reconciliation|confession/i', $combined)) {
                $title = 'Reconciliation';
            } elseif (stripos($combined, 'rosary') !== false) {
                $title = 'Rosary';
            } elseif (stripos($combined, 'adoration') !== false) {
                $title = 'Adoration';
            }
            $item = $this->calendar_item_from_line($combined, $date, $title);
            $item['title'] = $title;
            $devotions[] = $item;
            foreach (array_keys($used) as $used_index) {
                $devotion_indexes[$used_index] = true;
            }
            $sources[] = $combined;
        }

        // Remaining dated/timed lines in the left-column calendar become parish events.
        $current_date = '';
        foreach ($lines as $index => $line) {
            $date = $this->date_from_line($line, $bulletin_date);
            if ($date !== '') {
                $current_date = $date;
            }
            if (isset($mass_indexes[$index]) || isset($devotion_indexes[$index])) {
                continue;
            }
            if ($current_date === '' || $current_date < $week_start || $current_date > $week_end) {
                continue;
            }
            if (empty($this->times_in($line))) {
                continue;
            }
            if (preg_match('/mass\s*(?:&|and)\s*adoration\s*schedule/i', $line)) {
                continue;
            }
            if (preg_match('/reconciliation|confession|rosary|adoration/i', $line)) {
                continue;
            }
            if ($this->is_probable_contact_or_office_line($line)) {
                continue;
            }
            $item = $this->calendar_item_from_line($line, $current_date, '');
            if ($item['title'] === '' && $item['description'] === '') {
                continue;
            }
            // Do not duplicate a Mass line that was split by PDF extraction.
            if (stripos($line, 'mass') !== false && $mass_heading !== null && $index >= $mass_heading && $index <= $mass_heading + 32) {
                continue;
            }
            $events[] = $item;
            $sources[] = $line;
        }

        foreach ($lines as $line) {
            if (stripos($line, 'livestream') !== false || stripos($line, 'live stream') !== false || stripos($line, 'facebook live') !== false || stripos($line, 'youtube') !== false) {
                if (preg_match('/mass|rosary|worship/i', $line)) {
                    $livestream[] = array('description' => sanitize_text_field($line));
                    $sources[] = $line;
                }
            }
        }

        $masses = $this->dedupe_calendar_rows($masses);
        $devotions = $this->dedupe_calendar_rows($devotions);
        $events = $this->dedupe_calendar_rows($events);

        usort($masses, array($this, 'calendar_sort'));
        usort($devotions, array($this, 'calendar_sort'));
        usort($events, array($this, 'calendar_sort'));

        return array(
            'masses' => $masses,
            'devotions' => $devotions,
            'events' => $events,
            'livestream' => array_values(array_unique($livestream, SORT_REGULAR)),
            '_sources' => $sources,
        );
    }

    private function calendar_item_from_line($line, $date, $default_title)
    {
        $original = sanitize_text_field($line);
        $time = $this->time_expression($original);
        $location = $this->location_from_line($original);
        $title = $original;

        $title = preg_replace('/^(?:Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?)[\.,]?\s*/i', '', $title);
        $title = preg_replace('/^(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)[\.,]?\s+\d{1,2}(?:st|nd|rd|th)?[\.,]?\s*/i', '', $title);
        if ($time !== '') {
            $title = str_ireplace($time, '', $title);
        }
        $title = preg_replace('/\b(?:SP|SM)\s*:\s*/i', '', $title);
        $title = preg_replace('/\bSt\.?\s+Peter(?:\s+the\s+Apostle)?\b\s*[:\-]?/i', '', $title);
        $title = preg_replace('/\bSt\.?\s+Mary(?:[’\']s)?(?:\s+Mission)?\b\s*[:\-]?/i', '', $title);
        $title = trim($title, " \t\n\r\0\x0B-–—:;,.");
        if ($default_title !== '' && ($title === '' || strcasecmp($title, $location) === 0)) {
            $title = $default_title;
        }

        return array(
            'date' => $this->valid_date($date) ? $date : '',
            'time' => $time,
            'location' => $location,
            'title' => sanitize_text_field($title),
            'description' => $original,
        );
    }

    private function date_from_line($line, $bulletin_date)
    {
        $line = (string) $line;
        $base = DateTimeImmutable::createFromFormat('!Y-m-d', $bulletin_date);
        if (! $base) {
            return '';
        }

        $month_pattern = '(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';
        if (preg_match('/' . $month_pattern . '\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b/i', $line, $m)) {
            $month = $this->month_number($m[1]);
            $day = (int) $m[2];
            $year = (int) $base->format('Y');
            $candidate = DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-' . $day);
            if ($candidate && $candidate < $base->modify('-45 days')) {
                $candidate = $candidate->modify('+1 year');
            } elseif ($candidate && $candidate > $base->modify('+320 days')) {
                $candidate = $candidate->modify('-1 year');
            }
            return $candidate ? $candidate->format('Y-m-d') : '';
        }

        if (preg_match('/\b(Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?)\b/i', $line, $m)) {
            $target = $this->weekday_number($m[1]);
            return $this->next_weekday_date($bulletin_date, $target);
        }

        return '';
    }

    private function date_for_keyword_weekday($line, $bulletin_date)
    {
        if (preg_match('/\b(Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?)\b/i', (string) $line, $m)) {
            return $this->next_weekday_date($bulletin_date, $this->weekday_number($m[1]));
        }
        return '';
    }

    private function next_weekday_date($base_date, $target_weekday)
    {
        $base = DateTimeImmutable::createFromFormat('!Y-m-d', $base_date);
        if (! $base) {
            return '';
        }
        $base_weekday = (int) $base->format('N');
        $delta = ($target_weekday - $base_weekday + 7) % 7;
        if ($delta === 0) {
            $delta = 7;
        }
        return $base->modify('+' . $delta . ' days')->format('Y-m-d');
    }

    private function weekday_number($name)
    {
        $key = strtolower(substr(trim($name), 0, 3));
        $map = array('mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7);
        return isset($map[$key]) ? $map[$key] : 7;
    }

    private function month_number($name)
    {
        $key = strtolower(substr(trim($name), 0, 3));
        $map = array('jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12);
        return isset($map[$key]) ? $map[$key] : 1;
    }

    private function location_from_line($line)
    {
        if (preg_match('/\bSM\s*:/i', $line) || stripos($line, 'st. mary') !== false || stripos($line, 'st mary') !== false || stripos($line, 'two inlets') !== false) {
            return 'St. Mary’s';
        }
        if (preg_match('/\bSP\s*:/i', $line) || stripos($line, 'st. peter') !== false || stripos($line, 'st peter') !== false || stripos($line, 'park rapids') !== false) {
            return 'St. Peter';
        }
        return '';
    }

    private function time_expression($text)
    {
        $pattern = '/\b(?:0?[1-9]|1[0-2])(?::[0-5][0-9])?\s*(?:AM|PM)\b(?:\s*(?:-|–|—|to|&|and)\s*(?:0?[1-9]|1[0-2])(?::[0-5][0-9])?\s*(?:AM|PM)\b)?/i';
        if (preg_match($pattern, (string) $text, $m)) {
            return preg_replace('/\s+/', ' ', strtoupper(trim($m[0])));
        }
        return '';
    }

    private function times_in($text)
    {
        preg_match_all('/\b(?:0?[1-9]|1[0-2])(?::[0-5][0-9])?\s*(?:AM|PM)\b/i', (string) $text, $matches);
        $times = array();
        foreach ($matches[0] as $time) {
            $time = preg_replace('/\s+/', ' ', strtoupper(trim($time)));
            if (! in_array($time, $times, true)) {
                $times[] = $time;
            }
        }
        return $times;
    }

    private function compact_schedule_line($line, array $times)
    {
        $line = sanitize_text_field($line);
        if (count($times) > 0 && strlen($line) <= 120) {
            return $line;
        }
        return implode(' & ', $times);
    }

    private function looks_like_section_heading($line)
    {
        $line = trim((string) $line);
        if (strlen($line) < 4 || strlen($line) > 80) {
            return false;
        }
        $letters = preg_replace('/[^A-Za-z]/', '', $line);
        return $letters !== '' && strtoupper($letters) === $letters;
    }

    private function looks_like_event_line($line)
    {
        return preg_match('/\b(?:meeting|practice|breakfast|bible study|council|quilting|quilt|choir|knights|civil air patrol|class|formation|rehearsal|group)\b/i', (string) $line) === 1;
    }

    private function is_probable_contact_or_office_line($line)
    {
        return preg_match('/\b(?:office hours?|phone|fax|email|www\.|https?:|bulletin deadline|deadline|address)\b/i', (string) $line) === 1;
    }

    private function dedupe_calendar_rows(array $rows)
    {
        $seen = array();
        $result = array();
        foreach ($rows as $row) {
            $key = strtolower(implode('|', array(
                isset($row['date']) ? $row['date'] : '',
                isset($row['time']) ? $row['time'] : '',
                isset($row['location']) ? $row['location'] : '',
                isset($row['title']) ? $row['title'] : '',
            )));
            if ($key === '|||') {
                continue;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $row;
        }
        return $result;
    }

    private function calendar_sort($a, $b)
    {
        if (class_exists('CBP_Schedule_V30')) {
            return CBP_Schedule_V30::compare_rows($a, $b);
        }
        $ak = (isset($a['date']) ? $a['date'] : '') . ' ' . (isset($a['time']) ? $a['time'] : '');
        $bk = (isset($b['date']) ? $b['date'] : '') . ' ' . (isset($b['time']) ? $b['time'] : '');
        return strcmp($ak, $bk);
    }

    private function sanitize_calendar_rows($rows, $week_start, $week_end)
    {
        $clean = array();
        if (! is_array($rows)) {
            return $clean;
        }
        foreach ($rows as $row) {
            if (! is_array($row) || ! empty($row['delete'])) {
                continue;
            }
            $date = isset($row['date']) ? sanitize_text_field($row['date']) : '';
            $time = isset($row['time']) ? sanitize_text_field($row['time']) : '';
            $location = isset($row['location']) ? sanitize_text_field($row['location']) : '';
            $title = isset($row['title']) ? sanitize_text_field($row['title']) : '';
            $description = isset($row['description']) ? sanitize_text_field($row['description']) : '';
            if ($date === '' && $time === '' && $location === '' && $title === '' && $description === '') {
                continue;
            }
            if ($date !== '' && (! $this->valid_date($date) || $date < $week_start || $date > $week_end)) {
                continue;
            }
            $clean[] = array(
                'date' => $date,
                'time' => $time,
                'location' => $location,
                'title' => $title,
                'description' => $description,
            );
        }
        usort($clean, array($this, 'calendar_sort'));
        return $clean;
    }

    private function sanitize_livestream_rows($rows)
    {
        $clean = array();
        if (! is_array($rows)) {
            return $clean;
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $description = isset($row['description']) ? sanitize_text_field($row['description']) : '';
            if ($description !== '') {
                $clean[] = array('description' => $description);
            }
        }
        return $clean;
    }

    private function fields()
    {
        return array(
            'st_peter_saturday' => __('St. Peter Saturday Mass', 'church-bulletin-publisher'),
            'st_peter_sunday' => __('St. Peter Sunday Mass', 'church-bulletin-publisher'),
            'st_mary_sunday' => __('St. Mary’s Sunday Mass', 'church-bulletin-publisher'),
            'reconciliation' => __('St. Peter Reconciliation', 'church-bulletin-publisher'),
            'adoration' => __('St. Peter Adoration', 'church-bulletin-publisher'),
        );
    }

    private function preview_key()
    {
        return 'cbp_preview_' . get_current_user_id();
    }

    private function review_key()
    {
        return 'cbp_schedule_review_' . get_current_user_id();
    }

    private function authorize()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to update the parish schedule.', 'church-bulletin-publisher'), esc_html__('Access denied', 'church-bulletin-publisher'), array('response' => 403));
        }
    }

    private function valid_date($date)
    {
        if (! is_string($date) || $date === '') {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private function format_date($date)
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed ? $parsed->format('F j, Y') : $date;
    }

    private function format_date_short($date)
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed ? $parsed->format('M j') : $date;
    }

    private function format_date_day($date)
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed ? $parsed->format('l, F j') : $date;
    }

    private function redirect($type, $message)
    {
        $url = add_query_arg(array(
            'page' => 'church-bulletin-publisher',
            'cbp_notice' => sanitize_key($type),
            'cbp_message' => $message,
        ), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }
}
