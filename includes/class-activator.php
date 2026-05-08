<?php

if (! defined('ABSPATH')) {
    exit;
}

class HTMD_Activator
{
    public static function activate(): void
    {
        global $wpdb;

        $table_name = htmd_jobs_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            source_type VARCHAR(20) NOT NULL DEFAULT 'files',
            input_file_count INT UNSIGNED NOT NULL DEFAULT 0,
            output_file_count INT UNSIGNED NOT NULL DEFAULT 0,
            original_name TEXT NULL,
            storage_key VARCHAR(255) NULL,
            result_key VARCHAR(255) NULL,
            error_message TEXT NULL,
            options_json LONGTEXT NULL,
            download_token VARCHAR(64) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY download_token (download_token)
        ) {$charset_collate};";

        dbDelta($sql);

        add_option('htmd_settings', htmd_default_settings());

        if (! wp_next_scheduled('htmd_cleanup_jobs')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'htmd_cleanup_jobs');
        }
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled('htmd_cleanup_jobs');

        if ($timestamp) {
            wp_unschedule_event($timestamp, 'htmd_cleanup_jobs');
        }
    }
}
