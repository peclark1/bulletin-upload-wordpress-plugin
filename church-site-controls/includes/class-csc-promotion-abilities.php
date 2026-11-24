<?php
/**
 * Narrow, authenticated abilities for the existing Parish Promotions records.
 * No arbitrary meta, deletion, homepage markup, or display-mode changes.
 */
final class CSC_Promotion_Abilities {
    public static function permission() {
        return CSC_Controls::permission() && current_user_can('edit_pages') &&
            CSC_Parish_Promotions::on_authorized_site();
    }

    private static function error($code, $message, $status = 400) {
        return new WP_Error($code, $message, array('status' => $status));
    }

    private static function fields() {
        return array(
            'title' => array('type'=>'string','minLength'=>1,'maxLength'=>160),
            'image_id' => array('type'=>'integer','minimum'=>1),
            'alt' => array('type'=>'string','maxLength'=>2000),
            'start' => array('type'=>'string','pattern'=>'^\d{4}-\d{2}-\d{2}$'),
            'end' => array('type'=>'string','pattern'=>'^\d{4}-\d{2}-\d{2}$'),
            'info_url' => array('type'=>'string','maxLength'=>2048),
            'info_label' => array('type'=>'string','maxLength'=>60),
            'signup_url' => array('type'=>'string','maxLength'=>2048),
            'signup_label' => array('type'=>'string','maxLength'=>60),
            'sort' => array('type'=>'integer','minimum'=>0,'maximum'=>999),
            'status' => array('type'=>'string','enum'=>array('draft','publish')),
        );
    }

    public static function register() {
        if (!function_exists('wp_register_ability')) { return; }
        $fields = self::fields();
        $required = array('title','image_id','start','end','status');
        $defs = array(
            'list-promotions' => array('List existing Parish Promotions, revision tokens, and managed homepage display state.', true, array(), array()),
            'create-promotion' => array('Create a new promotion with an explicit draft or publish status and inclusive display dates. Refuses duplicates; does not change homepage mode.', false, $fields, $required),
            'update-promotion' => array('Update allowlisted fields on exactly one existing promotion. Requires current revision from list-promotions. Set status draft to withdraw; no deletion.', false, array_merge(array('id'=>array('type'=>'integer','minimum'=>1),'expected_revision'=>array('type'=>'string','pattern'=>'^[a-f0-9]{64}$')), $fields), array('id','expected_revision')),
        );
        foreach ($defs as $name=>$def) {
            wp_register_ability('church-site-controls/'.$name, array(
                'label'=>ucwords(str_replace('-', ' ', $name)), 'description'=>$def[0],
                'category'=>'church-site-controls',
                'input_schema'=>array('type'=>'object','properties'=>(object)$def[2],'required'=>$def[3],'additionalProperties'=>false),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'permission_callback'=>array(__CLASS__,'permission'),
                'execute_callback'=>function ($args=array()) use ($name) { return self::dispatch($name,$args); },
                'meta'=>array('public'=>true,'show_in_rest'=>true,'mcp'=>array('public'=>true),
                    'annotations'=>array('readonly'=>$def[1],'destructive'=>false,'idempotent'=>$def[1])),
            ));
        }
    }

    private static function revision($row) {
        return hash('sha256', CSC_Controls::site_identity() . ':' . wp_json_encode($row));
    }

    private static function decorate($row) {
        $row['revision'] = self::revision($row);
        $row['image_url'] = wp_get_attachment_image_url($row['image_id'], 'full') ?: '';
        $row['edit_url'] = add_query_arg(array('page'=>CSC_Parish_Promotions::MENU_SLUG,'edit'=>$row['id']), admin_url('admin.php'));
        return $row;
    }

