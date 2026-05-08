<?php
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$table_name = $wpdb->prefix . 'htmd_jobs';
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

delete_option('htmd_settings');

$timestamp = wp_next_scheduled('htmd_cleanup_jobs');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'htmd_cleanup_jobs');
}

$private_root = defined('HTMD_PRIVATE_DIR')
    ? (string) HTMD_PRIVATE_DIR
    : dirname(rtrim((string) ABSPATH, '/\\')) . DIRECTORY_SEPARATOR . 'htmd-private';
$jobs_dir = trailingslashit(wp_normalize_path($private_root)) . 'jobs/';

if (is_dir($jobs_dir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($jobs_dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }

    @rmdir($jobs_dir);
}
