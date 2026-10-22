/**
 * SPL Cloud Backup - Admin UI Interactive Logic
 */

jQuery(document).ready(function ($) {
    'use strict';

    var backupData = window.splBackupData || window.hdBackupData || {};

    // 1. Tab Switching
    $('.spl-tabs-nav .nav-tab').on('click', function (e) {
        e.preventDefault();
        var targetTab = $(this).attr('href').split('tab=')[1] || 'general';

        $('.spl-tabs-nav .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        $('.spl-tab-content').removeClass('is-active');
        $('.spl-tab-content').eq($(this).index()).addClass('is-active');

        // Update URL query string without page reload
        if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.set('tab', targetTab);
            window.history.replaceState(null, '', url.toString());
        }
    });

    // 2. Toggle Google Drive Auth Type
    function toggleDriveAuth() {
        var authType = $('input[name="spl_cloud_backup_settings[drive_auth_type]"]:checked').val();
        if (authType === 'oauth') {
            $('#drive-auth-sa').slideUp(200);
            $('#drive-auth-oauth').slideDown(200);
        } else {
            $('#drive-auth-oauth').slideUp(200);
            $('#drive-auth-sa').slideDown(200);
        }
    }

    $('input[name="spl_cloud_backup_settings[drive_auth_type]"]').on('change', toggleDriveAuth);
    toggleDriveAuth();

    // 3. Test Google Drive Connection
    $('#btn-test-drive').on('click', function () {
        var $btn = $(this);
        var $indicator = $('#drive-test-result');

        $btn.prop('disabled', true);
        $indicator.removeClass('success error').text('Đang kết nối tới Google API...').show();

        $.ajax({
            url: backupData.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'spl_backup_test_drive',
                nonce: backupData.nonce,
                drive_auth_type: $('input[name="spl_cloud_backup_settings[drive_auth_type]"]:checked').val() || 'service_account',
                service_account_json: $('textarea[name="spl_cloud_backup_settings[service_account_json]"]').val() || '',
                oauth_client_id: $('input[name="spl_cloud_backup_settings[oauth_client_id]"]').val() || '',
                oauth_client_secret: $('input[name="spl_cloud_backup_settings[oauth_client_secret]"]').val() || '',
                oauth_refresh_token: $('input[name="spl_cloud_backup_settings[oauth_refresh_token]"]').val() || ''
            },
            success: function (res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    $indicator.addClass('success').text(res.data.message);
                } else {
                    $indicator.addClass('error').text(res.data.message || 'Kết nối thất bại.');
                }
            },
            error: function () {
                $btn.prop('disabled', false);
                $indicator.addClass('error').text('Lỗi máy chủ khi gửi yêu cầu kiểm tra.');
            }
        });
    });

    // 4. Test Email Sending
    $('#btn-test-email').on('click', function () {
        var $btn = $(this);
        var $indicator = $('#email-test-result');
        var recipientInput = $('textarea[name="spl_cloud_backup_settings[email_recipients]"]').val() || '';
        var recipient = recipientInput.split(/[\n,;]+/)[0].trim();

        if (!recipient) {
            alert('Vui lòng nhập địa chỉ email nhận trước khi kiểm tra.');
            return;
        }

        $btn.prop('disabled', true);
        $indicator.removeClass('success error').text('Đang gửi mail thử nghiệm...').show();

        $.ajax({
            url: backupData.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'spl_backup_test_mail',
                recipient: recipient,
                nonce: backupData.nonce
            },
            success: function (res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    $indicator.addClass('success').text(res.data.message);
                } else {
                    $indicator.addClass('error').text(res.data.message || 'Gửi email thất bại.');
                }
            },
            error: function () {
                $btn.prop('disabled', false);
                $indicator.addClass('error').text('Lỗi máy chủ khi gửi email.');
            }
        });
    });

    // 5. Delete Local Backup File
    $(document).on('click', '.btn-delete-backup', function () {
        var $btn = $(this);
        var filename = $btn.data('filename');

        if (!confirm(backupData.strings ? backupData.strings.confirm_delete : 'Bạn có chắc chắn muốn xóa tệp này?')) {
            return;
        }

        $btn.prop('disabled', true);

        $.ajax({
            url: backupData.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'spl_backup_delete_local',
                filename: filename,
                nonce: backupData.nonce
            },
            success: function (res) {
                if (res.success) {
                    $btn.closest('tr').fadeOut(300, function () {
                        $(this).remove();
                    });
                } else {
                    alert(res.data.message || 'Không thể xóa tệp.');
                    $btn.prop('disabled', false);
                }
            },
            error: function () {
                alert('Lỗi kết nối máy chủ khi xóa tệp.');
                $btn.prop('disabled', false);
            }
        });
    });

    // 6. Manual Backup Live Runner
    $('#btn-start-manual-backup').on('click', function () {
        var $btn = $(this);
        var $panel = $('#spl-backup-runner-panel');
        var $bar = $('#spl-progress-bar');
        var $logs = $('#spl-runner-logs');
        var $badge = $('#spl-runner-status-badge');

        $btn.prop('disabled', true).addClass('updating-message');
        $panel.slideDown(300);
        $logs.empty();

        function addLog(msg) {
            var time = new Date().toTimeString().split(' ')[0];
            $logs.append($('<p>').text('[' + time + '] ' + msg));
            $logs.scrollTop($logs[0].scrollHeight);
        }

        $badge.removeClass('badge-success badge-error').addClass('badge-info').text('Đang thực thi...');
        $bar.css('width', '25%');
        addLog('Khởi chạy tiến trình sao lưu toàn diện (SPL Cloud Backup)...');
        addLog('Đang xuất CSDL MySQL và đóng gói tệp tin hệ thống...');

        var progressTimer = setInterval(function () {
            var currentW = parseInt($bar[0].style.width) || 25;
            if (currentW < 85) {
                $bar.css('width', (currentW + 5) + '%');
            }
        }, 1500);

        $.ajax({
            url: backupData.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'spl_backup_manual_run',
                nonce: backupData.nonce
            },
            timeout: 600000,
            success: function (res) {
                clearInterval(progressTimer);
                $btn.prop('disabled', false).removeClass('updating-message');

                if (res.success) {
                    $bar.css('width', '100%');
                    $badge.removeClass('badge-info badge-error').addClass('badge-success').text('Hoàn tất thành công!');
                    
                    if (res.data && res.data.logs) {
                        res.data.logs.forEach(function (log) {
                            addLog(log);
                        });
                    }
                    addLog('🎉 ' + res.data.message);

                    setTimeout(function () {
                        if (confirm('Sao lưu hoàn tất thành công! Bạn có muốn làm mới trang để cập nhật danh sách tệp và lịch sử không?')) {
                            window.location.reload();
                        }
                    }, 1000);
                } else {
                    $bar.css('width', '100%');
                    $badge.removeClass('badge-info badge-success').addClass('badge-error').text('Gặp sự cố!');
                    if (res.data && res.data.logs) {
                        res.data.logs.forEach(function (log) {
                            addLog(log);
                        });
                    }
                    addLog('⚠️ ' + (res.data ? res.data.message : 'Lỗi không xác định'));
                }
            },
            error: function (xhr, status, error) {
                clearInterval(progressTimer);
                $btn.prop('disabled', false).removeClass('updating-message');
                $badge.removeClass('badge-info badge-success').addClass('badge-error').text('Lỗi kết nối');
                addLog('LỖI KẾT NỐI MÁY CHỦ: ' + (error || status));
            }
        });
    });
});
