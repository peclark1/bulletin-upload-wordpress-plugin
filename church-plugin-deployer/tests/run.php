<?php
// Isolated WordPress/GitHub/installer simulation. No real HTTP, credentials or site writes.
if (!class_exists('ZipArchive')) { fwrite(STDERR,"PHP zip extension is required for CPD tests.\n"); exit(1); }
$work=sys_get_temp_dir().'/cpd-test-'.bin2hex(random_bytes(5));
mkdir($work,0700,true);mkdir($work.'/webroot/plugins/church-site-controls',0700,true);mkdir($work.'/webroot/wp-content',0700,true);
define('ABSPATH',$work.'/webroot/');
define('WP_CONTENT_DIR',$work.'/webroot/wp-content');
define('WP_PLUGIN_DIR',$work.'/webroot/plugins');
define('CPD_GITHUB_TOKEN','non-production-fixture-token');
define('CPD_PRIVATE_DIR',$work);
$GLOBALS['home']='https://stpeterpr.org/design-demo';
$GLOBALS['allowed']=true;
$GLOBALS['options']=array();
$GLOBALS['versions']=array('church-site-controls/church-site-controls.php'=>'0.2.0');
$GLOBALS['active']=true;
$GLOBALS['run_ok']=true;
$GLOBALS['artifact_name']='church-site-controls';
$GLOBALS['installed_once']=false;
$GLOBALS['permissions']=array();
$GLOBALS['abilities']=array();
$GLOBALS['calls']=array();
$GLOBALS['admin_pages']=array();
$GLOBALS['nonce_valid']=true;
$GLOBALS['test_user']=10;
$GLOBALS['transients']=array();
class CPD_Test_DB {
    public $options = 'wp_options';
    public function prepare($sql,$match) { return str_replace('%s', "'" . $match . "'", $sql); }
    public function esc_like($str) { return $str; }
    public function get_col($query) {
        return array_values(array_filter(array_keys($GLOBALS['options']), function($x) {
            return strpos($x,'cpd_ready_')===0;
        }));
    }
}
$GLOBALS['wpdb']=new CPD_Test_DB();
file_put_contents(WP_PLUGIN_DIR.'/church-site-controls/church-site-controls.php',"<?php\n/**\n * Plugin Name: Church Site Controls\n * Version: 0.2.0\n */\n");

