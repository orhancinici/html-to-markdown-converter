<?php

if (! defined('ABSPATH')) {
    exit;
}

class HTMD_Archive_Extractor
{
    /**
     * Extracts all supported HTML files from an archive to $target_dir.
     *
     * @param  string $original_name  Original filename (used to detect type when $archive_path has no extension, e.g. a PHP tmp file)
     * @return array{relative_paths: string[], total_bytes: int}
     */
    public function extract(string $archive_path, string $target_dir, int $max_files, int $max_bytes, int $existing_file_count, string $original_name = ''): array
    {
        $extension = $this->resolve_extension($archive_path, $original_name);

        if ($extension === 'zip') {
            return $this->extract_zip($archive_path, $target_dir, $max_files, $max_bytes, $existing_file_count);
        }

        if ($extension === 'rar') {
            return $this->extract_rar($archive_path, $target_dir, $max_files, $max_bytes, $existing_file_count);
        }

        throw new RuntimeException(__('Unsupported archive format.', 'html-to-markdown-converter'));
    }

    /**
     * @param  string $original_name  Original filename (used when $archive_path is a PHP tmp path without extension)
     */
    public function count_html_entries(string $archive_path, int $max_files, int $max_bytes, int $existing_file_count, string $original_name = ''): int
    {
        $extension = $this->resolve_extension($archive_path, $original_name);

        if ($extension === 'zip') {
            return $this->count_zip($archive_path, $max_files, $max_bytes, $existing_file_count);
        }

        if ($extension === 'rar') {
            return $this->count_rar($archive_path, $max_files, $max_bytes, $existing_file_count);
        }

        throw new RuntimeException(__('Unsupported archive format.', 'html-to-markdown-converter'));
    }

    /**
     * Determines archive extension from $archive_path; falls back to $original_name when the
     * path has no extension (e.g. PHP uploaded tmp files like /tmp/phpXXXXXX).
     */
    private function resolve_extension(string $archive_path, string $original_name): string
    {
        $ext = strtolower((string) pathinfo($archive_path, PATHINFO_EXTENSION));
        if ($ext !== '' && $original_name === '') {
            return $ext;
        }
        if ($original_name !== '') {
            $name_ext = strtolower((string) pathinfo($original_name, PATHINFO_EXTENSION));
            if ($name_ext !== '') {
                return $name_ext;
            }
        }
        return $ext;
    }

    private function extract_zip(string $archive_path, string $target_dir, int $max_files, int $max_bytes, int $existing_file_count): array
    {
        if (! class_exists('ZipArchive')) {
            throw new RuntimeException(__('ZipArchive is not available on this server.', 'html-to-markdown-converter'));
        }

        $archive = new ZipArchive();
        if (true !== $archive->open($archive_path)) {
            throw new RuntimeException(__('The ZIP archive could not be opened.', 'html-to-markdown-converter'));
        }

        $entries = array();
        $total_bytes = 0;

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $raw = $archive->getNameIndex($i);
            if (false === $raw) {
                $archive->close();
                throw new RuntimeException(__('The archive contains invalid entries.', 'html-to-markdown-converter'));
            }

            $normalized = str_replace('\\', '/', $raw);
            if ($normalized === '' || substr($normalized, -1) === '/') {
                continue;
            }

            $relative = $this->validate_entry_path($normalized);
            if ($relative === '' || ! htmd_is_supported_html($relative)) {
                continue;
            }

            $entries[] = array('raw' => $raw, 'path' => $relative);

            if (($existing_file_count + count($entries)) > $max_files) {
                $archive->close();
                throw new RuntimeException(__('The archive contains more HTML files than allowed for one job.', 'html-to-markdown-converter'));
            }

            $stat = $archive->statIndex($i);
            $entry_size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
            $total_bytes += max(0, $entry_size);

            if ($total_bytes > $max_bytes) {
                $archive->close();
                throw new RuntimeException(__('The extracted HTML files exceed the configured size limit.', 'html-to-markdown-converter'));
            }
        }

