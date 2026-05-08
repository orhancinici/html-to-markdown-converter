<?php

if (! defined('ABSPATH')) {
    exit;
}

class HTMD_Job_Repository
{
    private string $table_name;

    public function __construct()
    {
        $this->table_name = htmd_jobs_table_name();
    }

    public function create(int $user_id, string $source_type, string $original_name, array $options, string $storage_key): int
    {
        global $wpdb;

        $now = current_time('mysql');

        $inserted = $wpdb->insert(
            $this->table_name,
            array(
                'user_id'          => $user_id,
                'status'           => 'queued',
                'source_type'      => $source_type,
                'input_file_count' => 0,
                'output_file_count' => 0,
                'original_name'    => $original_name,
                'storage_key'      => $storage_key,
                'result_key'       => '',
                'error_message'    => '',
                'options_json'     => wp_json_encode($options),
                'download_token'   => '',
                'created_at'       => $now,
                'updated_at'       => $now,
            ),
            array('%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if (false === $inserted) {
            throw new RuntimeException(__('The conversion job could not be created.', 'html-to-markdown-converter'));
        }

        return (int) $wpdb->insert_id;
    }

    public function update_status(int $job_id, string $status, array $data = array()): void
    {
        global $wpdb;

        $payload = array_merge(
            array(
                'status' => $status,
                'updated_at' => current_time('mysql'),
            ),
            $data
        );

        $formats = array();

        foreach ($payload as $value) {
            if (is_int($value)) {
                $formats[] = '%d';
            } else {
                $formats[] = '%s';
            }
        }

        $wpdb->update($this->table_name, $payload, array('id' => $job_id), $formats, array('%d'));
    }

    public function get(int $job_id): ?array
    {
        global $wpdb;

        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $job_id), ARRAY_A);

        return is_array($job) ? $job : null;
    }

    public function find_by_download_token(string $token): ?array
    {
        global $wpdb;

        if ($token === '') {
            return null;
        }

        $job = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE download_token = %s LIMIT 1", $token),
            ARRAY_A
        );

        return is_array($job) ? $job : null;
    }

    public function set_download_token(int $job_id, string $token): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table_name,
            array('download_token' => $token, 'updated_at' => current_time('mysql')),
            array('id' => $job_id),
            array('%s', '%s'),
            array('%d')
        );
    }

    public function clear_download_token(int $job_id): void
    {
        $this->set_download_token($job_id, '');
    }

    public function get_stale_jobs(int $older_than_minutes): array
    {
        global $wpdb;

        $older_than_minutes = max(1, $older_than_minutes);
        $cutoff = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($older_than_minutes * MINUTE_IN_SECONDS));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, status, storage_key FROM {$this->table_name} WHERE updated_at <= %s",
                $cutoff
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    public function get_cleanup_candidates(array $statuses, int $older_than_minutes): array
    {
        global $wpdb;

        $statuses = array_values(array_filter(array_map('sanitize_key', $statuses)));
        if (empty($statuses)) {
            return array();
        }

        $older_than_minutes = max(1, $older_than_minutes);
        $cutoff = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($older_than_minutes * MINUTE_IN_SECONDS));
        $placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
        $sql = "SELECT id, status, storage_key FROM {$this->table_name}
                WHERE updated_at <= %s
                AND storage_key IS NOT NULL
                AND storage_key <> ''
                AND status IN ({$placeholders})";

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, array_merge(array($cutoff), $statuses)),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    public function mark_storage_cleaned(int $job_id, string $status = '', string $error_message = ''): void
    {
        global $wpdb;

        $payload = array(
            'storage_key' => '',
            'result_key' => '',
            'download_token' => '',
            'updated_at' => current_time('mysql'),
        );
        $formats = array('%s', '%s', '%s', '%s');

        if ($status !== '') {
            $payload['status'] = $status;
            $formats[] = '%s';
        }

        if ($error_message !== '') {
            $payload['error_message'] = $error_message;
            $formats[] = '%s';
        }

        $wpdb->update($this->table_name, $payload, array('id' => $job_id), $formats, array('%d'));
    }

    public function get_daily_user_stats(int $days = 7): array
    {
        global $wpdb;

        $days = max(1, min(90, $days));
        $cutoff = wp_date('Y-m-d 00:00:00', current_time('timestamp') - (($days - 1) * DAY_IN_SECONDS));
        $users_table = $wpdb->users;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    DATE(j.created_at) AS report_date,
                    j.user_id,
                    COALESCE(u.display_name, u.user_login, %s) AS user_name,
                    COUNT(*) AS job_count,
                    SUM(j.input_file_count) AS input_file_count,
                    SUM(j.output_file_count) AS output_file_count,
                    SUM(CASE WHEN j.status = 'completed' OR j.status = 'delivered' THEN 1 ELSE 0 END) AS completed_count,
                    SUM(CASE WHEN j.status = 'failed' THEN 1 ELSE 0 END) AS failed_count
                FROM {$this->table_name} j
                LEFT JOIN {$users_table} u ON u.ID = j.user_id
                WHERE j.created_at >= %s
                GROUP BY DATE(j.created_at), j.user_id, u.display_name, u.user_login
                ORDER BY report_date DESC, job_count DESC, user_name ASC",
                __('Deleted user', 'html-to-markdown-converter'),
                $cutoff
            ),
            ARRAY_A
        );
    }

    public function get_summary_stats(int $days = 7): array
    {
        global $wpdb;

        $days = max(1, min(90, $days));
        $cutoff = wp_date('Y-m-d 00:00:00', current_time('timestamp') - (($days - 1) * DAY_IN_SECONDS));

        $summary = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) AS total_jobs,
                    COUNT(DISTINCT user_id) AS total_users,
                    SUM(input_file_count) AS input_file_count,
                    SUM(output_file_count) AS output_file_count,
                    SUM(CASE WHEN status = 'completed' OR status = 'delivered' THEN 1 ELSE 0 END) AS completed_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
                FROM {$this->table_name}
                WHERE created_at >= %s",
                $cutoff
            ),
            ARRAY_A
        );

        return is_array($summary) ? $summary : array();
    }

    public function get_today_input_volume(): int
    {
        global $wpdb;

        $start_of_day = wp_date('Y-m-d 00:00:00', current_time('timestamp'));
        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(input_file_count), 0)
                FROM {$this->table_name}
                WHERE created_at >= %s",
                $start_of_day
            )
        );

        return (int) $total;
    }

    public function delete(int $job_id): void
    {
        global $wpdb;

        $wpdb->delete($this->table_name, array('id' => $job_id), array('%d'));
    }
}
