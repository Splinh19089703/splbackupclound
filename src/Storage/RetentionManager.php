<?php

namespace SPLCloudBackup\Storage;

use SPLCloudBackup\Drive\GoogleDriveClient;

defined('ABSPATH') || exit;

class RetentionManager
{
    private array $settings;

    public function __construct(?array $settings = null)
    {
        $this->settings = $settings ?? get_option('spl_cloud_backup_settings', []);
    }

    /**
     * Run full retention policy on both local storage and Google Drive.
     *
     * @param GoogleDriveClient|null $driveClient
     * @param string|null $folderId
     * @param callable|null $logger function(string $msg)
     * @return array<string, int>
     */
    public function execute(?GoogleDriveClient $driveClient = null, ?string $folderId = null, ?callable $logger = null): array
    {
        $localKeep = isset($this->settings['retention_local_count']) ? (int) $this->settings['retention_local_count'] : 5;
        $driveKeep = isset($this->settings['retention_drive_count']) ? (int) $this->settings['retention_drive_count'] : 14;

        $stats = [
            'local_deleted' => 0,
            'drive_deleted' => 0,
        ];

        // 1. Local retention
        $stats['local_deleted'] = LocalVault::purgeOldBackups($localKeep);
        if ($logger && $stats['local_deleted'] > 0) {
            $logger(sprintf(__('Đã dọn dẹp %d bản sao lưu cục bộ cũ (giữ lại %d bản gần nhất).', 'spl-cloud-backup'), $stats['local_deleted'], $localKeep));
        }

        // 2. Google Drive retention
        if ($driveClient && !empty($folderId) && $driveKeep > 0) {
            $driveFiles = $driveClient->listFilesInFolder($folderId, 100);
            if (count($driveFiles) > $driveKeep) {
                // files are ordered by createdTime desc
                $toDelete = array_slice($driveFiles, $driveKeep);
                foreach ($toDelete as $f) {
                    if (!empty($f['id']) && $driveClient->deleteFile($f['id'])) {
                        $stats['drive_deleted']++;
                    }
                }

                if ($logger && $stats['drive_deleted'] > 0) {
                    $logger(sprintf(__('Đã dọn dẹp %d bản sao lưu cũ trên Google Drive (giữ lại %d bản gần nhất).', 'spl-cloud-backup'), $stats['drive_deleted'], $driveKeep));
                }
            }
        }

        return $stats;
    }
}
