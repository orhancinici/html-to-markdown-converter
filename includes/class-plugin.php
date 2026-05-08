<?php

if (! defined('ABSPATH')) {
    exit;
}

require_once HTMD_PLUGIN_DIR . 'includes/class-settings.php';
require_once HTMD_PLUGIN_DIR . 'includes/class-job-repository.php';
require_once HTMD_PLUGIN_DIR . 'includes/class-html-normalizer.php';
require_once HTMD_PLUGIN_DIR . 'includes/class-markdown-converter.php';
require_once HTMD_PLUGIN_DIR . 'includes/class-archive-extractor.php';
require_once HTMD_PLUGIN_DIR . 'includes/class-upload-handler.php';
require_once HTMD_PLUGIN_DIR . 'includes/class-download-controller.php';
require_once HTMD_PLUGIN_DIR . 'includes/class-shortcode.php';

class HTMD_Plugin
{
    private HTMD_Settings $settings;
    private HTMD_Job_Repository $jobs;
    private HTMD_HTML_Normalizer $normalizer;
    private HTMD_Markdown_Converter $converter;
    private HTMD_Archive_Extractor $extractor;
    private HTMD_Upload_Handler $upload_handler;
    private HTMD_Download_Controller $download_controller;
    private HTMD_Shortcode $shortcode;

    public function __construct()
    {
        $this->settings           = new HTMD_Settings();
        $this->jobs               = new HTMD_Job_Repository();
        $this->normalizer         = new HTMD_HTML_Normalizer();
        $this->converter          = new HTMD_Markdown_Converter();
        $this->extractor          = new HTMD_Archive_Extractor();
        $this->upload_handler     = new HTMD_Upload_Handler($this->jobs, $this->normalizer, $this->converter, $this->extractor);
        $this->download_controller = new HTMD_Download_Controller($this->jobs);
        $this->shortcode          = new HTMD_Shortcode($this->jobs);
    }

    public function run(): void
    {
        $this->settings->register();
        $this->upload_handler->register();
        $this->download_controller->register();
        $this->shortcode->register();
    }
}
