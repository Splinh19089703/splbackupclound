# ☁️ SPL Cloud Backup (WordPress Plugin)

> **Giải pháp sao lưu tự động và thủ công Cơ sở dữ liệu (MySQL) & Mã nguồn website lên Google Drive và gửi thông báo qua Email cho hệ sinh thái Bluera — DailyXeDien.**

---

## 🌟 Tính Năng Nổi Bật (Key Features)

1. **Google Drive API v3 (Zero-Bloat)**:
   - Tự sinh JWT Token mã hóa RSA SHA-256 qua OpenSSL core của PHP.
   - Hoàn toàn **không cần cài đặt Google Client SDK (15MB)** với hàng trăm tệp tin nặng nề.
   - Hỗ trợ cả **Service Account JSON** (khuyên dùng cho server) lẫn **OAuth 2.0 (Refresh Token)**.
   - Tích hợp giao thức **Resumable Upload (5MB chunking)** cho phép tải lên an toàn các tệp tin lớn (50MB - 1GB+) mà không lo bị timeout.

2. **Bộ máy sao lưu CSDL đa tầng (Dual-Engine Dumper)**:
   - **Tầng 1 (CLI)**: Ưu tiên gọi trực tiếp `mysqldump` kết hợp nén `gzip` siêu tốc (tốc độ vài giây, 0% tải RAM PHP).
   - **Tầng 2 (PHP Streaming Fallback)**: Đọc từng bảng và nén trực tiếp vào `gzwrite()` theo batch 1.000 records để không bao giờ tràn bộ nhớ (OOM).

3. **Đóng gói mã nguồn thông minh (Smart Archiver)**:
   - Tùy chọn phạm vi linh hoạt: *Chỉ CSDL (Database Only)*, *CSDL + wp-content* hoặc *Toàn bộ website (Full Site)*.
   - Bộ lọc loại trừ tự động: `.git`, `node_modules`, `cache`, `litespeed`, các file nén cũ và thư mục vault `spl-backups`.

4. **Điều phối Email thông minh (Smart Email Dispatcher)**:
   - Tự động kiểm tra dung lượng:
     - Tệp $\le$ 20MB (như CSDL `.sql.gz`): Đính kèm trực tiếp vào email.
     - Tệp > 20MB (như Source `.zip`): Tự động loại trừ đính kèm để tránh bị mail server từ chối, thay vào đó nhúng nút tải về trên Google Drive.
   - Giao diện email HTML chuẩn thương hiệu hiện đại.

5. **Lập lịch linh hoạt & Tích hợp Server Crontab (aaPanel)**:
   - Hỗ trợ lịch chạy **WP-Cron** tự động (Daily, Twice Daily, Weekly, Monthly).
   - Cung cấp **Server Cron Webhook** kèm mã token bảo mật:
     ```bash
     curl -s "https://yourdomain.com/?spl_backup_cron=run&token=YOUR_SECRET_TOKEN" > /dev/null 2>&1
     ```

6. **Chính sách lưu giữ tự động (Retention Policy)**:
   - Tự động dọn dẹp các bản backup cũ vượt quá số lượng cho phép trên cả ổ cứng local và Google Drive.
   - Tùy chọn **xóa file cục bộ ngay sau khi tải lên Drive** giúp giải phóng 100% dung lượng đĩa VPS.

7. **Giao diện Dashboard Admin & WP-CLI**:
   - Nút **"Sao lưu ngay"** với thanh tiến trình AJAX và terminal log hiển thị thời gian thực.
   - Bộ lệnh WP-CLI hoàn chỉnh:
     ```bash
     wp spl-backup run
     wp spl-backup list
     wp spl-backup clean
     wp spl-backup test-drive
     ```

---

## 📁 Cấu Trúc Thư Mục Plugin

```
spl-cloud-backup/
├── spl-cloud-backup.php       # Entry file, autoloader, lifecycle hooks
├── assets/
│   ├── admin.css              # Stylesheet Dashboard
│   └── admin.js               # Logic AJAX & Interactive UI
├── src/
│   ├── Plugin.php             # Bộ điều phối trung tâm
│   ├── Admin/
│   │   └── SettingsPage.php   # Giao diện cài đặt 5 tab
│   ├── Archiver/
│   │   └── FileArchiver.php   # Đóng gói zip tệp tin
│   ├── CLI/
│   │   └── BackupCommand.php  # WP-CLI suite
│   ├── Drive/
│   │   └── GoogleDriveClient.php # API client Google Drive v3
│   ├── Dumper/
│   │   └── DatabaseDumper.php # Bộ máy xuất CSDL đa tầng
│   ├── Notification/
│   │   └── EmailNotifier.php  # Gửi báo cáo email HTML
│   ├── Scheduler/
│   │   └── CronRunner.php     # WP-Cron & Webhook Server Cron
│   └── Storage/
│       ├── LocalVault.php     # Kho lưu trữ cục bộ bảo mật (.htaccess)
│       └── RetentionManager.php # Quy chế dọn dẹp tự động
├── .gitignore
└── README.md
```

---

## 🛠️ Cài Đặt & Sử Dụng

1. Tải thư mục `spl-cloud-backup` vào `wp-content/plugins/`.
2. Vào **WordPress Admin** $\rightarrow$ **Plugins** $\rightarrow$ Kích hoạt **SPL Cloud Backup**.
3. Truy cập menu **SPL Backup** trên thanh bên trái để cấu hình Google Drive, Email và Lịch trình.

---

## 📄 Bản Quyền & Phát Triển
Phát triển bởi **SPL Team / Bluera Việt Nhật** dành cho hệ thống website thương mại điện tử WordPress.
