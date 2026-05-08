<?php

if (! defined('ABSPATH')) {
    exit;
}

function htmd_default_settings(): array
{
    return array(
        'maintenance_mode'        => 0,
        'max_files_per_job'       => 40,
        'max_upload_mb'           => 100,
        'daily_operation_quota'   => 500,
        'admin_bypass_quota'      => 1,
        'max_job_lifetime_minutes' => 10,
        'rate_limit_window_minutes' => 10,
        'rate_limit_max_jobs'     => 5,
        'concurrent_jobs_per_client' => 1,
        'strip_page_numbers'      => 1,
        'remove_page_headers'     => 1,
        'keep_footnotes'          => 1,
    );
}

function htmd_get_settings(): array
{
    $settings = get_option('htmd_settings', array());

    return wp_parse_args(is_array($settings) ? $settings : array(), htmd_default_settings());
}

function htmd_user_can_access(?WP_User $user = null): bool
{
    $settings = htmd_get_settings();
    $user = $user ?: wp_get_current_user();
    $is_admin = $user && $user->exists() && user_can($user, 'manage_options');

    if (! empty($settings['maintenance_mode'])) {
        return $is_admin;
    }

    return true;
}

function htmd_get_access_denied_message(?WP_User $user = null): string
{
    return __('The conversion system is temporarily in maintenance mode. Please try again later.', 'html-to-markdown-converter');
}

function htmd_jobs_table_name(): string
{
    global $wpdb;

    return $wpdb->prefix . 'htmd_jobs';
}

function htmd_upload_base_dir(): array
{
    $root = defined('HTMD_PRIVATE_DIR')
        ? (string) HTMD_PRIVATE_DIR
        : dirname(rtrim((string) ABSPATH, '/\\')) . DIRECTORY_SEPARATOR . 'htmd-private';

    $base_dir = trailingslashit(wp_normalize_path($root)) . 'jobs/';

    return array(
        'dir' => $base_dir,
        'url' => '',
    );
}

function htmd_prepare_directory(string $path): bool
{
    if (is_dir($path)) {
        return true;
    }

    return wp_mkdir_p($path);
}

function htmd_prepare_private_directory(string $path): bool
{
    if (! htmd_prepare_directory($path)) {
        return false;
    }

    $index_file = trailingslashit($path) . 'index.php';
    if (! file_exists($index_file)) {
        if (false === @file_put_contents($index_file, "<?php\n// Silence is golden.\n")) {
            return false;
        }
    }

    $htaccess_file = trailingslashit($path) . '.htaccess';
    if (! file_exists($htaccess_file)) {
        if (false === @file_put_contents($htaccess_file, "Options -Indexes\nRequire all denied\nOrder deny,allow\nDeny from all\n")) {
            return false;
        }
    }

    return true;
}

function htmd_client_fingerprint(): string
{
    $user_id = get_current_user_id();
    if ($user_id > 0) {
        return 'user_' . $user_id;
    }

    $ip = '';
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $key) {
        if (empty($_SERVER[ $key ])) {
            continue;
        }

        $candidate = (string) wp_unslash($_SERVER[ $key ]);
        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $candidate = trim((string) strtok($candidate, ','));
        }

        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $ip = $candidate;
            break;
        }
    }

    return 'guest_' . hash_hmac('sha256', $ip !== '' ? $ip : 'unknown', wp_salt('nonce'));
}

function htmd_rate_limit_key(): string
{
    return 'htmd_rate_' . md5(htmd_client_fingerprint());
}

function htmd_lock_option_name(string $name): string
{
    return 'htmd_lock_' . md5($name);
}

function htmd_acquire_lock(string $name, int $ttl_seconds): bool
{
    $option = htmd_lock_option_name($name);
    $now = time();
    $expires = $now + max(30, $ttl_seconds);
    $current = (int) get_option($option, 0);

    if ($current > $now) {
        return false;
    }

    if (add_option($option, (string) $expires, '', 'no')) {
        return true;
    }

    $current = (int) get_option($option, 0);
    if ($current > $now) {
        return false;
    }

    delete_option($option);

    return add_option($option, (string) $expires, '', 'no');
}

function htmd_release_lock(string $name): void
{
    delete_option(htmd_lock_option_name($name));
}

function htmd_generate_storage_key(): string
{
    return 'job_' . wp_generate_password(32, false, false);
}

function htmd_normalize_storage_key(string $storage_key): string
{
    $storage_key = sanitize_file_name($storage_key);

    return preg_match('/\Ajob_[A-Za-z0-9]{16,}\z/', $storage_key) ? $storage_key : '';
}

function htmd_job_directory(string $storage_key, int $job_id = 0): string
{
    $base = htmd_upload_base_dir();
    $safe_key = htmd_normalize_storage_key($storage_key);

    if ($safe_key !== '') {
        return trailingslashit($base['dir']) . $safe_key;
    }

    return $job_id > 0 ? trailingslashit($base['dir']) . $job_id : '';
}

function htmd_flatten_files_array(array $file_post): array
{
    $files = array();

    if (! isset($file_post['name']) || ! is_array($file_post['name'])) {
        return $files;
    }

    foreach ($file_post['name'] as $index => $name) {
        if (empty($name)) {
            continue;
        }

        $files[] = array(
            'name' => $name,
            'type' => $file_post['type'][ $index ] ?? '',
            'tmp_name' => $file_post['tmp_name'][ $index ] ?? '',
            'error' => $file_post['error'][ $index ] ?? UPLOAD_ERR_NO_FILE,
            'size' => $file_post['size'][ $index ] ?? 0,
        );
    }

    return $files;
}

function htmd_is_supported_html(string $path): bool
{
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

    return in_array($extension, array('html', 'htm'), true);
}

function htmd_archive_capabilities(): array
{
    return array(
        'zip' => class_exists('ZipArchive'),
        'rar' => class_exists('RarArchive'),
    );
}

function htmd_is_supported_archive(string $path): bool
{
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

    if ($extension === 'zip') {
        return true;
    }

    if ($extension === 'rar') {
        return class_exists('RarArchive');
    }

    return false;
}

function htmd_smart_output_filename(string $source_relative_path): string
{
    $normalized = str_replace('\\', '/', $source_relative_path);
    $basename   = pathinfo($normalized, PATHINFO_FILENAME);
    $folder     = dirname($normalized);

    if ($basename === '') {
        $basename = 'document';
    }

    if (preg_match('/^\d+$/', $basename) && $folder !== '' && $folder !== '.' && $folder !== '/') {
        $parent = basename($folder);
        $parent = sanitize_file_name($parent);
        if ($parent !== '' && $parent !== '.') {
            return sanitize_file_name($parent . '-' . $basename) . '.md';
        }
    }

    return sanitize_file_name($basename) . '.md';
}

function htmd_delete_directory_tree(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }

    @rmdir($directory);
}

function htmd_delete_job_directory(int $job_id, string $storage_key = ''): void
{
    $directory = htmd_job_directory($storage_key, $job_id);

    if ($directory === '') {
        return;
    }

    htmd_delete_directory_tree($directory);
}

function htmd_job_lifetime_seconds(): int
{
    $settings = htmd_get_settings();
    $minutes = isset($settings['max_job_lifetime_minutes']) ? (int) $settings['max_job_lifetime_minutes'] : 10;
    $minutes = max(1, min(60, $minutes));

    return $minutes * MINUTE_IN_SECONDS;
}
