<?php

if (! defined('ABSPATH')) {
    exit;
}

final class CBP_Storage
{
    public static function private_root()
    {
        return trailingslashit(WP_CONTENT_DIR) . 'bulletin-publisher-private';
    }

    public static function published_root($test_mode)
    {
        if ($test_mode) {
            $uploads = wp_upload_dir();
            return trailingslashit($uploads['basedir']) . 'bulletin-publisher-test';
        }

        return trailingslashit(WP_CONTENT_DIR) . 'bulletins';
    }

    public static function published_url($test_mode)
    {
        if ($test_mode) {
            $uploads = wp_upload_dir();
            return trailingslashit($uploads['baseurl']) . 'bulletin-publisher-test';
        }

        return trailingslashit(content_url()) . 'bulletins';
    }

    public static function ensure_directories()
    {
        $private = self::private_root();
        $test = self::published_root(true);

        if (! wp_mkdir_p($private) || ! wp_mkdir_p($test)) {
            return new WP_Error('cbp_directory', __('Bulletin Publisher could not create its storage directories.', 'church-bulletin-publisher'));
        }

        self::protect_private_directory($private);
        self::write_index_file($private);
        self::write_index_file($test);
        return true;
    }

    public static function create_job_directory($user_id)
    {
        $result = self::ensure_directories();
        if (is_wp_error($result)) {
            return $result;
        }

        $token = wp_generate_uuid4();
        $path = trailingslashit(self::private_root()) . absint($user_id) . '/' . $token;
        if (! wp_mkdir_p($path)) {
            return new WP_Error('cbp_job_directory', __('Could not create a private preview directory.', 'church-bulletin-publisher'));
        }
        self::write_index_file($path);
        return $path;
    }

    public static function delete_tree($path)
    {
        $root = wp_normalize_path(self::private_root());
        $target = wp_normalize_path($path);
        if (strpos($target, trailingslashit($root)) !== 0 || ! is_dir($target)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($target);
    }

    private static function protect_private_directory($path)
    {
        $rules = "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
        $file = trailingslashit($path) . '.htaccess';
        if (! file_exists($file)) {
            @file_put_contents($file, $rules, LOCK_EX);
        }
    }

    private static function write_index_file($path)
    {
        $file = trailingslashit($path) . 'index.php';
        if (! file_exists($file)) {
            @file_put_contents($file, "<?php\n// Silence is golden.\n", LOCK_EX);
        }
    }
}
