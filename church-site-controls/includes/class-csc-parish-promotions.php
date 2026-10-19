<?php
/**
 * Parish Promotions: staff-owned records and date-controlled homepage feed.
 * This first release is intentionally staging-only. The existing approved
 * carousel markup/style remains owned by CSC_Promotions_Preview.
 */
final class CSC_Parish_Promotions {
    const TYPE = 'csc_promotion';
    const MODE_OPTION = 'csc_parish_promotions_live';
    const MENU_SLUG = 'parish-promotions';
    const META_PREFIX = '_csc_promo_';

    public static function on_staging() {
        return untrailingslashit(home_url()) === CSC_Controls::SITE &&
            untrailingslashit(site_url()) === CSC_Controls::SITE &&
            get_template() === 'kadence';
    }

    public static function enabled() {
        return self::on_staging() && (string) get_option(self::MODE_OPTION, '0') === '1';
    }

    public static function register_type() {
        if (!self::on_staging()) { return; }
        register_post_type(self::TYPE, array(
            'label' => 'Parish Promotions',
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => false,
            'show_in_rest' => false,
            'capability_type' => 'page',
            'map_meta_cap' => true,
            'supports' => array('title'),
        ));
    }

    private static function permission() {
        if (!self::on_staging() || !current_user_can('edit_pages')) {
            wp_die('You are not authorized to manage parish promotions on this installation.', 403);
        }
    }

    private static function meta($id, $key) {
        return (string) get_post_meta($id, self::META_PREFIX . $key, true);
    }

