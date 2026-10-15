<?php
/** Typed, narrow theme-mod editor. The entire option is CAS-protected on every write. */
final class CSC_Global {
    const OPTION = 'theme_mods_kadence';

    public static function definitions() {
        // These IDs were verified against Kadence 1.5.2 Customizer controls on staging.
        return array(
            'header.sticky_background_desktop' => array('key'=>'header_sticky_background','path'=>array('desktop','color'),'type'=>'color'),
            'header.sticky_navigation_color' => array('key'=>'header_sticky_navigation_color','path'=>array('desktop','color'),'type'=>'color'),
            'header.main_background_desktop' => array('key'=>'header_main_background','path'=>array('desktop','color'),'type'=>'color'),
            'header.main_height_desktop' => array('key'=>'header_main_height','path'=>array('size','desktop'),'type'=>'integer','min'=>40,'max'=>180),
            'footer.middle_background_desktop' => array('key'=>'footer_middle_background','path'=>array('desktop','color'),'type'=>'color'),
            'footer.bottom_background_desktop' => array('key'=>'footer_bottom_background','path'=>array('desktop','color'),'type'=>'color'),
            'footer.wrap_background_desktop' => array('key'=>'footer_wrap_background','path'=>array('desktop','color'),'type'=>'color'),
        );
    }
    private static function valid_site() {
        if (get_template() !== 'kadence' || get_stylesheet() !== 'kadence' || !function_exists('Kadence\\kadence') || \Kadence\kadence()->get_option_type() !== 'theme_mod') {
            return new WP_Error('csc_unsupported_theme', 'Only Kadence theme_mod storage is supported.');
        }
        return true;
    }
    private static function valid_mapping($definition) {
        if (apply_filters('kadence_settings_key_custom_mapping','',$definition['key'])) {
            return new WP_Error('csc_custom_storage','This Kadence setting has custom storage and cannot be edited as a theme mod.');
        }
        return true;
    }
    private static function raw() { return get_option(self::OPTION, array()); }
    private static function revision($raw) { return hash('sha256', CSC_Controls::SITE . ':' . self::OPTION . ':' . serialize($raw)); }
    private static function at($raw, $definition) {
        $exists = array_key_exists($definition['key'], $raw); $node = $exists ? $raw[$definition['key']] : null;
        foreach ($definition['path'] as $step) {
            if (!is_array($node) || !array_key_exists($step, $node)) { return array(false, null); }
            $node = $node[$step];
        }
        return array(true, $node);
    }
    private static function set($raw, $definition, $value, $present=true) {
        $key=$definition['key']; $path=$definition['path'];
        if (!$present && count($path) === 0) { unset($raw[$key]); return $raw; }
        if (!isset($raw[$key]) || !is_array($raw[$key])) { $raw[$key]=array(); }
        $node =& $raw[$key];
        for ($i=0;$i<count($path)-1;$i++) { $step=$path[$i]; if (!isset($node[$step]) || !is_array($node[$step])) { $node[$step]=array(); } $node =& $node[$step]; }
        $leaf=$path[count($path)-1];
        if ($present) { $node[$leaf]=$value; } else { unset($node[$leaf]); }
        return $raw;
    }
    private static function parent_presence($raw,$def) {
        $node=$raw; $presence=array();
        foreach (array_merge(array($def['key']),array_slice($def['path'],0,-1)) as $step) {
            $found=is_array($node) && array_key_exists($step,$node); $presence[]=$found;
            $node=$found ? $node[$step] : null;
        }
        return $presence;
    }
    private static function prune_new_empty_parents($raw,$def,$presence) {
        $path=array_merge(array($def['key']),array_slice($def['path'],0,-1));
        for ($depth=count($path)-1;$depth>=0;$depth--) {
            if (!empty($presence[$depth])) { continue; }
            $node =& $raw; for ($i=0;$i<$depth;$i++) { $node =& $node[$path[$i]]; }
            if (isset($node[$path[$depth]]) && $node[$path[$depth]]===array()) { unset($node[$path[$depth]]); }
            unset($node);
        }
        return $raw;
    }
    private static function validate($definition,$value) {
        if ($definition['type']==='color') { return is_string($value) && preg_match('/\A#[a-fA-F0-9]{6}\z/', $value); }
        return is_int($value) && $value >= $definition['min'] && $value <= $definition['max'];
    }
    public static function inspect($target=null) {
        $ok=self::valid_site(); if (is_wp_error($ok)) { return $ok; }
        $raw=self::raw(); if (!is_array($raw)) { return new WP_Error('csc_bad_storage','Unexpected Kadence theme mods.'); }
        $defs=self::definitions(); if ($target!==null && (!is_string($target) || !isset($defs[$target]))) { return new WP_Error('csc_invalid_field','Unsupported target.'); }
        $fields=array();
        foreach ($defs as $name=>$def) {
            if ($target!==null && $target!==$name) { continue; }
            $mapped=self::valid_mapping($def);
            list($present,$stored)=self::at($raw,$def);
            $effective=\Kadence\kadence()->option($def['key'], null);
            $fields[$name]=array('type'=>$def['type'],'range'=>isset($def['min'])?array($def['min'],$def['max']):null,
                'storage'=>self::OPTION.'.'.$def['key'].'.'.implode('.',$def['path']),
                'editable'=>!is_wp_error($mapped) && self::at(array($def['key']=>$effective),$def)[0],
                'stored_present'=>$present,'stored'=>$stored,'effective_option'=>$effective);
        }
        return array('theme_version'=>wp_get_theme()->get('Version'),'expected_revision'=>self::revision($raw),'fields'=>$fields);
    }
    private static function cas($old,$new) {
        global $wpdb;
        $old_value=maybe_serialize($old); $new_value=maybe_serialize($new);
        $rows=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",$new_value,self::OPTION,$old_value));
        if ($rows!==1) { return new WP_Error('csc_conflict','Kadence option changed before write; inspect again.'); }
        wp_cache_delete(self::OPTION,'options'); wp_cache_delete('alloptions','options'); wp_cache_delete('notoptions','options');
        // Match WordPress option update actions after the guarded SQL write.
        do_action('update_option_'.self::OPTION,$old,$new,self::OPTION);
        do_action('updated_option',self::OPTION,$old,$new);
        do_action('church_site_controls_global_changed', self::OPTION);
        return true;
    }
    public static function update($args) {
        $defs=self::definitions(); $target=$args['target'];
        if (!is_string($target) || !isset($defs[$target])) { return new WP_Error('csc_invalid_field','Unsupported target.'); }
        $def=$defs[$target]; if (!self::validate($def,$args['value'])) { return new WP_Error('csc_invalid_value','Value does not match field schema.'); }
        $ok=self::valid_site(); if (is_wp_error($ok)) { return $ok; }
        $ok=self::valid_mapping($def); if (is_wp_error($ok)) { return $ok; }
        $old=self::raw(); if (!is_array($old)) { return new WP_Error('csc_bad_storage','Unexpected Kadence theme mods.'); }
        if (!hash_equals(self::revision($old),$args['expected_revision'])) { return new WP_Error('csc_stale_revision','Theme settings changed; inspect again.'); }
        $effective=\Kadence\kadence()->option($def['key'],null);
        if (!self::at(array($def['key']=>$effective),$def)[0]) { return new WP_Error('csc_unsupported_shape','Kadence effective field shape does not match verified adapter path.'); }
        list($present,$before)=self::at($old,$def);
        if ($present && $before===$args['value']) { return new WP_Error('csc_no_change','Value already matches.'); }
        $new=self::set($old,$def,$args['value']);
        $record=array('change_id'=>wp_generate_uuid4(),'version'=>CSC_Controls::VERSION,'site'=>CSC_Controls::SITE,'target_type'=>'theme_mod','target'=>$target,
            'before_present'=>$present,'before'=>$before,'parent_presence'=>self::parent_presence($old,$def),'after'=>$args['value'],'before_revision'=>self::revision($old),'after_revision'=>self::revision($new),
            'actor'=>get_current_user_id(),'created_at'=>gmdate('c'),'status'=>'prepared');
        if (!add_option(CSC_Controls::PREFIX.$record['change_id'],$record,'',false)) { return new WP_Error('csc_audit_failed','Rollback record not saved; no write.'); }
        $write=self::cas($old,$new); if (is_wp_error($write)) { return $write; }
        $record['status']='applied'; update_option(CSC_Controls::PREFIX.$record['change_id'],$record,false);
        return array('change'=>$record,'readback'=>self::inspect($target));
    }
    public static function undo($record,$expected_revision) {
        $defs=self::definitions(); $target=$record['target']; if (!isset($defs[$target])) { return new WP_Error('csc_invalid_field','Unsupported historical field.'); }
        $ok=self::valid_site(); if (is_wp_error($ok)) { return $ok; }
        $ok=self::valid_mapping($defs[$target]); if (is_wp_error($ok)) { return $ok; }
        $old=self::raw(); if (!is_array($old)) { return new WP_Error('csc_bad_storage','Unexpected Kadence theme mods.'); }
        if (!hash_equals(self::revision($old),$expected_revision)) { return new WP_Error('csc_stale_revision','Theme settings changed; inspect again.'); }
        if ($record['status']==='prepared' && !hash_equals(self::revision($old),$record['after_revision'])) {
            return new WP_Error('csc_conflict','Prepared write is not proven applied; undo refused.');
        }
        list($present,$current)=self::at($old,$defs[$target]);
        if (!$present || $current!==$record['after']) { return new WP_Error('csc_conflict','This setting was edited since the recorded change.'); }
        $new=self::set($old,$defs[$target],$record['before'],$record['before_present']);
        // Remove any containers introduced solely for this override. Preserve all siblings.
        if (!$record['before_present']) { $new=self::prune_new_empty_parents($new,$defs[$target],$record['parent_presence']); }
        $write=self::cas($old,$new); if (is_wp_error($write)) { return $write; }
        return self::inspect($target);
    }
}
