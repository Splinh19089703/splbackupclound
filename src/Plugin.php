<?php

namespace SPLCloudBackup;

use SPLCloudBackup\Admin\SettingsPage;
use SPLCloudBackup\Drive\GoogleDriveClient;
use SPLCloudBackup\Notification\EmailNotifier;
use SPLCloudBackup\Scheduler\CronRunner;
use SPLCloudBackup\Storage\LocalVault;

defined('ABSPATH') || exit;

class Plugin
{
    private static ?Plugin $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * Plugin activation handler.
     */
    public static function onActivate(): void
    {
        LocalVault::ensureVaultExists();

        $existing = get_option('spl_cloud_backup_settings');
        if (empty($existing)) {
            update_option('spl_cloud_backup_settings', SettingsPage::getDefaultSettings(), false);
        }

        CronRunner::syncSchedule();
    }

    /**
     * Plugin deactivation handler.
     */
    public static function onDeactivate(): void
    {
        wp_clear_scheduled_hook(CronRunner::CRON_HOOK);
    }

    /**
     * Initialize plugin hooks.
     */
    public function init(): void
    {
        // Init scheduler & webhook
        CronRunner::init();

        // Admin menu & settings
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

        // Re-sync Cron when schedule settings are added or updated
        add_action('add_option_spl_cloud_backup_settings', [CronRunner::class, 'syncSchedule']);
        add_action('update_option_spl_cloud_backup_settings', [CronRunner::class, 'syncSchedule']);

        // AJAX endpoints
        add_action('wp_ajax_spl_backup_manual_run', [$this, 'ajaxManualRun']);
        add_action('wp_ajax_spl_backup_test_drive', [$this, 'ajaxTestDrive']);
        add_action('wp_ajax_spl_backup_test_mail', [$this, 'ajaxTestMail']);
        add_action('wp_ajax_spl_backup_delete_local', [$this, 'ajaxDeleteLocal']);

        // Legacy / fallback AJAX support
        add_action('wp_ajax_hd_backup_manual_run', [$this, 'ajaxManualRun']);
        add_action('wp_ajax_hd_backup_test_drive', [$this, 'ajaxTestDrive']);
        add_action('wp_ajax_hd_backup_test_mail', [$this, 'ajaxTestMail']);
        add_action('wp_ajax_hd_backup_delete_local', [$this, 'ajaxDeleteLocal']);

        // File download handler
        add_action('admin_post_spl_backup_download', [$this, 'handleDownload']);
        add_action('admin_post_hd_backup_download', [$this, 'handleDownload']);
    }

    /**
     * Register top-level admin menu page.
     */
    public function registerAdminMenu(): void
    {
        add_menu_page(
            __('SPL Cloud Backup', 'spl-cloud-backup'),
            __('SPL Backup', 'spl-cloud-backup'),
            'manage_options',
            'spl-cloud-backup',
            [SettingsPage::class, 'render'],
            'dashicons-cloud-saved',
            80
        );
    }

    /**
     * Register settings and hook sync schedule on save.
     */
    public function registerSettings(): void
    {
        register_setting(
            'spl_cloud_backup_group',
            'spl_cloud_backup_settings',
            [$this, 'sanitizeSettings']
        );
    }

    /**
     * Sanitize settings and re-sync WP-Cron schedule.
     */
    public function sanitizeSettings(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $sanitized = [];
        $sanitized['backup_type'] = in_array($input['backup_type'] ?? '', ['db_only', 'db_and_files', 'full'], true)
            ? $input['backup_type']
            : 'db_only';

        $sanitized['custom_excludes'] = sanitize_textarea_field($input['custom_excludes'] ?? '');
        $sanitized['enable_google_drive'] = !empty($input['enable_google_drive']) ? 1 : 0;
        $sanitized['drive_auth_type'] = ($input['drive_auth_type'] ?? '') === 'oauth' ? 'oauth' : 'service_account';

        $sanitized['service_account_json'] = trim($input['service_account_json'] ?? '');
        $sanitized['oauth_client_id'] = sanitize_text_field($input['oauth_client_id'] ?? '');
        $sanitized['oauth_client_secret'] = sanitize_text_field($input['oauth_client_secret'] ?? '');
        $sanitized['oauth_refresh_token'] = sanitize_text_field($input['oauth_refresh_token'] ?? '');
        $sanitized['drive_folder_name'] = sanitize_text_field($input['drive_folder_name'] ?? '');

        $sanitized['enable_email_report'] = !empty($input['enable_email_report']) ? 1 : 0;
        $sanitized['email_recipients'] = sanitize_textarea_field($input['email_recipients'] ?? '');
        $sanitized['email_attach_files'] = !empty($input['email_attach_files']) ? 1 : 0;

        $sanitized['schedule_frequency'] = sanitize_key($input['schedule_frequency'] ?? 'daily');
        $sanitized['schedule_time'] = sanitize_text_field($input['schedule_time'] ?? '02:00');
        $sanitized['webhook_secret'] = sanitize_text_field($input['webhook_secret'] ?? wp_generate_password(24, false, false));

        $sanitized['retention_local_count'] = max(0, (int) ($input['retention_local_count'] ?? 5));
        $sanitized['retention_drive_count'] = max(1, (int) ($input['retention_drive_count'] ?? 14));
        $sanitized['delete_local_after_upload'] = !empty($input['delete_local_after_upload']) ? 1 : 0;

        return $sanitized;
    }

