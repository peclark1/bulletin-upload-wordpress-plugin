<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Visual-only prototype for a document-like weekly schedule editor.
 *
 * This intentionally does not save data, update the pending review, generate
 * Word content, or publish anything. It exists solely to test the interaction
 * model with parish staff before we commit to the production data model.
 */
final class CBP_Weekly_Editor_Prototype
{
    private static $instance;
    private $hook_suffix = '';

    public static function instance()
    {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', array($this, 'admin_menu'), 30);
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
    }

    public function admin_menu()
    {
        $this->hook_suffix = (string) add_submenu_page(
            'church-bulletin-publisher',
            __('Weekly Schedule Prototype', 'church-bulletin-publisher'),
            __('Weekly Schedule Prototype', 'church-bulletin-publisher'),
            'manage_options',
            'cbp-weekly-editor-prototype',
            array($this, 'render_page')
        );
    }

    public function admin_assets($hook)
    {
        if ($hook !== $this->hook_suffix) {
            return;
        }

        wp_enqueue_style(
            'cbp-weekly-editor-prototype',
            CBP_URL . 'assets/weekly-editor-prototype.css',
            array(),
            CBP_VERSION
        );
        wp_enqueue_script(
            'cbp-weekly-editor-prototype',
            CBP_URL . 'assets/weekly-editor-prototype.js',
            array(),
            CBP_VERSION,
            true
        );

        wp_localize_script(
            'cbp-weekly-editor-prototype',
            'cbpWeeklyPrototype',
            array(
                'weekLabel' => 'October 12–18, 2026',
                'weekStart' => '2026-10-12',
                'days' => $this->days(),
                'items' => $this->items(),
                'locations' => array(
                    'St. Peter',
                    'St. Mary’s',
                    'Heritage Living Center',
                    'Other / no location',
                ),
                'types' => array(
                    'Mass',
                    'No Mass',
                    'Liturgy of the Word',
                    'Rosary',
                    'Adoration',
                    'Reconciliation',
                    'Parish Event',
                    'Cancelled Event',
                ),
            )
        );
    }

