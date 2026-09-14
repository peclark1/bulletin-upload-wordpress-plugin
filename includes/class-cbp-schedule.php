<?php

if (! defined('ABSPATH')) {
    exit;
}

final class CBP_Schedule
{
    const OPTION = 'cbp_mass_schedule';
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
    }

    public static function activate()
    {
        if (! get_option(self::OPTION)) {
            add_option(self::OPTION, self::defaults(), '', false);
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
        );
    }

    public function render_review_panel()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $preview = get_transient($this->preview_key());
        if (! is_array($preview) || empty($preview['path'])) {
            return;
        }

        $review = get_transient($this->review_key());
        $current = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        $diagnostic = $this->diagnostic();
        ?>
        <section class="cbp-card cbp-schedule-review" style="margin-top:20px;">
            <h2><?php esc_html_e('4. Review schedule changes', 'church-bulletin-publisher'); ?></h2>
            <p><?php esc_html_e('Extract Mass, Reconciliation, and Adoration times from the reviewed bulletin. Nothing changes on the website until a person approves the proposed values.', 'church-bulletin-publisher'); ?></p>

            <?php if (is_wp_error($diagnostic)) : ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html($diagnostic->get_error_message()); ?></p></div>
            <?php else : ?>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="margin-bottom:18px;">
                    <input type="hidden" name="action" value="cbp_extract_schedule">
                    <?php wp_nonce_field('cbp_extract_schedule'); ?>
                    <?php submit_button(__('Extract Schedule from Preview', 'church-bulletin-publisher'), 'secondary', 'submit', false); ?>
                </form>
            <?php endif; ?>

            <?php if (is_array($review) && ! empty($review['candidates'])) : ?>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="cbp_apply_schedule">
                    <?php wp_nonce_field('cbp_apply_schedule'); ?>
                    <input type="hidden" name="bulletin_date" value="<?php echo esc_attr(isset($review['bulletin_date']) ? $review['bulletin_date'] : ''); ?>">

                    <table class="widefat striped" style="max-width:1000px; margin:12px 0 16px;">
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

                    <?php if (! empty($review['source_lines'])) : ?>
                        <details style="margin:12px 0 18px;">
                            <summary><strong><?php esc_html_e('Show bulletin lines used for extraction', 'church-bulletin-publisher'); ?></strong></summary>
                            <pre style="white-space:pre-wrap; background:#f6f7f7; padding:12px; max-height:300px; overflow:auto;"><?php echo esc_html(implode("\n", $review['source_lines'])); ?></pre>
                        </details>
                    <?php endif; ?>

                    <label style="display:block; margin:12px 0; font-weight:600;">
                        <input type="checkbox" name="confirm_schedule" value="1" required>
                        <?php esc_html_e('I reviewed these values and approve them as the website schedule.', 'church-bulletin-publisher'); ?>
                    </label>
                    <?php submit_button(__('Approve Schedule Update', 'church-bulletin-publisher'), 'primary', 'submit', false); ?>
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

    public function extract_schedule()
    {
        $this->authorize();
        check_admin_referer('cbp_extract_schedule');

        $preview = get_transient($this->preview_key());
        if (! is_array($preview) || empty($preview['path']) || ! is_readable($preview['path'])) {
            $this->redirect('error', __('The private preview is missing or expired. Create it again.', 'church-bulletin-publisher'));
        }

        $text = $this->pdf_text($preview['path']);
        if (is_wp_error($text)) {
            set_transient($this->review_key(), array('error' => $text->get_error_message()), self::REVIEW_TTL);
            $this->redirect('error', $text->get_error_message());
        }

        $parsed = $this->parse_schedule($text);
        $parsed['bulletin_date'] = isset($preview['date']) ? $preview['date'] : '';
        set_transient($this->review_key(), $parsed, self::REVIEW_TTL);
        $this->redirect('success', __('Schedule extracted. Review every proposed value before approving it.', 'church-bulletin-publisher'));
    }

    public function apply_schedule()
    {
        $this->authorize();
        check_admin_referer('cbp_apply_schedule');

        if (empty($_POST['confirm_schedule']) || sanitize_text_field(wp_unslash($_POST['confirm_schedule'])) !== '1') {
            $this->redirect('error', __('Schedule approval was not confirmed.', 'church-bulletin-publisher'));
        }

        $incoming = isset($_POST['schedule']) && is_array($_POST['schedule']) ? wp_unslash($_POST['schedule']) : array();
        $current = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        foreach ($this->fields() as $key => $label) {
            if (! array_key_exists($key, $incoming)) {
                continue;
            }
            $value = sanitize_text_field($incoming[$key]);
            if ($value !== '') {
                $current[$key] = $value;
            }
        }

        $current['updated_utc'] = gmdate('c');
        $current['source_bulletin_date'] = isset($_POST['bulletin_date']) ? sanitize_text_field(wp_unslash($_POST['bulletin_date'])) : '';
        update_option(self::OPTION, $current, false);
        delete_transient($this->review_key());

        do_action('cbp_schedule_updated', $current);
        $this->redirect('success', __('Approved schedule saved. Pages using the church_mass_schedule shortcode now show the new values.', 'church-bulletin-publisher'));
    }

    public function schedule_shortcode($atts)
    {
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

    private function diagnostic()
    {
        if (! function_exists('proc_open')) {
            return new WP_Error('cbp_schedule_proc_open', __('Automatic schedule extraction needs PHP proc_open(), which is disabled on this server. The review workflow remains safe, but PDF text extraction cannot run here yet.', 'church-bulletin-publisher'));
        }

        $tool = $this->find_pdftotext();
        if (! $tool) {
            return new WP_Error('cbp_schedule_pdftotext', __('Automatic schedule extraction needs the pdftotext utility. It was not found on this server. No website content has been changed.', 'church-bulletin-publisher'));
        }
        return $tool;
    }

    private function find_pdftotext()
    {
        foreach (array('/usr/bin/pdftotext', '/usr/local/bin/pdftotext') as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = trailingslashit($directory) . 'pdftotext';
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    private function pdf_text($path)
    {
        $diagnostic = $this->diagnostic();
        if (is_wp_error($diagnostic)) {
            return $diagnostic;
        }

        $command = escapeshellarg($diagnostic) . ' -layout -nopgbrk ' . escapeshellarg($path) . ' -';
        $pipes = array();
        $process = proc_open($command, array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        ), $pipes);
        if (! is_resource($process)) {
            return new WP_Error('cbp_schedule_start', __('Could not start PDF text extraction.', 'church-bulletin-publisher'));
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0 || trim((string) $stdout) === '') {
            return new WP_Error('cbp_schedule_extract', __('The bulletin PDF did not yield readable text. It may be image-only or use a PDF encoding pdftotext cannot read.', 'church-bulletin-publisher') . ' ' . trim((string) $stderr));
        }
        return (string) $stdout;
    }

    private function parse_schedule($text)
    {
        $raw_lines = preg_split('/\R/u', (string) $text);
        $lines = array();
        foreach ($raw_lines as $line) {
            $line = preg_replace('/\s+/u', ' ', trim($line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $candidates = array_fill_keys(array_keys($this->fields()), '');
        $sources = array();
        foreach ($lines as $index => $line) {
            if (! preg_match('/\b(?:0?[1-9]|1[0-2]):[0-5][0-9]\s*(?:AM|PM)\b/i', $line)) {
                continue;
            }
            $start = max(0, $index - 3);
            $context_lines = array_slice($lines, $start, min(7, count($lines) - $start));
            $context = implode(' | ', $context_lines);
            $lower = strtolower($context);
            $times = $this->times_in($line . ' ' . $context);

            if (strpos($lower, 'reconciliation') !== false || strpos($lower, 'confession') !== false) {
                if ($candidates['reconciliation'] === '') {
                    $candidates['reconciliation'] = $this->compact_schedule_line($line, $context, $times);
                    $sources[] = $context;
                }
                continue;
            }
            if (strpos($lower, 'adoration') !== false) {
                if ($candidates['adoration'] === '') {
                    $candidates['adoration'] = $this->compact_schedule_line($line, $context, $times);
                    $sources[] = $context;
                }
                continue;
            }

            $is_mary = strpos($lower, "st. mary") !== false || strpos($lower, 'st mary') !== false || strpos($lower, 'two inlets') !== false;
            $is_peter = strpos($lower, 'st. peter') !== false || strpos($lower, 'st peter') !== false || strpos($lower, 'park rapids') !== false;
            $is_saturday = strpos($lower, 'saturday') !== false || preg_match('/\bsat\.?\b/i', $context);
            $is_sunday = strpos($lower, 'sunday') !== false || preg_match('/\bsun\.?\b/i', $context);

            if ($is_mary && $is_sunday && $candidates['st_mary_sunday'] === '' && ! empty($times)) {
                $candidates['st_mary_sunday'] = $times[0];
                $sources[] = $context;
            } elseif ($is_peter && $is_saturday && $candidates['st_peter_saturday'] === '' && ! empty($times)) {
                $candidates['st_peter_saturday'] = $times[0];
                $sources[] = $context;
            } elseif ($is_peter && $is_sunday && $candidates['st_peter_sunday'] === '' && ! empty($times)) {
                $candidates['st_peter_sunday'] = $times[0];
                $sources[] = $context;
            }
        }

        $current = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        foreach ($candidates as $key => $value) {
            if ($value === '') {
                $candidates[$key] = isset($current[$key]) ? $current[$key] : '';
            }
        }

        return array(
            'candidates' => $candidates,
            'source_lines' => array_values(array_unique(array_slice($sources, 0, 20))),
        );
    }

    private function times_in($text)
    {
        preg_match_all('/\b(?:0?[1-9]|1[0-2]):[0-5][0-9]\s*(?:AM|PM)\b/i', (string) $text, $matches);
        $times = array();
        foreach ($matches[0] as $time) {
            $time = preg_replace('/\s+/', ' ', strtoupper(trim($time)));
            if (! in_array($time, $times, true)) {
                $times[] = $time;
            }
        }
        return $times;
    }

    private function compact_schedule_line($line, $context, array $times)
    {
        $line = sanitize_text_field($line);
        if (count($times) > 0 && strlen($line) <= 100) {
            return $line;
        }
        return implode(' & ', $times);
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

    private function redirect($type, $message)
    {
        $url = add_query_arg(array(
            'page' => 'church-bulletin-publisher',
            'cbp_notice' => sanitize_key($type),
            'cbp_message' => rawurlencode($message),
        ), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }
}