    /**
     * Enqueue CSS & JS on plugin page.
     */
    public function enqueueAdminAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_spl-cloud-backup') {
            return;
        }

        wp_enqueue_style(
            'spl-cloud-backup-admin',
            SPL_CLOUD_BACKUP_URL . 'assets/admin.css',
            [],
            SPL_CLOUD_BACKUP_VERSION
        );

        wp_enqueue_script(
            'spl-cloud-backup-admin',
            SPL_CLOUD_BACKUP_URL . 'assets/admin.js',
            ['jquery'],
            SPL_CLOUD_BACKUP_VERSION,
            true
        );

        wp_localize_script('spl-cloud-backup-admin', 'splBackupData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('spl_backup_ajax_nonce'),
            'strings'  => [
                'confirm_delete' => __('Bạn có chắc chắn muốn xóa bản sao lưu này?', 'spl-cloud-backup'),
                'backup_running' => __('Tiến trình sao lưu đang thực hiện, vui lòng không đóng trình duyệt...', 'spl-cloud-backup'),
                'success'        => __('Hoàn tất thành công!', 'spl-cloud-backup'),
                'error'          => __('Đã xảy ra lỗi!', 'spl-cloud-backup'),
            ],
        ]);
    }

    /**
     * AJAX: Run manual backup.
     */
    public function ajaxManualRun(): void
    {
        check_ajax_referer('spl_backup_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Không đủ quyền hạn.', 'spl-cloud-backup')]);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(1800);
        }
        @ini_set('memory_limit', '1024M');

        $result = CronRunner::runFullBackup('Thủ công (Admin Dashboard)');

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Test Google Drive connection (supports live unsaved form fields).
     */
    public function ajaxTestDrive(): void
    {
        check_ajax_referer('spl_backup_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Không đủ quyền hạn.', 'spl-cloud-backup')]);
        }

        $settings = get_option('spl_cloud_backup_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        if (isset($_POST['drive_auth_type'])) {
            $settings['drive_auth_type'] = ($_POST['drive_auth_type'] === 'oauth') ? 'oauth' : 'service_account';
        }
        if (isset($_POST['service_account_json'])) {
            $settings['service_account_json'] = trim(wp_unslash((string) $_POST['service_account_json']));
        }
        if (isset($_POST['oauth_client_id'])) {
            $settings['oauth_client_id'] = sanitize_text_field(wp_unslash((string) $_POST['oauth_client_id']));
        }
        if (isset($_POST['oauth_client_secret'])) {
            $settings['oauth_client_secret'] = sanitize_text_field(wp_unslash((string) $_POST['oauth_client_secret']));
        }
        if (isset($_POST['oauth_refresh_token'])) {
            $settings['oauth_refresh_token'] = sanitize_text_field(wp_unslash((string) $_POST['oauth_refresh_token']));
        }

        $client = new GoogleDriveClient($settings);
        $res = $client->testConnection();

        if ($res['success']) {
            wp_send_json_success($res);
        } else {
            wp_send_json_error($res);
        }
    }

    /**
     * AJAX: Test Email sending.
     */
    public function ajaxTestMail(): void
    {
        check_ajax_referer('spl_backup_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Không đủ quyền hạn.', 'spl-cloud-backup')]);
        }

        $recipient = sanitize_email($_POST['recipient'] ?? get_option('admin_email'));
        $notifier = new EmailNotifier();
        $res = $notifier->sendTestEmail($recipient);

        if ($res['success']) {
            wp_send_json_success($res);
        } else {
            wp_send_json_error($res);
        }
    }

    /**
     * AJAX: Safely delete local backup file.
     */
    public function ajaxDeleteLocal(): void
    {
        check_ajax_referer('spl_backup_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Không đủ quyền hạn.', 'spl-cloud-backup')]);
        }

        $filename = sanitize_file_name($_POST['filename'] ?? '');
        if (empty($filename)) {
            wp_send_json_error(['message' => __('Tên tệp không hợp lệ.', 'spl-cloud-backup')]);
        }

        $deleted = LocalVault::deleteFile($filename);
        if ($deleted) {
            wp_send_json_success(['message' => sprintf(__('Đã xóa tệp: %s', 'spl-cloud-backup'), $filename)]);
        } else {
            wp_send_json_error(['message' => __('Không thể xóa tệp tin.', 'spl-cloud-backup')]);
        }
    }

    /**
     * Admin POST: Secure file download stream.
     */
    public function handleDownload(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Không có quyền tải tệp tin.', 'spl-cloud-backup'));
        }

        $isSplNonce = isset($_REQUEST['_wpnonce']) && wp_verify_nonce($_REQUEST['_wpnonce'], 'spl_backup_download');
        $isHdNonce = isset($_REQUEST['_wpnonce']) && wp_verify_nonce($_REQUEST['_wpnonce'], 'hd_backup_download');

        if (!$isSplNonce && !$isHdNonce) {
            wp_die(__('Liên kết tải về đã hết hạn hoặc không hợp lệ.', 'spl-cloud-backup'));
        }

        $filename = sanitize_file_name($_GET['file'] ?? '');
        $path = LocalVault::getFilePath($filename);

        if (!$path || !file_exists($path)) {
            wp_die(__('Tệp tin không tồn tại trong kho lưu trữ.', 'spl-cloud-backup'));
        }

        $size = filesize($path);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = ($ext === 'gz') ? 'application/gzip' : 'application/zip';

        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . $size);

        readfile($path);
        exit;
    }
}
