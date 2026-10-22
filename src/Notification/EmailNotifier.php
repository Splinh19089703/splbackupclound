<?php

namespace SPLCloudBackup\Notification;

defined('ABSPATH') || exit;

class EmailNotifier
{
    private array $settings;

    public function __construct(?array $settings = null)
    {
        $this->settings = $settings ?? get_option('spl_cloud_backup_settings', []);
    }

    /**
     * Send backup report email.
     *
     * @param array<string, mixed> $backupSummary
     * @return bool
     */
    public function sendReport(array $backupSummary): bool
    {
        $recipients = $this->getRecipients();
        if (empty($recipients)) {
            return false;
        }

        $siteName = get_bloginfo('name');
        $isSuccess = !empty($backupSummary['success']);
        $statusText = $isSuccess ? 'THÀNH CÔNG' : 'GẶP SỰ CỐ';

        $subject = sprintf('[%s] Báo cáo sao lưu dữ liệu website - %s (%s)', $siteName, $statusText, wp_date('d/m/Y H:i'));

        $attachments = [];
        $filesInfo = $backupSummary['files'] ?? [];
        $attachThreshold = 20 * 1024 * 1024; // 20MB max SMTP limit

        $allowAttach = !empty($this->settings['email_attach_files']);

        foreach ($filesInfo as &$file) {
            $path = $file['path'] ?? '';
            $size = $file['size'] ?? 0;

            if ($allowAttach && file_exists($path) && $size > 0 && $size <= $attachThreshold) {
                $attachments[] = $path;
                $file['attached'] = true;
            }
        }
        unset($file);
        $backupSummary['files'] = $filesInfo;

        $htmlBody = $this->renderHtmlBody($backupSummary, $isSuccess);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $siteName . ' <' . get_option('admin_email') . '>',
        ];