    public function render_page()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this prototype.', 'church-bulletin-publisher'));
        }
        ?>
        <div class="wrap cbp-weekly-prototype-wrap">
            <div class="cbp-proto-title-row">
                <div>
                    <h1><?php esc_html_e('Weekly Bulletin Schedule', 'church-bulletin-publisher'); ?></h1>
                    <p class="description"><?php esc_html_e('Visual prototype — designed to feel like editing the bulletin, while keeping the schedule structured.', 'church-bulletin-publisher'); ?></p>
                </div>
                <span class="cbp-proto-badge"><?php esc_html_e('Prototype only', 'church-bulletin-publisher'); ?></span>
            </div>

            <div class="notice notice-warning inline cbp-proto-notice">
                <p><strong><?php esc_html_e('Nothing on this page is saved or published.', 'church-bulletin-publisher'); ?></strong>
                <?php esc_html_e('Try editing, adding, cancelling, and previewing entries. Reloading or leaving the page resets everything.', 'church-bulletin-publisher'); ?></p>
            </div>

            <div class="cbp-proto-toolbar" role="toolbar" aria-label="<?php esc_attr_e('Weekly schedule controls', 'church-bulletin-publisher'); ?>">
                <button type="button" class="button cbp-proto-week-nav" disabled aria-disabled="true">← <?php esc_html_e('Previous Week', 'church-bulletin-publisher'); ?></button>
                <div class="cbp-proto-week-label" id="cbp-proto-week-label"></div>
                <button type="button" class="button cbp-proto-week-nav" disabled aria-disabled="true"><?php esc_html_e('Next Week', 'church-bulletin-publisher'); ?> →</button>
            </div>

            <div class="cbp-proto-actions">
                <button type="button" class="button button-primary" id="cbp-proto-add">+ <?php esc_html_e('Add Item', 'church-bulletin-publisher'); ?></button>
                <button type="button" class="button" id="cbp-proto-preview"><?php esc_html_e('Bulletin Preview', 'church-bulletin-publisher'); ?></button>
                <button type="button" class="button" id="cbp-proto-reset"><?php esc_html_e('Reset Changes', 'church-bulletin-publisher'); ?></button>
                <span class="cbp-proto-spacer"></span>
                <button type="button" class="button" disabled aria-disabled="true" title="<?php esc_attr_e('Planned for the production version.', 'church-bulletin-publisher'); ?>"><?php esc_html_e('Copy for Word', 'church-bulletin-publisher'); ?></button>
                <button type="button" class="button button-primary" disabled aria-disabled="true" title="<?php esc_attr_e('Planned for the production version.', 'church-bulletin-publisher'); ?>"><?php esc_html_e('Finish This Week', 'church-bulletin-publisher'); ?></button>
            </div>

            <div class="cbp-proto-help" id="cbp-proto-help">
                <?php esc_html_e('Click any bulletin line to edit it. Hover between days to add another item.', 'church-bulletin-publisher'); ?>
            </div>

            <div class="cbp-proto-workspace">
                <main class="cbp-proto-paper" aria-label="<?php esc_attr_e('Bulletin schedule preview', 'church-bulletin-publisher'); ?>">
                    <div class="cbp-proto-paper-heading">
                        <div class="cbp-proto-parish-name"><?php esc_html_e('St. Peter the Apostle & St. Mary’s Two Inlets', 'church-bulletin-publisher'); ?></div>
                        <div class="cbp-proto-section-title"><?php esc_html_e('This Week at the Parishes', 'church-bulletin-publisher'); ?></div>
                        <div class="cbp-proto-week-subtitle" id="cbp-proto-paper-week"></div>
                    </div>
                    <div id="cbp-proto-document"></div>
                </main>

                <aside class="cbp-proto-side-note">
                    <h2><?php esc_html_e('What we are testing', 'church-bulletin-publisher'); ?></h2>
                    <p><?php esc_html_e('Does this feel more like editing a familiar bulletin than filling out a database?', 'church-bulletin-publisher'); ?></p>
                    <ul>
                        <li><?php esc_html_e('Most of last week is already present.', 'church-bulletin-publisher'); ?></li>
                        <li><?php esc_html_e('The schedule stays in chronological order automatically.', 'church-bulletin-publisher'); ?></li>
                        <li><?php esc_html_e('Location, time, event type, and intentions remain separate structured fields.', 'church-bulletin-publisher'); ?></li>
                        <li><?php esc_html_e('Preview hides all editing controls.', 'church-bulletin-publisher'); ?></li>
                    </ul>
                </aside>
            </div>

            <div class="cbp-proto-dialog-backdrop" id="cbp-proto-dialog-backdrop" hidden></div>
            <section class="cbp-proto-dialog" id="cbp-proto-dialog" hidden aria-modal="true" role="dialog" aria-labelledby="cbp-proto-dialog-title">
                <div class="cbp-proto-dialog-head">
                    <h2 id="cbp-proto-dialog-title"><?php esc_html_e('Edit bulletin item', 'church-bulletin-publisher'); ?></h2>
                    <button type="button" class="cbp-proto-dialog-close" id="cbp-proto-close" aria-label="<?php esc_attr_e('Close editor', 'church-bulletin-publisher'); ?>">×</button>
                </div>
                <div class="cbp-proto-form-grid">
                    <label>
                        <span><?php esc_html_e('Day', 'church-bulletin-publisher'); ?></span>
                        <select id="cbp-proto-field-date"></select>
                    </label>
                    <label>
                        <span><?php esc_html_e('Time', 'church-bulletin-publisher'); ?></span>
                        <input type="text" id="cbp-proto-field-time" placeholder="e.g. 6:00 PM, 4:00–4:30 PM, After Mass">
                    </label>
                    <label>
                        <span><?php esc_html_e('Location', 'church-bulletin-publisher'); ?></span>
                        <select id="cbp-proto-field-location"></select>
                    </label>
                    <label>
                        <span><?php esc_html_e('Type', 'church-bulletin-publisher'); ?></span>
                        <select id="cbp-proto-field-type"></select>
                    </label>
                    <label class="cbp-proto-form-wide">
                        <span id="cbp-proto-detail-label"><?php esc_html_e('Name, intention, or note', 'church-bulletin-publisher'); ?></span>
                        <input type="text" id="cbp-proto-field-detail" placeholder="e.g. † Tom Spahn or Bible Study">
                    </label>
                </div>
                <p class="cbp-proto-live-example"><strong><?php esc_html_e('Bulletin line:', 'church-bulletin-publisher'); ?></strong> <span id="cbp-proto-live-line"></span></p>
                <div class="cbp-proto-dialog-actions">
                    <button type="button" class="button cbp-proto-delete" id="cbp-proto-delete"><?php esc_html_e('Delete', 'church-bulletin-publisher'); ?></button>
                    <span class="cbp-proto-spacer"></span>
                    <button type="button" class="button" id="cbp-proto-cancel"><?php esc_html_e('Cancel', 'church-bulletin-publisher'); ?></button>
                    <button type="button" class="button button-primary" id="cbp-proto-done"><?php esc_html_e('Done', 'church-bulletin-publisher'); ?></button>
                </div>
            </section>
        </div>
        <?php
    }

    private function days()
    {
        return array(
            array('date' => '2026-10-12', 'label' => 'Monday, October 12'),
            array('date' => '2026-10-13', 'label' => 'Tuesday, October 13'),
            array('date' => '2026-10-14', 'label' => 'Wednesday, October 14'),
            array('date' => '2026-10-15', 'label' => 'Thursday, October 15'),
            array('date' => '2026-10-16', 'label' => 'Friday, October 16'),
            array('date' => '2026-10-17', 'label' => 'Saturday, October 17'),
            array('date' => '2026-10-18', 'label' => 'Sunday, October 18'),
        );
    }

    private function items()
    {
        return array(
            array('date'=>'2026-10-12','time'=>'10:00 AM','location'=>'St. Peter','type'=>'Parish Event','detail'=>'Bible Study'),
            array('date'=>'2026-10-12','time'=>'6:00 PM–8:30 PM','location'=>'St. Peter','type'=>'Parish Event','detail'=>'Civil Air Patrol Meeting'),
            array('date'=>'2026-10-12','time'=>'6:30 PM','location'=>'St. Peter','type'=>'Parish Event','detail'=>'Bible Study'),

            array('date'=>'2026-10-13','time'=>'','location'=>'Other / no location','type'=>'Cancelled Event','detail'=>'NO Ladies lunch'),
            array('date'=>'2026-10-13','time'=>'','location'=>'St. Peter','type'=>'No Mass','detail'=>''),
            array('date'=>'2026-10-13','time'=>'6:00 PM','location'=>'St. Peter','type'=>'Parish Event','detail'=>'Choir practice'),
            array('date'=>'2026-10-13','time'=>'6:30 PM','location'=>'St. Peter','type'=>'Parish Event','detail'=>'St. Vincent de Paul Information Meeting'),
            array('date'=>'2026-10-13','time'=>'7:00 PM','location'=>'St. Peter','type'=>'Parish Event','detail'=>'Young Adult Catechism Study'),

            array('date'=>'2026-10-14','time'=>'10:00 AM','location'=>'Heritage Living Center','type'=>'Liturgy of the Word','detail'=>''),
            array('date'=>'2026-10-14','time'=>'1:00 PM','location'=>'St. Peter','type'=>'Parish Event','detail'=>'Faith Formation Grades 1–6'),
            array('date'=>'2026-10-14','time'=>'5:30 PM–8:00 PM','location'=>'St. Peter','type'=>'Adoration','detail'=>''),
            array('date'=>'2026-10-14','time'=>'6:00 PM','location'=>'St. Peter','type'=>'Parish Event','detail'=>'Alpha'),
            array('date'=>'2026-10-14','time'=>'','location'=>'St. Peter','type'=>'Cancelled Event','detail'=>'NO Evening Faith Formation Grades 7–11'),

            array('date'=>'2026-10-15','time'=>'','location'=>'St. Mary’s','type'=>'No Mass','detail'=>''),
            array('date'=>'2026-10-15','time'=>'6:00 AM–5:00 PM','location'=>'St. Peter','type'=>'Adoration','detail'=>''),
            array('date'=>'2026-10-15','time'=>'9:00 AM','location'=>'St. Peter','type'=>'Parish Event','detail'=>'Men’s Bible Study'),
            array('date'=>'2026-10-15','time'=>'6:00 PM','location'=>'Other / no location','type'=>'Parish Event','detail'=>'Men’s Burger & Beer — call office before 4:00 pm for location'),

            array('date'=>'2026-10-16','time'=>'','location'=>'St. Peter','type'=>'No Mass','detail'=>''),

            array('date'=>'2026-10-17','time'=>'4:00 PM–4:30 PM','location'=>'St. Peter','type'=>'Reconciliation','detail'=>''),
            array('date'=>'2026-10-17','time'=>'4:30 PM','location'=>'St. Peter','type'=>'Rosary','detail'=>''),
            array('date'=>'2026-10-17','time'=>'5:00 PM','location'=>'St. Peter','type'=>'Mass','detail'=>'† Tom Spahn'),

            array('date'=>'2026-10-18','time'=>'8:30 AM','location'=>'St. Peter','type'=>'Rosary','detail'=>''),
            array('date'=>'2026-10-18','time'=>'9:00 AM','location'=>'St. Peter','type'=>'Mass','detail'=>'† Tom Wermerskirchen'),
            array('date'=>'2026-10-18','time'=>'','location'=>'St. Peter','type'=>'Parish Event','detail'=>'Coffee & Rolls Team 3'),
            array('date'=>'2026-10-18','time'=>'10:30 AM','location'=>'St. Mary’s','type'=>'Rosary','detail'=>''),
            array('date'=>'2026-10-18','time'=>'11:00 AM','location'=>'St. Mary’s','type'=>'Mass','detail'=>'† Kimberly Wettels'),
            array('date'=>'2026-10-18','time'=>'After Mass','location'=>'St. Mary’s','type'=>'Parish Event','detail'=>'Soup & Sandwich'),
        );
    }
}
