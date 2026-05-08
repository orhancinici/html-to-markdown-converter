<?php
if (! defined('ABSPATH')) {
    exit;
}

$settings = htmd_get_settings();
$caps     = htmd_archive_capabilities();
?>
<div class="htmd-wrap" id="htmd-app">

    <!-- ── Sidebar ─────────────────────────────────────────── -->
    <aside class="htmd-sidebar">
        <div class="htmd-sidebar__inner">

            <div class="htmd-sidebar__brand">
<div class="htmd-sidebar__title">
                    HTML<br>
                    <span class="htmd-sidebar__arrow">↓</span><br>
                    Markdown
                </div>
                <p class="htmd-sidebar__desc">
                    <?php esc_html_e('HTML, klasör veya arşiv dosyalarını Markdown formatına dönüştürün.', 'html-to-markdown-converter'); ?>
                </p>
            </div>

            <nav class="htmd-steps" aria-label="<?php esc_attr_e('Dönüştürme adımları', 'html-to-markdown-converter'); ?>">
                <div class="htmd-step is-active" data-step="upload">
                    <div class="htmd-step__num">01</div>
                    <div class="htmd-step__label"><?php esc_html_e('Yükle', 'html-to-markdown-converter'); ?></div>
                </div>
                <div class="htmd-step" data-step="convert">
                    <div class="htmd-step__num">02</div>
                    <div class="htmd-step__label"><?php esc_html_e('Dönüştür', 'html-to-markdown-converter'); ?></div>
                </div>
                <div class="htmd-step" data-step="download">
                    <div class="htmd-step__num">03</div>
                    <div class="htmd-step__label"><?php esc_html_e('İndir', 'html-to-markdown-converter'); ?></div>
                </div>
            </nav>

            <div class="htmd-sidebar__limits">
                <div class="htmd-limits-title">LİMİTLER</div>
                <div class="htmd-limit-row">
                    <span><?php esc_html_e('Maks. dosya', 'html-to-markdown-converter'); ?></span>
                    <span class="htmd-limit-val"><?php echo esc_html($settings['max_files_per_job']); ?></span>
                </div>
                <div class="htmd-limit-row">
                    <span><?php esc_html_e('Maks. boyut', 'html-to-markdown-converter'); ?></span>
                    <span class="htmd-limit-val"><?php echo esc_html($settings['max_upload_mb']); ?> MB</span>
                </div>
                <div class="htmd-limit-credit">
                    <a href="https://github.com/orhancinici/html-to-markdown-converter" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Kaynak kodu GitHub\'da', 'html-to-markdown-converter'); ?>
                    </a>
                </div>
            </div>

        </div>
    </aside>

    <!-- ── Content ─────────────────────────────────────────── -->
    <main class="htmd-content">

        <!-- ── Form Card ───────────────────────────────────── -->
        <div id="htmd-form-card">
            <form class="htmd-form" id="htmd-form" novalidate>
                <?php wp_nonce_field('htmd_submit_job', 'htmd_nonce'); ?>

                <!-- Visually-hidden file inputs triggered by JS -->
                <input id="htmd_files" type="file" name="htmd_files[]"
                       accept=".html,.htm,text/html" multiple
                       data-file-input class="htmd-file-input" data-tab-input="files">

                <input id="htmd_folder" type="file" name="htmd_folder[]"
                       accept=".html,.htm,text/html" multiple
                       webkitdirectory directory
                       data-file-input data-folder-input class="htmd-file-input" data-tab-input="folder">

                <input id="htmd_archive" type="file" name="htmd_archive"
                       accept=".zip,application/zip<?php echo $caps['rar'] ? ',.rar,application/x-rar-compressed' : ''; ?>"
                       data-file-input class="htmd-file-input" data-tab-input="archive">

                <!-- Step caption + Tab pills -->
                <div class="htmd-content-header">
                    <div>
                        <div class="htmd-step-caption"><?php esc_html_e('Adım 01 — Kaynak Seç', 'html-to-markdown-converter'); ?></div>
                        <div class="htmd-content-title"><?php esc_html_e('Dosyalarınızı yükleyin', 'html-to-markdown-converter'); ?></div>
                    </div>
                    <div class="htmd-tabs" role="tablist">
                        <button type="button" class="htmd-tab is-active" data-tab="files" role="tab" aria-selected="true">
                            <?php esc_html_e('Dosya', 'html-to-markdown-converter'); ?>
                        </button>
                        <button type="button" class="htmd-tab" data-tab="folder" role="tab" aria-selected="false">
                            <?php esc_html_e('Klasör', 'html-to-markdown-converter'); ?>
                        </button>
                        <button type="button" class="htmd-tab" data-tab="archive" role="tab" aria-selected="false">
                            <?php echo esc_html($caps['rar']
                                ? __('ZIP / RAR', 'html-to-markdown-converter')
                                : __('ZIP', 'html-to-markdown-converter')); ?>
                            <?php if (! $caps['rar']) : ?>
                                <span class="htmd-tab__warn" title="<?php esc_attr_e('RAR desteği yok (ext-rar eksik)', 'html-to-markdown-converter'); ?>">!</span>
                            <?php endif; ?>
                        </button>
                    </div>
                </div>

                <!-- Dropzone -->
                <div class="htmd-dropzone" id="htmd-dropzone" data-dropzone>
                    <span class="htmd-bracket htmd-bracket--tl" aria-hidden="true"></span>
                    <span class="htmd-bracket htmd-bracket--tr" aria-hidden="true"></span>
                    <span class="htmd-bracket htmd-bracket--bl" aria-hidden="true"></span>
                    <span class="htmd-bracket htmd-bracket--br" aria-hidden="true"></span>

                    <div class="htmd-dropzone__icon" aria-hidden="true">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 16V4M6 10l6-6 6 6M4 20h16"/>
                        </svg>
                    </div>
                    <div class="htmd-dropzone__title"><?php esc_html_e('Dosyaları buraya bırakın', 'html-to-markdown-converter'); ?></div>
                    <div class="htmd-dropzone__hint" id="htmd-dropzone-hint">
                        <?php esc_html_e('veya tıklayarak seçin · HTML ve HTM dosyaları', 'html-to-markdown-converter'); ?>
                    </div>
                    <button type="button" class="htmd-pick-btn" id="htmd-pick-btn">
                        <?php esc_html_e('DOSYA SEÇ', 'html-to-markdown-converter'); ?>
                    </button>
                </div>

                <!-- File table (hidden until files selected) -->
                <div class="htmd-file-table" id="htmd-file-table" hidden>
                    <div class="htmd-file-table__header">
                        <span id="htmd-file-table-label"><?php esc_html_e('SEÇİLEN — 0 DOSYA', 'html-to-markdown-converter'); ?></span>
                        <span id="htmd-file-table-size">0 KB / <?php echo esc_html($settings['max_upload_mb']); ?> MB</span>
                    </div>
                    <div id="htmd-file-table-rows"></div>
                </div>

                <!-- Options -->
                <div class="htmd-options">
                    <div class="htmd-options-title"><?php esc_html_e('Seçenekler', 'html-to-markdown-converter'); ?></div>
                    <div class="htmd-toggles">
                        <label class="htmd-toggle">
                            <input type="checkbox" name="htmd_remove_arabic_diacritics" value="1">
                            <div class="htmd-toggle__track" aria-hidden="true">
                                <div class="htmd-toggle__knob"></div>
                            </div>
                            <div class="htmd-toggle__body">
                                <strong><?php esc_html_e('Arapça Harekeleri Kaldır', 'html-to-markdown-converter'); ?></strong>
                                <small><?php esc_html_e('Fetha, damme, kesra ve benzeri işaretler temizlenir.', 'html-to-markdown-converter'); ?></small>
                            </div>
                        </label>
                        <label class="htmd-toggle">
                            <input type="checkbox" name="htmd_remove_footnotes" value="1">
                            <div class="htmd-toggle__track" aria-hidden="true">
                                <div class="htmd-toggle__knob"></div>
                            </div>
                            <div class="htmd-toggle__body">
                                <strong><?php esc_html_e('Dipnotları Kaldır', 'html-to-markdown-converter'); ?></strong>
                                <small><?php esc_html_e('Dipnot blokları ve ilgili işaretler çıktıdan ayıklanır.', 'html-to-markdown-converter'); ?></small>
                            </div>
                        </label>
                    </div>

                    <button type="submit" class="htmd-submit-btn" id="htmd-submit-btn">
                        <?php esc_html_e('DÖNÜŞTÜR VE İNDİR', 'html-to-markdown-converter'); ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M5 12h14M13 6l6 6-6 6"/>
                        </svg>
                    </button>
                </div>

            </form>
        </div>

        <!-- ── Progress (hidden until submit) ──────────────── -->
        <div id="htmd-progress" hidden>
            <div class="htmd-section-caption"><?php esc_html_e('Adım 02 — Dönüştürme', 'html-to-markdown-converter'); ?></div>
            <div class="htmd-progress__head">
                <div class="htmd-progress__title" id="htmd-progress-label"><?php esc_html_e('Hazırlanıyor…', 'html-to-markdown-converter'); ?></div>
                <div class="htmd-progress__pct" id="htmd-progress-pct">0<span>%</span></div>
            </div>
            <div class="htmd-progress__bar-wrap" role="progressbar"
                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
                 id="htmd-progress-bar-wrap">
                <div class="htmd-progress__bar" id="htmd-progress-bar"></div>
                <div class="htmd-progress__cursor" id="htmd-progress-cursor"></div>
            </div>
        </div>

        <!-- ── Result (hidden until done) ──────────────────── -->
        <div id="htmd-result" hidden>
            <div class="htmd-section-caption"><?php esc_html_e('Adım 03 — Tamamlandı', 'html-to-markdown-converter'); ?></div>
            <div class="htmd-result__title">
                <span class="htmd-result__check" aria-hidden="true">✓</span>
                <?php esc_html_e('Dönüştürme başarılı', 'html-to-markdown-converter'); ?>
            </div>
            <div class="htmd-result__time" id="htmd-result-time"></div>

            <div class="htmd-result__stats" id="htmd-result-stats"></div>

            <div id="htmd-result-failed" hidden>
                <button type="button" class="htmd-result__failed-toggle" id="htmd-failed-toggle" aria-expanded="false">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                    <span id="htmd-failed-toggle-label"></span>
                </button>
                <ul class="htmd-result__failed-list" id="htmd-failed-list" hidden></ul>
            </div>

            <div class="htmd-result__actions" id="htmd-result-actions"></div>
            <p class="htmd-result__expiry" id="htmd-result-expiry"></p>
        </div>

    </main>

</div><!-- .htmd-wrap -->
