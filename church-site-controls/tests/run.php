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
function get_post($id) { return $id===3498 ? clone $GLOBALS['page'] : null; }
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
function do_action($name,...$args) { $GLOBALS['hooks'][]=$name; }
function wp_register_ability_category($name,$args) { $GLOBALS['category']=$name; }
function wp_register_ability($name,$args) { $GLOBALS['abilities'][$name]=$args; }
class FakeDB {
    public $posts='wp_posts'; public $options='wp_options';
    function esc_like($s) { return $s; }
    function get_col($query) { return array_slice(array_reverse(array_keys($GLOBALS['options'])),0,20); } public $race=false; public $fail=false;
    function prepare($sql,...$args) {
        if (strpos($sql,'SELECT option_name')===0) { return $args; }
        if (strpos($sql,'BINARY post_content = BINARY %s')===false) { throw new Exception('Missing atomic comparison'); }
        return $args;
    }
    function query($args) {
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
require __DIR__.'/../includes/class-csc-controls.php';
$fixture=file_get_contents(__DIR__.'/fixtures/home-cover.html');
$GLOBALS['page']=(object)array('ID'=>3498,'post_type'=>'page','post_content'=>$fixture);
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
check(count($GLOBALS['abilities'])===7,'seven abilities registered');
foreach ($GLOBALS['abilities'] as $name=>$def) {
    check($def['meta']['mcp']['public'] && $def['meta']['show_in_rest'] && is_callable($def['permission_callback']),'discoverable metadata and permission callback '.$name);
    $write=in_array($name,array('church-site-controls/update-hero','church-site-controls/undo'),true);
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
echo "$count tests passed. Local fakes only; live MCP verification remains separate.\n";
