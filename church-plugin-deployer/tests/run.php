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
function get_current_user_id() {return 10;}
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
check(count($GLOBALS['abilities'])===3,'three abilities registered');
check($GLOBALS['abilities']['church-plugin-deployer/deploy']['meta']['annotations']['destructive']===true,'deploy is labeled destructive');
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
check(is_wp_error(CPD::dispatch('deploy',array('approval_token'=>str_repeat('0',48)))),'unrecognized deployment token refused');
check(is_wp_error(CPD::dispatch('deploy',array('approval_token'=>'malformed'))),'invalid deployment token refused');
$deployed=CPD::dispatch('deploy',array('approval_token'=>$prepared['approval_token']));
check(!is_wp_error($deployed) && $deployed['status']==='deployed','native install with expected build succeeded');
check($GLOBALS['installed_once'] && $GLOBALS['versions']['church-site-controls/church-site-controls.php']==='0.2.1','new plugin version installed and verified');
check(is_wp_error(CPD::dispatch('deploy',array('approval_token'=>$prepared['approval_token']))),'approval token is single-use');
check(!get_option(CPD::LOCK,false),'deployment lock released');
check(get_option($deployed['audit_id'])['status']==='deployed','auditable deployment complete');
echo "$count Church Plugin Deployer tests passed. Fake API and upgrader, not live staging evidence.\n";