        $written = array();
        $actual_total_bytes = 0;
        foreach ($entries as $entry) {
            $target_path = trailingslashit($target_dir) . str_replace('/', DIRECTORY_SEPARATOR, $entry['path']);
            $target_subdir = dirname($target_path);

            if (! htmd_prepare_private_directory($target_subdir)) {
                $archive->close();
                throw new RuntimeException(__('Unable to prepare extracted file directories.', 'html-to-markdown-converter'));
            }

            $input_stream = $archive->getStream($entry['raw']);
            if (false === $input_stream) {
                $archive->close();
                throw new RuntimeException(__('An HTML file inside the archive could not be read.', 'html-to-markdown-converter'));
            }

            $output_stream = fopen($target_path, 'wb');
            if (false === $output_stream) {
                fclose($input_stream);
                $archive->close();
                throw new RuntimeException(__('An extracted HTML file could not be written.', 'html-to-markdown-converter'));
            }

            try {
                $copied = $this->copy_stream_with_limit($input_stream, $output_stream, $max_bytes - $actual_total_bytes);
            } catch (Throwable $exception) {
                fclose($input_stream);
                fclose($output_stream);
                @unlink($target_path);
                $archive->close();
                throw $exception;
            }

            fclose($input_stream);
            fclose($output_stream);
            $actual_total_bytes += $copied;
            $written[] = $entry['path'];
        }

        $archive->close();

