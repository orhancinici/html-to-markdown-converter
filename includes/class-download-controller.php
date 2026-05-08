<?php

if (! defined('ABSPATH')) {
    exit;
}

class HTMD_Download_Controller
{
    private HTMD_Job_Repository $jobs;

    public function __construct(HTMD_Job_Repository $jobs)
    {
        $this->jobs = $jobs;
    }

    public function register(): void
    {
        add_action('init', array($this, 'maybe_download'));
    }

    public function maybe_download(): void
    {
        if (! isset($_GET['htmd_download'])) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_GET['htmd_download']));
        if ($token === '') {
            wp_die(esc_html__('Geçersiz indirme bağlantısı.', 'html-to-markdown-converter'));
        }

        // Transient TTL kontrolü — token => job_id
        $job_id = (int) get_transient('htmd_dl_' . $token);
        if ($job_id <= 0) {
            wp_die(esc_html__('İndirme bağlantısının süresi dolmuş. Lütfen dosyalarınızı yeniden dönüştürün.', 'html-to-markdown-converter'));
        }

        $job = $this->jobs->find_by_download_token($token);
        if (! $job) {
            delete_transient('htmd_dl_' . $token);
            wp_die(esc_html__('İstenen iş bulunamadı.', 'html-to-markdown-converter'));
        }

        $job_user_id = (int) $job['user_id'];
        $current_user_id = get_current_user_id();
        // Misafir işleri (user_id = 0) için yalnızca token sahipliği yeterlidir;
        // üyeli işlerde indirici, işin sahibi veya bir yönetici olmalıdır.
        if ($job_user_id !== 0 && $job_user_id !== $current_user_id && ! current_user_can('manage_options')) {
            wp_die(esc_html__('Bu dosyayı indirme yetkiniz yok.', 'html-to-markdown-converter'));
        }

        if ('completed' !== $job['status']) {
            wp_die(esc_html__('Bu iş henüz indirilmeye hazır değil.', 'html-to-markdown-converter'));
        }

        $storage_key = (string) ($job['storage_key'] ?? '');
        $root = htmd_job_directory($storage_key, (int) $job['id']);
        $file = $root !== '' ? trailingslashit($root) . 'result.zip' : '';

        if ($file === '' || ! file_exists($file)) {
            delete_transient('htmd_dl_' . $token);
            $this->jobs->clear_download_token((int) $job['id']);
            wp_die(esc_html__('Sonuç arşivi bulunamadı. İş süresi dolmuş ve temizlenmiş olabilir.', 'html-to-markdown-converter'));
        }

        $this->jobs->update_status((int) $job['id'], 'delivered', array('download_token' => ''));
        delete_transient('htmd_dl_' . $token);

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: attachment; filename="markdown-' . (int) $job['id'] . '.zip"');
        readfile($file);
        htmd_delete_job_directory((int) $job['id'], $storage_key);
        $this->jobs->mark_storage_cleaned((int) $job['id'], 'delivered');
        exit;
    }
}
