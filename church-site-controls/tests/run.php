<?php
// Behavioral regression harness. WordPress/API/database are isolated fakes, NOT live MCP evidence.
class WP_Error {
    private $code; private $message;
    function __construct($code, $message, $data=null) { $this->code=$code; $this->message=$message; }
    function get_error_code() { return $this->code; }
    function get_error_message() { return $this->message; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
$GLOBALS['can']=true; $GLOBALS['can_edit']=true; $GLOBALS['url']='https://stpeterpr.org/design-demo'; $GLOBALS['options']=array();
function current_user_can($cap, $id=null) { return $cap==='manage_options' ? $GLOBALS['can'] : $GLOBALS['can_edit']; }
function home_url() { return $GLOBALS['url']; }
function site_url() { return $GLOBALS['url']; }
function untrailingslashit($s) { return rtrim($s,'/'); }
function get_post($id) { return $id===3498 ? clone $GLOBALS['page'] : ($id===3812 ? clone $GLOBALS['menu_item'] : null); }
function wp_get_object_terms($id,$taxonomy,$args) { return $id===3812 ? array(10) : array(); }
function get_nav_menu_locations() { return array('primary'=>10,'mobile'=>10); }
function wp_setup_nav_menu_item($post) {
    // Mirror the default nav_menu_attr_title / nav_menu_description wptexturize display filters.
    $post->attr_title=str_replace("'", '&#8217;', $post->post_excerpt);
    $post->description=str_replace("'", '&#8217;', $post->post_content);
    return $post;
}
function wp_http_validate_url($url) { return strpos($url,'https://')===0 ? $url : false; }
function wp_slash($value) { return addslashes($value); }
function wp_update_nav_menu_item($menu,$id,$args) {
    if (!empty($GLOBALS['menu_fail'])) { return new WP_Error('fake_failure','Menu API failed'); }
    if (!is_string($args['menu-item-classes'])) { return new WP_Error('fake_classes','Classes must be a string'); }
    $GLOBALS['menu_item']->title=stripslashes($args['menu-item-title']);
    $GLOBALS['menu_item']->post_excerpt=stripslashes($args['menu-item-attr-title']);
    $GLOBALS['menu_item']->post_content=stripslashes($args['menu-item-description']);
    $GLOBALS['menu_item']->url=$args['menu-item-url'];
    return $id;
}
function get_permalink($id) { return home_url().'/?page_id='.$id; }
function get_option($key,$default=false) { return $GLOBALS['options'][$key] ?? $default; }
function add_option($key,$value,$deprecated='',$autoload=null) {
    if (!empty($GLOBALS['audit_fail']) || isset($GLOBALS['options'][$key])) { return false; }
    $GLOBALS['options'][$key]=$value; return true;
}
function update_option($key,$value,$autoload=null) {
    if (!empty($GLOBALS['audit_update_fail'])) { return false; }
    $GLOBALS['options'][$key]=$value; return true;
}
function wp_generate_uuid4() { static $n=0; return '00000000-0000-4000-8000-'.sprintf('%012d',++$n); }
function get_current_user_id() { return 1; }
function current_time($type,$gmt=false) { return '2026-10-14 15:00:00'; }
function clean_post_cache($id) { $GLOBALS['cache_cleaned']=true; }
function wp_cache_delete($key,$group) { return true; }
function maybe_serialize($value) { return serialize($value); }
function do_action($name,...$args) { $GLOBALS['hooks'][]=$name; }
function wp_register_ability_category($name,$args) { $GLOBALS['category']=$name; }
function wp_register_ability($name,$args) { $GLOBALS['abilities'][$name]=$args; }
class FakeDB {
    public $posts='wp_posts'; public $options='wp_options';
    function esc_like($s) { return $s; }
    function get_col($query) { return array_slice(array_reverse(array_keys($GLOBALS['options'])),0,20); } public $race=false; public $fail=false;
    function prepare($sql,...$args) {
        if (strpos($sql,'SELECT option_name')===0) { return $args; }
        if (strpos($sql,'BINARY option_value = BINARY %s')!==false) { return array('theme_cas'=>$args); }
        if (strpos($sql,'BINARY post_content = BINARY %s')===false) { throw new Exception('Missing atomic comparison'); }
        return $args;
    }
    function query($args) {
        if (isset($args['theme_cas'])) {
            list($new,$key,$old)=$args['theme_cas'];
            if ($this->race) { $GLOBALS['options'][$key]['concurrent']='yes'; $this->race=false; }
            if ($this->fail || maybe_serialize($GLOBALS['options'][$key])!==$old) { return 0; }
            $GLOBALS['options'][$key]=unserialize($new); return 1;
        }
        if ($this->race) { $GLOBALS['page']->post_content.=' concurrent editor'; $this->race=false; }
        if ($this->fail || $GLOBALS['page']->post_content!==$args[4]) { return 0; }
        $GLOBALS['page']->post_content=$args[0]; return 1;
    }
}
$GLOBALS['wpdb']=new FakeDB();
require __DIR__.'/kadence-stub.php';
$GLOBALS['option_type']='theme_mod'; $GLOBALS['template']='kadence'; $GLOBALS['mods']=array('header_main_height'=>array('size'=>array('desktop'=>80),'unit'=>'px'));
function wp_get_theme() { return new class { function get($key) { return $key==='Name' ? 'Kadence' : '1.5.2'; } }; }
function get_template() { return $GLOBALS['template']; }
function get_stylesheet() { return 'kadence'; }
function wp_get_custom_css() { return '.example { color: red; }'; }
function get_theme_mod($key,$default=null) { return $GLOBALS['mods'][$key] ?? $default; }
function apply_filters($name,$default,...$args) { return $GLOBALS['mapping'] ?? $default; }
require __DIR__.'/../includes/class-csc-hero.php';
require __DIR__.'/../includes/class-csc-global.php';
require __DIR__.'/../includes/class-csc-navigation.php';
require __DIR__.'/../includes/class-csc-controls.php';
$fixture=file_get_contents(__DIR__.'/fixtures/home-cover.html');
$GLOBALS['page']=(object)array('ID'=>3498,'post_type'=>'page','post_content'=>$fixture);
$GLOBALS['menu_item']=(object)array('ID'=>3812,'post_type'=>'nav_menu_item','type'=>'post_type','object'=>'page','object_id'=>3510,'menu_item_parent'=>0,
    'menu_order'=>4,'title'=>'St. Mary’s Two Inlets & Grotto','url'=>'https://stpeterpr.org/design-demo/?page_id=3510','target'=>'','attr_title'=>'',
    'description'=>'','post_excerpt'=>'','post_content'=>'','classes'=>array('custom'),'xfn'=>'');
$count=0;
function check($condition,$name) { global $count; if (!$condition) { fwrite(STDERR,"FAIL: $name\n"); exit(1); } $count++; echo "PASS: $name\n"; }
function call_csc($name,$args=array()) { return CSC_Controls::dispatch($name,$args); }
function error_is($result,$code) { return is_wp_error($result) && $result->get_error_code()===$code; }
function revision() { return call_csc('inspect-hero',array('page_id'=>3498))['expected_revision']; }
function update_args($value=78) { return array('page_id'=>3498,'field'=>'padding_top_px','value'=>$value,'expected_revision'=>revision()); }
$hero=CSC_Hero::inspect($fixture);
check(!is_wp_error($hero) && $hero['value']===70,'real staging Cover fixture parsed as 70px');
$patched=CSC_Hero::patch($fixture,78);
check(CSC_Hero::inspect($patched)['value']===78,'attribute and HTML both read 78px');
check(str_replace(array('"top":"78px"','padding-top:78px'),array('"top":"70px"','padding-top:70px'),$patched)===$fixture,'only two exact tokens changed, all siblings byte-identical');
check(CSC_Hero::patch($patched,70)===$fixture,'adapter restores exact original bytes');
foreach (array(-1,201,'78',78.5,null,true,array(),'<script>') as $bad) {
    check(error_is(CSC_Hero::patch($fixture,$bad),'csc_invalid_value'),'reject malformed/out-of-range value '.json_encode($bad));
}
foreach (array('plain HTML', '<!-- wp:group -->'.$fixture, str_replace('padding-top:70px','padding-top:71px',$fixture), str_replace('"top":"70px"','"top":"2rem"',$fixture), str_replace('padding-top:70px','padding-top:70px;padding-top:70px',$fixture)) as $bad) {
    check(error_is(CSC_Hero::inspect($bad),'csc_unsupported_hero'),'reject unsupported/ambiguous hero');
}
check(CSC_Hero::inspect(CSC_Hero::patch($fixture,0))['value']===0,'zero padding accepted');
check(CSC_Hero::inspect(CSC_Hero::patch($fixture,200))['value']===200,'upper bound accepted');
CSC_Controls::register_category(); CSC_Controls::register_abilities();
check(count($GLOBALS['abilities'])===11,'eleven abilities registered');
foreach ($GLOBALS['abilities'] as $name=>$def) {
    check($def['meta']['mcp']['public'] && $def['meta']['show_in_rest'] && is_callable($def['permission_callback']),'discoverable metadata and permission callback '.$name);
    $write=in_array($name,array('church-site-controls/update-hero','church-site-controls/undo','church-site-controls/update-global','church-site-controls/update-navigation'),true);
    check($def['meta']['annotations']['readonly']===!$write,'correct readonly annotation '.$name);
}
check(error_is(call_csc('inspect-hero',array('page_id'=>'3498')),'csc_invalid_page'),'reject string page id');
check(error_is(call_csc('inspect-hero',array('page_id'=>1)),'csc_invalid_page'),'reject missing page');
$args=update_args(); $GLOBALS['can']=false;
foreach (array('capabilities'=>array(),'inspect-header'=>array(),'inspect-hero'=>array('page_id'=>3498),'update-hero'=>$args,'recent-changes'=>array(),'get-change'=>array('change_id'=>'x'),'undo'=>array('change_id'=>'x','expected_revision'=>'x')) as $name=>$input) {
    check(error_is(call_csc($name,$input),'csc_forbidden'),'unauthorized '.$name.' rejected');
}
check(!CSC_Controls::permission(),'ability permission callback rejects unauthorized');
$GLOBALS['can']=true; $GLOBALS['can_edit']=false;
check(error_is(call_csc('update-hero',$args),'csc_invalid_page'),'per-page edit capability required'); $GLOBALS['can_edit']=true;
$GLOBALS['url']='https://stpeterpr.org';
check(error_is(call_csc('update-hero',$args),'csc_wrong_site'),'production site refused'); $GLOBALS['url']='https://stpeterpr.org/design-demo';
check(error_is(call_csc('update-hero',$args+array('option'=>'arbitrary')),'csc_invalid_input'),'unallowlisted input refused');
$bad=$args; $bad['field']='css'; check(error_is(call_csc('update-hero',$bad),'csc_invalid_field'),'arbitrary CSS refused');
$bad=$args; $bad['expected_revision']=str_repeat('0',64); check(error_is(call_csc('update-hero',$bad),'csc_stale_revision'),'stale update rejected');
check(error_is(call_csc('update-hero',update_args(70)),'csc_no_change'),'no-op produces no audit');
$GLOBALS['audit_fail']=true; check(error_is(call_csc('update-hero',$args),'csc_audit_failed') && $GLOBALS['page']->post_content===$fixture,'audit failure prevents write'); $GLOBALS['audit_fail']=false;
$GLOBALS['wpdb']->race=true; check(error_is(call_csc('update-hero',$args),'csc_conflict') && strpos($GLOBALS['page']->post_content,'concurrent editor')!==false,'atomic race preserves concurrent editor'); $GLOBALS['page']->post_content=$fixture;
$result=call_csc('update-hero',$args);
check(!is_wp_error($result) && $result['readback']['hero']['value']===78,'update and readback work');
$change=$result['change'];
check($change['before']===70 && $change['after']===78 && $change['status']==='applied','durable auditable before/after record');
check(!isset($change['content']) && !isset($change['post_content']),'audit excludes full page content');
check(call_csc('get-change',array('change_id'=>$change['change_id']))===$change,'change can be recovered by ID');
$undo=array('change_id'=>$change['change_id'],'expected_revision'=>revision());
check(error_is(call_csc('undo',array('change_id'=>$change['change_id'],'expected_revision'=>$args['expected_revision'])),'csc_stale_revision'),'undo rejects stale input token');
$GLOBALS['page']->post_content.=' newer edit';
check(error_is(call_csc('undo',array('change_id'=>$change['change_id'],'expected_revision'=>revision())),'csc_stale_revision'),'undo rejects any intervening edit even with new token');
$GLOBALS['page']->post_content=$patched;
$result=call_csc('undo',$undo);
check(!is_wp_error($result) && $result['restored'] && $GLOBALS['page']->post_content===$fixture,'undo reproduces exact original content');
check(revision()===$args['expected_revision'],'original revision restored');
check(error_is(call_csc('undo',$undo),'csc_invalid_change'),'duplicate undo refused');
$GLOBALS['audit_update_fail']=true;
$result=call_csc('update-hero',update_args()); $GLOBALS['audit_update_fail']=false;
check($result['change']['status']==='prepared','post-write audit status failure retains recoverable prepared record');
$undo=call_csc('undo',array('change_id'=>$result['change']['change_id'],'expected_revision'=>revision()));
check(!is_wp_error($undo) && $GLOBALS['page']->post_content===$fixture,'prepared record supports exact undo');
check(!empty($GLOBALS['cache_cleaned']) && in_array('church_site_controls_changed',$GLOBALS['hooks']),'successful writes invalidate post cache and notify cache integrations');
check(count(call_csc('recent-changes')['changes'])>=2,'interrupted responses recoverable from recent history');
$header=call_csc('inspect-header');
check($header['fields']['header_main_height']['storage']==='theme_mods_kadence.header_main_height','header reports theme mod storage');
check($header['fields']['header_main_height']['stored']===$GLOBALS['mods']['header_main_height'],'header preserves composite raw value');
check($header['fields']['logo_width']['stored']===null,'missing header setting reported as null');
check($header['custom_css']['sha256']===hash('sha256',wp_get_custom_css()),'custom CSS distinguished by fingerprint');
$GLOBALS['mapping']='custom_theme_settings';
check(call_csc('inspect-header')['fields']['header_main_height']['stored']===null,'custom mapping does not mislabel raw theme mod'); unset($GLOBALS['mapping']);
$GLOBALS['option_type']='option';
check(call_csc('inspect-header')['fields']['header_main_height']['stored']===null,'option-backed mode does not claim raw theme mod');
$GLOBALS['template']='other';
check(call_csc('inspect-header')['supported']===false,'non-Kadence theme refused honestly');
$GLOBALS['template']='kadence'; $GLOBALS['option_type']='theme_mod';
$GLOBALS['mods']['header_sticky_background']=array('desktop'=>array('color'=>'#ffffff'));
$GLOBALS['mods']['footer_middle_background']=array('desktop'=>array('color'=>'#eeeeee'));
$GLOBALS['mods']['footer_bottom_background']=array('desktop'=>array('color'=>'#eeeeee'));
$GLOBALS['mods']['footer_wrap_background']=array('desktop'=>array('color'=>'#eeeeee'));
$GLOBALS['mods']['header_main_background']=array('desktop'=>array('color'=>'#ffffff'));
$GLOBALS['mods']['header_main_height']=array('size'=>array('desktop'=>80));
$GLOBALS['options']['theme_mods_kadence']=array('unrelated'=>array('keep'=>'yes'));
$initial=$GLOBALS['options']['theme_mods_kadence'];
$global=call_csc('inspect-global');
check(count($global['fields'])>=6 && !$global['fields']['header.sticky_background_desktop']['stored_present'],'allowlisted target matrix and absent override');
check(error_is(call_csc('update-global',array('target'=>'header.sticky_background_desktop','value'=>'red','expected_revision'=>$global['expected_revision'])),'csc_invalid_value'),'invalid global color refused');
check(error_is(call_csc('update-global',array('target'=>'header.sticky_background_desktop','value'=>'#ffffff','expected_revision'=>str_repeat('0',64))),'csc_stale_revision'),'stale global input refused before write');
$GLOBALS['url']='https://stpeterpr.org';
check(error_is(call_csc('inspect-global'),'csc_wrong_site'),'global read blocked outside staging');
$GLOBALS['url']='https://stpeterpr.org/design-demo';
foreach (array('header.main_background_desktop'=>'#123456','header.main_height_desktop'=>90,'footer.bottom_background_desktop'=>'#123456','footer.wrap_background_desktop'=>'#123456') as $field=>$value) {
    $before=call_csc('inspect-global');
    $changed=call_csc('update-global',array('target'=>$field,'value'=>$value,'expected_revision'=>$before['expected_revision']));
    check(!is_wp_error($changed) && $changed['readback']['fields'][$field]['stored']===$value,'reusable field '.$field);
    $restored=call_csc('undo',array('change_id'=>$changed['change']['change_id'],'expected_revision'=>$changed['readback']['expected_revision']));
    check(!is_wp_error($restored) && $GLOBALS['options']['theme_mods_kadence']===$initial,'exact per-field undo '.$field);
}
$hargs=array('target'=>'header.sticky_background_desktop','value'=>'#234567','expected_revision'=>$global['expected_revision']);
$GLOBALS['wpdb']->race=true;
check(error_is(call_csc('update-global',$hargs),'csc_conflict'),'theme CAS refuses concurrent option edit');
unset($GLOBALS['options']['theme_mods_kadence']['concurrent']);
$h=call_csc('update-global',$hargs);
check(!is_wp_error($h) && $h['readback']['fields'][$hargs['target']]['stored']==='#234567','header setting readback');
check($GLOBALS['options']['theme_mods_kadence']['unrelated']===$initial['unrelated'],'theme siblings preserved');
$fargs=array('target'=>'footer.middle_background_desktop','value'=>'#abcdef','expected_revision'=>$h['readback']['expected_revision']);
$f=call_csc('update-global',$fargs);
check(!is_wp_error($f) && $f['readback']['fields'][$fargs['target']]['stored']==='#abcdef','same adapter edits footer setting');
check(error_is(call_csc('update-global',$hargs),'csc_stale_revision'),'stale global revision refused');
$hu=call_csc('undo',array('change_id'=>$h['change']['change_id'],'expected_revision'=>$f['readback']['expected_revision']));
check(!is_wp_error($hu) && !$hu['readback']['fields'][$hargs['target']]['stored_present'],'header undo restores missing override');
check($GLOBALS['options']['theme_mods_kadence']['footer_middle_background']['desktop']['color']==='#abcdef','independent header undo preserves later footer setting');
$fu=call_csc('undo',array('change_id'=>$f['change']['change_id'],'expected_revision'=>$hu['readback']['expected_revision']));
check(!is_wp_error($fu) && $GLOBALS['options']['theme_mods_kadence']===$initial,'footer undo restores original option including absent overrides');
$nav=call_csc('inspect-navigation',array('item_id'=>3812));
check($nav['menu_id']===10 && count($nav['locations'])===2,'classic nav item and desktop/mobile locations');
$nargs=array('item_id'=>3812,'field'=>'label','value'=>"St. Mary's",'expected_revision'=>$nav['expected_revision']);
$n=call_csc('update-navigation',$nargs);
check(!is_wp_error($n) && $n['readback']['label']==="St. Mary's" && $n['readback']['url']===$nav['url'],'label edit keeps destination');
check(error_is(call_csc('update-navigation',$nargs),'csc_stale_revision'),'stale navigation revision refused');
check(error_is(call_csc('update-navigation',array('item_id'=>3812,'field'=>'url','value'=>'https://evil.example','expected_revision'=>$n['readback']['expected_revision'])),'csc_invalid_value'),'post-type URL mutation refused');
$nu=call_csc('undo',array('change_id'=>$n['change']['change_id'],'expected_revision'=>$n['readback']['expected_revision']));
check(!is_wp_error($nu) && $nu['readback']['label']===$nav['label'],'navigation undo restores exact label');
$nav=call_csc('inspect-navigation',array('item_id'=>3812));
check($nav['attr_title']==='','blank raw title attribute is represented accurately');
check(error_is(call_csc('update-navigation',array('item_id'=>3812,'field'=>'label','value'=>'','expected_revision'=>$nav['expected_revision'])),'csc_invalid_value'),'empty menu label remains forbidden');
check(error_is(call_csc('update-navigation',array('item_id'=>3812,'field'=>'attr_title','value'=>'<script>','expected_revision'=>$nav['expected_revision'])),'csc_invalid_value'),'HTML in title attribute is rejected');
$attr_args=array('item_id'=>3812,'field'=>'attr_title','value'=>"St. Mary's Parish",'expected_revision'=>$nav['expected_revision']);
$attr=call_csc('update-navigation',$attr_args);
check(!is_wp_error($attr) && $attr['readback']['attr_title']==="St. Mary's Parish" && $GLOBALS['menu_item']->post_excerpt==="St. Mary's Parish",'apostrophe title attribute round-trips through raw storage');
$formatted=wp_setup_nav_menu_item(get_post(3812));
check($formatted->attr_title==='St. Mary&#8217;s Parish' && $attr['readback']['attr_title']!==$formatted->attr_title,'formatted WordPress title attribute does not break revision/readback');
check(error_is(call_csc('update-navigation',$attr_args),'csc_stale_revision'),'stale title-attribute revision rejected');
$clear=call_csc('update-navigation',array('item_id'=>3812,'field'=>'attr_title','value'=>'','expected_revision'=>$attr['readback']['expected_revision']));
check(!is_wp_error($clear) && $clear['readback']['attr_title']==='' && $GLOBALS['menu_item']->post_excerpt==='','title attribute can be explicitly cleared');
$clear_undo=call_csc('undo',array('change_id'=>$clear['change']['change_id'],'expected_revision'=>$clear['readback']['expected_revision']));
check(!is_wp_error($clear_undo) && $clear_undo['readback']['attr_title']==="St. Mary's Parish",'undo restores the nonempty title attribute');
$attr_undo=call_csc('undo',array('change_id'=>$attr['change']['change_id'],'expected_revision'=>$clear_undo['readback']['expected_revision']));
check(!is_wp_error($attr_undo) && $attr_undo['readback']['attr_title']==='' && $GLOBALS['menu_item']->post_excerpt==='','undo restores originally blank title attribute');
check($attr_undo['readback']['label']===$nav['label'] && $attr_undo['readback']['url']===$nav['url'],'attribute editing preserves label and destination');

$css=file_get_contents(__DIR__.'/../assets/mega-navigation.css');
check(strpos($css,'@media (min-width: 1025px)')!==false &&
    strpos($css,'.primary-menu-container.csc-mega-ready > ul.menu > li.menu-item-has-children > a .dropdown-nav-toggle')!==false,
    'desktop chevron suppressed only after mega enhancement initializes');
check(strpos($css,'#mobile-drawer .drawer-inner')!==false &&
    strpos($css,'#mobile-drawer .mobile-navigation button')!==false,
    'native mobile drawer disclosures and scrolling styles preserved');
$js=file_get_contents(__DIR__.'/../assets/mega-navigation.js');
check(strpos($js,"nav.classList.add('csc-mega-ready')")!==false &&
    strpos($js,"window.matchMedia('(min-width: 1025px)')")!==false,
    'JS readiness marker and desktop-only breakpoint remain aligned with chevron CSS');

echo "$count tests passed. Local fakes only; live MCP verification remains separate.\n";