        return array(
            'relative_paths' => $written,
            'total_bytes'    => $actual_total_bytes,
        );
    }

    private function count_zip(string $archive_path, int $max_files, int $max_bytes, int $existing_file_count): int
    {
        if (! class_exists('ZipArchive')) {
            throw new RuntimeException(__('ZipArchive is not available on this server.', 'html-to-markdown-converter'));
        }

        $archive = new ZipArchive();
        if (true !== $archive->open($archive_path)) {
            throw new RuntimeException(__('The ZIP archive could not be opened.', 'html-to-markdown-converter'));
        }

        $count = 0;
        $total_bytes = 0;

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $raw = $archive->getNameIndex($i);
            if (false === $raw) {
                $archive->close();
                throw new RuntimeException(__('The archive contains invalid entries.', 'html-to-markdown-converter'));
            }

            $normalized = str_replace('\\', '/', $raw);
            if ($normalized === '' || substr($normalized, -1) === '/') {
                continue;
            }

            $relative = $this->validate_entry_path($normalized);
            if ($relative === '' || ! htmd_is_supported_html($relative)) {
                continue;
            }

            $count++;

            if (($existing_file_count + $count) > $max_files) {
                $archive->close();
                throw new RuntimeException(__('The archive contains more HTML files than allowed for one job.', 'html-to-markdown-converter'));
            }

            $stat = $archive->statIndex($i);
            $entry_size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
            $total_bytes += max(0, $entry_size);

            if ($total_bytes > $max_bytes) {
                $archive->close();
                throw new RuntimeException(__('The extracted HTML files exceed the configured size limit.', 'html-to-markdown-converter'));
            }
        }

        $archive->close();

        return $count;
    }

    private function extract_rar(string $archive_path, string $target_dir, int $max_files, int $max_bytes, int $existing_file_count): array
    {
        if (! class_exists('RarArchive')) {
            throw new RuntimeException(__('RAR archives are not supported on this server (PHP ext-rar is missing). Please upload a ZIP archive instead.', 'html-to-markdown-converter'));
        }

        $archive = @RarArchive::open($archive_path);
        if (! $archive) {
            throw new RuntimeException(__('The RAR archive could not be opened.', 'html-to-markdown-converter'));
        }

        $entries_raw = $archive->getEntries();
        if (false === $entries_raw) {
            $archive->close();
            throw new RuntimeException(__('The RAR archive contains invalid entries.', 'html-to-markdown-converter'));
        }

        $valid = array();
        $total_bytes = 0;

        foreach ($entries_raw as $entry) {
            if ($entry->isDirectory()) {
                continue;
            }

            $raw = $entry->getName();
            $normalized = str_replace('\\', '/', (string) $raw);
            $relative = $this->validate_entry_path($normalized);
            if ($relative === '' || ! htmd_is_supported_html($relative)) {
                continue;
            }

            $valid[] = array('entry' => $entry, 'path' => $relative);

            if (($existing_file_count + count($valid)) > $max_files) {
                $archive->close();
                throw new RuntimeException(__('The archive contains more HTML files than allowed for one job.', 'html-to-markdown-converter'));
            }

            $total_bytes += max(0, (int) $entry->getUnpackedSize());

            if ($total_bytes > $max_bytes) {
                $archive->close();
                throw new RuntimeException(__('The extracted HTML files exceed the configured size limit.', 'html-to-markdown-converter'));
            }
        }

        $written = array();
        $actual_total_bytes = 0;
        foreach ($valid as $item) {
            $target_path = trailingslashit($target_dir) . str_replace('/', DIRECTORY_SEPARATOR, $item['path']);
            $target_subdir = dirname($target_path);

            if (! htmd_prepare_private_directory($target_subdir)) {
                $archive->close();
                throw new RuntimeException(__('Unable to prepare extracted file directories.', 'html-to-markdown-converter'));
            }

            $input_stream = $item['entry']->getStream();
            if (false === $input_stream) {
                $archive->close();
                throw new RuntimeException(__('An HTML file inside the archive could not be read.', 'html-to-markdown-converter'));
            }

            $output_stream = fopen($target_path, 'wb');
            if (false === $output_stream) {
                fclose($input_stream);
                $archive->close();
                throw new RuntimeException(__('An extracted HTML file could not be written.', 'html-to-markdown-converter'));
            }

            try {
                $copied = $this->copy_stream_with_limit($input_stream, $output_stream, $max_bytes - $actual_total_bytes);
            } catch (Throwable $exception) {
                fclose($input_stream);
                fclose($output_stream);
                @unlink($target_path);
                $archive->close();
                throw $exception;
            }

            fclose($input_stream);
            fclose($output_stream);
            $actual_total_bytes += $copied;
            $written[] = $item['path'];
        }

        $archive->close();

        return array(
            'relative_paths' => $written,
            'total_bytes'    => $actual_total_bytes,
        );
    }

    private function count_rar(string $archive_path, int $max_files, int $max_bytes, int $existing_file_count): int
    {
        if (! class_exists('RarArchive')) {
            throw new RuntimeException(__('RAR archives are not supported on this server (PHP ext-rar is missing). Please upload a ZIP archive instead.', 'html-to-markdown-converter'));
        }

        $archive = @RarArchive::open($archive_path);
        if (! $archive) {
            throw new RuntimeException(__('The RAR archive could not be opened.', 'html-to-markdown-converter'));
        }

        $entries = $archive->getEntries();
        if (false === $entries) {
            $archive->close();
            throw new RuntimeException(__('The RAR archive contains invalid entries.', 'html-to-markdown-converter'));
        }

        $count = 0;
        $total_bytes = 0;

        foreach ($entries as $entry) {
            if ($entry->isDirectory()) {
                continue;
            }

            $normalized = str_replace('\\', '/', (string) $entry->getName());
            $relative = $this->validate_entry_path($normalized);
            if ($relative === '' || ! htmd_is_supported_html($relative)) {
                continue;
            }

            $count++;

            if (($existing_file_count + $count) > $max_files) {
                $archive->close();
                throw new RuntimeException(__('The archive contains more HTML files than allowed for one job.', 'html-to-markdown-converter'));
            }

            $total_bytes += max(0, (int) $entry->getUnpackedSize());

            if ($total_bytes > $max_bytes) {
                $archive->close();
                throw new RuntimeException(__('The extracted HTML files exceed the configured size limit.', 'html-to-markdown-converter'));
            }
        }

        $archive->close();

        return $count;
    }

    private function validate_entry_path(string $entry): string
    {
        if ($entry === '' || false !== strpos($entry, "\0") || strpos($entry, '/') === 0 || preg_match('#^[A-Za-z]:/#', $entry)) {
            throw new RuntimeException(__('The archive contains unsafe paths.', 'html-to-markdown-converter'));
        }

        $segments = explode('/', trim($entry, '/'));
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                throw new RuntimeException(__('The archive contains unsafe paths.', 'html-to-markdown-converter'));
            }
        }

        return trim($entry, '/');
    }

    private function copy_stream_with_limit($input_stream, $output_stream, int $remaining_bytes): int
    {
        if ($remaining_bytes <= 0) {
            throw new RuntimeException(__('The extracted HTML files exceed the configured size limit.', 'html-to-markdown-converter'));
        }

        $written = 0;
        while (! feof($input_stream)) {
            $space = $remaining_bytes - $written;
            if ($space <= 0) {
                throw new RuntimeException(__('The extracted HTML files exceed the configured size limit.', 'html-to-markdown-converter'));
            }

            $chunk = fread($input_stream, min(8192, $space + 1));
            if (false === $chunk) {
                throw new RuntimeException(__('An HTML file inside the archive could not be read.', 'html-to-markdown-converter'));
            }

            if ($chunk === '') {
                if (feof($input_stream)) {
                    break;
                }
                throw new RuntimeException(__('An HTML file inside the archive could not be read.', 'html-to-markdown-converter'));
            }

            $length = strlen($chunk);
            if ($length > $space) {
                throw new RuntimeException(__('The extracted HTML files exceed the configured size limit.', 'html-to-markdown-converter'));
            }

            $offset = 0;
            while ($offset < $length) {
                $bytes = fwrite($output_stream, substr($chunk, $offset));
                if (false === $bytes || $bytes === 0) {
                    throw new RuntimeException(__('An extracted HTML file could not be written.', 'html-to-markdown-converter'));
                }
                $offset += $bytes;
            }

            $written += $length;
        }

        return $written;
    }
}
