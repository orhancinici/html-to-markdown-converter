<?php

if (! defined('ABSPATH')) {
    exit;
}

class HTMD_Shortcode
{
    private HTMD_Job_Repository $jobs;

    public function __construct(HTMD_Job_Repository $jobs)
    {
        $this->jobs = $jobs;
    }

    public function register(): void
    {
        add_shortcode('htmd_upload', array($this, 'render'));
    }

    public function render(): string
    {
        if (! htmd_user_can_access()) {
            return '<p>' . esc_html(htmd_get_access_denied_message()) . '</p>';
        }

        $this->enqueue_assets();

        $settings = htmd_get_settings();

        ob_start();
        require HTMD_PLUGIN_DIR . 'templates/front-upload-form.php';

        return (string) ob_get_clean();
    }

    private function enqueue_assets(): void
    {
        $settings = htmd_get_settings();
        $caps     = htmd_archive_capabilities();

        wp_enqueue_style(
            'htmd-front',
            HTMD_PLUGIN_URL . 'assets/front.css',
            array(),
            HTMD_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'jszip',
            HTMD_PLUGIN_URL . 'assets/vendor/jszip.min.js',
            array(),
            '3.10.1',
            true
        );

        wp_enqueue_script(
            'htmd-front',
            HTMD_PLUGIN_URL . 'assets/front.js',
            array('jszip'),
            HTMD_PLUGIN_VERSION,
            true
        );

        wp_localize_script('htmd-front', 'HTMD', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('htmd_submit_job'),
            'limits'  => array(
                'max_files'     => (int) ($settings['max_files_per_job'] ?? 20),
                'max_upload_mb' => (int) ($settings['max_upload_mb'] ?? 25),
            ),
            'caps'    => array(
                'rar' => $caps['rar'],
                'zip' => $caps['zip'],
            ),
            'i18n'    => array(
                'uploading'       => __('Yükleniyor…', 'html-to-markdown-converter'),
                'processing'      => __('Dönüştürülüyor…', 'html-to-markdown-converter'),
                'done'            => __('Tamamlandı', 'html-to-markdown-converter'),
                'error'           => __('Hata', 'html-to-markdown-converter'),
                'retry'           => __('Yeni İş Başlat', 'html-to-markdown-converter'),
                'download'        => __('ZIP İndir', 'html-to-markdown-converter'),
                'noFiles'         => __('Dosya seçilmedi', 'html-to-markdown-converter'),
                'noArchive'       => __('Arşiv seçilmedi', 'html-to-markdown-converter'),
                'filesSelected'   => __('dosya seçildi', 'html-to-markdown-converter'),
                'expiresSec'      => __('saniye içinde sona erer', 'html-to-markdown-converter'),
                'expiresMin'      => __('dakika içinde sona erer', 'html-to-markdown-converter'),
                'linkExpired'     => __('İndirme bağlantısının süresi doldu.', 'html-to-markdown-converter'),
                'buildingZip'     => __('Klasör paketleniyor…', 'html-to-markdown-converter'),
                'successCount'    => __('başarılı', 'html-to-markdown-converter'),
                'failedCount'     => __('başarısız', 'html-to-markdown-converter'),
                'failedListTitle' => __('Başarısız Dosyalar', 'html-to-markdown-converter'),
            ),
        ));
    }
}
