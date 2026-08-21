<?php

if (! defined('ABSPATH')) {
    exit;
}

final class CBP_PDF_Merger
{
    public static function diagnostic()
    {
        if (! function_exists('proc_open')) {
            return new WP_Error('cbp_proc_open', __('PHP proc_open() is disabled; the server cannot start a PDF merge utility.', 'church-bulletin-publisher'));
        }

        $tool = self::find_tool();
        if (! $tool) {
            return new WP_Error('cbp_pdf_tool', __('Neither qpdf nor pdfunite was found. Install one of them, or add a bundled PHP PDF merger before using the plugin.', 'church-bulletin-publisher'));
        }

        return $tool;
    }

    public static function merge(array $inputs, $output)
    {
        if (count($inputs) < 2) {
            return new WP_Error('cbp_input_count', __('At least two PDF components are required.', 'church-bulletin-publisher'));
        }

        foreach ($inputs as $input) {
            if (! self::is_pdf($input)) {
                return new WP_Error('cbp_invalid_pdf', sprintf(__('The file %s is not a readable PDF.', 'church-bulletin-publisher'), basename($input)));
            }
        }

        $tool = self::diagnostic();
        if (is_wp_error($tool)) {
            return $tool;
        }

        $args = array_map('escapeshellarg', $inputs);
        if ($tool['name'] === 'qpdf') {
            $command = escapeshellarg($tool['path']) . ' --empty --pages ' . implode(' ', $args) . ' -- ' . escapeshellarg($output);
        } else {
            $command = escapeshellarg($tool['path']) . ' ' . implode(' ', $args) . ' ' . escapeshellarg($output);
        }

        $result = self::run($command);
        if ($result['exit_code'] !== 0 || ! self::is_pdf($output)) {
            @unlink($output);
            return new WP_Error('cbp_merge_failed', __('The PDF merge failed.', 'church-bulletin-publisher') . ' ' . trim($result['stderr']));
        }

        return true;
    }

    public static function is_pdf($path)
    {
        if (! is_readable($path) || filesize($path) < 5) {
            return false;
        }
        $handle = fopen($path, 'rb');
        if (! $handle) {
            return false;
        }
        $magic = fread($handle, 5);
        fclose($handle);
        return $magic === '%PDF-';
    }

    private static function find_tool()
    {
        $candidates = array(
            'qpdf' => array('/usr/bin/qpdf', '/usr/local/bin/qpdf'),
            'pdfunite' => array('/usr/bin/pdfunite', '/usr/local/bin/pdfunite'),
        );

        foreach ($candidates as $name => $paths) {
            foreach ($paths as $path) {
                if (is_executable($path)) {
                    return array('name' => $name, 'path' => $path);
                }
            }
        }

        $path = getenv('PATH');
        foreach (explode(PATH_SEPARATOR, (string) $path) as $directory) {
            foreach (array_keys($candidates) as $name) {
                $candidate = trailingslashit($directory) . $name;
                if (is_executable($candidate)) {
                    return array('name' => $name, 'path' => $candidate);
                }
            }
        }
        return false;
    }

    private static function run($command)
    {
        $pipes = array();
        $process = proc_open(
            $command,
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        if (! is_resource($process)) {
            return array('exit_code' => 1, 'stdout' => '', 'stderr' => 'Unable to start PDF utility.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        return array('exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr);
    }
}