class WP_Error {
    private $code;private $message;
    function __construct($code,$message,$data=null) {$this->code=$code;$this->message=$message;}
    function get_error_code() {return $this->code;}
    function get_error_message() {return $this->message;}
}
function is_wp_error($v) {return $v instanceof WP_Error;}
function current_user_can($cap) {return $GLOBALS['allowed'];}
function home_url() {return $GLOBALS['home'];}
function site_url() {return $GLOBALS['home'];}
function untrailingslashit($url) {return rtrim($url,'/');}
function is_multisite() {return false;}
function wp_register_ability_category($name,$args) {$GLOBALS['category']=$name;}
function wp_register_ability($name,$args) {$GLOBALS['abilities'][$name]=$args;}
function get_plugins() {return array('church-site-controls/church-site-controls.php'=>array('Version'=>$GLOBALS['versions']['church-site-controls/church-site-controls.php']));}
function is_plugin_active($file) {return $file==='church-site-controls/church-site-controls.php' && $GLOBALS['active'];}
function get_option($k,$default=false) {return $GLOBALS['options'][$k]??$default;}
function add_option($k,$v,$deprecated='',$autoload=null) {if(array_key_exists($k,$GLOBALS['options']))return false;$GLOBALS['options'][$k]=$v;return true;}
function update_option($k,$v,$autoload=null) {$GLOBALS['options'][$k]=$v;return true;}
function delete_option($k) {unset($GLOBALS['options'][$k]);return true;}
function get_current_user_id() {return $GLOBALS['test_user'];}
function add_management_page($title,$menu,$cap,$slug,$callback) {
    $GLOBALS['admin_pages'][$slug]=array('title'=>$title,'cap'=>$cap,'callback'=>$callback);
}
function admin_url($path='') {return 'https://stpeterpr.org/design-demo/wp-admin/'.$path;}
function esc_html($s) {return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_attr($s) {return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_url($s) {return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function wp_nonce_field($action,$name='_wpnonce') {
    echo '<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr('nonce_'.$action).'">';
}
function wp_verify_nonce($nonce,$action) {
    return $GLOBALS['nonce_valid'] && $nonce==='nonce_'.$action ? 1 : false;
}
function wp_unslash($x) {return $x;}
function submit_button($text,$type='primary',$name='submit',$wrap=true) {
    echo '<button class="button button-'.esc_attr($type).'">'.esc_html($text).'</button>';
}
function set_transient($key,$value,$expiration) {$GLOBALS['transients'][$key]=$value;return true;}
function get_transient($key) {return $GLOBALS['transients'][$key]??false;}
function delete_transient($key) {unset($GLOBALS['transients'][$key]);return true;}
function wp_die($message) {throw new RuntimeException((string)$message);}
function get_temp_dir() {return sys_get_temp_dir().'/';}
function get_filesystem_method() {return 'direct';}
function wp_clean_plugins_cache($force=false) {}
function wp_parse_url($url) {return parse_url($url);}
function wp_remote_retrieve_response_code($r) {return $r['code'];}
function wp_remote_retrieve_body($r) {return $r['body']??'';}
function wp_remote_retrieve_header($r,$key) {return $r['headers'][$key]??null;}
function wp_safe_remote_get($url,$opts=array()) {
    $GLOBALS['calls'][]=$url;
    if(strpos($url,'/actions/runs/44/artifacts?')!==false) {
        return array('code'=>200,'body'=>json_encode(array('artifacts'=>array(array('name'=>$GLOBALS['artifact_name'],'id'=>99,'expired'=>false,'workflow_run'=>array('id'=>44))))));
    }
    if(strpos($url,'/actions/runs/44')!==false) {
        return array('code'=>200,'body'=>json_encode(array('status'=>'completed','conclusion'=>$GLOBALS['run_ok']?'success':'failure',
            'name'=>'Build WordPress plugin ZIPs','path'=>'.github/workflows/build-test.yml',
            'repository'=>array('full_name'=>'peclark1/bulletin-upload-wordpress-plugin'),
            'head_repository'=>array('full_name'=>'peclark1/bulletin-upload-wordpress-plugin'),
            'head_branch'=>'feature/church-site-controls-v0.3-navigation','head_sha'=>str_repeat('a',40),'event'=>'pull_request')));
    }
    if(strpos($url,'/actions/artifacts/99/zip')!==false) {
        return array('code'=>302,'headers'=>array('location'=>'https://pipelinesghubeus.blob.core.windows.net/approved-artifact'));
    }
    if(strpos($url,'https://pipelinesghubeus.blob.core.windows.net/')===0) {
        if (!empty($opts['headers']['Authorization'])) {return new WP_Error('leak','Token was forwarded to blob storage');}
        copy($GLOBALS['outer_archive'],$opts['filename']);
        return array('code'=>200);
    }
    return new WP_Error('unexpected','Unexpected outbound URL');
}
class Automatic_Upgrader_Skin {}
class Plugin_Upgrader {
    function __construct($skin) {}
    function install($package,$args=array()) {
        if (empty($args['overwrite_package'])) return new WP_Error('replace_required','Overwrite not set');
        $z=new ZipArchive();if($z->open($package)!==true)return false;
        $file=$z->getFromName('church-site-controls/church-site-controls.php');$z->close();
        if (!is_string($file) || strpos($file,'0.2.1')===false)return false;
        file_put_contents(WP_PLUGIN_DIR.'/church-site-controls/church-site-controls.php',$file);
        $GLOBALS['versions']['church-site-controls/church-site-controls.php']='0.2.1';
        $GLOBALS['installed_once']=true;return true;
    }
}

require __DIR__.'/../includes/class-cpd.php';
require __DIR__.'/../includes/class-cpd-admin.php';
$count=0;
function check($ok,$label) {global $count;if (!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);} echo "PASS $label\n";$count++;}
$z=new ZipArchive();
$inner=$work.'/inner.zip';
$z->open($inner,ZipArchive::CREATE);
$z->addFromString('church-site-controls/church-site-controls.php',"<?php\n/**\n * Plugin Name: Church Site Controls\n * Version: 0.2.1\n */\n");
$z->addFromString('church-site-controls/readme.txt','Successful CI artifact from fixture');
$z->close();
$outer=$work.'/artifact.zip';
$z->open($outer,ZipArchive::CREATE);$z->addFile($inner,'church-site-controls-0.2.1.zip');$z->close();
$GLOBALS['outer_archive']=$outer;

CPD::register_category();CPD::register_abilities();
check(count($GLOBALS['abilities'])===2,'only inspect and prepare MCP abilities are registered');
check(!isset($GLOBALS['abilities']['church-plugin-deployer/deploy']),'destructive deploy ability is absent from MCP interface');
check($GLOBALS['abilities']['church-plugin-deployer/inspect']['meta']['annotations']['readonly']===true,'inspection labeled read-only');
$info=CPD::dispatch('inspect');
check($info['plugins']['church-site-controls']['active'] && $info['plugins']['church-site-controls']['version']==='0.2.0','inspect reports installed plugin and version');
check($info['deploy_enabled']===true,'staging prerequisites ready with test credential');
$GLOBALS['home']='https://stpeterpr.org';
check(is_wp_error(CPD::dispatch('inspect')),'production URL refused');
$GLOBALS['home']='https://stpeterpr.org/design-demo';
$GLOBALS['allowed']=false;
check(is_wp_error(CPD::dispatch('inspect')),'unauthorized user refused');
$GLOBALS['allowed']=true;
check(is_wp_error(CPD::dispatch('prepare',array('plugin'=>'wp-staging','run_id'=>44))),'unallowlisted plugin refused');
check(is_wp_error(CPD::dispatch('prepare',array('plugin'=>'church-site-controls','run_id'=>44,'url'=>'https://example.org'))),'arbitrary URL rejected');
$GLOBALS['run_ok']=false;
check(is_wp_error(CPD::dispatch('prepare',array('plugin'=>'church-site-controls','run_id'=>44))),'failed CI workflow refused');
$GLOBALS['run_ok']=true;
$GLOBALS['artifact_name']='random-plugin';
check(is_wp_error(CPD::dispatch('prepare',array('plugin'=>'church-site-controls','run_id'=>44))),'unrecognized artifact refused');
$GLOBALS['artifact_name']='church-site-controls';
$prepared=CPD::dispatch('prepare',array('plugin'=>'church-site-controls','run_id'=>44));
check(!is_wp_error($prepared) && $prepared['status']==='ready' && $prepared['proposed_version']==='0.2.1','verified artifact prepared');
check($prepared['backup_created'] && strlen($prepared['backup_sha256'])===64,'recoverable local plugin backup is hashed');
check($GLOBALS['installed_once']===false,'prepare never changes installed plugin');
check(is_wp_error(CPD::dispatch('deploy',array('approval_token'=>$prepared['approval_token']))),'MCP deploy dispatch rejected');
check(is_wp_error(CPD_Package::deploy(str_repeat('0',48))),'unrecognized backend deployment token refused');
check(is_wp_error(CPD_Package::deploy('malformed')),'invalid backend deployment token refused');
$deployed=CPD_Package::deploy($prepared['approval_token']);
check(!is_wp_error($deployed) && $deployed['status']==='deployed','native install with expected build succeeded');
check($GLOBALS['installed_once'] && $GLOBALS['versions']['church-site-controls/church-site-controls.php']==='0.2.1','new plugin version installed and verified');
check(is_wp_error(CPD_Package::deploy($prepared['approval_token'])),'approval token is single-use');
check(!get_option(CPD::LOCK,false),'deployment lock released');
check(get_option($deployed['audit_id'])['status']==='deployed','auditable deployment complete');

// Reset our fake plugin for a separate, entirely UI-driven installation test.
$GLOBALS['versions']['church-site-controls/church-site-controls.php']='0.2.0';
$GLOBALS['installed_once']=false;
file_put_contents(WP_PLUGIN_DIR.'/church-site-controls/church-site-controls.php',"<?php\n/**\n * Plugin Name: Church Site Controls\n * Version: 0.2.0\n */\n");
CPD_Admin::register();
check(isset($GLOBALS['admin_pages'][CPD_Admin::SLUG]),'admin tools page registered on staging');

$GLOBALS['home']='https://stpeterpr.org';
$GLOBALS['admin_pages']=array();
CPD_Admin::register();
check(!$GLOBALS['admin_pages'],'admin menu is not registered on production');
$GLOBALS['home']='https://stpeterpr.org/design-demo';
$GLOBALS['allowed']=false;
CPD_Admin::register();
check(!$GLOBALS['admin_pages'],'admin menu is not registered for unauthorized users');
$GLOBALS['allowed']=true;

$prepared_ui=CPD::dispatch('prepare',array('plugin'=>'church-site-controls','run_id'=>44));
check(!is_wp_error($prepared_ui) && $prepared_ui['status']==='ready','fresh UI-only candidate is prepared');
$items=CPD_Admin::prepared();
$matched=array_values(array_filter($items,function($item)use($prepared_ui){
    return ($item['record']['sha256'] ?? '')===$prepared_ui['artifact_sha256'] &&
        ($item['record']['status'] ?? '')==='ready';
}));
check(count($matched)>=1,'admin screen discovers pending records from options');
$key=end($matched)['option'];
$record=get_option($key);
check(strpos($key,'cpd_ready_')===0 && $record['prepared_by']===10,'UI uses hashed option key and current administrator');
ob_start();
CPD_Admin::page();
$html=ob_get_clean();
check(strpos($html,'Church Plugin Deployer')!==false &&
    strpos($html,'Install Prepared Update')!==false,'admin approval screen renders current update');
check(strpos($html,$prepared_ui['approval_token'])===false,'admin screen never exposes raw MCP approval token');
check(strpos($html,'0.2.0')!==false && strpos($html,'0.2.1')!==false &&
    strpos($html,'Recovery ZIP SHA-256')!==false,'admin UI displays version and recovery evidence');
check(is_wp_error(CPD_Admin::apply_request('GET',array('prepared'=>$key,'cpd_nonce'=>'nonce_'.CPD_Admin::ACTION.'_'.$key))),
    'GET cannot initiate native installer');
check(is_wp_error(CPD_Admin::apply_request('POST',array('prepared'=>'anything','cpd_nonce'=>'test'))),
    'unrecognized option key is rejected');
check(is_wp_error(CPD_Admin::apply_request('POST',array('prepared'=>$key,'cpd_nonce'=>'invalid'))),
    'wrong WordPress nonce rejected');
check(!$GLOBALS['installed_once'],'nonce and method failures never replace installed plugin');
$GLOBALS['test_user']=11;
check(count(CPD_Admin::prepared())===0,'other admin cannot list another user prepared updates');
check(is_wp_error(CPD_Admin::apply_request('POST',array('prepared'=>$key,
    'cpd_nonce'=>'nonce_'.CPD_Admin::ACTION.'_'.$key))),'another admin cannot deploy private prepared update');
$GLOBALS['test_user']=10;
$GLOBALS['home']='https://stpeterpr.org';
check(is_wp_error(CPD_Admin::apply_request('POST',array('prepared'=>$key,
    'cpd_nonce'=>'nonce_'.CPD_Admin::ACTION.'_'.$key))),'production site cannot invoke admin handler');
$GLOBALS['home']='https://stpeterpr.org/design-demo';
$before=get_option($key);
$before['expires']=time()-1;update_option($key,$before);
check(count(array_filter(CPD_Admin::prepared(),function($item)use($key){return $item['option']===$key;}))===0,
    'expired preparations do not render as actionable');
check(is_wp_error(CPD_Admin::apply_request('POST',array('prepared'=>$key,
    'cpd_nonce'=>'nonce_'.CPD_Admin::ACTION.'_'.$key))),'expired preparation cannot install');
$before['expires']=time()+900;update_option($key,$before);
$GLOBALS['nonce_valid']=false;
check(is_wp_error(CPD_Admin::apply_request('POST',array('prepared'=>$key,
    'cpd_nonce'=>'nonce_'.CPD_Admin::ACTION.'_'.$key))),'invalidated nonce prevents install');
$GLOBALS['nonce_valid']=true;
$GLOBALS['versions']['church-site-controls/church-site-controls.php']='0.2.99';
check(is_wp_error(CPD_Admin::apply_request('POST',array('prepared'=>$key,
    'cpd_nonce'=>'nonce_'.CPD_Admin::ACTION.'_'.$key))),'changed installed version prevents stale update');
$GLOBALS['versions']['church-site-controls/church-site-controls.php']='0.2.0';
$result=CPD_Admin::apply_request('POST',array('prepared'=>$key,
    'cpd_nonce'=>'nonce_'.CPD_Admin::ACTION.'_'.$key));
check(!is_wp_error($result) && $result['status']==='deployed' &&
    $GLOBALS['versions']['church-site-controls/church-site-controls.php']==='0.2.1',
    'valid WP admin confirmation invokes shared native installer and verifies version');
check(!get_option(CPD::LOCK,false) && get_option($result['audit_id'])['status']==='deployed',
    'admin path releases lock and records durable audit');
check(is_wp_error(CPD_Admin::apply_request('POST',array('prepared'=>$key,
    'cpd_nonce'=>'nonce_'.CPD_Admin::ACTION.'_'.$key))),'WordPress confirmation is single-use');
check(count(array_filter(CPD_Admin::prepared(),function($item)use($key){return $item['option']===$key;}))===0,
    'used update is removed from admin page list');

echo "$count Church Plugin Deployer tests passed. Fake API and upgrader, not live staging evidence.\n";
