<?php
/** Classic WordPress menu items only; no Navigation block or arbitrary menu creation. */
final class CSC_Navigation {
    private static function snapshot($id) {
        if (!is_int($id) || $id < 1 || !current_user_can('edit_post',$id)) { return new WP_Error('csc_invalid_item','Menu item unavailable.'); }
        $post=get_post($id);
        if (!$post || $post->post_type!=='nav_menu_item') { return new WP_Error('csc_invalid_item','Classic menu item required.'); }
        $terms=wp_get_object_terms($id,'nav_menu',array('fields'=>'ids'));
        if (is_wp_error($terms) || count($terms)!==1) { return new WP_Error('csc_ambiguous_menu','Item must belong to exactly one classic menu.'); }
        $menu=(int)$terms[0]; $item=wp_setup_nav_menu_item($post);
        $locations=array(); foreach (get_nav_menu_locations() as $location=>$menu_id) { if ((int)$menu_id===$menu) { $locations[]=$location; } }
        if (!$locations) { return new WP_Error('csc_inactive_menu','Item is not in an assigned menu.'); }
        $data=array('item_id'=>$id,'menu_id'=>$menu,'locations'=>$locations,'type'=>$item->type,'object'=>$item->object,'object_id'=>(int)$item->object_id,
            'parent_id'=>(int)$item->menu_item_parent,'order'=>(int)$item->menu_order,'label'=>$item->title,'url'=>$item->url,
            'target'=>$item->target,'attr_title'=>$item->attr_title,'description'=>$item->description,'classes'=>array_values((array)$item->classes),'xfn'=>$item->xfn);
        $data['expected_revision']=hash('sha256',CSC_Controls::SITE.':nav:'.serialize($data));
        return $data;
    }
    public static function inspect($id) { return self::snapshot($id); }
    private static function valid($field,$value,$snapshot) {
        if ($field==='label' || $field==='attr_title') { return is_string($value) && $value!=='' && strlen($value)<=100 && strip_tags($value)===$value && !preg_match('/[\x00-\x1f]/',$value); }
        if ($field==='url' && $snapshot['type']==='custom' && is_string($value) && strlen($value)<500) {
            return strpos($value,CSC_Controls::SITE.'/')===0 && wp_http_validate_url($value);
        }
        return false;
    }
    private static function save($item,$field,$value) {
        $args=array('menu-item-title'=>$field==='label'?$value:$item['label'],'menu-item-attr-title'=>$field==='attr_title'?$value:$item['attr_title'],
            'menu-item-url'=>$field==='url'?$value:$item['url'],'menu-item-status'=>'publish','menu-item-type'=>$item['type'],
            'menu-item-object'=>$item['object'],'menu-item-object-id'=>$item['object_id'],'menu-item-parent-id'=>$item['parent_id'],
            'menu-item-position'=>$item['order'],'menu-item-target'=>$item['target'],'menu-item-description'=>$item['description'],
            'menu-item-classes'=>$item['classes'],'menu-item-xfn'=>$item['xfn']);
        return wp_update_nav_menu_item($item['menu_id'],$item['item_id'],$args);
    }
    public static function update($args) {
        $item=self::snapshot($args['item_id']); if (is_wp_error($item)) { return $item; }
        if (!hash_equals($item['expected_revision'],$args['expected_revision'])) { return new WP_Error('csc_stale_revision','Menu item changed; inspect again.'); }
        $field=$args['field']; if (!self::valid($field,$args['value'],$item)) { return new WP_Error('csc_invalid_value','Unsupported or invalid menu field/value.'); }
        if ($item[$field]===$args['value']) { return new WP_Error('csc_no_change','Value already matches.'); }
        $record=array('change_id'=>wp_generate_uuid4(),'version'=>CSC_Controls::VERSION,'site'=>CSC_Controls::SITE,'target_type'=>'menu_item','item_id'=>$item['item_id'],
            'menu_id'=>$item['menu_id'],'field'=>$field,'before'=>$item[$field],'after'=>$args['value'],'before_revision'=>$item['expected_revision'],
            'actor'=>get_current_user_id(),'created_at'=>gmdate('c'),'status'=>'prepared');
        if (!add_option(CSC_Controls::PREFIX.$record['change_id'],$record,'',false)) { return new WP_Error('csc_audit_failed','Rollback record not saved; no write.'); }
        // WordPress menu API has no compare-and-swap primitive. Recheck immediately before save;
        // this narrows, but cannot eliminate, a concurrent editor race.
        $fresh=self::snapshot($item['item_id']); if (is_wp_error($fresh) || $fresh['expected_revision']!==$item['expected_revision']) { return new WP_Error('csc_conflict','Menu changed before write.'); }
        $saved=self::save($item,$field,$args['value']); if (is_wp_error($saved)) { return $saved; }
        $after=self::snapshot($item['item_id']); if (is_wp_error($after) || $after[$field]!==$args['value']) { return new WP_Error('csc_readback_failed','Menu write did not read back as requested; inspect history before retry.'); }
        $record['after_revision']=$after['expected_revision']; $record['status']='applied'; update_option(CSC_Controls::PREFIX.$record['change_id'],$record,false);
        return array('change'=>$record,'readback'=>$after);
    }
    public static function undo($record,$expected_revision) {
        $item=self::snapshot($record['item_id']); if (is_wp_error($item)) { return $item; }
        if (!hash_equals($item['expected_revision'],$expected_revision) || $item[$record['field']]!==$record['after'] || $item['menu_id']!==$record['menu_id']) {
            return new WP_Error('csc_conflict','Menu item changed; undo refused.');
        }
        if ($record['status']==='prepared' && (!isset($record['after_revision']) || $record['after_revision']!==$item['expected_revision'])) {
            return new WP_Error('csc_conflict','Prepared menu write is not proven applied; undo refused.');
        }
        $saved=self::save($item,$record['field'],$record['before']); if (is_wp_error($saved)) { return $saved; }
        $after=self::snapshot($record['item_id']);
        if (is_wp_error($after) || $after[$record['field']]!==$record['before']) { return new WP_Error('csc_readback_failed','Undo readback failed.'); }
        return $after;
    }
}
