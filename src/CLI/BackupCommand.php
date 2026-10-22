<?php

namespace SPLCloudBackup\CLI;

use SPLCloudBackup\Drive\GoogleDriveClient;
use SPLCloudBackup\Scheduler\CronRunner;
use SPLCloudBackup\Storage\LocalVault;
use SPLCloudBackup\Storage\RetentionManager;
use WP_CLI;

defined('ABSPATH') || exit;

/**
 * SPL Cloud Backup CLI commands.
 */
class BackupCommand
{
    /**
     * Run website backup.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Backup type (db_only, db_and_files, full).
     *
     * [--no-upload]
     * : Skip Google Drive upload.
     *
     * [--no-email]
     * : Skip email report dispatch.
     *
     * ## EXAMPLES
     *
     *     wp spl-backup run
     *     wp spl-backup run --type=full
     *     wp spl-backup run --no-upload
     *
     * @param array $args
     * @param array $assocArgs
     */
    public function run(array $args, array $assocArgs): void
    {
        WP_CLI::line('🚀 Bắt đầu chạy sao lưu SPL Cloud Backup qua WP-CLI...');

        $overrides = [];
        if (isset($assocArgs['type']) && in_array($assocArgs['type'], ['db_only', 'db_and_files', 'full'], true)) {
            $overrides['backup_type'] = $assocArgs['type'];
        }
        if (isset($assocArgs['no-upload']) || (isset($assocArgs['upload']) && $assocArgs['upload'] === false)) {
            $overrides['enable_google_drive'] = 0;
        }
        if (isset($assocArgs['no-email']) || (isset($assocArgs['email']) && $assocArgs['email'] === false)) {
            $overrides['enable_email_report'] = 0;
        }

        $result = CronRunner::runFullBackup('WP-CLI Terminal', function ($msg, $pct) {
            WP_CLI::line(sprintf('[%3d%%] %s', (int)$pct, $msg));
        }, $overrides);

        if ($result['success']) {
            WP_CLI::success(sprintf('Sao lưu thành công! Thời gian: %ss', $result['duration']));
        } else {
            WP_CLI::error('Tiến trình sao lưu gặp sự cố: ' . ($result['message'] ?? ''));
        }
    }

    /**
     * List all local backup files in the vault.
     *
     * ## EXAMPLES
     *
     *     wp spl-backup list
     */
    public function list(array $args, array $assocArgs): void
    {
        $backups = LocalVault::listBackups();

        if (empty($backups)) {
            WP_CLI::line('Không có tệp tin sao lưu nào trong thư mục cục bộ.');
            return;
        }

        $format = $assocArgs['format'] ?? 'table';
        $items = [];

        foreach ($backups as $b) {
            $items[] = [
                'Filename' => $b['filename'],
                'Type'     => $b['type'],
                'Size'     => $b['size_human'],
                'Date'     => $b['date_human'],
            ];
        }

        WP_CLI\Utils\format_items($format, $items, ['Filename', 'Type', 'Size', 'Date']);
    }

    /**
     * Test Google Drive API connection.
     *
     * ## EXAMPLES
     *
     *     wp spl-backup test-drive
     */
    public function test_drive(array $args, array $assocArgs): void
    {
        $driveClient = new GoogleDriveClient();
        $res = $driveClient->testConnection();

        if ($res['success']) {
            WP_CLI::success($res['message']);
        } else {
            WP_CLI::error($res['message']);
        }
    }

    /**
     * Clean old backups according to retention policy.
     *
     * ## EXAMPLES
     *
     *     wp spl-backup clean
     */
    public function clean(array $args, array $assocArgs): void
    {
        $settings = get_option('spl_cloud_backup_settings', []);
        $retention = new RetentionManager($settings);
        $driveClient = !empty($settings['enable_google_drive']) ? new GoogleDriveClient($settings) : null;
        $folderName = trim($settings['drive_folder_name'] ?? '') ?: 'SPL-Backups-' . sanitize_title(get_bloginfo('name'));
        $folderId = $driveClient ? $driveClient->getOrCreateFolder($folderName) : null;

        $stats = $retention->execute($driveClient, $folderId, function ($msg) {
            WP_CLI::line($msg);
        });

        WP_CLI::success(sprintf('Đã dọn dẹp: %d tệp cục bộ, %d tệp trên Google Drive.', $stats['local_deleted'], $stats['drive_deleted']));
    }
}
