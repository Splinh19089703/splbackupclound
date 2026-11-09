<?php

namespace SPLCloudBackup\Admin;

use SPLCloudBackup\Drive\GoogleDriveClient;
use SPLCloudBackup\Storage\LocalVault;

defined('ABSPATH') || exit;

class SettingsPage
{
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Bạn không có quyền truy cập trang này.', 'spl-cloud-backup'));
        }

        $settings = get_option('spl_cloud_backup_settings', self::getDefaultSettings());
        $history = get_option('spl_cloud_backup_history', []);
        $lastBackup = !empty($history[0]) ? $history[0] : null;

        $vaultPath = LocalVault::getVaultPath();
        $localBackups = LocalVault::listBackups();

        $webhookUrl = add_query_arg([
            'spl_backup_cron' => 'run',
            'token'           => $settings['webhook_secret'] ?? '',
        ], home_url('/'));

        $activeTab = sanitize_key($_GET['tab'] ?? 'general');

        ?>
        <div class="wrap spl-backup-wrap">
            <header class="spl-backup-header">
                <div class="spl-header-left">
                    <span class="spl-logo-badge"><span class="dashicons dashicons-cloud-saved"></span></span>
                    <div>
                        <h1 class="spl-title"><?php esc_html_e('SPL Cloud Backup', 'spl-cloud-backup'); ?> <span class="spl-version">v<?php echo esc_html(SPL_CLOUD_BACKUP_VERSION); ?></span></h1>
                        <p class="spl-subtitle"><?php esc_html_e('Giải pháp sao lưu tự động CSDL & Mã nguồn lên Google Drive & Email cho hệ thống Bluera — DailyXeDien', 'spl-cloud-backup'); ?></p>
                    </div>
                </div>
                <div class="spl-header-right">
                    <button type="button" id="btn-start-manual-backup" class="button button-primary button-hero spl-btn-pulse">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Sao lưu ngay', 'spl-cloud-backup'); ?>
                    </button>
                </div>
            </header>

            <!-- Metrics Bar -->
            <div class="spl-metrics-grid">
                <div class="spl-metric-card">
                    <div class="spl-metric-icon icon-blue"><span class="dashicons dashicons-database"></span></div>
                    <div class="spl-metric-content">
                        <span class="spl-metric-label"><?php esc_html_e('Phiên sao lưu gần nhất', 'spl-cloud-backup'); ?></span>
                        <strong class="spl-metric-value">
                            <?php echo $lastBackup ? esc_html($lastBackup['date']) : esc_html__('Chưa có', 'spl-cloud-backup'); ?>
                        </strong>
                        <span class="spl-metric-meta">
                            <?php if ($lastBackup): ?>
                                <span class="spl-badge <?php echo !empty($lastBackup['success']) ? 'badge-success' : 'badge-error'; ?>">
                                    <?php echo !empty($lastBackup['success']) ? esc_html__('Thành công', 'spl-cloud-backup') : esc_html__('Sự cố', 'spl-cloud-backup'); ?>
                                </span>
                                (<?php echo esc_html($lastBackup['duration'] ?? '0'); ?>s)
                            <?php else: ?>
                                <?php esc_html_e('Sẵn sàng khởi chạy lần đầu', 'spl-cloud-backup'); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <div class="spl-metric-card">
                    <div class="spl-metric-icon icon-green"><span class="dashicons dashicons-google"></span></div>
                    <div class="spl-metric-content">
                        <span class="spl-metric-label"><?php esc_html_e('Google Drive Cloud', 'spl-cloud-backup'); ?></span>
                        <strong class="spl-metric-value">
                            <?php echo !empty($settings['enable_google_drive']) ? esc_html__('Đang kích hoạt', 'spl-cloud-backup') : esc_html__('Chưa bật', 'spl-cloud-backup'); ?>
                        </strong>
                        <span class="spl-metric-meta">
                            <?php echo esc_html($settings['drive_folder_name'] ?? 'Mặc định'); ?>
                        </span>
                    </div>
                </div>

                <div class="spl-metric-card">
                    <div class="spl-metric-icon icon-purple"><span class="dashicons dashicons-clock"></span></div>
                    <div class="spl-metric-content">
                        <span class="spl-metric-label"><?php esc_html_e('Lịch trình tự động', 'spl-cloud-backup'); ?></span>
                        <strong class="spl-metric-value">
                            <?php
                            $freqMap = [
                                'disabled'    => __('Tắt', 'spl-cloud-backup'),
                                'daily'       => __('Hàng ngày (02:00 AM)', 'spl-cloud-backup'),
                                'twicedaily'  => __('2 lần / ngày', 'spl-cloud-backup'),
                                'weekly'      => __('Hàng tuần', 'spl-cloud-backup'),
                                'monthly'     => __('Hàng tháng', 'spl-cloud-backup'),
                            ];
                            echo esc_html($freqMap[$settings['schedule_frequency'] ?? 'daily'] ?? __('Hàng ngày', 'spl-cloud-backup'));
                            ?>
                        </strong>
                        <span class="spl-metric-meta">
                            <?php esc_html_e('Hỗ trợ WP-Cron & aaPanel Webhook', 'spl-cloud-backup'); ?>
                        </span>
                    </div>
                </div>

                <div class="spl-metric-card">
                    <div class="spl-metric-icon icon-amber"><span class="dashicons dashicons-media-archive"></span></div>
                    <div class="spl-metric-content">
                        <span class="spl-metric-label"><?php esc_html_e('Bản lưu cục bộ (Local)', 'spl-cloud-backup'); ?></span>
                        <strong class="spl-metric-value">
                            <?php echo count($localBackups); ?> <?php esc_html_e('tệp tin', 'spl-cloud-backup'); ?>
                        </strong>
                        <span class="spl-metric-meta">
                            <?php
                            $totalSize = array_sum(array_column($localBackups, 'size'));
                            echo esc_html(size_format($totalSize, 2));
                            ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Live Runner Modal / Section -->
            <div id="spl-backup-runner-panel" class="spl-runner-panel" style="display: none;">
                <div class="spl-runner-header">
                    <h3><span class="dashicons dashicons-update spl-spin"></span> <?php esc_html_e('Đang thực hiện sao lưu trực tuyến...', 'spl-cloud-backup'); ?></h3>
                    <span id="spl-runner-status-badge" class="spl-badge badge-info"><?php esc_html_e('Đang khởi chạy', 'spl-cloud-backup'); ?></span>
                </div>
                <div class="spl-progress-bar-container">
                    <div id="spl-progress-bar" class="spl-progress-bar" style="width: 0%;"></div>
                </div>
                <div class="spl-runner-log-terminal">
                    <div id="spl-runner-logs" class="spl-logs-content"></div>
                </div>
            </div>

            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper spl-tabs-nav">
                <a href="<?php echo esc_url(add_query_arg('tab', 'general')); ?>" class="nav-tab <?php echo $activeTab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Cấu hình chung', 'spl-cloud-backup'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'drive')); ?>" class="nav-tab <?php echo $activeTab === 'drive' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-google"></span> <?php esc_html_e('Google Drive Cloud', 'spl-cloud-backup'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'email')); ?>" class="nav-tab <?php echo $activeTab === 'email' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-email"></span> <?php esc_html_e('Báo cáo Email', 'spl-cloud-backup'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'schedule')); ?>" class="nav-tab <?php echo $activeTab === 'schedule' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e('Lịch trình & Server Cron', 'spl-cloud-backup'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'history')); ?>" class="nav-tab <?php echo $activeTab === 'history' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-list-view"></span> <?php esc_html_e('Tệp tin & Lịch sử', 'spl-cloud-backup'); ?>
                </a>
            </nav>

            <!-- Tab Contents -->
            <form id="spl-backup-settings-form" method="post" action="options.php" class="spl-form-container">
                <?php
                settings_fields('spl_cloud_backup_group');
                ?>

                <!-- TAB: GENERAL -->
                <div class="spl-tab-content <?php echo $activeTab === 'general' ? 'is-active' : ''; ?>">
                    <div class="spl-card">
                        <h3><?php esc_html_e('Phạm vi & Đối tượng sao lưu', 'spl-cloud-backup'); ?></h3>
                        
                        <div class="spl-field-row">
                            <label class="spl-field-label"><?php esc_html_e('Chế độ sao lưu:', 'spl-cloud-backup'); ?></label>
                            <div class="spl-field-input">
                                <label class="spl-radio-option">
                                    <input type="radio" name="spl_cloud_backup_settings[backup_type]" value="db_only" <?php checked($settings['backup_type'] ?? 'db_only', 'db_only'); ?>>
                                    <strong><?php esc_html_e('Chỉ Cơ sở dữ liệu (Database Only - Khuyến nghị cho lịch hàng ngày)', 'spl-cloud-backup'); ?></strong>
                                    <p class="description"><?php esc_html_e('Tối ưu siêu tốc (khoảng 3 - 10 giây). Sao lưu toàn bộ bảng MySQL vào tệp .sql.gz, dung lượng nhỏ gọn, gửi qua email được.', 'spl-cloud-backup'); ?></p>
                                </label>
                                <label class="spl-radio-option">
                                    <input type="radio" name="spl_cloud_backup_settings[backup_type]" value="db_and_files" <?php checked($settings['backup_type'] ?? 'db_only', 'db_and_files'); ?>>
                                    <strong><?php esc_html_e('Cơ sở dữ liệu + Thư mục wp-content (Themes, Plugins, Uploads)', 'spl-cloud-backup'); ?></strong>
                                    <p class="description"><?php esc_html_e('Bao gồm CSDL cùng toàn bộ giao diện, plugin và hình ảnh đã tải lên. Bỏ qua core WordPress để tiết kiệm dung lượng.', 'spl-cloud-backup'); ?></p>
                                </label>
                                <label class="spl-radio-option">
                                    <input type="radio" name="spl_cloud_backup_settings[backup_type]" value="full" <?php checked($settings['backup_type'] ?? 'db_only', 'full'); ?>>
                                    <strong><?php esc_html_e('Toàn bộ Website (Full Site)', 'spl-cloud-backup'); ?></strong>
                                    <p class="description"><?php esc_html_e('Đóng gói toàn bộ mã nguồn website từ thư mục gốc ABSPATH vào tệp .zip.', 'spl-cloud-backup'); ?></p>
                                </label>
                            </div>
                        </div>

                        <div class="spl-field-row">
                            <label class="spl-field-label"><?php esc_html_e('Thư mục Vault bảo mật:', 'spl-cloud-backup'); ?></label>
                            <div class="spl-field-input">
                                <input type="text" class="regular-text" readonly value="<?php echo esc_attr($vaultPath); ?>">
                                <p class="description"><?php esc_html_e('Thư mục đã được khóa an toàn tự động bởi file .htaccess (Require all denied) & index.php ngăn chặn mọi truy cập trực tiếp từ trình duyệt.', 'spl-cloud-backup'); ?></p>
                            </div>
                        </div>

                        <div class="spl-field-row">
                            <label class="spl-field-label"><?php esc_html_e('Loại trừ bổ sung (Exclusions):', 'spl-cloud-backup'); ?></label>
                            <div class="spl-field-input">
                                <textarea name="spl_cloud_backup_settings[custom_excludes]" rows="4" class="large-text code"><?php echo esc_textarea($settings['custom_excludes'] ?? ''); ?></textarea>
                                <p class="description"><?php esc_html_e('Mỗi dòng một tên thư mục hoặc mẫu tệp cần bỏ qua khi nén zip. Mặc định hệ thống đã tự động bỏ qua .git, node_modules, cache, litespeed, và thư mục spl-backups.', 'spl-cloud-backup'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB: GOOGLE DRIVE -->
                <div class="spl-tab-content <?php echo $activeTab === 'drive' ? 'is-active' : ''; ?>">
                    <div class="spl-card">
                        <h3><?php esc_html_e('Cấu hình Google Drive Cloud Storage (API v3)', 'spl-cloud-backup'); ?></h3>
                        
                        <div class="spl-field-row">
                            <label class="spl-field-label"><?php esc_html_e('Kích hoạt Google Drive:', 'spl-cloud-backup'); ?></label>
                            <div class="spl-field-input">
                                <label>
                                    <input type="checkbox" name="spl_cloud_backup_settings[enable_google_drive]" value="1" <?php checked(!empty($settings['enable_google_drive'])); ?>>
                                    <?php esc_html_e('Tự động đẩy bản sao lưu lên Google Drive ngay sau khi tạo', 'spl-cloud-backup'); ?>
                                </label>
                            </div>
                        </div>

                        <div class="spl-field-row">
                            <label class="spl-field-label"><?php esc_html_e('Phương thức xác thực:', 'spl-cloud-backup'); ?></label>
                            <div class="spl-field-input">
                                <label class="spl-radio-inline">
                                    <input type="radio" name="spl_cloud_backup_settings[drive_auth_type]" value="oauth" <?php checked($settings['drive_auth_type'] ?? 'oauth', 'oauth'); ?>>
                                    <strong><?php esc_html_e('OAuth 2.0 - Client ID / Refresh Token (Khuyến nghị cho Google Drive cá nhân)', 'spl-cloud-backup'); ?></strong>
                                </label>
                                <label class="spl-radio-inline" style="margin-left: 20px;">
                                    <input type="radio" name="spl_cloud_backup_settings[drive_auth_type]" value="service_account" <?php checked($settings['drive_auth_type'] ?? 'oauth', 'service_account'); ?>>
                                    <?php esc_html_e('Google Service Account JSON (Chỉ dùng cho Google Workspace Shared Drive)', 'spl-cloud-backup'); ?>
                                </label>
                            </div>
                        </div>

                        <!-- Service Account Block -->
                        <div id="drive-auth-sa" class="spl-auth-block">
                            <div class="spl-field-row">
                                <label class="spl-field-label"><?php esc_html_e('Nội dung Service Account JSON:', 'spl-cloud-backup'); ?></label>
                                <div class="spl-field-input">
                                    <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 10px 14px; margin-bottom: 10px; border-radius: 4px; color: #92400e; font-size: 13px;">
                                        <strong>⚠️ Lưu ý quan trọng từ Google Cloud:</strong> Tài khoản Service Account (<code>*.iam.gserviceaccount.com</code>) có hạn mức bộ nhớ <strong>0 Byte (0 B / 0 B)</strong> nên sẽ báo lỗi <code>Service Accounts do not have storage quota</code> khi tải tệp lên Google Drive cá nhân (My Drive). Vui lòng chọn <strong>OAuth 2.0 (Client ID / Refresh Token)</strong> bên trên để sử dụng chính dung lượng Google Drive (15GB+) của bạn.
                                    </div>
                                    <textarea name="spl_cloud_backup_settings[service_account_json]" rows="6" class="large-text code" placeholder='{"type": "service_account", "project_id": "...", "private_key": "...", "client_email": "..."}'><?php echo esc_textarea($settings['service_account_json'] ?? ''); ?></textarea>
                                    <p class="description">
                                        <?php esc_html_e('Chỉ sử dụng Service Account nếu bạn đang đẩy bản sao lưu vào Bộ nhớ dùng chung (Shared Drives) của Google Workspace doanh nghiệp.', 'spl-cloud-backup'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- OAuth Block -->
                        <div id="drive-auth-oauth" class="spl-auth-block" style="display: none;">
                            <?php
                            $redirectUri = admin_url('admin.php?page=spl-cloud-backup&spl_oauth=callback');
                            $oauthConnectUrl = wp_nonce_url(admin_url('admin.php?page=spl-cloud-backup&spl_oauth=connect'), 'spl_oauth_connect');
                            $hasToken = !empty($settings['oauth_refresh_token']);
                            ?>
                            <div class="spl-field-row">
                                <label class="spl-field-label"><?php esc_html_e('Authorized Redirect URI:', 'spl-cloud-backup'); ?></label>
                                <div class="spl-field-input">
                                    <input type="text" class="large-text code" readonly value="<?php echo esc_attr($redirectUri); ?>" onclick="this.select();">
                                    <p class="description"><?php esc_html_e('Sao chép URI này dán vào mục "Authorized redirect URIs" trong Google Cloud Console khi tạo OAuth Client ID.', 'spl-cloud-backup'); ?></p>
                                </div>
                            </div>
                            <div class="spl-field-row">
                                <label class="spl-field-label"><?php esc_html_e('Client ID:', 'spl-cloud-backup'); ?></label>
                                <div class="spl-field-input">
                                    <input type="text" name="spl_cloud_backup_settings[oauth_client_id]" class="large-text" value="<?php echo esc_attr($settings['oauth_client_id'] ?? ''); ?>" placeholder="xxxx.apps.googleusercontent.com">
                                </div>
                            </div>
                            <div class="spl-field-row">
                                <label class="spl-field-label"><?php esc_html_e('Client Secret:', 'spl-cloud-backup'); ?></label>
                                <div class="spl-field-input">
                                    <input type="password" name="spl_cloud_backup_settings[oauth_client_secret]" class="large-text" value="<?php echo esc_attr($settings['oauth_client_secret'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="spl-field-row">
                                <label class="spl-field-label"><?php esc_html_e('Trạng thái xác thực:', 'spl-cloud-backup'); ?></label>
                                <div class="spl-field-input">
                                    <?php if ($hasToken): ?>
                                        <span class="spl-badge badge-success" style="padding: 6px 12px; font-size: 13px; display: inline-block; margin-bottom: 8px;">
                                            ✓ <?php esc_html_e('Đã liên kết Google Account thành công!', 'spl-cloud-backup'); ?>
                                        </span>
                                        <br>
                                        <a href="<?php echo esc_url($oauthConnectUrl); ?>" class="button button-secondary">
                                            <span class="dashicons dashicons-update"></span> <?php esc_html_e('Ủy quyền liên kết lại', 'spl-cloud-backup'); ?>
                                        </a>
                                    <?php else: ?>
                                        <p class="description" style="margin-bottom: 8px; color: #b45309;">
                                            <?php esc_html_e('Sau khi nhập Client ID & Secret và bấm "Lưu cài đặt", hãy bấm nút bên dưới để cấp quyền kết nối tới Google Drive:', 'spl-cloud-backup'); ?>
                                        </p>
                                        <a href="<?php echo esc_url($oauthConnectUrl); ?>" class="button button-primary button-hero">
                                            <span class="dashicons dashicons-google"></span> <?php esc_html_e('Ủy quyền kết nối Google Drive ngay', 'spl-cloud-backup'); ?>
                                        </a>
                                    <?php endif; ?>
                                    <input type="hidden" name="spl_cloud_backup_settings[oauth_refresh_token]" value="<?php echo esc_attr($settings['oauth_refresh_token'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="spl-field-row">
                            <label class="spl-field-label"><?php esc_html_e('Tên thư mục trên Google Drive:', 'spl-cloud-backup'); ?></label>
                            <div class="spl-field-input">
                                <input type="text" name="spl_cloud_backup_settings[drive_folder_name]" class="regular-text" value="<?php echo esc_attr($settings['drive_folder_name'] ?? ('SPL-Backups-' . sanitize_title(get_bloginfo('name')))); ?>">
                                <p class="description"><?php esc_html_e('Nếu thư mục chưa có trên Drive, plugin sẽ tự động tạo mới.', 'spl-cloud-backup'); ?></p>
                            </div>
                        </div>

                        <div class="spl-field-row">
                            <label class="spl-field-label"></label>
                            <div class="spl-field-input">
                                <button type="button" id="btn-test-drive" class="button button-secondary">
                                    <span class="dashicons dashicons-networking"></span> <?php esc_html_e('Kiểm tra kết nối Google Drive', 'spl-cloud-backup'); ?>
                                </button>
                                <span id="drive-test-result" class="spl-test-indicator"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB: EMAIL -->
                <div class="spl-tab-content <?php echo $activeTab === 'email' ? 'is-active' : ''; ?>">
                    <div class="spl-card">
                        <h3><?php esc_html_e('Cấu hình Gửi Báo cáo Email', 'spl-cloud-backup'); ?></h3>

                        <div class="spl-field-row">
                            <label class="spl-field-label"><?php esc_html_e('Bật gửi báo cáo Email:', 'spl-cloud-backup'); ?></label>
                            <div class="spl-field-input">
                                <label>
                                    <input type="checkbox" name="spl_cloud_backup_settings[enable_email_report]" value="1" <?php checked(!empty($settings['enable_email_report'])); ?>>
                                    <?php esc_html_e('Gửi email thông báo trạng thái kèm link tải ngay khi sao lưu xong', 'spl-cloud-backup'); ?>
                                </label>
                            </div>
                        </div>

                        <div class="spl-field-row">
                            <label class="spl-field-label"><?php esc_html_e('Danh sách email nhận:', 'spl-cloud-backup'); ?></label>
                            <div class="spl-field-input">
                                <textarea name="spl_cloud_backup_settings[email_recipients]" rows="3" class="regular-text" placeholder="admin@domain.com"><?php echo esc_textarea($settings['email_recipients'] ?? get_option('admin_email')); ?></textarea>
                                <p class="description"><?php esc_html_e('Nhập các địa chỉ email nhận báo cáo (cách nhau bởi dấu phẩy hoặc xuống dòng).', 'spl-cloud-backup'); ?></p>
                            </div>
                        </div>

                        <div class="spl-field-row">
                            <label class="spl-field-label"><?php esc_html_e('Cơ chế đính kèm tệp tin:', 'spl-cloud-backup'); ?></label>
                            <div class="spl-field-input">
                                <label>
                                    <input type="checkbox" name="spl_cloud_backup_settings[email_attach_files]" value="1" <?php checked(!empty($settings['email_attach_files'])); ?>>
                                    <strong><?php esc_html_e('Đính kèm tệp tin sao lưu trực tiếp vào mail nếu dung lượng ≤ 20MB', 'spl-cloud-backup'); ?></strong>
                                </label>
                                <p class="description"><?php esc_html_e('Rất tiện lợi cho việc sao lưu Database (.sql.gz dung lượng nhỏ). Với các tệp tin > 20MB (như Source zip), plugin sẽ tự động loại trừ tệp đính kèm để tránh bị mail server từ chối và nhúng nút tải về qua Google Drive.', 'spl-cloud-backup'); ?></p>
                            </div>
                        </div>

                        <div class="spl-field-row">
                            <label class="spl-field-label"></label>
                            <div class="spl-field-input">
                                <button type="button" id="btn-test-email" class="button button-secondary">
                                    <span class="dashicons dashicons-email-alt"></span> <?php esc_html_e('Gửi email thử nghiệm', 'spl-cloud-backup'); ?>
                                </button>
                                <span id="email-test-result" class="spl-test-indicator"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB: SCHEDULE & CRON -->
                <div class="spl-tab-content <?php echo $activeTab === 'schedule' ? 'is-active' : ''; ?>">
                    <div class="spl-card">
                        <h3><?php esc_html_e('Lập lịch tự động & Tích hợp Server Crontab (aaPanel)', 'spl-cloud-backup'); ?></h3>

                        <div class="spl-field-row">
                            <label class="spl-field-label"><?php esc_html_e('Tần suất chạy WP-Cron:', 'spl-cloud-backup'); ?></label>
                            <div class="spl-field-input">
                                <select name="spl_cloud_backup_settings[schedule_frequency]">
                                    <option value="disabled" <?php selected($settings['schedule_frequency'] ?? 'daily', 'disabled'); ?>><?php esc_html_e('Tắt (Chỉ chạy thủ công hoặc qua Crontab Server)', 'spl-cloud-backup'); ?></option>
                                    <option value="daily" <?php selected($settings['schedule_frequency'] ?? 'daily', 'daily'); ?>><?php esc_html_e('Hàng ngày (Daily - Khuyến nghị)', 'spl-cloud-backup'); ?></option>
                                    <option value="twicedaily" <?php selected($settings['schedule_frequency'] ?? 'daily', 'twicedaily'); ?>><?php esc_html_e('Hai lần một ngày (Twice Daily)', 'spl-cloud-backup'); ?></option>
                                    <option value="weekly" <?php selected($settings['schedule_frequency'] ?? 'daily', 'weekly'); ?>><?php esc_html_e('Hàng tuần (Weekly)', 'spl-cloud-backup'); ?></option>
                                    <option value="monthly" <?php selected($settings['schedule_frequency'] ?? 'daily', 'monthly'); ?>><?php esc_html_e('Hàng tháng (Monthly)', 'spl-cloud-backup'); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="spl-field-row">
                            <label class="spl-field-label"><?php esc_html_e('Thời điểm chạy trong ngày:', 'spl-cloud-backup'); ?></label>
                            <div class="spl-field-input">
                                <input type="time" name="spl_cloud_backup_settings[schedule_time]" value="<?php echo esc_attr($settings['schedule_time'] ?? '02:00'); ?>">
                                <span class="description"><?php esc_html_e('(Nên chọn thời điểm ít truy cập từ 01:00 đến 04:00 sáng)', 'spl-cloud-backup'); ?></span>
                            </div>
                        </div>

                        <div class="spl-field-row">
                            <label class="spl-field-label"><?php esc_html_e('Server Cron Webhook (aaPanel):', 'spl-cloud-backup'); ?></label>
                            <div class="spl-field-input">
                                <div class="spl-copy-input-group">
                                    <input type="text" id="spl-webhook-url" class="large-text code" readonly value="<?php echo esc_attr($webhookUrl); ?>">
                                    <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('spl-webhook-url').value); alert('Đã sao chép link Webhook!');">
                                        <span class="dashicons dashicons-admin-page"></span> <?php esc_html_e('Sao chép', 'spl-cloud-backup'); ?>
                                    </button>
                                </div>
                                <p class="description">
                                    <?php esc_html_e('Lệnh cấu hình trong aaPanel Cron (Shell Script hoặc URL):', 'spl-cloud-backup'); ?><br>
                                    <code>curl -s "<?php echo esc_url($webhookUrl); ?>" > /dev/null 2>&1</code>
                                </p>
                            </div>
                        </div>

                        <div class="spl-field-row">
                            <label class="spl-field-label"><?php esc_html_e('Mã bảo mật Token Webhook:', 'spl-cloud-backup'); ?></label>
                            <div class="spl-field-input">
                                <input type="text" name="spl_cloud_backup_settings[webhook_secret]" class="regular-text code" value="<?php echo esc_attr($settings['webhook_secret'] ?? ''); ?>">
                                <button type="button" class="button button-secondary" onclick="document.querySelector('[name=\'spl_cloud_backup_settings[webhook_secret]\']').value = Array.from(crypto.getRandomValues(new Uint8Array(16))).map(b => b.toString(16).padStart(2,'0')).join('');">
                                    <?php esc_html_e('Tạo mã ngẫu nhiên mới', 'spl-cloud-backup'); ?>
                                </button>
                            </div>
                        </div>

                        <hr class="spl-divider">

                        <h3><?php esc_html_e('Chính sách lưu giữ & Tự động dọn dẹp (Retention)', 'spl-cloud-backup'); ?></h3>

                        <div class="spl-field-row">
                            <label class="spl-field-label"><?php esc_html_e('Số bản lưu giữ cục bộ (Local):', 'spl-cloud-backup'); ?></label>
                            <div class="spl-field-input">
                                <input type="number" min="0" max="100" name="spl_cloud_backup_settings[retention_local_count]" value="<?php echo esc_attr($settings['retention_local_count'] ?? 5); ?>" style="width: 80px;">
                                <span class="description"><?php esc_html_e('bản gần nhất (Các bản cũ hơn sẽ tự động bị xóa khỏi ổ cứng VPS).', 'spl-cloud-backup'); ?></span>
                            </div>
                        </div>

                        <div class="spl-field-row">
                            <label class="spl-field-label"><?php esc_html_e('Số bản lưu trên Google Drive:', 'spl-cloud-backup'); ?></label>
                            <div class="spl-field-input">
                                <input type="number" min="1" max="365" name="spl_cloud_backup_settings[retention_drive_count]" value="<?php echo esc_attr($settings['retention_drive_count'] ?? 14); ?>" style="width: 80px;">
                                <span class="description"><?php esc_html_e('bản gần nhất (Khuyến nghị 14 hoặc 30 bản).', 'spl-cloud-backup'); ?></span>
                            </div>
                        </div>

                        <div class="spl-field-row">
                            <label class="spl-field-label"><?php esc_html_e('Tối ưu dung lượng đĩa VPS:', 'spl-cloud-backup'); ?></label>
                            <div class="spl-field-input">
                                <label>
                                    <input type="checkbox" name="spl_cloud_backup_settings[delete_local_after_upload]" value="1" <?php checked(!empty($settings['delete_local_after_upload'])); ?>>
                                    <strong><?php esc_html_e('Tự động xóa tệp sao lưu cục bộ sau khi tải lên Google Drive thành công', 'spl-cloud-backup'); ?></strong>
                                </label>
                                <p class="description"><?php esc_html_e('Tính năng tuyệt vời cho VPS có dung lượng đĩa hạn chế. Không lưu trữ tệp nặng trên server của bạn.', 'spl-cloud-backup'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB: HISTORY & FILES -->
                <div class="spl-tab-content <?php echo $activeTab === 'history' ? 'is-active' : ''; ?>">
                    <div class="spl-card">
                        <h3><?php esc_html_e('Danh sách tệp tin sao lưu cục bộ trong Vault', 'spl-cloud-backup'); ?></h3>

                        <?php if (empty($localBackups)): ?>
                            <p class="description"><?php esc_html_e('Hiện chưa có tệp tin sao lưu nào trong thư mục cục bộ.', 'spl-cloud-backup'); ?></p>
                        <?php else: ?>
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Tên tệp tin', 'spl-cloud-backup'); ?></th>
                                        <th><?php esc_html_e('Loại', 'spl-cloud-backup'); ?></th>
                                        <th><?php esc_html_e('Kích thước', 'spl-cloud-backup'); ?></th>
                                        <th><?php esc_html_e('Ngày tạo', 'spl-cloud-backup'); ?></th>
                                        <th style="width: 150px;"><?php esc_html_e('Thao tác', 'spl-cloud-backup'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($localBackups as $item): ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($item['filename']); ?></strong></td>
                                            <td><span class="spl-badge badge-info"><?php echo esc_html($item['type']); ?></span></td>
                                            <td><?php echo esc_html($item['size_human']); ?></td>
                                            <td><?php echo esc_html($item['date_human']); ?></td>
                                            <td>
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=spl_backup_download&file=' . urlencode($item['filename'])), 'spl_backup_download')); ?>" class="button button-small">
                                                    <span class="dashicons dashicons-download"></span> <?php esc_html_e('Tải về', 'spl-cloud-backup'); ?>
                                                </a>
                                                <button type="button" class="button button-small button-link-delete btn-delete-backup" data-filename="<?php echo esc_attr($item['filename']); ?>">
                                                    <span class="dashicons dashicons-trash"></span>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <hr class="spl-divider" style="margin-top: 30px;">

                        <h3><?php esc_html_e('Nhật ký các phiên sao lưu gần đây', 'spl-cloud-backup'); ?></h3>
                        <?php if (empty($history)): ?>
                            <p class="description"><?php esc_html_e('Chưa có lịch sử sao lưu được ghi nhận.', 'spl-cloud-backup'); ?></p>
                        <?php else: ?>
                            <div class="spl-history-list">
                                <?php foreach ($history as $h): ?>
                                    <div class="spl-history-item">
                                        <div class="spl-history-meta">
                                            <span class="spl-badge <?php echo !empty($h['success']) ? 'badge-success' : 'badge-error'; ?>">
                                                <?php echo !empty($h['success']) ? esc_html__('Thành công', 'spl-cloud-backup') : esc_html__('Thất bại', 'spl-cloud-backup'); ?>
                                            </span>
                                            <strong><?php echo esc_html($h['date'] ?? ''); ?></strong> &bull;
                                            <span><?php echo esc_html($h['trigger'] ?? 'Thủ công'); ?></span> &bull;
                                            <span><?php echo esc_html((string)($h['duration'] ?? '0')); ?>s</span>
                                        </div>
                                        <?php if (!empty($h['files'])): ?>
                                            <ul class="spl-history-files">
                                                <?php foreach ($h['files'] as $f): ?>
                                                    <li>
                                                        <code><?php echo esc_html($f['name']); ?></code> (<?php echo esc_html($f['size_fmt'] ?? size_format($f['size'] ?? 0, 2)); ?>)
                                                        <?php if (!empty($f['drive_link'])): ?>
                                                            &bull; <a href="<?php echo esc_url($f['drive_link']); ?>" target="_blank" style="color: #2563eb;"><?php esc_html_e('Xem Drive', 'spl-cloud-backup'); ?></a>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                        <?php if (!empty($h['logs']) && is_array($h['logs'])): ?>
                                            <details style="margin-top: 8px;">
                                                <summary style="cursor: pointer; font-size: 12px; color: #475569; font-weight: 500;">
                                                    <?php esc_html_e('Xem nhật ký chi tiết (Execution Logs)', 'spl-cloud-backup'); ?>
                                                </summary>
                                                <pre style="margin-top: 6px; padding: 10px 12px; background: #0f172a; color: #cbd5e1; border-radius: 6px; font-size: 12px; line-height: 1.5; max-height: 220px; overflow-y: auto; white-space: pre-wrap;"><?php echo esc_html(implode("\n", $h['logs'])); ?></pre>
                                            </details>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="spl-form-actions">
                    <button type="submit" name="submit" id="submit" class="button button-primary button-large">
                        <span class="dashicons dashicons-saved"></span> <?php esc_html_e('Lưu cài đặt', 'spl-cloud-backup'); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Default settings dictionary.
     */
    public static function getDefaultSettings(): array
    {
        return [
            'backup_type'               => 'db_only',
            'custom_excludes'           => '',
            'enable_google_drive'       => 0,
            'drive_auth_type'           => 'service_account',
            'service_account_json'      => '',
            'oauth_client_id'           => '',
            'oauth_client_secret'       => '',
            'oauth_refresh_token'       => '',
            'drive_folder_name'         => 'SPL-Backups-' . sanitize_title(get_bloginfo('name')),
            'enable_email_report'       => 1,
            'email_recipients'          => get_option('admin_email'),
            'email_attach_files'        => 1,
            'schedule_frequency'        => 'daily',
            'schedule_time'             => '02:00',
            'webhook_secret'            => wp_generate_password(24, false, false),
            'retention_local_count'     => 5,
            'retention_drive_count'     => 14,
            'delete_local_after_upload' => 0,
        ];
    }
}