        return wp_mail($recipients, $subject, $htmlBody, $headers, $attachments);
    }

    /**
     * Send a quick test email to verify SMTP delivery.
     */
    public function sendTestEmail(string $testRecipient): array
    {
        if (!is_email($testRecipient)) {
            return [
                'success' => false,
                'message' => __('Địa chỉ email nhận không hợp lệ.', 'spl-cloud-backup'),
            ];
        }

        $siteName = get_bloginfo('name');
        $subject = sprintf('[%s] Kiểm tra kết nối Email - SPL Cloud Backup', $siteName);

        $html = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 8px;">'
              . '<h2 style="color: #2563eb; margin-top: 0;">SPL Cloud Backup - Test Email</h2>'
              . '<p>Xin chào quản trị viên,</p>'
              . '<p>Đây là email kiểm tra tính năng gửi thông báo tự động từ plugin <strong>SPL Cloud Backup</strong> trên website <strong>' . esc_html($siteName) . '</strong>.</p>'
              . '<p style="color: #16a34a; font-weight: bold;">Cấu hình gửi email của bạn đang hoạt động bình thường!</p>'
              . '<hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;">'
              . '<p style="font-size: 12px; color: #64748b;">Thời gian gửi: ' . wp_date('Y-m-d H:i:s') . ' (Giờ hệ thống)</p>'
              . '</div>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $sent = wp_mail($testRecipient, $subject, $html, $headers);

        if ($sent) {
            return [
                'success' => true,
                'message' => sprintf(__('Đã gửi email thử nghiệm thành công tới %s!', 'spl-cloud-backup'), $testRecipient),
            ];
        }

        return [
            'success' => false,
            'message' => __('Không thể gửi email. Vui lòng kiểm tra lại cấu hình SMTP (ví dụ FluentSMTP).', 'spl-cloud-backup'),
        ];
    }

    /**
     * Get list of validated recipient email addresses.
     *
     * @return array<int, string>
     */
    private function getRecipients(): array
    {
        $raw = trim($this->settings['email_recipients'] ?? '');
        if (empty($raw)) {
            $adminEmail = get_option('admin_email');
            return is_email($adminEmail) ? [$adminEmail] : [];
        }

        $lines = preg_split('/[\r\n,;]+/', $raw);
        $valid = [];
        foreach ($lines as $line) {
            $clean = trim($line);
            if (is_email($clean)) {
                $valid[] = $clean;
            }
        }

        return array_unique($valid);
    }

    /**
     * Render beautiful HTML report template.
     */
    private function renderHtmlBody(array $summary, bool $isSuccess): string
    {
        $siteName = get_bloginfo('name');
        $siteUrl = home_url();
        $date = wp_date('d/m/Y H:i:s');
        $triggerMode = esc_html($summary['trigger_mode'] ?? 'Thủ công (Admin)');
        $duration = esc_html((string) ($summary['duration'] ?? '0')) . 's';

        $badgeColor = $isSuccess ? '#16a34a' : '#dc2626';
        $badgeText = $isSuccess ? 'THÀNH CÔNG' : 'CẢNH BÁO';

        $files = $summary['files'] ?? [];
        $filesRowsHtml = '';

        foreach ($files as $f) {
            $name = esc_html($f['name'] ?? '');
            $size = esc_html($f['size_fmt'] ?? size_format($f['size'] ?? 0, 2));
            $type = esc_html($f['type'] ?? 'Tệp tin');
            $driveLink = $f['drive_link'] ?? '';
            $isAttached = !empty($f['attached']);

            $actionCol = '';
            if (!empty($driveLink)) {
                $actionCol .= '<a href="' . esc_url($driveLink) . '" target="_blank" style="display: inline-block; padding: 4px 10px; background-color: #2563eb; color: #ffffff; text-decoration: none; border-radius: 4px; font-size: 12px; margin-right: 6px;">Xem trên Drive</a>';
            }
            if ($isAttached) {
                $actionCol .= '<span style="font-size: 11px; color: #16a34a; background: #dcfce7; padding: 3px 8px; border-radius: 4px;">Đã đính kèm</span>';
            }

            $filesRowsHtml .= '<tr>'
                           . '<td style="padding: 10px 14px; border-bottom: 1px solid #e2e8f0; font-family: monospace; font-size: 13px;">' . $name . '</td>'
                           . '<td style="padding: 10px 14px; border-bottom: 1px solid #e2e8f0;">' . $type . '</td>'
                           . '<td style="padding: 10px 14px; border-bottom: 1px solid #e2e8f0; font-weight: 600;">' . $size . '</td>'
                           . '<td style="padding: 10px 14px; border-bottom: 1px solid #e2e8f0;">' . $actionCol . '</td>'
                           . '</tr>';
        }

        if (empty($filesRowsHtml)) {
            $filesRowsHtml = '<tr><td colspan="4" style="padding: 14px; text-align: center; color: #64748b;">Không có tệp tin sao lưu được tạo.</td></tr>';
        }

        $logs = $summary['logs'] ?? [];
        $logsHtml = '';
        if (!empty($logs)) {
            $logsHtml .= '<div style="margin-top: 24px; padding: 14px; background: #0f172a; color: #94a3b8; border-radius: 6px; font-family: Consolas, monospace; font-size: 12px; line-height: 1.6; max-height: 250px; overflow-y: auto;">';
            $logsHtml .= '<strong style="color: #38bdf8;">Nhật ký tiến trình (Execution Logs):</strong><br>';
            foreach ($logs as $log) {
                $logsHtml .= '&gt; ' . esc_html($log) . '<br>';
            }
            $logsHtml .= '</div>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Báo cáo sao lưu {$siteName}</title>
</head>
<body style="margin: 0; padding: 24px; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #334155;">
    <div style="max-width: 680px; margin: 0 auto; background: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.08);">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 28px 32px; color: #ffffff;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 style="margin: 0; font-size: 20px; font-weight: 700; letter-spacing: -0.02em;">SPL Cloud Backup</h1>
                    <p style="margin: 4px 0 0 0; color: #94a3b8; font-size: 13px;">Hệ thống sao lưu tự động & bảo vệ dữ liệu</p>
                </div>
                <div style="text-align: right;">
                    <span style="display: inline-block; background-color: {$badgeColor}; color: #ffffff; padding: 6px 14px; border-radius: 9999px; font-size: 12px; font-weight: 700; letter-spacing: 0.05em;">{$badgeText}</span>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div style="padding: 32px;">
            <h2 style="font-size: 16px; margin: 0 0 16px 0; color: #0f172a;">Thông tin chi tiết phiên sao lưu</h2>
            
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 13px;">
                <tr>
                    <td style="padding: 8px 0; color: #64748b; width: 140px;">Website:</td>
                    <td style="padding: 8px 0; font-weight: 600;"><a href="{$siteUrl}" style="color: #2563eb; text-decoration: none;">{$siteName}</a></td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #64748b;">Thời gian:</td>
                    <td style="padding: 8px 0;">{$date}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #64748b;">Cơ chế kích hoạt:</td>
                    <td style="padding: 8px 0;">{$triggerMode}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #64748b;">Thời lượng thực thi:</td>
                    <td style="padding: 8px 0;">{$duration}</td>
                </tr>
            </table>

            <h3 style="font-size: 14px; margin: 24px 0 12px 0; color: #0f172a; text-transform: uppercase; letter-spacing: 0.04em;">Tệp tin sao lưu đã tạo</h3>
            <div style="border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden;">
                <table style="width: 100%; border-collapse: collapse; text-align: left;">
                    <thead>
                        <tr style="background: #f8fafc; font-size: 12px; color: #64748b; text-transform: uppercase;">
                            <th style="padding: 10px 14px; border-bottom: 1px solid #e2e8f0;">Tên tệp</th>
                            <th style="padding: 10px 14px; border-bottom: 1px solid #e2e8f0;">Loại</th>
                            <th style="padding: 10px 14px; border-bottom: 1px solid #e2e8f0;">Kích thước</th>
                            <th style="padding: 10px 14px; border-bottom: 1px solid #e2e8f0;">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$filesRowsHtml}
                    </tbody>
                </table>
            </div>

            {$logsHtml}

            <div style="margin-top: 28px; padding: 14px; background: #eff6ff; border-left: 4px solid #3b82f6; border-radius: 4px; font-size: 13px; color: #1e40af;">
                <strong>Lưu ý bảo mật:</strong> Các tệp sao lưu được lưu trữ trên Google Drive và lưu trữ cục bộ tại thư mục được mã hóa/chặn truy cập trực tiếp. Hãy đảm bảo tài khoản Google Drive của bạn được bảo vệ 2 lớp (2FA).
            </div>
        </div>

        <!-- Footer -->
        <div style="background: #f8fafc; padding: 18px 32px; border-top: 1px solid #e2e8f0; text-align: center; font-size: 12px; color: #94a3b8;">
            Hệ thống sao lưu tự động SPL Cloud Backup &bull; Được phát triển bởi Bluera Việt Nhật
        </div>
    </div>
</body>
</html>
HTML;
    }
}