    private static function valid_date($date) {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) { return false; }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $dt !== false && $dt->format('Y-m-d') === $date;
    }

    public static function valid_window($start, $end) {
        return self::valid_date($start) && self::valid_date($end) && $start <= $end;
    }

    private static function url($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') { return ''; }
        // Use full links, from internal pages or external registration providers.
        $candidate = esc_url_raw($raw, array('http', 'https'));
        $scheme = strtolower((string) wp_parse_url($candidate, PHP_URL_SCHEME));
        $host = (string) wp_parse_url($candidate, PHP_URL_HOST);
        if (!$candidate || !in_array($scheme, array('http', 'https'), true) || !$host) {
            return new WP_Error('csc_invalid_link', 'Enter a complete http:// or https:// address, or leave the button empty.');
        }
        return $candidate;
    }

    private static function id_in_post($id) {
        $post = $id ? get_post($id) : null;
        return $post && $post->post_type === self::TYPE && $post->post_status !== 'trash' ? $post : null;
    }

    private static function row($post) {
        $id = $post->ID;
        return array(
            'id' => (int) $id,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'image_id' => (int) self::meta($id, 'image_id'),
            'alt' => self::meta($id, 'alt'),
            'start' => self::meta($id, 'start'),
            'end' => self::meta($id, 'end'),
            'info_url' => self::meta($id, 'info_url'),
            'info_label' => self::meta($id, 'info_label') ?: 'More Information',
            'signup_url' => self::meta($id, 'signup_url'),
            'signup_label' => self::meta($id, 'signup_label') ?: 'Sign Up',
            'sort' => (int) self::meta($id, 'sort'),
            'modified_gmt' => $post->post_modified_gmt,
        );
    }

    public static function records() {
        if (!self::on_staging()) { return array(); }
        $posts = get_posts(array(
            'post_type' => self::TYPE,
            'post_status' => array('publish', 'draft', 'pending'),
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        return array_map(array(__CLASS__, 'row'), $posts);
    }

    public static function active_records($today = null) {
        if (!self::on_staging()) { return array(); }
        if ($today === null) { $today = wp_date('Y-m-d'); } // WordPress site local day
        $active = array_values(array_filter(self::records(), static function ($row) use ($today) {
            return $row['status'] === 'publish' &&
                CSC_Parish_Promotions::valid_window($row['start'], $row['end']) &&
                $row['start'] <= $today && $today <= $row['end'] &&
                $row['image_id'] > 0 && wp_attachment_is_image($row['image_id']);
        }));
        usort($active, static function ($a, $b) {
            return ($a['sort'] <=> $b['sort']) ?: ($a['id'] <=> $b['id']);
        });
        return $active;
    }

    private static function image($id, $title, $alt) {
        $src = wp_get_attachment_image_url($id, 'large');
        if (!$src) { return null; }
        return array(
            'src' => $src,
            'srcset' => (string) wp_get_attachment_image_srcset($id, 'large'),
            'alt' => $alt !== '' ? $alt : $title,
        );
    }

    private static function public_item($row, $demo = false) {
        $image = self::image($row['image_id'], $row['title'], $row['alt']);
        if (!$image) { return null; }
        return array(
            'title' => $row['title'],
            'image' => $image,
            'info_url' => $demo ? '' : $row['info_url'],
            'info_label' => $row['info_label'],
            'signup_url' => $demo ? '' : $row['signup_url'],
            'signup_label' => $row['signup_label'],
        );
    }

    public static function sample_rows() {
        return array(
            array('title' => 'Alpha Fall 2026', 'image_id' => 3487, 'alt' => 'Archived Alpha Fall 2026 poster', 'info_label' => 'More Information', 'signup_label' => 'Sign Up'),
            array('title' => 'The Bible Timeline', 'image_id' => 3449, 'alt' => 'Archived Bible Timeline poster', 'info_label' => 'More Information', 'signup_label' => 'Sign Up'),
            array('title' => '2026 Parish Rummage Sale', 'image_id' => 3437, 'alt' => 'Archived 2026 Rummage Sale poster', 'info_label' => 'More Information', 'signup_label' => 'Sign Up'),
        );
    }

    public static function public_feed() {
        if (!self::on_staging()) { return array('mode' => 'off', 'items' => array()); }
        $demo = !self::enabled();
        $rows = $demo ? self::sample_rows() : self::active_records();
        $items = array();
        foreach ($rows as $row) {
            $item = self::public_item($row, $demo);
            if ($item) { $items[] = $item; }
        }
        return array('mode' => $demo ? 'demo' : 'managed', 'items' => $items);
    }

    public static function rest_routes() {
        if (!self::on_staging()) { return; }
        register_rest_route('parish-promotions/v1', '/carousel', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'rest_feed'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function rest_feed() {
        $response = new WP_REST_Response(self::public_feed(), 200);
        // Bypass hosting/CDN page cache so end dates take effect at midnight.
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $response;
    }

    public static function admin_menu() {
        if (!self::on_staging()) { return; }
        add_menu_page(
            'Parish Promotions', 'Parish Promotions', 'edit_pages',
            self::MENU_SLUG, array(__CLASS__, 'admin_screen'),
            'dashicons-images-alt2', 28
        );
    }

    public static function admin_assets($hook) {
        if (!self::on_staging() || $hook !== 'toplevel_page_' . self::MENU_SLUG) { return; }
        wp_enqueue_media();
        $base = plugin_dir_url(dirname(__FILE__) . '/../church-site-controls.php');
        wp_enqueue_script('csc-promo-admin', $base . 'assets/promotions-admin.js', array('jquery'), CSC_Controls::VERSION, true);
        wp_enqueue_style('csc-promo-admin', $base . 'assets/promotions-admin.css', array(), CSC_Controls::VERSION);
    }

    private static function state($row, $today) {
        if ($row['status'] !== 'publish') { return 'Draft'; }
        if (!self::valid_window($row['start'], $row['end'])) { return 'Needs dates'; }
        if ($row['end'] < $today) { return 'Expired'; }
        if ($row['start'] > $today) { return 'Scheduled'; }
        return 'Active';
    }

    public static function admin_screen() {
        self::permission();
        $edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $editing = $edit_id ? self::id_in_post($edit_id) : null;
        if ($edit_id && !$editing) { wp_die('This promotion does not exist.', 404); }
        $row = $editing ? self::row($editing) : array(
            'id' => 0, 'title' => '', 'status' => 'draft', 'image_id' => 0, 'alt' => '',
            'start' => '', 'end' => '', 'info_url' => '', 'info_label' => 'More Information',
            'signup_url' => '', 'signup_label' => 'Sign Up', 'sort' => 100,
            'modified_gmt' => '',
        );
        $base = admin_url('admin.php?page=' . self::MENU_SLUG);
        $today = wp_date('Y-m-d');
        $records = self::records();
        $notice = isset($_GET['notice']) ? sanitize_key(wp_unslash($_GET['notice'])) : '';
        $saved_id = isset($_GET['saved_id']) ? absint($_GET['saved_id']) : 0;
        $saved_post = $saved_id ? self::id_in_post($saved_id) : null;
        $pages = get_pages(array('sort_column' => 'post_title', 'sort_order' => 'asc'));
        ?>
        <div class="wrap csc-promotions-admin">
          <h1 class="wp-heading-inline">Parish Promotions</h1>
          <a href="<?php echo esc_url($base); ?>" class="page-title-action csc-promo-add-new">Add New Promotion</a>
          <hr class="wp-header-end">
          <p>Manage the featured posters above Quick Links on the homepage. Artwork appears exactly as uploaded; the buttons are displayed below it.</p>
          <?php if ($notice === 'created' && $saved_post) : ?>
            <div class="notice notice-success is-dismissible csc-promo-success" role="status">
              <p><strong><?php echo esc_html($saved_post->post_title); ?></strong> was saved as a new promotion.
                <a href="<?php echo esc_url(add_query_arg('edit', $saved_id, $base)); ?>">Edit this promotion</a>
                or keep using the empty <strong>Add a promotion</strong> form to create another.</p>
            </div>
          <?php endif; ?>
          <?php if ($notice === 'updated' && $editing) : ?>
            <div class="notice notice-success is-dismissible csc-promo-success" role="status">
              <p>Changes to <strong><?php echo esc_html($row['title']); ?></strong> saved. You are still editing this promotion.
                <a href="<?php echo esc_url($base); ?>">Add a different promotion</a>.</p>
            </div>
          <?php endif; ?>
          <?php if ($notice === 'mode') : ?><div class="notice notice-success is-dismissible"><p>Homepage promotion mode updated.</p></div><?php endif; ?>
          <div class="csc-promo-mode">
            <div>
              <strong>Homepage display:</strong>
              <?php echo self::enabled() ? '<span class="csc-promo-pill">Staff-managed promotions</span>' : '<span class="csc-promo-pill is-demo">Historical design samples</span>'; ?>
              <p><?php echo self::enabled()
                  ? 'Only published promotions within their display dates appear. If none qualify, the section is hidden.'
                  : 'Your currently approved Alpha, Bible Timeline and Rummage Sale sample carousel remains visible. Build and preview real promotions before switching.'; ?></p>
            </div>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
              <input type="hidden" name="action" value="csc_promotion_mode">
              <?php wp_nonce_field('csc_promotion_mode'); ?>
              <input type="hidden" name="enabled" value="<?php echo self::enabled() ? '0' : '1'; ?>">
              <?php submit_button(self::enabled() ? 'Show design samples' : 'Enable staff-managed promotions', 'secondary', 'submit', false); ?>
            </form>
          </div>

          <div class="csc-promo-grid">
            <section class="csc-promo-panel">
              <h2><?php echo $edit_id ? 'Edit promotion: ' . esc_html($row['title']) : 'Add a promotion'; ?></h2>
              <?php if ($edit_id) : ?>
                <p class="description csc-promo-edit-notice">You are editing an existing promotion. <strong>Save Changes updates this record</strong>; it will not create a new one. <a href="<?php echo esc_url($base); ?>">Add a new promotion instead</a>.</p>
              <?php else : ?>
                <p class="description csc-promo-edit-notice">This form creates a new promotion. After you save, it will reset so you can add the next one.</p>
              <?php endif; ?>
              <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="csc-promo-form">
                <input type="hidden" name="action" value="csc_promotion_save">
                <input type="hidden" name="promotion_id" value="<?php echo esc_attr((string) $row['id']); ?>">
                <input type="hidden" name="modified_gmt" value="<?php echo esc_attr($row['modified_gmt']); ?>">
                <?php wp_nonce_field('csc_promotion_save'); ?>
                <p><label for="csc-title"><strong>Event or promotion title *</strong></label>
                  <input required class="large-text" id="csc-title" name="title" maxlength="160"
                         value="<?php echo esc_attr($row['title']); ?>"></p>
                <div class="csc-promo-image-field">
                  <label for="csc-image-id"><strong>Promotional graphic *</strong></label>
                  <input type="hidden" id="csc-image-id" name="image_id" value="<?php echo esc_attr((string) $row['image_id']); ?>">
                  <div id="csc-image-preview" class="csc-promo-image-preview">
                    <?php if ($row['image_id'] && wp_attachment_is_image($row['image_id'])) {
                        echo wp_get_attachment_image($row['image_id'], 'medium', false, array('alt' => 'Selected graphic preview'));
                    } else { echo '<span>No graphic selected</span>'; } ?>
                  </div>
                  <button type="button" id="csc-image-choose" class="button">Choose or upload graphic</button>
                  <button type="button" id="csc-image-clear" class="button">Clear</button>
                </div>
                <p><label for="csc-alt"><strong>Accessible description of the poster</strong></label>
                  <textarea class="large-text" rows="2" id="csc-alt" name="alt" placeholder="Briefly describe the important details shown on the graphic"><?php echo esc_textarea($row['alt']); ?></textarea>
                  <span class="description">Recommended when the graphic contains event dates, text or contact details. Title is used if blank.</span></p>
                <div class="csc-promo-dates">
                  <p><label for="csc-start"><strong>Start showing *</strong></label>
                    <input required type="date" id="csc-start" name="start" value="<?php echo esc_attr($row['start']); ?>"></p>
                  <p><label for="csc-end"><strong>Stop showing after *</strong></label>
                    <input required type="date" id="csc-end" name="end" value="<?php echo esc_attr($row['end']); ?>"></p>
                </div>
                <p class="description">The end date is inclusive. Dates use the WordPress site's local time; these are promotion display dates, not necessarily event dates.</p>
                <fieldset><legend><strong>More Information button (optional)</strong></legend>
                  <p><label for="csc-info-label">Button text</label>
                    <input class="regular-text" id="csc-info-label" name="info_label" maxlength="60" value="<?php echo esc_attr($row['info_label']); ?>"></p>
                  <p><label for="csc-info-url">Information page or external URL</label>
                    <input class="large-text" type="url" id="csc-info-url" name="info_url" placeholder="https://..." value="<?php echo esc_attr($row['info_url']); ?>">
                    <select class="csc-page-choose" data-target="csc-info-url"><option value="">Choose an existing parish page…</option>
                      <?php foreach ($pages as $page) : ?><option value="<?php echo esc_url(get_permalink($page->ID)); ?>"><?php echo esc_html($page->post_title); ?></option><?php endforeach; ?>
                    </select></p>
                </fieldset>
                <fieldset><legend><strong>Sign Up button (optional)</strong></legend>
                  <p><label for="csc-signup-label">Button text</label>
                    <input class="regular-text" id="csc-signup-label" name="signup_label" maxlength="60" value="<?php echo esc_attr($row['signup_label']); ?>"></p>
                  <p><label for="csc-signup-url">Registration page or external URL</label>
                    <input class="large-text" type="url" id="csc-signup-url" name="signup_url" placeholder="https://..." value="<?php echo esc_attr($row['signup_url']); ?>">
                    <select class="csc-page-choose" data-target="csc-signup-url"><option value="">Choose an existing parish page…</option>
                      <?php foreach ($pages as $page) : ?><option value="<?php echo esc_url(get_permalink($page->ID)); ?>"><?php echo esc_html($page->post_title); ?></option><?php endforeach; ?>
                    </select></p>
                </fieldset>
                <div class="csc-promo-card-preview" aria-label="Promotion card preview">
                  <h3>Preview before saving</h3>
                  <div class="csc-promo-card-inner">
                    <div id="csc-card-preview-image" class="csc-promo-card-image">
                      <?php if ($row['image_id'] && wp_attachment_is_image($row['image_id'])) {
                          echo wp_get_attachment_image($row['image_id'], 'medium', false, array('alt' => 'Promotion artwork preview'));
                      } else { echo '<span>Choose a graphic to preview the promotion.</span>'; } ?>
                    </div>
                    <div class="csc-promo-card-buttons">
                      <span id="csc-card-info" class="csc-promo-card-link" <?php if (!$row['info_url']) : ?>hidden<?php endif; ?>><?php echo esc_html($row['info_label']); ?></span>
                      <span id="csc-card-signup" class="csc-promo-card-link is-primary" <?php if (!$row['signup_url']) : ?>hidden<?php endif; ?>><?php echo esc_html($row['signup_label']); ?></span>
                    </div>
                  </div>
                  <p class="description">This is a local preview. It never publishes or activates a promotion.</p>
                </div>
                <div class="csc-promo-dates">
                  <p><label for="csc-sort"><strong>Display order</strong></label>
                    <input id="csc-sort" type="number" min="0" max="999" name="sort" value="<?php echo esc_attr((string) $row['sort']); ?>">
                    <span class="description">Lower numbers appear first.</span></p>
                  <p><label for="csc-status"><strong>Publication</strong></label>
                    <select name="status" id="csc-status">
                      <option value="draft" <?php selected($row['status'], 'draft'); ?>>Draft / inactive</option>
                      <option value="publish" <?php selected($row['status'], 'publish'); ?>>Published (date-controlled)</option>
                    </select></p>
                </div>
                <?php submit_button($edit_id ? 'Save Changes' : 'Save Promotion', 'primary', 'submit', true); ?>
                <?php if ($edit_id) : ?><p><a class="button button-secondary" href="<?php echo esc_url($base); ?>">Add New Promotion</a></p><?php endif; ?>
              </form>
            </section>

            <section class="csc-promo-panel">
              <h2>Saved promotions</h2>
              <p>Drafts do not appear publicly. Published entries begin and end automatically on their scheduled display dates.</p>
              <?php if (!$records) : ?>
                <p>No staff-managed promotions have been created yet. The historical examples will remain visible until you enable the new workflow.</p>
              <?php else : ?>
                <table class="widefat striped"><thead><tr><th>Promotion</th><th>Displays</th><th>State</th><th>Order</th></tr></thead><tbody>
                <?php foreach ($records as $item) : ?>
                  <tr>
                    <td><a href="<?php echo esc_url(add_query_arg('edit', $item['id'], $base)); ?>"><strong><?php echo esc_html($item['title']); ?></strong></a>
                      <small> <?php echo $item['image_id'] ? 'Graphic selected' : 'Missing graphic'; ?></small></td>
                    <td><?php echo esc_html($item['start'] ?: '—'); ?> to <?php echo esc_html($item['end'] ?: '—'); ?></td>
                    <td><?php echo esc_html(self::state($item, $today)); ?></td>
                    <td><?php echo esc_html((string) $item['sort']); ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody></table>
              <?php endif; ?>
              <p><strong>Current site date:</strong> <?php echo esc_html($today); ?> (WordPress timezone: <?php echo esc_html(wp_timezone_string()); ?>)</p>
              <p><strong>Homepage:</strong> <a href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener noreferrer">Open staging homepage</a></p>
            </section>
          </div>
        </div>
        <?php
    }

    public static function save_redirect_url($saved_id, $is_new) {
        $args = array('page' => self::MENU_SLUG);
        if ($is_new) {
            // Land back on a blank creation form, with an edit link to the saved item.
            $args['notice'] = 'created';
            $args['saved_id'] = absint($saved_id);
        } else {
            // Editing remains explicit and never silently creates a duplicate.
            $args['edit'] = absint($saved_id);
            $args['notice'] = 'updated';
        }
        return add_query_arg($args, admin_url('admin.php'));
    }

    public static function save() {
        self::permission();
        check_admin_referer('csc_promotion_save');
        $v = static function ($key) {
            return isset($_POST[$key]) && is_scalar($_POST[$key]) ? trim((string) wp_unslash($_POST[$key])) : '';
        };
        $id = absint($v('promotion_id'));
        $post = $id ? self::id_in_post($id) : null;
        if ($id && !$post) { wp_die('Cannot edit a nonexistent parish promotion.', 404); }
        if ($post && $v('modified_gmt') !== $post->post_modified_gmt) {
            wp_die('Someone else edited this promotion. Reload the form before saving to avoid overwriting their work.', 409);
        }
        $title = sanitize_text_field($v('title'));
        $image_id = absint($v('image_id'));
        $start = $v('start'); $end = $v('end');
        $status = $v('status') === 'publish' ? 'publish' : 'draft';
        $sort = filter_var($v('sort'), FILTER_VALIDATE_INT);
        $info = self::url($v('info_url')); $signup = self::url($v('signup_url'));
        if (!$title || strlen($title) > 160) { wp_die('Enter a promotion title of 160 characters or less.', 400); }
        if (!$image_id || !wp_attachment_is_image($image_id)) { wp_die('Select a valid image from the Media Library.', 400); }
        if (!self::valid_window($start, $end)) { wp_die('Enter valid display start and end dates (end date may not precede start).', 400); }
        if (is_wp_error($info)) { wp_die(esc_html($info->get_error_message()), 400); }
        if (is_wp_error($signup)) { wp_die(esc_html($signup->get_error_message()), 400); }
        if ($sort === false || $sort < 0 || $sort > 999) { wp_die('Display order must be between 0 and 999.', 400); }
        $data = array('post_type' => self::TYPE, 'post_title' => $title, 'post_status' => $status);
        if ($id) { $data['ID'] = $id; }
        $saved_id = $id ? wp_update_post(wp_slash($data), true) : wp_insert_post(wp_slash($data), true);
        if (is_wp_error($saved_id) || !$saved_id) { wp_die('WordPress could not save the promotion.', 500); }
        $meta = array(
            'image_id' => $image_id,
            'alt' => sanitize_textarea_field($v('alt')),
            'start' => $start, 'end' => $end,
            'info_url' => $info,
            'signup_url' => $signup,
            'info_label' => sanitize_text_field($v('info_label')) ?: 'More Information',
            'signup_label' => sanitize_text_field($v('signup_label')) ?: 'Sign Up',
            'sort' => $sort,
        );
        foreach ($meta as $key => $value) { update_post_meta($saved_id, self::META_PREFIX . $key, $value); }
        clean_post_cache($saved_id);
        wp_safe_redirect(self::save_redirect_url($saved_id, !$id));
        exit;
    }

    public static function set_mode() {
        self::permission();
        check_admin_referer('csc_promotion_mode');
        $enabled = isset($_POST['enabled']) && wp_unslash($_POST['enabled']) === '1' ? '1' : '0';
        update_option(self::MODE_OPTION, $enabled, false);
        wp_safe_redirect(add_query_arg(array('page' => self::MENU_SLUG, 'notice' => 'mode'), admin_url('admin.php')));
        exit;
    }
}

add_action('init', array('CSC_Parish_Promotions', 'register_type'));
add_action('rest_api_init', array('CSC_Parish_Promotions', 'rest_routes'));
add_action('admin_menu', array('CSC_Parish_Promotions', 'admin_menu'));
add_action('admin_enqueue_scripts', array('CSC_Parish_Promotions', 'admin_assets'));
add_action('admin_post_csc_promotion_save', array('CSC_Parish_Promotions', 'save'));
add_action('admin_post_csc_promotion_mode', array('CSC_Parish_Promotions', 'set_mode'));
