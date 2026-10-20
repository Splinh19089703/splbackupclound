<?php
/**
 * Plugin Name:       SPL Cloud Backup
 * Plugin URI:        https://bluerabike.com
 * Description:       Tự động và thủ công sao lưu Cơ sở dữ liệu và Mã nguồn website lên Google Drive và gửi thông báo qua Email (Hệ sinh thái SPL).
 * Version:           1.0.0
 * Author:            SPL Team / Bluera Việt Nhật
 * Author URI:        https://bluerabike.com
 * Text Domain:       spl-cloud-backup
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.1
 */

defined('ABSPATH') || exit;

define('SPL_CLOUD_BACKUP_VERSION', '1.0.0');
define('SPL_CLOUD_BACKUP_FILE', __FILE__);
define('SPL_CLOUD_BACKUP_PATH', plugin_dir_path(__FILE__));
define('SPL_CLOUD_BACKUP_URL', plugin_dir_url(__FILE__));

// PSR-4 Autoloader for SPLCloudBackup namespace
spl_autoload_register(function ($class) {
    $prefix = 'SPLCloudBackup\\';
    $baseDir = SPL_CLOUD_BACKUP_PATH . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Plugin Activation Hook
register_activation_hook(__FILE__, function () {
    SPLCloudBackup\Plugin::onActivate();
});

// Plugin Deactivation Hook
register_deactivation_hook(__FILE__, function () {
    SPLCloudBackup\Plugin::onDeactivate();
});

// Boot the plugin
add_action('plugins_loaded', function () {
    SPLCloudBackup\Plugin::getInstance()->init();
});

// Register WP-CLI command if running under CLI
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('spl-backup', 'SPLCloudBackup\\CLI\\BackupCommand');
}
