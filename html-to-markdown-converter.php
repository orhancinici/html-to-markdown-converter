<?php
/**
 * Plugin Name: HTML to Markdown Converter
 * Plugin URI:  https://github.com/orhancinici/html-to-markdown-converter
 * Description: Converts uploaded HTML and HTM files to Markdown and packages the output for download.
 * Version:     0.2.0
 * Author:      Orhan Çinici
 * Author URI:  https://github.com/orhancinici
 * Text Domain: html-to-markdown-converter
 */

if (! defined('ABSPATH')) {
    exit;
}

define('HTMD_PLUGIN_VERSION', '0.2.0');
define('HTMD_PLUGIN_FILE', __FILE__);
define('HTMD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HTMD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HTMD_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once HTMD_PLUGIN_DIR . 'includes/helpers.php';
require_once HTMD_PLUGIN_DIR . 'includes/class-activator.php';
require_once HTMD_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook(__FILE__, array('HTMD_Activator', 'activate'));
register_deactivation_hook(__FILE__, array('HTMD_Activator', 'deactivate'));

function htmd_plugin(): HTMD_Plugin
{
    static $plugin = null;

    if (null === $plugin) {
        $plugin = new HTMD_Plugin();
    }

    return $plugin;
}

htmd_plugin()->run();
