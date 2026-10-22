<?php

namespace SPLCloudBackup\Scheduler;

use SPLCloudBackup\Archiver\FileArchiver;
use SPLCloudBackup\Drive\GoogleDriveClient;
use SPLCloudBackup\Dumper\DatabaseDumper;
use SPLCloudBackup\Notification\EmailNotifier;
use SPLCloudBackup\Storage\LocalVault;
use SPLCloudBackup\Storage\RetentionManager;

defined('ABSPATH') || exit;

class CronRunner
{
    public const CRON_HOOK = 'spl_cloud_backup_scheduled_event';

    /**
     * Boot the scheduler and webhook listener.
     */
    public static function init(): void
    {
        // Add custom cron intervals
        add_filter('cron_schedules', [__CLASS__, 'registerSchedules']);

        // Hook scheduled backup runner
        add_action(self::CRON_HOOK, [__CLASS__, 'handleScheduledBackup']);

        // Hook aaPanel / Server Cron Webhook listener
        add_action('init', [__CLASS__, 'handleWebhookTrigger']);
    }

    /**
     * Register weekly and monthly intervals for WP-Cron.
     *
     * @param array<string, array<string, mixed>> $schedules
     * @return array<string, array<string, mixed>>
     */
    public static function registerSchedules(array $schedules): array
    {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => 604800,
                'display'  => __('Mỗi tuần một lần (Weekly)', 'spl-cloud-backup'),
            ];
        }

        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = [
                'interval' => 2592000,
                'display'  => __('Mỗi tháng một lần (Monthly)', 'spl-cloud-backup'),
            ];
        }

        return $schedules;
    }

    /**
     * Update WP-Cron schedule based on current settings.
     */
    public static function syncSchedule(): void
    {
        $settings = get_option('spl_cloud_backup_settings', []);
        $frequency = $settings['schedule_frequency'] ?? 'daily';

        wp_clear_scheduled_hook(self::CRON_HOOK);

        if ($frequency === 'disabled' || empty($frequency)) {
            return;
        }

        // Calculate next run time in site's local timezone (default 02:00 AM)
        $timeStr = $settings['schedule_time'] ?? '02:00';
        $parts = explode(':', $timeStr);
        $hour = isset($parts[0]) ? max(0, min(23, (int) $parts[0])) : 2;
        $minute = isset($parts[1]) ? max(0, min(59, (int) $parts[1])) : 0;

        $tz = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);
        $target = $now->setTime($hour, $minute, 0);

        if ($target <= $now) {
            $target = $target->modify('+1 day');
        }

        wp_schedule_event($target->getTimestamp(), $frequency, self::CRON_HOOK);
    }

    /**
     * Execute scheduled backup via WP-Cron.
     */
    public static function handleScheduledBackup(): void
    {
        self::runFullBackup('WP-Cron Tự động');
    }

    /**
     * Handle Server Crontab / aaPanel Webhook execution.
     * URL pattern: https://domain.com/?spl_backup_cron=run&token=SECRET_TOKEN
     */
    public static function handleWebhookTrigger(): void
    {
        $isWebhook = (isset($_GET['spl_backup_cron']) && $_GET['spl_backup_cron'] === 'run')
                  || (isset($_GET['hd_backup_cron']) && $_GET['hd_backup_cron'] === 'run');

        if (!$isWebhook) {
            return;
        }

        $token = sanitize_text_field($_GET['token'] ?? '');
        $settings = get_option('spl_cloud_backup_settings', []);
        $secret = $settings['webhook_secret'] ?? '';

        if (empty($secret) || !hash_equals($secret, $token)) {
            wp_die(__('Mã bảo mật Webhook không chính xác.', 'spl-cloud-backup'), 'Access Denied', ['response' => 403]);
        }

        // Disable memory limits & execution limits for long running backup
        if (function_exists('set_time_limit')) {
            @set_time_limit(1800);
        }
        @ini_set('memory_limit', '1024M');

        $result = self::runFullBackup('Server Crontab (aaPanel Webhook)');

        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($result);
        exit;
    }

    /**
     * Run full orchestrated backup pipeline.
     *
     * @param string $triggerMode e.g. 'Thủ công (Admin)', 'WP-Cron', 'Server Crontab (aaPanel)'
     * @param callable|null $logger function(string $msg, float $percent)
     * @param array<string, mixed> $overrides Optional runtime setting overrides (e.g. from WP-CLI)
     * @return array<string, mixed>
     */
    public static function runFullBackup(string $triggerMode = 'Thủ công', ?callable $logger = null, array $overrides = []): array
    {
        $lockKey = 'spl_backup_is_running';
        if (get_transient($lockKey)) {
            return [
                'success' => false,
                'message' => __('Một tiến trình sao lưu khác đang chạy trên hệ thống. Vui lòng thử lại sau ít phút.', 'spl-cloud-backup'),
            ];
        }

        set_transient($lockKey, time(), 1800); // 30 minutes lock

        $startTime = microtime(true);
        $savedSettings = get_option('spl_cloud_backup_settings', []);
        $settings = array_merge(is_array($savedSettings) ? $savedSettings : [], $overrides);
        $logs = [];

        $logFn = function (string $msg, float $percent = 0) use (&$logs, $logger) {
            $entry = sprintf('[%s] %s', wp_date('H:i:s'), $msg);
            $logs[] = $entry;
            if ($logger) {
                $logger($msg, $percent);
            }
        };

        $createdFiles = [];
        $overallSuccess = true;

        try {
            $logFn(sprintf(__('Khởi chạy tiến trình sao lưu hệ sinh thái SPL (Cơ chế: %s)...', 'spl-cloud-backup'), $triggerMode), 2);

            $backupType = $settings['backup_type'] ?? 'db_only';

            // 1. Dump Database
            $logFn(__('Đang bắt đầu sao lưu Cơ sở dữ liệu MySQL...', 'spl-cloud-backup'), 5);
            $dbResult = DatabaseDumper::dump(function ($msg, $pct) use ($logFn) {
                $logFn($msg, 5 + ($pct * 0.25));
            });

            if ($dbResult['success']) {
                $logFn($dbResult['message'], 30);
                $createdFiles[] = [
                    'type'     => 'Database',
                    'path'     => $dbResult['file_path'],
                    'name'     => $dbResult['file_name'],
                    'size'     => $dbResult['file_size'],
                    'size_fmt' => $dbResult['file_size_fmt'],
                ];
            } else {
                $overallSuccess = false;
                $logFn(__('LỖI DUMP CSDL: ', 'spl-cloud-backup') . ($dbResult['message'] ?? 'Unknown error'), 30);
            }

            // 2. Archive Files (if enabled)
            if ($backupType === 'db_and_files' || $backupType === 'full') {
                $scope = ($backupType === 'full') ? 'full' : 'wp_content';
                $logFn(sprintf(__('Đang đóng gói tệp tin mã nguồn (Phạm vi: %s)...', 'spl-cloud-backup'), $scope), 32);

                $excludes = array_filter(array_map('trim', explode("\n", $settings['custom_excludes'] ?? '')));
                $fileResult = FileArchiver::archive($scope, $excludes, function ($msg, $pct) use ($logFn) {
                    $logFn($msg, 32 + ($pct * 0.28));
                });

                if ($fileResult['success']) {
                    $logFn($fileResult['message'], 60);
                    $createdFiles[] = [
                        'type'     => ($scope === 'full') ? 'Full Website' : 'Source/wp-content',
                        'path'     => $fileResult['file_path'],
                        'name'     => $fileResult['file_name'],
                        'size'     => $fileResult['file_size'],
                        'size_fmt' => $fileResult['file_size_fmt'],
                    ];
                } else {
                    $overallSuccess = false;
                    $logFn(__('LỖI ĐÓNG GÓI TỆP TIN: ', 'spl-cloud-backup') . ($fileResult['message'] ?? 'Unknown error'), 60);
                }
            }

            // 3. Upload to Google Drive (if enabled)
            $enableDrive = !empty($settings['enable_google_drive']);
            $driveClient = null;
            $folderId = null;

            if ($enableDrive && !empty($createdFiles)) {
                $logFn(__('Bắt đầu truyền tải dữ liệu an toàn lên Google Drive...', 'spl-cloud-backup'), 62);
                $driveClient = new GoogleDriveClient($settings);
                $folderName = trim($settings['drive_folder_name'] ?? '') ?: 'SPL-Backups-' . sanitize_title(get_bloginfo('name'));
                $folderId = $driveClient->getOrCreateFolder($folderName);

                if ($folderId) {
                    $totalUploads = count($createdFiles);
                    foreach ($createdFiles as $idx => &$fInfo) {
                        $logFn(sprintf(__('Đang tải lên tệp: %s...', 'spl-cloud-backup'), $fInfo['name']), 65 + ($idx * 15));
                        $uploadRes = $driveClient->uploadFile($fInfo['path'], $fInfo['name'], $folderId, function ($msg, $pct) use ($logFn, $idx, $totalUploads) {
                            $base = 65 + ($idx * (25 / $totalUploads));
                            $step = (25 / $totalUploads) * ($pct / 100);
                            $logFn($msg, $base + $step);
                        });

                        if ($uploadRes['success']) {
                            $fInfo['drive_link'] = $uploadRes['web_link'];
                            $logFn($uploadRes['message'], 88);
                        } else {
                            $overallSuccess = false;
                            $logFn(__('LỖI TẢI LÊN GOOGLE DRIVE: ', 'spl-cloud-backup') . ($uploadRes['message'] ?? 'Unknown'), 88);
                        }
                    }
                    unset($fInfo);
                } else {
                    $overallSuccess = false;
                    $logFn(__('Không thể tạo hoặc truy cập thư mục lưu trữ trên Google Drive.', 'spl-cloud-backup'), 88);
                }
            }

            // 4. Send Email Notification (if enabled)
            $enableEmail = !empty($settings['enable_email_report']);
            if ($enableEmail) {
                $logFn(__('Đang gửi email báo cáo tình trạng sao lưu...', 'spl-cloud-backup'), 90);
                $notifier = new EmailNotifier($settings);

                $summaryData = [
                    'success'      => $overallSuccess,
                    'trigger_mode' => $triggerMode,
                    'duration'     => round(microtime(true) - $startTime, 2),
                    'files'        => $createdFiles,
                    'logs'         => $logs,
                ];

                $mailSent = $notifier->sendReport($summaryData);
                if ($mailSent) {
                    $logFn(__('Đã gửi email báo cáo thành công tới quản trị viên.', 'spl-cloud-backup'), 93);
                } else {
                    $logFn(__('Lưu ý: Không thể gửi email báo cáo qua hàm wp_mail().', 'spl-cloud-backup'), 93);
                }
            }

            // 5. Retention Management
            $logFn(__('Đang thực hiện quy chế dọn dẹp các bản sao lưu cũ (Retention)...', 'spl-cloud-backup'), 95);
            $retention = new RetentionManager($settings);
            $retention->execute($driveClient, $folderId, function ($msg) use ($logFn) {
                $logFn($msg, 97);
            });

            // 6. Delete local files after upload if option is checked
            if (!empty($settings['delete_local_after_upload']) && $enableDrive) {
                foreach ($createdFiles as $fInfo) {
                    if (!empty($fInfo['drive_link']) && file_exists($fInfo['path'])) {
                        @unlink($fInfo['path']);
                        $logFn(sprintf(__('Đã xóa tệp tin cục bộ để giải phóng dung lượng đĩa: %s', 'spl-cloud-backup'), $fInfo['name']), 98);
                    }
                }
            }
        } catch (\Throwable $e) {
            $overallSuccess = false;
            $logFn(__('LỖI HỆ THỐNG: ', 'spl-cloud-backup') . $e->getMessage(), 100);
        } finally {
            delete_transient($lockKey);
        }

        $duration = round(microtime(true) - $startTime, 2);

        $logFn(sprintf(__('Hoàn tất tiến trình sao lưu trong %ss!', 'spl-cloud-backup'), $duration), 100);

        // Record history entry
        self::recordHistory([
            'date'         => wp_date('Y-m-d H:i:s'),
            'trigger'      => $triggerMode,
            'duration'     => $duration,
            'success'      => $overallSuccess,
            'files'        => $createdFiles,
            'logs'         => $logs,
        ]);

        return [
            'success'  => $overallSuccess,
            'duration' => $duration,
            'files'    => $createdFiles,
            'logs'     => $logs,
            'message'  => $overallSuccess
                ? sprintf(__('Tiến trình sao lưu hoàn tất trong %ss.', 'spl-cloud-backup'), $duration)
                : sprintf(__('Tiến trình sao lưu kết thúc có sự cố sau %ss. Vui lòng kiểm tra nhật ký.', 'spl-cloud-backup'), $duration),
        ];
    }

    /**
     * Record history entry in WordPress options (capped at 25 entries).
     */
    private static function recordHistory(array $entry): void
    {
        $history = get_option('spl_cloud_backup_history', []);
        if (!is_array($history)) {
            $history = [];
        }

        array_unshift($history, $entry);
        $history = array_slice($history, 0, 25);

        update_option('spl_cloud_backup_history', $history, false);
    }
}
