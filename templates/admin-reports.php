<?php
if (! defined('ABSPATH')) {
    exit;
}

$summary = wp_parse_args(
    is_array($summary) ? $summary : array(),
    array(
        'total_jobs'        => 0,
        'total_users'       => 0,
        'input_file_count'  => 0,
        'output_file_count' => 0,
        'completed_count'   => 0,
        'failed_count'      => 0,
    )
);
?>
<div class="wrap">
    <h1><?php esc_html_e('HTML → Markdown Raporlar', 'html-to-markdown-converter'); ?></h1>
    <p><?php esc_html_e('Günlük bazda hangi kullanıcının kaç dosya dönüştürdüğünü ve dönüşüm hacmini buradan takip edebilirsiniz.', 'html-to-markdown-converter'); ?></p>

    <form method="get" style="margin:16px 0 24px;">
        <input type="hidden" name="page" value="htmd-reports">
        <label for="htmd-report-days"><strong><?php esc_html_e('Dönem:', 'html-to-markdown-converter'); ?></strong></label>
        <select id="htmd-report-days" name="days" style="margin:0 8px;">
            <?php foreach (array(7, 14, 30, 60, 90) as $option) : ?>
                <option value="<?php echo esc_attr((string) $option); ?>" <?php selected($days, $option); ?>>
                    <?php echo esc_html(sprintf(
                        _n('Son %d gün', 'Son %d gün', $option, 'html-to-markdown-converter'),
                        $option
                    )); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php submit_button(__('Uygula', 'html-to-markdown-converter'), 'secondary', '', false); ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=htmd-settings')); ?>" class="button" style="margin-left:8px;">
            <?php esc_html_e('← Ayarlara Dön', 'html-to-markdown-converter'); ?>
        </a>
    </form>

    <!-- Özet kartları -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:28px;">

        <div class="postbox" style="padding:16px 20px;margin:0;">
            <div style="color:#646970;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                <?php esc_html_e('Toplam İş', 'html-to-markdown-converter'); ?>
            </div>
            <div style="font-size:30px;font-weight:700;line-height:1.1;">
                <?php echo esc_html(number_format_i18n((int) $summary['total_jobs'])); ?>
            </div>
        </div>

        <div class="postbox" style="padding:16px 20px;margin:0;">
            <div style="color:#646970;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                <?php esc_html_e('Aktif Kullanıcı', 'html-to-markdown-converter'); ?>
            </div>
            <div style="font-size:30px;font-weight:700;line-height:1.1;">
                <?php echo esc_html(number_format_i18n((int) $summary['total_users'])); ?>
            </div>
        </div>

        <div class="postbox" style="padding:16px 20px;margin:0;">
            <div style="color:#646970;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                <?php esc_html_e('Kaynak Dosya', 'html-to-markdown-converter'); ?>
            </div>
            <div style="font-size:30px;font-weight:700;line-height:1.1;">
                <?php echo esc_html(number_format_i18n((int) $summary['input_file_count'])); ?>
            </div>
        </div>

        <div class="postbox" style="padding:16px 20px;margin:0;">
            <div style="color:#646970;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                <?php esc_html_e('Markdown Çıktı', 'html-to-markdown-converter'); ?>
            </div>
            <div style="font-size:30px;font-weight:700;line-height:1.1;">
                <?php echo esc_html(number_format_i18n((int) $summary['output_file_count'])); ?>
            </div>
        </div>

        <div class="postbox" style="padding:16px 20px;margin:0;">
            <div style="color:#646970;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                <?php esc_html_e('Tamamlanan', 'html-to-markdown-converter'); ?>
            </div>
            <div style="font-size:30px;font-weight:700;line-height:1.1;color:#135e32;">
                <?php echo esc_html(number_format_i18n((int) $summary['completed_count'])); ?>
            </div>
        </div>

        <div class="postbox" style="padding:16px 20px;margin:0;">
            <div style="color:#646970;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                <?php esc_html_e('Başarısız', 'html-to-markdown-converter'); ?>
            </div>
            <div style="font-size:30px;font-weight:700;line-height:1.1;color:#b32d2e;">
                <?php echo esc_html(number_format_i18n((int) $summary['failed_count'])); ?>
            </div>
        </div>

    </div>

    <?php if (empty($report_rows)) : ?>
        <div class="notice notice-info inline" style="padding:12px 16px;">
            <p><?php esc_html_e('Seçilen dönem için rapor verisi bulunmuyor.', 'html-to-markdown-converter'); ?></p>
        </div>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Tarih', 'html-to-markdown-converter'); ?></th>
                    <th><?php esc_html_e('Kullanıcı', 'html-to-markdown-converter'); ?></th>
                    <th><?php esc_html_e('İş Sayısı', 'html-to-markdown-converter'); ?></th>
                    <th><?php esc_html_e('Kaynak Dosya', 'html-to-markdown-converter'); ?></th>
                    <th><?php esc_html_e('Markdown Çıktı', 'html-to-markdown-converter'); ?></th>
                    <th><?php esc_html_e('Tamamlanan', 'html-to-markdown-converter'); ?></th>
                    <th><?php esc_html_e('Başarısız', 'html-to-markdown-converter'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report_rows as $row) : ?>
                    <tr>
                        <td><?php echo esc_html(wp_date(get_option('date_format'), strtotime((string) $row['report_date']))); ?></td>
                        <td><?php echo esc_html((string) $row['user_name']); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) $row['job_count'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) $row['input_file_count'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) $row['output_file_count'])); ?></td>
                        <td style="color:#135e32;font-weight:600;"><?php echo esc_html(number_format_i18n((int) $row['completed_count'])); ?></td>
                        <td style="<?php echo (int) $row['failed_count'] > 0 ? 'color:#b32d2e;font-weight:600;' : ''; ?>">
                            <?php echo esc_html(number_format_i18n((int) $row['failed_count'])); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
