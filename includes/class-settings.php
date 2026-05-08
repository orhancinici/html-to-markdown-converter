<?php

if (! defined('ABSPATH')) {
    exit;
}

class HTMD_Settings
{
    public function register(): void
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function add_admin_menu(): void
    {
        add_menu_page(
            __('HTML → Markdown', 'html-to-markdown-converter'),
            __('HTML → Markdown', 'html-to-markdown-converter'),
            'manage_options',
            'htmd-settings',
            array($this, 'render_settings_page'),
            'dashicons-media-text',
            80
        );

        add_submenu_page(
            'htmd-settings',
            __('Ayarlar', 'html-to-markdown-converter'),
            __('Ayarlar', 'html-to-markdown-converter'),
            'manage_options',
            'htmd-settings',
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            'htmd-settings',
            __('Raporlar', 'html-to-markdown-converter'),
            __('Raporlar', 'html-to-markdown-converter'),
            'manage_options',
            'htmd-reports',
            array($this, 'render_reports_page')
        );
    }

    public function register_settings(): void
    {
        register_setting('htmd_settings_group', 'htmd_settings', array($this, 'sanitize_settings'));

        add_settings_section(
            'htmd_general',
            __('Genel Ayarlar', 'html-to-markdown-converter'),
            '__return_false',
            'htmd-settings'
        );

        add_settings_field('maintenance_mode', __('Bakım modu', 'html-to-markdown-converter'), array($this, 'render_checkbox'), 'htmd-settings', 'htmd_general', array('key' => 'maintenance_mode', 'description' => __('Etkinleştirildiğinde dönüştürme aracına yalnızca yöneticiler erişebilir.', 'html-to-markdown-converter')));
        add_settings_field('max_files_per_job', __('İş başına maksimum dosya', 'html-to-markdown-converter'), array($this, 'render_number_field'), 'htmd-settings', 'htmd_general', array('key' => 'max_files_per_job', 'min' => 1));
        add_settings_field('max_upload_mb', __('Maksimum yükleme boyutu (MB)', 'html-to-markdown-converter'), array($this, 'render_number_field'), 'htmd-settings', 'htmd_general', array('key' => 'max_upload_mb', 'min' => 1));
        add_settings_field('daily_operation_quota', __('Günlük işlem kotası', 'html-to-markdown-converter'), array($this, 'render_number_field'), 'htmd-settings', 'htmd_general', array('key' => 'daily_operation_quota', 'min' => 0, 'description' => __('Günlük maksimum kaynak dosya sayısı. Kotayı devre dışı bırakmak için 0 girin.', 'html-to-markdown-converter')));
        add_settings_field('admin_bypass_quota', __('Yönetici kota muafiyeti', 'html-to-markdown-converter'), array($this, 'render_checkbox'), 'htmd-settings', 'htmd_general', array('key' => 'admin_bypass_quota', 'description' => __('Günlük kota dolduğunda yöneticilerin dönüştürmeye devam etmesine izin verir.', 'html-to-markdown-converter')));
        add_settings_field('max_job_lifetime_minutes', __('İndirme bağlantısı geçerlilik süresi (dakika)', 'html-to-markdown-converter'), array($this, 'render_number_field'), 'htmd-settings', 'htmd_general', array('key' => 'max_job_lifetime_minutes', 'min' => 1, 'description' => __('Oluşturulan indirme bağlantısının ne kadar süre geçerli kalacağı. Varsayılan: 10 dakika.', 'html-to-markdown-converter')));

        add_settings_field('rate_limit_window_minutes', __('Hız limiti penceresi (dakika)', 'html-to-markdown-converter'), array($this, 'render_number_field'), 'htmd-settings', 'htmd_general', array('key' => 'rate_limit_window_minutes', 'min' => 1, 'description' => __('Aynı kullanıcı veya IP için yükleme sayacının sıfırlanacağı süre.', 'html-to-markdown-converter')));
        add_settings_field('rate_limit_max_jobs', __('Hız limiti iş sayısı', 'html-to-markdown-converter'), array($this, 'render_number_field'), 'htmd-settings', 'htmd_general', array('key' => 'rate_limit_max_jobs', 'min' => 1, 'description' => __('Hız limiti penceresi içinde izin verilen maksimum dönüştürme isteği.', 'html-to-markdown-converter')));
        add_settings_field('concurrent_jobs_per_client', __('Eşzamanlı iş limiti', 'html-to-markdown-converter'), array($this, 'render_number_field'), 'htmd-settings', 'htmd_general', array('key' => 'concurrent_jobs_per_client', 'min' => 1, 'description' => __('Aynı kullanıcı veya IP için aynı anda izin verilen iş sayısı.', 'html-to-markdown-converter')));

    }

    public function sanitize_settings(array $input): array
    {
        $defaults = htmd_default_settings();

        return array(
            'maintenance_mode' => empty($input['maintenance_mode']) ? 0 : 1,
            'max_files_per_job' => max(1, absint($input['max_files_per_job'] ?? $defaults['max_files_per_job'])),
            'max_upload_mb' => max(1, absint($input['max_upload_mb'] ?? $defaults['max_upload_mb'])),
            'daily_operation_quota' => max(0, absint($input['daily_operation_quota'] ?? $defaults['daily_operation_quota'])),
            'admin_bypass_quota' => empty($input['admin_bypass_quota']) ? 0 : 1,
            'max_job_lifetime_minutes' => max(1, min(60, absint($input['max_job_lifetime_minutes'] ?? $defaults['max_job_lifetime_minutes']))),
            'rate_limit_window_minutes' => max(1, min(1440, absint($input['rate_limit_window_minutes'] ?? $defaults['rate_limit_window_minutes']))),
            'rate_limit_max_jobs' => max(1, min(1000, absint($input['rate_limit_max_jobs'] ?? $defaults['rate_limit_max_jobs']))),
            'concurrent_jobs_per_client' => max(1, min(10, absint($input['concurrent_jobs_per_client'] ?? $defaults['concurrent_jobs_per_client']))),
        );
    }

    public function render_settings_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Bu sayfaya erişim yetkiniz yok.', 'html-to-markdown-converter'));
        }

        require HTMD_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    public function render_reports_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Bu sayfaya erişim yetkiniz yok.', 'html-to-markdown-converter'));
        }

        $days = isset($_GET['days']) ? max(1, min(90, absint($_GET['days']))) : 7;
        $repository = new HTMD_Job_Repository();
        $summary = $repository->get_summary_stats($days);
        $report_rows = $repository->get_daily_user_stats($days);

        require HTMD_PLUGIN_DIR . 'templates/admin-reports.php';
    }

    public function render_checkbox(array $args): void
    {
        $settings = htmd_get_settings();
        $key = $args['key'];
        ?>
        <label>
            <input type="checkbox" name="htmd_settings[<?php echo esc_attr($key); ?>]" value="1" <?php checked(! empty($settings[ $key ])); ?>>
        </label>
        <?php if (! empty($args['description'])) : ?>
            <p class="description"><?php echo esc_html((string) $args['description']); ?></p>
        <?php endif; ?>
        <?php
    }

    public function render_number_field(array $args): void
    {
        $settings = htmd_get_settings();
        $key = $args['key'];
        $min = isset($args['min']) ? (int) $args['min'] : 0;
        ?>
        <input type="number" min="<?php echo esc_attr((string) $min); ?>" name="htmd_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) ($settings[ $key ] ?? '')); ?>" class="small-text">
        <?php if (! empty($args['description'])) : ?>
            <p class="description"><?php echo esc_html((string) $args['description']); ?></p>
        <?php endif; ?>
        <?php
    }

}
