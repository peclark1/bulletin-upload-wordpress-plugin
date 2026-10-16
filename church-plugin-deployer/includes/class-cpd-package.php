<?php
/**
 * GitHub Actions provenance checks, private snapshot, and native WP upgrader integration.
 * All executable packages must originate from the allowlisted repository and workflow.
 */
final class CPD_Package {
    const MAX_ARCHIVE = 31457280;
    const MAX_EXPANDED = 125829120;

    private static function fail($code,$message) { return new WP_Error('cpd_'.$code,$message); }

    public static function private_root($create=true) {
        $parent=defined('CPD_PRIVATE_DIR') ? CPD_PRIVATE_DIR : get_temp_dir();
        if (!is_string($parent) || !$parent || !is_dir($parent)) {
            return self::fail('private_dir','Configure CPD_PRIVATE_DIR as a private server directory, or provide a writable system temp directory.');
        }
        $real=realpath($parent);
        $docs=array(ABSPATH,defined('WP_CONTENT_DIR')?WP_CONTENT_DIR:ABSPATH,isset($_SERVER['DOCUMENT_ROOT'])?$_SERVER['DOCUMENT_ROOT']:'');
        foreach ($docs as $doc) {
            if (!$doc) { continue; }
            $root=realpath($doc);
            if ($root && ($real===$root || strpos($real,$root.DIRECTORY_SEPARATOR)===0)) {
                return self::fail('unsafe_path','Deployment scratch and backup storage must be outside the public web root.');
            }
        }
        $dir=rtrim($real,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'church-plugin-deployer-'.substr(hash('sha256',ABSPATH),0,14);
        if ($create && !is_dir($dir) && !mkdir($dir,0700,true)) {
            return self::fail('private_dir','Could not create protected deployment scratch directory.');
        }
        if ($create && (!is_dir($dir) || !is_writable($dir))) {
            return self::fail('private_dir','Protected deployment scratch directory is not writable.');
        }
        if (is_dir($dir)) { @chmod($dir,0700); }
        return $dir;
    }
    private static function token() { return bin2hex(random_bytes(24)); }
    private static function fetch_json($path) {
        if (!defined('CPD_GITHUB_TOKEN') || !is_string(CPD_GITHUB_TOKEN) || strlen(CPD_GITHUB_TOKEN)<13) {
            return self::fail('github_not_configured','Set a GitHub fine-grained read-only Actions token in wp-config.php or the server environment.');
        }
        $url='https://api.github.com/repos/'.CPD::REPO.'/'.$path;
        $response=wp_safe_remote_get($url,array('timeout'=>25,'redirection'=>0,
            'headers'=>array('Authorization'=>'Bearer '.CPD_GITHUB_TOKEN,
                'Accept'=>'application/vnd.github+json','X-GitHub-Api-Version'=>'2022-11-28',
                'User-Agent'=>'Church-Plugin-Deployer/0.1')));
        if (is_wp_error($response)) { return self::fail('github_transport','GitHub API request failed.'); }
        $status=wp_remote_retrieve_response_code($response);
        if ($status!==200) {
            return self::fail('github_api','GitHub API rejected request (HTTP '.$status.'). Confirm Actions:read repository token scope.');
        }
        $data=json_decode(wp_remote_retrieve_body($response),true);
        return is_array($data)?$data:self::fail('github_json','GitHub API returned malformed data.');
    }
    private static function allowed_host($url) {
        $p=wp_parse_url($url);
        if (!is_array($p) || ($p['scheme']??'')!=='https' ||
            empty($p['host']) || isset($p['user']) || isset($p['pass']) ||
            (isset($p['port']) && $p['port']!==443)) { return false; }
        $host=strtolower($p['host']);
        foreach (array('githubusercontent.com','blob.core.windows.net') as $base) {
            if ($host===$base || substr($host,-strlen('.'.$base))==='.'. $base) { return true; }
        }
        return false;
    }
    private static function download_artifact($id,$dest) {
        if (!is_int($id) || $id<1) { return self::fail('artifact','Invalid GitHub artifact identifier.'); }
        $endpoint='https://api.github.com/repos/'.CPD::REPO.'/actions/artifacts/'.$id.'/zip';
        $response=wp_safe_remote_get($endpoint,array('timeout'=>25,'redirection'=>0,'headers'=>array(
            'Authorization'=>'Bearer '.CPD_GITHUB_TOKEN,
            'Accept'=>'application/vnd.github+json','User-Agent'=>'Church-Plugin-Deployer/0.1')));
        if (is_wp_error($response)) { return self::fail('artifact','Could not request the approved build archive.'); }
        $status=wp_remote_retrieve_response_code($response);
        if ($status!==302 && $status!==307 && $status!==200) {
            return self::fail('artifact','GitHub artifact was not downloadable (HTTP '.$status.').');
        }
        if ($status===200) { return self::fail('artifact','Unexpected GitHub artifact response; refused to bypass signed redirect validation.'); }
        $url=wp_remote_retrieve_header($response,'location');
        for ($redirect=0;$redirect<3;$redirect++) {
            if (!is_string($url) || !self::allowed_host($url)) {
                return self::fail('artifact_redirect','GitHub supplied an untrusted artifact storage redirect.');
            }
            @unlink($dest);
            $res=wp_safe_remote_get($url,array('timeout'=>90,'redirection'=>0,'stream'=>true,
                'filename'=>$dest,'limit_response_size'=>self::MAX_ARCHIVE,'headers'=>array('User-Agent'=>'Church-Plugin-Deployer/0.1')));
            if (is_wp_error($res)) { @unlink($dest); return self::fail('artifact_transfer','Signed artifact download failed.'); }
            $code=wp_remote_retrieve_response_code($res);
            if ($code===200) {
                if (!is_file($dest) || filesize($dest)<100 || filesize($dest)>self::MAX_ARCHIVE) {
                    @unlink($dest); return self::fail('artifact_size','Artifact exceeded limit or had no content.');
                }
                @chmod($dest,0600); return true;
            }
            $url=($code===302 || $code===307)?wp_remote_retrieve_header($res,'location'):null;
        }
        @unlink($dest); return self::fail('artifact_redirect','Too many redirects or unrecognized download response.');
    }
    private static function safe_member($entry,$directory) {
        if (!is_string($entry) || $entry==='' || strpos($entry,'\\')!==false ||
            strpos($entry,"\0")!==false || $entry[0]==='/' || preg_match('/\\A[A-Za-z]:/',$entry)) { return false; }
        $parts=explode('/',$entry);
        if ($parts[0]!==$directory) { return false; }
        foreach ($parts as $part) {
            if ($part==='.' || $part==='..' || preg_match('/[\\x00-\\x1f]/',$part)) { return false; }
        }
        return true;
    }
    private static function check_zip($zip_path,$target,&$version) {
        if (!class_exists('ZipArchive')) { return self::fail('zip_extension','PHP ZipArchive is required.'); }
        $z=new ZipArchive();
        if ($z->open($zip_path)!==true) { return self::fail('bad_zip','WordPress plugin ZIP cannot be opened.'); }
        $folder=explode('/',$target['file'])[0];
        $entry=$target['file'];
        $sum=0; $found=false;
        if ($z->numFiles>10000) { $z->close(); return self::fail('zip_entries','Too many plugin archive entries.'); }
        for ($i=0;$i<$z->numFiles;$i++) {
            $info=$z->statIndex($i);
            if (!$info || !self::safe_member($info['name'],$folder)) {
                $z->close(); return self::fail('zip_path','Archive includes paths outside the expected plugin directory.');
            }
            $sum+=(int)$info['size'];
            if ($sum>self::MAX_EXPANDED) { $z->close(); return self::fail('zip_size','Uncompressed plugin exceeds size limit.'); }
            if (method_exists($z,'getExternalAttributesIndex')) {
                $opsys=0;$attr=0;
                if ($z->getExternalAttributesIndex($i,$opsys,$attr) && (($attr >> 16) & 0170000)===0120000) {
                    $z->close(); return self::fail('zip_symlink','Symlink in update package is forbidden.');
                }
            }
            if ($info['name']===$entry) { $found=true; }
        }
        if (!$found) { $z->close(); return self::fail('zip_identity','Package does not contain the approved plugin entry point.'); }
        $file=$z->getFromName($entry,8192); $z->close();
        if (!is_string($file) ||
            !preg_match('/^\\s*\\*\\s*Plugin Name:\\s*(.+?)\\s*$/mi',$file,$n) ||
            !preg_match('/^\\s*\\*\\s*Version:\\s*(.+?)\\s*$/mi',$file,$v) ||
            trim($n[1])!==$target['name'] || !preg_match('/\\A[0-9][A-Za-z0-9.\\-]*\\z/',trim($v[1]))) {
            return self::fail('zip_header','Plugin identity or version header does not match the allowlist.');
        }
        $version=trim($v[1]); return true;
    }
    private static function unpack_artifact($source,$target,$destination) {
        $z=new ZipArchive();
        if ($z->open($source)!==true) { return self::fail('bad_artifact','GitHub artifact archive is unreadable.'); }
        $selected=null;
        if ($z->numFiles!==1) { $z->close(); return self::fail('bad_artifact','Artifact must contain exactly one WordPress plugin ZIP.'); }
        $info=$z->statIndex(0);
        if (!$info || !preg_match($target['archive'],$info['name']) || strpos($info['name'],'/')!==false ||
            $info['size']>self::MAX_ARCHIVE || $info['size']<100) {
            $z->close(); return self::fail('bad_artifact','GitHub artifact includes an unexpected plugin package.');
        }
        $stream=$z->getStream($info['name']);
        $output=@fopen($destination,'wb');
        if (!$stream || !$output) {
            if (is_resource($stream)) { fclose($stream); }
            if (is_resource($output)) { fclose($output); }
            $z->close(); return self::fail('private_dir','Could not stage the plugin package.');
        }
        $bytes=stream_copy_to_stream($stream,$output,self::MAX_ARCHIVE+1);
        fclose($stream); fclose($output); $z->close();
        if (!$bytes || $bytes>self::MAX_ARCHIVE || $bytes!==$info['size']) {
            @unlink($destination); return self::fail('bad_artifact','Nested plugin archive is truncated or too large.');
        }
        @chmod($destination,0600);
        return true;
    }
    private static function snapshot($target,$destination) {
        $folder=explode('/',$target['file'])[0];
        $base=realpath(WP_PLUGIN_DIR);
        $source=realpath(WP_PLUGIN_DIR.'/'.$folder);
        if (!$base || !$source || strpos($source,$base.DIRECTORY_SEPARATOR)!==0 || !is_dir($source)) {
            return self::fail('plugin_dir','Target plugin directory is missing or outside WordPress plugins.');
        }
        $zip=new ZipArchive();
        if ($zip->open($destination,ZipArchive::CREATE | ZipArchive::OVERWRITE)!==true) {
            return self::fail('backup','Could not create a private pre-update plugin archive.');
        }
        $n=0;$total=0;
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile() || !$file->isReadable()) {
                $zip->close(); @unlink($destination); return self::fail('backup','Could not safely archive a plugin file.');
            }
            $local=$folder.'/'.substr($file->getPathname(),strlen($source)+1);
            $size=$file->getSize(); $n++;$total+=$size;
            if ($n>10000 || $total>self::MAX_EXPANDED || !$zip->addFile($file->getPathname(),str_replace(DIRECTORY_SEPARATOR,'/',$local))) {
                $zip->close(); @unlink($destination); return self::fail('backup','Current plugin is too large or cannot be archived.');
            }
        }
        if ($n<1 || !$zip->close() || !is_file($destination)) {
            @unlink($destination); return self::fail('backup','Could not finish the current-plugin backup.');
        }
        @chmod($destination,0600);
        $ver=null; $valid=self::check_zip($destination,$target,$ver);
        if (is_wp_error($valid)) { @unlink($destination);return $valid; }
        return array('sha256'=>hash_file('sha256',$destination),'version'=>$ver,'size'=>filesize($destination));
    }
    private static function private_file($root,$filename,$sha) {
        if (!preg_match('/\\A[a-z0-9-]{10,90}\\.zip\\z/',$filename)) { return false; }
        $f=$root.DIRECTORY_SEPARATOR.$filename;
        return is_file($f) && hash_equals($sha,hash_file('sha256',$f)) ? $f : false;
    }
    public static function prepare($slug,$run_id) {
        if (!class_exists('ZipArchive')) { return self::fail('zip_extension','ZipArchive is required.'); }
        $root=self::private_root(); if (is_wp_error($root)) { return $root; }
        $installed=CPD::installed($slug); if (is_wp_error($installed)) { return $installed; }
        if (!$installed['active']) { return self::fail('inactive','Target plugin is inactive; refusing to change its activation state.'); }
        $target=CPD::targets()[$slug];
        $run=self::fetch_json('actions/runs/'.$run_id); if (is_wp_error($run)) { return $run; }
        if (($run['status']??'')!=='completed' || ($run['conclusion']??'')!=='success' ||
            ($run['name']??'')!=='Build WordPress plugin ZIPs' ||
            ($run['path']??'')!==CPD::WORKFLOW ||
            ($run['repository']['full_name']??'')!==CPD::REPO ||
            ($run['head_repository']['full_name']??'')!==CPD::REPO ||
            !in_array($run['event']??'',array('pull_request','push','workflow_dispatch'),true) ||
            !preg_match('/\\A[a-f0-9]{40}\\z/',$run['head_sha']??'')) {
            return self::fail('untrusted_run','Run is not a successful, trusted build from the approved repository and workflow.');
        }
        $branch=$run['head_branch']??'';
        if ($branch!=='main' && !preg_match('/\\A(?:feature|fix)\\/[a-zA-Z0-9._\\/-]+\\z/',$branch)) {
            return self::fail('untrusted_branch','Workflow run branch is not allowlisted for staging deployment.');
        }
        $artifacts=self::fetch_json('actions/runs/'.$run_id.'/artifacts?per_page=100');
        if (is_wp_error($artifacts)) { return $artifacts; }
        $matches=array();
        foreach (($artifacts['artifacts']??array()) as $a) {
            if (!empty($a['expired']) || !isset($a['name']) || !preg_match($target['artifact'],$a['name'])) { continue; }
            if (!isset($a['id']) || (int)$a['id']<1 || ($a['workflow_run']['id']??$run_id)!=$run_id) { continue; }
            $matches[]=$a;
        }
        if (count($matches)!==1) { return self::fail('artifact_match','Expected exactly one approved plugin artifact for this run.'); }

        $id=self::token();
        $outer=$root.'/outer-'.$id.'.zip';
        $package=$root.'/package-'.$id.'.zip';
        $backup=$root.'/backup-'.$id.'.zip';
        $download=self::download_artifact((int)$matches[0]['id'],$outer);
        if (is_wp_error($download)) { return $download; }
        $inner=self::unpack_artifact($outer,$target,$package);
        @unlink($outer);
        if (is_wp_error($inner)) { return $inner; }
        $version=null;$validated=self::check_zip($package,$target,$version);
        if (is_wp_error($validated)) { @unlink($package);return $validated; }
        if (version_compare($version,$installed['version'],'<=')) {
            @unlink($package);return self::fail('version','Approved package must have a version newer than the installed plugin.');
        }
        $backupResult=self::snapshot($target,$backup);
        if (is_wp_error($backupResult)) { @unlink($package);return $backupResult; }
        if ($backupResult['version']!==$installed['version']) {
            @unlink($package); @unlink($backup);return self::fail('backup_version','Current plugin changed while taking the backup.');
        }
        $token=self::token();
        $record=array('plugin'=>$slug,'run_id'=>$run_id,'commit'=>$run['head_sha'],'branch'=>$branch,
            'from_version'=>$installed['version'],'to_version'=>$version,'prepared_by'=>get_current_user_id(),
            'expires'=>time()+CPD::TTL,'status'=>'ready','file'=>basename($package),
            'sha256'=>hash_file('sha256',$package),'backup'=>basename($backup),'backup_sha256'=>$backupResult['sha256']);
        $name=CPD::STATE_PREFIX.hash('sha256',$token);
        if (!add_option($name,$record,'',false)) {
            @unlink($package); @unlink($backup);return self::fail('state','Could not store an approval record.');
        }
        return array('status'=>'ready','plugin'=>$slug,'installed_version'=>$installed['version'],
            'proposed_version'=>$version,'github_run_id'=>$run_id,'commit'=>$run['head_sha'],
            'artifact_sha256'=>$record['sha256'],'backup_created'=>true,'backup_sha256'=>$backupResult['sha256'],
            'backup_size_bytes'=>$backupResult['size'],'approval_token'=>$token,
            'expires_in_seconds'=>CPD::TTL,'note'=>'Preparation does NOT install. Deploy separately with the one-time token after approval.');
    }
    /**
     * Legacy internal token path, not exposed as an MCP ability in v0.2.
     * Both entry points call the same guarded, audited WordPress updater.
     */
    public static function deploy($token) {
        if (!is_string($token) || !preg_match('/\\A[a-f0-9]{48}\\z/', $token)) {
            return self::fail('approval','Invalid prepared authorization.');
        }
        return self::deploy_record(CPD::STATE_PREFIX.hash('sha256',$token));
    }
    public static function deploy_prepared($name) {
        if (!is_string($name) || !preg_match('/\\Acpd_ready_[a-f0-9]{64}\\z/', $name)) {
            return self::fail('approval','Invalid prepared deployment record.');
        }
        return self::deploy_record($name);
    }
    private static function deploy_record($name) {
        $ok=CPD::guard();
        if (is_wp_error($ok)) { return $ok; }
        $record=get_option($name,false);
        if (!is_array($record) || ($record['status']??'')!=='ready' || ($record['expires']??0)<time() ||
            ($record['prepared_by']??0)!==get_current_user_id()) {
            return self::fail('approval','No current prepared deployment belongs to this administrator.');
        }
        if (!isset(CPD::targets()[$record['plugin']])) { return self::fail('approval','Unrecognized prepared plugin.'); }
        $root=self::private_root(false);if (is_wp_error($root)) { return $root; }
        $package=self::private_file($root,$record['file'],$record['sha256']);
        $backup=self::private_file($root,$record['backup'],$record['backup_sha256']);
        if (!$package || !$backup) { return self::fail('snapshot','Prepared package or recovery snapshot is missing or changed.'); }
        $installed=CPD::installed($record['plugin']);
        if (is_wp_error($installed) || !$installed['active'] || $installed['version']!==$record['from_version']) {
            return self::fail('stale','Installed plugin changed or became inactive after preparation.');
        }
        if (!function_exists('get_filesystem_method')) { require_once ABSPATH.'wp-admin/includes/file.php'; }
        if (get_filesystem_method()!=='direct') {
            return self::fail('filesystem','WordPress filesystem access is not direct; manual ZIP upgrade remains required.');
        }
        if (!add_option(CPD::LOCK,array('at'=>time(),'actor'=>get_current_user_id()),'',false)) {
            return self::fail('busy','Another deployment lock is present. Inspect and clear it only after manual review.');
        }
        $audit=array('plugin'=>$record['plugin'],'run_id'=>$record['run_id'],'commit'=>$record['commit'],
            'from_version'=>$record['from_version'],'to_version'=>$record['to_version'],
            'actor'=>get_current_user_id(),'at'=>gmdate('c'),'status'=>'attempted',
            'package_sha256'=>$record['sha256'],'backup_sha256'=>$record['backup_sha256']);
        $auditKey=CPD::AUDIT_PREFIX.bin2hex(random_bytes(12));
        if (!add_option($auditKey,$audit,'',false)) { delete_option(CPD::LOCK);return self::fail('audit','Could not create a deployment audit record.'); }
        $result=null;
        try {
            if (!class_exists('Plugin_Upgrader')) { require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php'; }
            $upgrader=new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            $done=$upgrader->install($package,array('overwrite_package'=>true));
            if (is_wp_error($done) || $done!==true) {
                $result=self::fail('install','WordPress native plugin installer failed; recovery ZIP is retained on the server.');
            } else {
                wp_clean_plugins_cache(true);
                $after=CPD::installed($record['plugin']);
                if (is_wp_error($after) || !$after['active'] || $after['version']!==$record['to_version']) {
                    $result=self::fail('verify','WordPress upgrade finished but plugin version/activation verification failed; recovery ZIP retained.');
                } else {
                    $audit['status']='deployed';
                    $result=array('status'=>'deployed','plugin'=>$record['plugin'],
                        'installed_version'=>$after['version'],'active'=>$after['active'],
                        'commit'=>$record['commit'],'github_run_id'=>$record['run_id'],
                        'backup_retained'=>true,'audit_id'=>$auditKey);
                }
            }
        } catch (Throwable $e) {
            // Do not leak local paths or stack traces to MCP.
            $result=self::fail('install_exception','Native plugin install raised an exception; recovery ZIP retained.');
        } finally {
            if (is_wp_error($result)) { $audit['status']='failed_or_unverified'; }
            update_option($auditKey,$audit,false);
            $record['status']='used';update_option($name,$record,false);
            if (is_file($package)) { @unlink($package); }
            delete_option(CPD::LOCK);
        }
        return $result;
    }
}
