<?php

if (! defined('ABSPATH')) {
    exit;
}

class HTMD_Upload_Handler
{
    private HTMD_Job_Repository $jobs;
    private HTMD_HTML_Normalizer $normalizer;
    private HTMD_Markdown_Converter $converter;
    private HTMD_Archive_Extractor $extractor;

    public function __construct(
        HTMD_Job_Repository $jobs,
        HTMD_HTML_Normalizer $normalizer,
        HTMD_Markdown_Converter $converter,
        HTMD_Archive_Extractor $extractor
    ) {
        $this->jobs = $jobs;
        $this->normalizer = $normalizer;
        $this->converter = $converter;
        $this->extractor = $extractor;
    }

    public function register(): void
    {
        add_action('wp_ajax_htmd_submit_job', array($this, 'handle_ajax_submission'));
        add_action('wp_ajax_nopriv_htmd_submit_job', array($this, 'handle_ajax_submission'));
        add_action('htmd_cleanup_jobs', array($this, 'cleanup_expired_jobs'));

        if (! wp_next_scheduled('htmd_cleanup_jobs')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'htmd_cleanup_jobs');
        }
    }

    public function handle_ajax_submission(): void
    {
        if (! htmd_user_can_access()) {
            wp_send_json_error(array('message' => htmd_get_access_denied_message()), 403);
        }

        check_ajax_referer('htmd_submit_job', 'htmd_nonce');

        try {
            $settings = htmd_get_settings();
            $this->enforce_rate_limit($settings);
            $lock_key = $this->acquire_client_job_slot($settings);
            if ($lock_key !== '') {
                register_shutdown_function(static function () use ($lock_key): void {
                    htmd_release_lock($lock_key);
                });
            }

            $this->cleanup_expired_jobs();

            $job_options = $this->resolve_job_options($settings);

            $files = isset($_FILES['htmd_files']) ? htmd_flatten_files_array($_FILES['htmd_files']) : array();
            $archive_file = $_FILES['htmd_archive'] ?? null;

            if (empty($files) && (empty($archive_file) || empty($archive_file['name']))) {
                throw new RuntimeException(__('Lütfen en az bir HTML dosyası veya arşiv yükleyin.', 'html-to-markdown-converter'));
            }

            $max_files = (int) $settings['max_files_per_job'];
            if (count($files) > $max_files) {
                throw new RuntimeException(__('Tek bir iş için çok fazla dosya yüklendi.', 'html-to-markdown-converter'));
            }

            $max_upload_bytes = (int) $settings['max_upload_mb'] * 1024 * 1024;
            $total_upload_bytes = 0;
            foreach ($files as $file) {
                $total_upload_bytes += (int) ($file['size'] ?? 0);
            }
            $total_upload_bytes += (int) ($archive_file['size'] ?? 0);

            if ($total_upload_bytes > $max_upload_bytes) {
                throw new RuntimeException(__('Yüklenen dosyalar yapılandırılmış boyut sınırını aşıyor.', 'html-to-markdown-converter'));
            }

            $incoming_input_count = count($files);
            if (! empty($archive_file['name'])) {
                if (! htmd_is_supported_archive((string) $archive_file['name'])) {
                    throw new RuntimeException(__('Yalnızca ZIP ve RAR arşivleri desteklenir.', 'html-to-markdown-converter'));
                }
                $incoming_input_count += $this->extractor->count_html_entries(
                    (string) $archive_file['tmp_name'],
                    $max_files,
                    $max_upload_bytes,
                    count($files),
                    (string) $archive_file['name'] // orijinal ad: uzantı tespiti için (tmp_name uzantısız)
                );
            }

            if ($incoming_input_count < 1) {
                throw new RuntimeException(__('Desteklenen HTML ya da HTM dosyası bulunamadı.', 'html-to-markdown-converter'));
            }

            $daily_quota = (int) ($settings['daily_operation_quota'] ?? 0);
            $admin_bypass_quota = ! empty($settings['admin_bypass_quota']) && current_user_can('manage_options');
            if ($daily_quota > 0 && ! $admin_bypass_quota) {
                $used_today = $this->jobs->get_today_input_volume();
                if (($used_today + $incoming_input_count) > $daily_quota) {
                    throw new RuntimeException(sprintf(
                        __('Günlük dönüştürme kotası doldu. Bugün %1$d kaynak dosya kullanıldı; bu yükleme toplamı %3$d sınırındaki %2$d değerine çıkaracaktı.', 'html-to-markdown-converter'),
                        $used_today,
                        $used_today + $incoming_input_count,
                        $daily_quota
                    ));
                }
            }

            $user_id = get_current_user_id();
            $source_type = ! empty($archive_file['name']) && ! empty($files)
                ? 'mixed'
                : (! empty($archive_file['name']) ? 'archive' : 'files');
            $original_name = ! empty($archive_file['name'])
                ? sanitize_file_name((string) $archive_file['name'])
                : __('HTML Yükleme', 'html-to-markdown-converter');
            $storage_key = htmd_generate_storage_key();

            $job_id = $this->jobs->create($user_id, $source_type, $original_name, $job_options, $storage_key);
            $job_paths = $this->prepare_job_paths($storage_key);

            try {
                $this->jobs->update_status($job_id, 'processing', array(
                    'input_file_count' => $incoming_input_count,
                ));

                $this->store_uploaded_files($files, $job_paths['input']);
                if (! empty($archive_file['name'])) {
                    $stored_archive = $this->store_archive_file($archive_file, $job_paths['input']);
                    $this->extractor->extract(
                        $stored_archive,
                        $job_paths['extracted'],
                        $max_files,
                        $max_upload_bytes,
                        count($files)
                    );
                }

                $html_files = $this->discover_html_files(array($job_paths['input'], $job_paths['extracted']));

                if (empty($html_files)) {
                    throw new RuntimeException(__('Desteklenen HTML ya da HTM dosyası bulunamadı.', 'html-to-markdown-converter'));
                }

                if (count($html_files) > $max_files) {
                    throw new RuntimeException(__('Arşiv, bu iş için izin verilenden daha fazla HTML dosyası içeriyor.', 'html-to-markdown-converter'));
                }

                $conversion = $this->convert_files(
                    $html_files,
                    array($job_paths['input'], $job_paths['extracted']),
                    $job_paths['output'],
                    $job_options
                );

                if ($conversion['success'] < 1) {
                    throw new RuntimeException(__('Hiçbir dosya başarıyla dönüştürülemedi.', 'html-to-markdown-converter'));
                }

                $result_path = $this->package_results($job_paths['output'], $job_paths['root']);

                $token = wp_generate_password(32, false, false);
                $lifetime = htmd_job_lifetime_seconds();

                $this->jobs->update_status($job_id, 'completed', array(
                    'input_file_count'  => count($html_files),
                    'output_file_count' => $conversion['success'],
                    'result_key'        => basename($result_path),
                    'storage_key'       => $storage_key,
                    'error_message'     => '',
                    'download_token'    => $token,
                ));

                set_transient('htmd_dl_' . $token, $job_id, $lifetime);

                $download_url = add_query_arg(array('htmd_download' => $token), home_url('/'));

                wp_send_json_success(array(
                    'report' => array(
                        'total'        => count($html_files),
                        'success'      => $conversion['success'],
                        'failed'       => $conversion['failed'],
                        'failed_files' => $conversion['failed_files'],
                        'output_count' => $conversion['success'],
                    ),
                    'download_url' => $download_url,
                    'expires_in'   => $lifetime,
                ));
            } catch (Throwable $inner) {
                $this->jobs->update_status($job_id, 'failed', array(
                    'input_file_count' => $incoming_input_count,
                    'storage_key'      => $storage_key,
                    'error_message'    => $inner->getMessage(),
                ));
                htmd_delete_job_directory($job_id, $storage_key);
                throw $inner;
            }
        } catch (Throwable $exception) {
            wp_send_json_error(array('message' => $exception->getMessage()));
        }
    }

    public function cleanup_expired_jobs(): void
    {
        $settings = htmd_get_settings();
        $lifetime_minutes = isset($settings['max_job_lifetime_minutes']) ? (int) $settings['max_job_lifetime_minutes'] : 10;
        $lifetime_minutes = max(1, min(60, $lifetime_minutes));
        $abandoned_minutes = max(30, $lifetime_minutes);

        $this->cleanup_rows($this->jobs->get_cleanup_candidates(array('completed', 'failed'), $lifetime_minutes));
        $this->cleanup_rows(
            $this->jobs->get_cleanup_candidates(array('queued', 'processing'), $abandoned_minutes),
            'failed',
            __('The conversion job expired before it could finish.', 'html-to-markdown-converter')
        );
    }

    private function cleanup_rows(array $rows, string $status = '', string $error_message = ''): void
    {
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            htmd_delete_job_directory($id, (string) ($row['storage_key'] ?? ''));
            $this->jobs->mark_storage_cleaned($id, $status, $error_message);
        }
    }

    private function enforce_rate_limit(array $settings): void
    {
        if (current_user_can('manage_options')) {
            return;
        }

        $window_minutes = max(1, min(1440, (int) ($settings['rate_limit_window_minutes'] ?? 10)));
        $max_jobs = max(1, min(1000, (int) ($settings['rate_limit_max_jobs'] ?? 5)));
        $key = htmd_rate_limit_key();
        $now = time();
        $bucket = get_transient($key);

        if (! is_array($bucket) || empty($bucket['reset']) || (int) $bucket['reset'] <= $now) {
            $bucket = array(
                'count' => 0,
                'reset' => $now + ($window_minutes * MINUTE_IN_SECONDS),
            );
        }

        if ((int) $bucket['count'] >= $max_jobs) {
            $retry_after = max(1, (int) $bucket['reset'] - $now);
            throw new RuntimeException(sprintf(
                __('Too many conversion requests. Please wait %d seconds before trying again.', 'html-to-markdown-converter'),
                $retry_after
            ));
        }

        $bucket['count'] = (int) $bucket['count'] + 1;
        set_transient($key, $bucket, max(1, (int) $bucket['reset'] - $now));
    }

    private function acquire_client_job_slot(array $settings): string
    {
        $limit = max(1, min(10, (int) ($settings['concurrent_jobs_per_client'] ?? 1)));
        $ttl = max(60, htmd_job_lifetime_seconds() + 60);
        $base = htmd_client_fingerprint();

        for ($slot = 1; $slot <= $limit; $slot++) {
            $lock_key = $base . '_slot_' . $slot;
            if (htmd_acquire_lock($lock_key, $ttl)) {
                return $lock_key;
            }
        }

        throw new RuntimeException(__('A conversion is already running for this user or IP address. Please wait for it to finish.', 'html-to-markdown-converter'));
    }

    private function resolve_job_options(array $settings): array
    {
        $settings['keep_footnotes'] = isset($_POST['htmd_remove_footnotes']) ? 0 : 1;
        $settings['remove_arabic_diacritics'] = isset($_POST['htmd_remove_arabic_diacritics']) ? 1 : 0;

        return $settings;
    }

    private function prepare_job_paths(string $storage_key): array
    {
        $base = htmd_upload_base_dir();
        if (! htmd_prepare_private_directory($base['dir'])) {
            throw new RuntimeException(__('Upload directories could not be prepared.', 'html-to-markdown-converter'));
        }

        $root = trailingslashit(htmd_job_directory($storage_key));
        if ($root === '/') {
            throw new RuntimeException(__('Upload directories could not be prepared.', 'html-to-markdown-converter'));
        }
        $paths = array(
            'root'      => $root,
            'input'     => $root . 'input/',
            'extracted' => $root . 'extracted/',
            'output'    => $root . 'output/',
        );

        foreach ($paths as $path) {
            if (! htmd_prepare_private_directory($path)) {
                throw new RuntimeException(__('Yükleme klasörleri hazırlanamadı.', 'html-to-markdown-converter'));
            }
        }

        return $paths;
    }

    private function store_uploaded_files(array $files, string $target_dir): array
    {
        $stored = array();

        foreach ($files as $file) {
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException(__('Yüklenen dosyalardan biri geçersiz.', 'html-to-markdown-converter'));
            }

            if (! htmd_is_supported_html((string) $file['name'])) {
                throw new RuntimeException(__('Dosya alanında yalnızca HTML ve HTM dosyalarına izin verilir.', 'html-to-markdown-converter'));
            }

            if (! is_uploaded_file((string) $file['tmp_name'])) {
                throw new RuntimeException(__('One uploaded file is invalid.', 'html-to-markdown-converter'));
            }

            $target = $target_dir . wp_unique_filename($target_dir, sanitize_file_name((string) $file['name']));

            if (! move_uploaded_file((string) $file['tmp_name'], $target)) {
                throw new RuntimeException(__('Yüklenen HTML dosyası kaydedilemedi.', 'html-to-markdown-converter'));
            }

            $stored[] = $target;
        }

        return $stored;
    }

    private function store_archive_file(array $archive, string $target_dir): string
    {
        if ((int) ($archive['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(__('Arşiv yüklemesi başarısız oldu.', 'html-to-markdown-converter'));
        }

        if (! htmd_is_supported_archive((string) $archive['name'])) {
            throw new RuntimeException(__('Yalnızca ZIP ve RAR arşivleri desteklenir.', 'html-to-markdown-converter'));
        }

        if (! is_uploaded_file((string) $archive['tmp_name'])) {
            throw new RuntimeException(__('Archive upload failed.', 'html-to-markdown-converter'));
        }

        $target = $target_dir . wp_unique_filename($target_dir, sanitize_file_name((string) $archive['name']));

        if (! move_uploaded_file((string) $archive['tmp_name'], $target)) {
            throw new RuntimeException(__('Yüklenen arşiv dosyası kaydedilemedi.', 'html-to-markdown-converter'));
        }

        return $target;
    }

    private function discover_html_files(array $directories): array
    {
        $files = array();

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();
                if (htmd_is_supported_html($path)) {
                    $files[] = $path;
                }
            }
        }

        return array_values(array_unique($files));
    }

    private function convert_files(array $html_files, array $source_roots, string $output_dir, array $settings): array
    {
        $success = 0;
        $failed = 0;
        $failed_files = array();

        foreach ($html_files as $source_path) {
            $relative = $this->relative_to_roots($source_path, $source_roots);
            $display_name = $relative !== '' ? $relative : basename($source_path);

            try {
                $html = file_get_contents($source_path);
                if (false === $html) {
                    throw new RuntimeException(__('Kaynak dosya okunamadı.', 'html-to-markdown-converter'));
                }

                $normalized = $this->normalizer->normalize($html, $settings);
                $markdown   = $this->converter->convert($normalized);

                if (! empty($settings['remove_arabic_diacritics'])) {
                    $markdown = (string) preg_replace(
                        '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u',
                        '',
                        $markdown
                    );
                }

                $target_name = wp_unique_filename(
                    $output_dir,
                    htmd_smart_output_filename($relative !== '' ? $relative : basename($source_path))
                );
                $target_path = $output_dir . $target_name;

                $written = file_put_contents($target_path, $markdown);
                if (false === $written) {
                    throw new RuntimeException(__('Markdown çıktısı yazılamadı.', 'html-to-markdown-converter'));
                }

                $success++;
            } catch (Throwable $exception) {
                $failed++;
                $failed_files[] = array(
                    'name'  => $display_name,
                    'error' => $exception->getMessage(),
                );
            }
        }

        return array(
            'success'      => $success,
            'failed'       => $failed,
            'failed_files' => $failed_files,
        );
    }

    private function relative_to_roots(string $source_path, array $roots): string
    {
        $normalized_source = str_replace('\\', '/', $source_path);

        foreach ($roots as $root) {
            $normalized_root = rtrim(str_replace('\\', '/', $root), '/') . '/';
            if ($normalized_root !== '/' && strpos($normalized_source, $normalized_root) === 0) {
                return substr($normalized_source, strlen($normalized_root));
            }
        }

        return basename($source_path);
    }

    private function package_results(string $output_dir, string $root_dir): string
    {
        if (! class_exists('ZipArchive')) {
            throw new RuntimeException(__('Sonuçları paketlemek için ZipArchive gerekiyor ama bulunamadı.', 'html-to-markdown-converter'));
        }

        $result_path = $root_dir . 'result.zip';
        $archive = new ZipArchive();
        $opened = $archive->open($result_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if (true !== $opened) {
            throw new RuntimeException(__('Sonuç ZIP dosyası oluşturulamadı.', 'html-to-markdown-converter'));
        }

        $iterator = new DirectoryIterator($output_dir);
        foreach ($iterator as $file) {
            if ($file->isDot() || ! $file->isFile()) {
                continue;
            }

            if (strtolower($file->getExtension()) !== 'md') {
                continue;
            }

            $archive->addFile($file->getPathname(), $file->getFilename());
        }

        $archive->close();

        return $result_path;
    }
}
