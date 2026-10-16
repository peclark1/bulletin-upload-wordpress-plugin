<?php
final class CSC_Controls {
    const VERSION = '0.3.2';
    const SITE = 'https://stpeterpr.org/design-demo';
    const PREFIX = 'csc_change_';

    public static function permission() {
        return current_user_can('manage_options');
    }
    private static function guard() {
        if (!self::permission()) { return new WP_Error('csc_forbidden', 'Administrator manage_options capability required.', array('status' => 403)); }
        if (untrailingslashit(home_url()) !== self::SITE || untrailingslashit(site_url()) !== self::SITE) {
            return new WP_Error('csc_wrong_site', 'This experimental plugin operates only on the designated staging installation.');
        }
        return true;
    }
    public static function register_category() {
        if (function_exists('wp_register_ability_category')) {
            wp_register_ability_category('church-site-controls', array('label' => 'Church Site Controls', 'description' => 'Staging appearance inspection and reversible changes.'));
        }
    }
    public static function register_abilities() {
        if (!function_exists('wp_register_ability')) { return; }
        $id = array('type' => 'integer', 'minimum' => 1);
        $revision = array('type' => 'string', 'pattern' => '^[a-f0-9]{64}$');
        $change = array('type' => 'string', 'pattern' => '^[a-f0-9-]{36}$');
        $defs = array(
            'capabilities' => array('Inspect supported targets and authenticated permissions.', true, array(), array()),
            'inspect-header' => array('Read selected global Kadence header settings, effective values, storage and CSS fingerprint. No header writes in v0.1.', true, array(), array()),
            'inspect-hero' => array('Read the first Cover hero on a page, supported top padding and revision. Reports unsupported structures.', true, array('page_id' => $id), array('page_id')),
            'update-hero' => array('Change only first Cover hero top padding in px on staging. Requires expected_revision; returns durable undo change_id.', false, array('page_id' => $id, 'field' => array('type'=>'string','enum'=>array('padding_top_px')), 'value' => array('type'=>'integer','minimum'=>0,'maximum'=>200), 'expected_revision'=>$revision), array('page_id','field','value','expected_revision')),
            'recent-changes' => array('List the 20 most recent minimal audit records, including interrupted prepared changes, for recovery.', true, array(), array()),
            'get-change' => array('Read a sanitized audit/undo record by change ID, including prepared records after an interrupted call.', true, array('change_id'=>$change), array('change_id')),
            'undo' => array('Conditionally restore a supported hero, global theme field or menu item change.', false, array('change_id'=>$change,'expected_revision'=>$revision), array('change_id','expected_revision')),
            'inspect-global' => array('Inspect allowlisted Kadence header/footer fields, their raw state and revision.', true, array('target'=>array('type'=>'string')), array()),
            'update-global' => array('Update one schema-validated Kadence header/footer field with a fresh revision and audit ID.', false, array('target'=>array('type'=>'string'),'value'=>array('type'=>array('string','integer')),'expected_revision'=>$revision), array('target','value','expected_revision')),
            'inspect-navigation' => array('Inspect one classic menu item, assigned location, destination and revision.', true, array('item_id'=>$id), array('item_id')),
            'update-navigation' => array('Update label, title attribute or validated custom URL for one assigned classic menu item.', false, array('item_id'=>$id,'field'=>array('type'=>'string','enum'=>array('label','attr_title','url')),'value'=>array('type'=>'string'),'expected_revision'=>$revision), array('item_id','field','value','expected_revision')),
        );
        foreach ($defs as $name => $d) {
            wp_register_ability('church-site-controls/' . $name, array(
                'label' => ucwords(str_replace('-', ' ', $name)), 'description' => $d[0], 'category'=>'church-site-controls',
                'input_schema'=>array('type'=>'object','properties'=>$d[2],'required'=>$d[3],'additionalProperties'=>false),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'permission_callback'=>array(__CLASS__, 'permission'),
                'execute_callback'=>function ($args = array()) use ($name) { return self::dispatch($name, $args); },
                'meta'=>array('public'=>true,'show_in_rest'=>true,'mcp'=>array('public'=>true),
                    'annotations'=>array('readonly'=>$d[1],'destructive'=>false,'idempotent'=>$d[1])),
            ));
        }
    }
    public static function dispatch($name, $args = array()) {
        $ok = self::guard();
        if (is_wp_error($ok)) { return $ok; }
        if (!is_array($args)) { return new WP_Error('csc_invalid_input', 'Arguments must be an object.'); }
        $keys = array('capabilities'=>array(),'inspect-header'=>array(),'inspect-hero'=>array('page_id'),
            'update-hero'=>array('page_id','field','value','expected_revision'), 'recent-changes'=>array(), 'get-change'=>array('change_id'), 'undo'=>array('change_id','expected_revision'),
            'inspect-global'=>array('target'), 'update-global'=>array('target','value','expected_revision'),
            'inspect-navigation'=>array('item_id'), 'update-navigation'=>array('item_id','field','value','expected_revision'));
        $required=$keys[$name] ?? array();
        if ($name==='inspect-global') { $required=array(); }
        if (!isset($keys[$name]) || array_diff(array_keys($args), $keys[$name]) || array_diff($required, array_keys($args))) {
            return new WP_Error('csc_invalid_input', 'Unknown ability, missing argument or unallowlisted field.');
        }
        if (isset($args['expected_revision']) && (!is_string($args['expected_revision']) || !preg_match('/\A[a-f0-9]{64}\z/', $args['expected_revision']))) {
            return new WP_Error('csc_invalid_input', 'Expected revision must be a SHA-256 token.');
        }
        switch ($name) {
            case 'capabilities': return array('version'=>self::VERSION,'site'=>home_url(),'manage_options'=>self::permission(), 'header_write'=>true,
                'global_fields'=>CSC_Global::definitions(),'navigation_fields'=>array('label','attr_title','url_for_custom_items'),
                'hero_write'=>array('field'=>'padding_top_px','range'=>array(0,200),'requires'=>'first top-level core/cover with matching px attribute and saved HTML'),
                'undo'=>'Full-content revision checked; intervening edits are refused. Audit options are retained on deactivation/uninstall.',
                'unsupported'=>array('arbitrary CSS','arbitrary options','nested or non-Cover heroes','Navigation blocks'));
            case 'inspect-header': return self::header();
            case 'inspect-hero': return self::hero($args['page_id']);
            case 'update-hero': return self::update($args);
            case 'recent-changes': return self::recent_changes();
            case 'get-change': return self::change($args['change_id']);
            case 'undo': return self::undo($args);
            case 'inspect-global': return CSC_Global::inspect(isset($args['target']) ? $args['target'] : null);
            case 'update-global': return CSC_Global::update($args);
            case 'inspect-navigation': return CSC_Navigation::inspect($args['item_id']);
            case 'update-navigation': return CSC_Navigation::update($args);
        }
    }
    private static function page($id) {
        if (!is_int($id) || $id < 1) { return new WP_Error('csc_invalid_page', 'page_id must be a positive integer.'); }
        $page = get_post($id);
        if (!$page || $page->post_type !== 'page' || !current_user_can('edit_post', $id)) {
            return new WP_Error('csc_invalid_page', 'Page unavailable or edit permission denied.');
        }
        return $page;
    }
    private static function revision($page) {
        return hash('sha256', self::SITE . ':' . $page->ID . ':' . $page->post_content);
    }
    private static function hero($id) {
        $page = self::page($id);
        if (is_wp_error($page)) { return $page; }
        $hero = CSC_Hero::inspect($page->post_content);
        $result = array('page_id'=>$id, 'url'=>get_permalink($id), 'storage'=>'posts.post_content; first top-level core/cover attribute and saved wrapper style',
            'expected_revision'=>self::revision($page), 'content_sha256'=>hash('sha256', $page->post_content),
            'css_note'=>'Custom/theme CSS may override this value; stored-state readback is not visual verification.');
        if (is_wp_error($hero)) { return $result + array('supported'=>false,'reason'=>$hero->get_error_message()); }
        unset($hero['spans']);
        return $result + array('supported'=>true,'hero'=>$hero);
    }
    private static function header() {
        $theme = wp_get_theme();
        if (get_template() !== 'kadence' || !function_exists('Kadence\\kadence')) {
            return array('supported'=>false,'theme'=>$theme->get('Name'),'reason'=>'Active parent theme is not Kadence or its option accessor is unavailable.');
        }
        $kadence = \Kadence\kadence();
        $type = $kadence->get_option_type();
        $fields = array('header_main_height','header_main_background','header_main_layout','header_desktop_items',
            'header_mobile_items','header_sticky','transparent_header_enable','logo_width','logo_layout');
        $result = array('theme'=>$theme->get('Name'),'version'=>$theme->get('Version'),'parent_template'=>get_template(),
            'option_type'=>$type,'theme_mod_option'=>'theme_mods_' . get_stylesheet(), 'header_write'=>false, 'fields'=>array(),
            'custom_css'=>array('storage'=>'custom_css post for active stylesheet','sha256'=>hash('sha256',wp_get_custom_css()),'bytes'=>strlen(wp_get_custom_css())));
        foreach ($fields as $key) {
            $mapping = apply_filters('kadence_settings_key_custom_mapping', '', $key);
            $storage = $mapping ? 'custom mapping: ' . $mapping : ($type === 'theme_mod' ? 'theme_mods_' . get_stylesheet() . '.' . $key : 'Kadence option storage (read-only accessor)');
            $result['fields'][$key] = array('storage'=>$storage, 'effective'=>$kadence->option($key, null),
                'stored'=>($type === 'theme_mod' && !$mapping) ? get_theme_mod($key, null) : null);
        }
        return $result;
    }
    private static function recent_changes() {
        global $wpdb;
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 20", $wpdb->esc_like(self::PREFIX) . '%'));
        $records = array();
        foreach ($names as $name) {
            $record = get_option($name);
            if (is_array($record) && isset($record['site']) && $record['site'] === self::SITE) { $records[] = $record; }
        }
        return array('changes'=>$records);
    }
    private static function change($id) {
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $id)) {
            return new WP_Error('csc_invalid_change', 'Invalid change ID.');
        }
        $record = get_option(self::PREFIX . $id, false);
        if (!is_array($record)) { return new WP_Error('csc_missing_change', 'Change record not found.'); }
        return $record;
    }
    private static function update($args) {
        if ($args['field'] !== 'padding_top_px') { return new WP_Error('csc_invalid_field', 'Only padding_top_px is writable.'); }
        $page = self::page($args['page_id']);
        if (is_wp_error($page)) { return $page; }
        if (!hash_equals(self::revision($page), $args['expected_revision'])) { return new WP_Error('csc_stale_revision', 'Content changed; inspect again.'); }
        $after = CSC_Hero::patch($page->post_content, $args['value']);
        if (is_wp_error($after)) { return $after; }
        $hero = CSC_Hero::inspect($page->post_content);
        if ($after === $page->post_content) { return new WP_Error('csc_no_change', 'Value already matches; nothing written.'); }
        // Keep restoration within the same adapter's supported bounds.
        if ($hero['value'] > 200) { return new WP_Error('csc_unsupported_hero', 'Original padding exceeds reversible v0.1 range.'); }
        $next = clone $page; $next->post_content = $after;
        $record = array('change_id'=>wp_generate_uuid4(),'version'=>self::VERSION,'site'=>self::SITE,'page_id'=>$page->ID,
            'field'=>'padding_top_px','before'=>$hero['value'],'after'=>$args['value'],'before_revision'=>self::revision($page),
            'after_revision'=>self::revision($next),'actor'=>get_current_user_id(),'created_at'=>gmdate('c'),'status'=>'prepared');
        // Durable minimal before state precedes any content mutation. No full page/user data in audit.
        if (!add_option(self::PREFIX . $record['change_id'], $record, '', false)) { return new WP_Error('csc_audit_failed', 'Could not persist rollback record; content unchanged.'); }
        $written = self::cas($page, $after);
        if (is_wp_error($written)) { return $written; }
        $record['status'] = 'applied';
        if (!update_option(self::PREFIX . $record['change_id'], $record, false)) { $record['status'] = 'prepared'; }
        return array('change'=>$record,'readback'=>self::hero($page->ID));
    }
    private static function undo($args) {
        $record = self::change($args['change_id']);
        if (is_wp_error($record)) { return $record; }
        if ($record['site'] !== self::SITE || !in_array($record['status'], array('prepared','applied'), true)) {
            return new WP_Error('csc_invalid_change', 'Change is not pending restoration on this site.');
        }
        if (isset($record['target_type'])) {
            if ($record['target_type']==='theme_mod') { $readback=CSC_Global::undo($record,$args['expected_revision']); }
            elseif ($record['target_type']==='menu_item') { $readback=CSC_Navigation::undo($record,$args['expected_revision']); }
            else { return new WP_Error('csc_invalid_change','Unknown adapter.'); }
            if (is_wp_error($readback)) { return $readback; }
            $record['status']='undone'; $record['undone_at']=gmdate('c'); $record['undo_actor']=get_current_user_id();
            $saved=update_option(self::PREFIX.$record['change_id'],$record,false);
            return array('change_id'=>$record['change_id'],'restored'=>true,'audit_updated'=>(bool)$saved,'readback'=>$readback);
        }
        $page = self::page($record['page_id']);
        if (is_wp_error($page)) { return $page; }
        if (!hash_equals(self::revision($page), $args['expected_revision']) || !hash_equals($record['after_revision'], self::revision($page))) {
            return new WP_Error('csc_stale_revision', 'Intervening page edit detected; automatic undo refused.');
        }
        $restored = CSC_Hero::patch($page->post_content, $record['before']);
        if (is_wp_error($restored)) { return $restored; }
        $next = clone $page; $next->post_content = $restored;
        if (!hash_equals($record['before_revision'], self::revision($next))) { return new WP_Error('csc_restore_mismatch', 'Restoration would not reproduce original content.'); }
        $written = self::cas($page, $restored);
        if (is_wp_error($written)) { return $written; }
        $record['status']='undone'; $record['undone_at']=gmdate('c'); $record['undo_actor']=get_current_user_id();
        $saved = update_option(self::PREFIX . $record['change_id'], $record, false);
        return array('change_id'=>$record['change_id'],'restored'=>true,'audit_updated'=>(bool)$saved,'readback'=>self::hero($page->ID));
    }
    private static function cas($page, $content) {
        global $wpdb;
        // Atomic binary comparison prevents the read/check/write race of wp_update_post.
        // Only content and standard modified timestamps are updated; no theme option writes.
        $rows = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_content = %s, post_modified = %s, post_modified_gmt = %s WHERE ID = %d AND post_type = 'page' AND BINARY post_content = BINARY %s",
            $content, current_time('mysql'), current_time('mysql', true), $page->ID, $page->post_content));
        if ($rows !== 1) { return new WP_Error('csc_conflict', 'Atomic write failed or content changed; no overwrite attempted.'); }
        clean_post_cache($page->ID);
        // Dedicated hook for cache integrations; this narrow CAS intentionally does not run generic save_post handlers.
        do_action('church_site_controls_changed', $page->ID);
        return true;
    }
}