    public static function dispatch($name, $args=array()) {
        if (!self::permission()) { return self::error('csc_forbidden', 'Authorized site and administrator edit_pages permission required.', 403); }
        if (!is_array($args)) { return self::error('csc_invalid_input', 'Arguments must be an object.'); }
        if ($name === 'list-promotions') {
            if ($args) { return self::error('csc_invalid_input', 'List takes no arguments.'); }
            $rows = array_values(array_filter(CSC_Parish_Promotions::records(), static function ($row) {
                return current_user_can('edit_post', $row['id']);
            }));
            return array('site'=>CSC_Controls::site_identity(),'today'=>wp_date('Y-m-d'),
                'managed'=>CSC_Parish_Promotions::enabled(),
                'records'=>array_map(array(__CLASS__,'decorate'),$rows),
                'active_ids'=>CSC_Parish_Promotions::enabled() ? array_column(CSC_Parish_Promotions::active_records(),'id') : array());
        }
        if (!in_array($name,array('create-promotion','update-promotion'),true)) {
            return self::error('csc_invalid_input','Unknown promotion ability.');
        }
        // Preserve the explicit production display opt-in and never toggle it.
        if (!CSC_Controls::is_staging_site() && !CSC_Parish_Promotions::enabled()) {
            return self::error('csc_mode_disabled','Production managed promotions are not enabled.',403);
        }
        if (!current_user_can('publish_pages')) { return self::error('csc_forbidden','Publish pages permission required.',403); }
        $update = $name === 'update-promotion';
        $fields = self::fields();
        $allowed = array_keys($fields);
        if ($update) { $allowed = array_merge($allowed,array('id','expected_revision')); }
        if (array_diff(array_keys($args),$allowed)) { return self::error('csc_invalid_input','Unknown promotion field.'); }
        foreach ($fields as $key=>$schema) {
            if (!array_key_exists($key,$args)) { continue; }
            $value = $args[$key];
            if ($schema['type']==='integer') {
                if (!is_int($value) || $value<$schema['minimum'] || (isset($schema['maximum']) && $value>$schema['maximum'])) {
                    return self::error('csc_invalid_input','Invalid integer: '.$key);
                }
            } elseif (!is_string($value) || (isset($schema['maxLength']) && strlen($value)>$schema['maxLength']) ||
                (isset($schema['enum']) && !in_array($value,$schema['enum'],true))) {
                return self::error('csc_invalid_input','Invalid string: '.$key);
            }
        }
        if ($update) {
            if (!isset($args['id'],$args['expected_revision']) || !is_int($args['id']) || $args['id']<1 ||
                !is_string($args['expected_revision']) || !preg_match('/\A[a-f0-9]{64}\z/',$args['expected_revision']) ||
                !array_intersect(array_keys($args),array_keys($fields))) {
                return self::error('csc_invalid_input','Update requires id, expected_revision and at least one editable field.');
            }
        } elseif (array_diff(array('title','image_id','start','end','status'),array_keys($args))) {
            return self::error('csc_invalid_input','Creation requires title, image_id, start, end and explicit status.');
        }
        // Serialize MCP writes for this site; do not wait indefinitely on another request.
        global $wpdb;
        $lock = 'csc-promo-' . substr(hash('sha256',CSC_Controls::site_identity() . ':' . $wpdb->prefix),0,48);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock)) !== '1') {
            return self::error('csc_busy','Promotion writes are busy; inspect and retry.',409);
        }
        try { return self::write($args,$update,$fields); }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }

    private static function write($args,$update,$fields) {
        $rows = CSC_Parish_Promotions::records();
        $before = null;
        $id = $update ? $args['id'] : 0;
        if ($update) {
            foreach ($rows as $row) { if ($row['id']===$id) { $before=$row; break; } }
            if (!$before || !current_user_can('edit_post',$id)) {
                return self::error('csc_invalid_promotion','Promotion not found or not editable.',404);
            }
            if (!hash_equals(self::revision($before),$args['expected_revision'])) {
                return self::error('csc_stale_revision','Promotion changed. List again before editing.',409);
            }
        }
        $defaults=array('alt'=>'','info_url'=>'','info_label'=>'More Information','signup_url'=>'','signup_label'=>'Sign Up','sort'=>100);
        $values=array_replace($defaults,$before ?: array(),array_intersect_key($args,$fields));
        if (!in_array($values['status'],array('draft','publish'),true)) {
            return self::error('csc_invalid_input','Set an explicit supported status for this record.');
        }
        if (!CSC_Parish_Promotions::valid_window($values['start'],$values['end'])) {
            return self::error('csc_invalid_input','Invalid inclusive display date window.');
        }
        if (!wp_attachment_is_image($values['image_id']) || !wp_get_attachment_image_url($values['image_id'],'full')) {
            return self::error('csc_invalid_image','Select an existing Media Library image.');
        }
        // Refuse matching artwork or matching title with overlapping display windows.
        foreach ($rows as $row) {
            if ($row['id']===$id || $row['end']<$values['start'] || $row['start']>$values['end']) { continue; }
            if ($row['image_id']===$values['image_id'] ||
                strcasecmp(trim($row['title']),sanitize_text_field($values['title']))===0) {
                return self::error('csc_duplicate','Overlapping promotion already exists (ID '.$row['id'].'); list and edit it instead.',409);
            }
        }
        // Durable before state: even interrupted writes remain inspectable.
        $change_id=wp_generate_uuid4();
        $audit=array('change_id'=>$change_id,'site'=>CSC_Controls::site_identity(),'actor'=>get_current_user_id(),
            'created_at'=>gmdate('c'),'operation'=>$update?'update':'create','id'=>$id,'before'=>$before,
            'requested'=>array_intersect_key($values,$fields),'status'=>'prepared');
        $key='csc_promotion_change_'.$change_id;
        if (!add_option($key,$audit,'',false)) { return self::error('csc_audit_failed','Unable to save audit record; no promotion written.',500); }
        $saved=CSC_Parish_Promotions::persist($id,static function ($field) use ($values) {
            return trim((string)($values[$field] ?? ''));
        });
        if (is_wp_error($saved)) { return $saved; }
        $audit['id']=$saved['id']; $audit['after']=$saved; $audit['status']='applied';
        $audit_ok=update_option($key,$audit,false);
        return array('record'=>self::decorate($saved),'change_id'=>$change_id,'audit_updated'=>(bool)$audit_ok,
            'managed'=>CSC_Parish_Promotions::enabled(),
            'active'=>CSC_Parish_Promotions::enabled() && in_array($saved['id'],array_column(CSC_Parish_Promotions::active_records(),'id'),true));
    }
}
add_action('wp_abilities_api_init',array('CSC_Promotion_Abilities','register'));
