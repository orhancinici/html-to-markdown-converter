<?php
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e('HTML → Markdown Dönüştürücü', 'html-to-markdown-converter'); ?></h1>
    <p><?php esc_html_e('HTML kaynaklarını kimin yükleyebileceğini ve dönüştürme akışının nasıl çalışacağını buradan yapılandırın.', 'html-to-markdown-converter'); ?></p>

    <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=htmd-reports')); ?>" class="button button-secondary">
            <?php esc_html_e('Kullanım Raporlarını Görüntüle', 'html-to-markdown-converter'); ?>
        </a>
    </p>

    <form method="post" action="options.php">
        <?php
        settings_fields('htmd_settings_group');
        do_settings_sections('htmd-settings');
        submit_button(__('Ayarları Kaydet', 'html-to-markdown-converter'));
        ?>
    </form>

    <hr>

    <p>
        <strong><?php esc_html_e('Kısa Kod:', 'html-to-markdown-converter'); ?></strong>
        <code>[htmd_upload]</code>
        — <?php esc_html_e('Bu kısa kodu herhangi bir sayfa veya yazıya ekleyerek dönüştürücüyü yayınlayın.', 'html-to-markdown-converter'); ?>
    </p>
</div>
