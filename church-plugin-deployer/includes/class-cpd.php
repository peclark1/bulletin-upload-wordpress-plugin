<?php
/**
 * Strict staging-only MCP entry point.
 * Do not expose arbitrary URLs, plugin paths, branch filters, or credentials.
 */
final class CPD {
    const VERSION = '0.2.0';
    const SITE = 'https://stpeterpr.org/design-demo';
    const REPO = 'peclark1/bulletin-upload-wordpress-plugin';
    const WORKFLOW = '.github/workflows/build-test.yml';
    const STATE_PREFIX = 'cpd_ready_';
    const AUDIT_PREFIX = 'cpd_audit_';
    const LOCK = 'cpd_deploy_lock';
    const TTL = 900;

    public static function targets() {
        return array(
            'church-site-controls' => array(
                'file' => 'church-site-controls/church-site-controls.php',
                'name' => 'Church Site Controls',
                'artifact' => '/\\Achurch-site-controls\\z/',
                'archive' => '/\\Achurch-site-controls-[0-9][A-Za-z0-9.\\-]*\\.zip\\z/',
            ),
            'parish-forms' => array(
                'file' => 'parish-forms/parish-forms.php',
                'name' => 'Parish Forms',
                'artifact' => '/\\Aparish-forms-[0-9][A-Za-z0-9.\\-]*\\.zip\\z/',
                'archive' => '/\\Aparish-forms-[0-9][A-Za-z0-9.\\-]*\\.zip\\z/',
            ),
            'bulletin-publisher' => array(
                'file' => 'bulletin-upload-wordpress-plugin/bulletin-upload-wordpress-plugin.php',
                'name' => 'Church Bulletin Publisher',
                'artifact' => '/\\Achurch-bulletin-publisher-[0-9][A-Za-z0-9.\\-]*\\.zip\\z/',
                'archive' => '/\\Achurch-bulletin-publisher-[0-9][A-Za-z0-9.\\-]*\\.zip\\z/',
            ),
        );
    }
    public static function permission() {
        return current_user_can('manage_options') && current_user_can('install_plugins') && current_user_can('update_plugins');
    }
    public static function guard() {
        if (!self::permission()) { return new WP_Error('cpd_forbidden','Administrator plugin-install and plugin-update privileges are required.'); }
        if (untrailingslashit(home_url()) !== self::SITE || untrailingslashit(site_url()) !== self::SITE) {
            return new WP_Error('cpd_wrong_site','This plugin runs only on the /design-demo WordPress installation.');
        }
        if (is_multisite()) { return new WP_Error('cpd_multisite','Multisite deployment is not supported.'); }
        return true;
    }
    public static function register_category() {
        if (function_exists('wp_register_ability_category')) {
            wp_register_ability_category('church-plugin-deployer',array('label'=>'Church Plugin Deployer','description'=>'Staging-only, audited and allowlisted upgrades.'));
        }
    }
    public static function register_abilities() {
        if (!function_exists('wp_register_ability')) { return; }
        $fields=array(
            'inspect'=>array('description'=>'Inspect allowed staging plugins and deployment prerequisites; no changes.','props'=>array(),'required'=>array(),'readonly'=>true),
            'prepare'=>array('description'=>'Validate one successful GitHub Actions artifact and create a private rollback snapshot; never installs.','props'=>array('plugin'=>array('type'=>'string','enum'=>array_keys(self::targets())),'run_id'=>array('type'=>'integer','minimum'=>1)),'required'=>array('plugin','run_id'),'readonly'=>false),
        );
        foreach ($fields as $name=>$f) {
            wp_register_ability('church-plugin-deployer/'.$name,array(
                'label'=>ucfirst($name).' plugin deployment',
                'description'=>$f['description'],
                'category'=>'church-plugin-deployer',
                'input_schema'=>array('type'=>'object','properties'=>$f['props'],'required'=>$f['required'],'additionalProperties'=>false),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'permission_callback'=>array(__CLASS__,'permission'),
                'execute_callback'=>function($args=array()) use ($name) { return self::dispatch($name,$args); },
                'meta'=>array('public'=>true,'show_in_rest'=>true,'mcp'=>array('public'=>true),
                    'annotations'=>array('readonly'=>$f['readonly'],'destructive'=>$name==='deploy','idempotent'=>$name==='inspect'))
            ));
        }
    }
    public static function dispatch($name,$args=array()) {
        $ok=self::guard(); if (is_wp_error($ok)) { return $ok; }
        if (!is_array($args)) { return new WP_Error('cpd_invalid_input','Expected an object.'); }
        $keys=array('inspect'=>array(),'prepare'=>array('plugin','run_id'));
        if (!isset($keys[$name]) || array_diff(array_keys($args),$keys[$name]) || array_diff($keys[$name],array_keys($args))) {
            return new WP_Error('cpd_invalid_input','Missing or unexpected arguments.');
        }
        if ($name==='inspect') { return self::inspect(); }
        if ($name==='prepare') {
            if (!is_string($args['plugin']) || !isset(self::targets()[$args['plugin']]) ||
                !is_int($args['run_id']) || $args['run_id']<1) {
                return new WP_Error('cpd_invalid_input','Unknown plugin or invalid workflow run.');
            }
            return CPD_Package::prepare($args['plugin'],$args['run_id']);
        }
        return new WP_Error('cpd_unknown_ability', 'Deployment requires administrator confirmation inside WordPress.');
    }
    public static function installed($slug) {
        if (!function_exists('get_plugins')) { require_once ABSPATH.'wp-admin/includes/plugin.php'; }
        $targets=self::targets();
        if (!isset($targets[$slug])) { return new WP_Error('cpd_target','Unknown target.'); }
        $file=$targets[$slug]['file'];
        $plugins=get_plugins();
        if (!isset($plugins[$file])) { return new WP_Error('cpd_missing_plugin','Target is not installed under the expected directory.'); }
        $active=is_plugin_active($file);
        return array('version'=>(string)$plugins[$file]['Version'],'active'=>(bool)$active,'file'=>$file);
    }
    public static function inspect() {
        $plugins=array();
        foreach (array_keys(self::targets()) as $slug) {
            $found=self::installed($slug);
            $plugins[$slug]=is_wp_error($found)?array('installed'=>false,'reason'=>$found->get_error_code()):
                array('installed'=>true,'version'=>$found['version'],'active'=>$found['active']);
        }
        $credential=defined('CPD_GITHUB_TOKEN') && is_string(CPD_GITHUB_TOKEN) && strlen(CPD_GITHUB_TOKEN)>12;
        $temp=CPD_Package::private_root(false);
        return array('version'=>self::VERSION,'staging'=>self::SITE,'repository'=>self::REPO,
            'github_credential_configured'=>$credential, 'secure_temp_ready'=>!is_wp_error($temp),
            'zip_extension'=>class_exists('ZipArchive'),'plugins'=>$plugins,
            'deploy_enabled'=>$credential && !is_wp_error($temp) && class_exists('ZipArchive'));
    }
}
require_once __DIR__.'/class-cpd-package.php';
