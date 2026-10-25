<?php
/**
 * Design-only featured promotions carousel for staging Home (#13).
 *
 * Sample posters come from existing Media Library attachments. There is no
 * event scheduling or staff-admin UI in this phase. Do not migrate demo
 * entries into real published promotions.
 */
final class CSC_Promotions_Preview {
    private const HOME_ID = 3498;

    private static function is_staging_home() {
        return !is_admin() &&
            CSC_Controls::site_identity() !== false &&
            get_template() === 'kadence' &&
            is_page(self::HOME_ID);
    }

    public static function register() {
        add_shortcode('parish_promotions_preview', array(__CLASS__, 'shortcode'));
    }

    public static function enqueue() {
        if (!self::is_staging_home()) { return; }
        $base = plugin_dir_url(dirname(__FILE__) . '/../church-site-controls.php');
        wp_enqueue_style('csc-promotions-preview', $base . 'assets/promotions-preview.css', array(), CSC_Controls::VERSION);
        wp_enqueue_script('csc-promotions-preview', $base . 'assets/promotions-preview.js', array(), CSC_Controls::VERSION, true);
    }

    private static function samples() {
        // Known historical media assets; intentionally do not invent event links.
        $samples = array(
            array('attachment_id' => 3487, 'title' => 'Alpha Fall 2026'),
            array('attachment_id' => 3449, 'title' => 'The Bible Timeline'),
            array('attachment_id' => 3437, 'title' => '2026 Parish Rummage Sale'),
        );
        return array_values(array_filter($samples, static function ($item) {
            return wp_attachment_is_image($item['attachment_id']);
        }));
    }

    public static function shortcode() {
        if (!self::is_staging_home()) { return ''; }
        // The approved sample carousel remains until staff explicitly switch
        // to managed promotions. In managed mode a cache-safe public feed
        // populates this shell and rechecks local display dates on every visit.
        if ((string) get_option(CSC_Parish_Promotions::MODE_OPTION, '0') === '1') {
            return '<section class="csc-promotions-preview" aria-labelledby="csc-promo-heading" aria-roledescription="carousel" data-mode="managed" data-feed-url="' .
                esc_url(rest_url('parish-promotions/v1/carousel')) . '" data-interval="7000">' .
                '<div class="csc-promo-intro"><p class="csc-promo-eyebrow">Parish life</p><h2 id="csc-promo-heading">Featured at Our Parishes</h2></div>' .
                '<p class="csc-promo-loading">Loading current promotions…</p><div class="csc-promo-stage"></div></section>';
        }
        // Do not show historical demo posters on the live production homepage.
        // Production shows staff-managed promotions only when explicitly enabled.
        if (!CSC_Controls::is_staging_site()) { return ''; }
        $items = self::samples();
        if (!$items) { return ''; }
        $count = count($items);
        ob_start();
        ?>
        <section class="csc-promotions-preview" aria-labelledby="csc-promo-heading"
                 aria-roledescription="carousel" data-interval="7000"
                 data-mode="demo" data-feed-url="<?php echo esc_url(rest_url('parish-promotions/v1/carousel')); ?>">
          <div class="csc-promo-intro">
            <p class="csc-promo-eyebrow">Parish life</p>
            <h2 id="csc-promo-heading">Featured at Our Parishes</h2>
            <p class="csc-promo-demo-note">Design preview using archived parish posters — these are not current event announcements or active sign-ups.</p>
          </div>
          <div class="csc-promo-stage" aria-label="Promotional posters">
            <?php foreach ($items as $index => $item) : ?>
              <article class="csc-promo-slide <?php echo $index === 0 ? 'is-current' : 'is-off'; ?>"
                       data-index="<?php echo esc_attr((string) $index); ?>"
                       data-title="<?php echo esc_attr($item['title']); ?>"
                       aria-roledescription="slide"
                       aria-label="<?php echo esc_attr($item['title'] . ', ' . ($index + 1) . ' of ' . $count); ?>"
                       <?php if ($index !== 0) : ?>aria-hidden="true" inert<?php endif; ?>>
                <div class="csc-promo-artwork">
                  <?php echo wp_get_attachment_image($item['attachment_id'], 'large', false, array(
                      'class' => 'csc-promo-poster',
                      'alt' => 'Archived ' . $item['title'] . ' promotional poster',
                      'loading' => $index === 0 ? 'eager' : 'lazy',
                      'sizes' => '(max-width: 760px) 92vw, 650px',
                  )); ?>
                </div>
                <div class="csc-promo-actions">
                  <span class="csc-promo-action is-demo" aria-disabled="true">More Information</span>
                  <span class="csc-promo-action is-demo is-primary" aria-disabled="true">Sign Up</span>
                </div>
                <button type="button" class="csc-promo-select"
                        aria-label="<?php echo esc_attr('Show ' . $item['title'] . ' in the center'); ?>"
                        tabindex="-1"></button>
              </article>
            <?php endforeach; ?>
          </div>
          <?php if ($count > 1) : ?>
            <div class="csc-promo-controls" aria-label="Slideshow controls">
              <button type="button" class="csc-promo-prev" aria-label="Previous promotion">&#8592;</button>
              <div class="csc-promo-dots" role="group" aria-label="Choose promotion">
                <?php foreach ($items as $index => $item) : ?>
                  <button type="button" class="csc-promo-dot"
                          data-index="<?php echo esc_attr((string) $index); ?>"
                          aria-label="<?php echo esc_attr('Show ' . $item['title']); ?>"
                          aria-current="<?php echo $index === 0 ? 'true' : 'false'; ?>"></button>
                <?php endforeach; ?>
              </div>
              <button type="button" class="csc-promo-next" aria-label="Next promotion">&#8594;</button>
              <button type="button" class="csc-promo-pause" aria-pressed="false">Pause slideshow</button>
            </div>
            <p class="csc-promo-status screen-reader-text" aria-live="polite" aria-atomic="true">
              <?php echo esc_html($items[0]['title'] . ', 1 of ' . $count); ?>
            </p>
          <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }
}
add_action('init', array('CSC_Promotions_Preview', 'register'));
add_action('wp_enqueue_scripts', array('CSC_Promotions_Preview', 'enqueue'), 50);
