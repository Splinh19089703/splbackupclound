<?php

namespace SPLCloudBackup\Archiver;

use SPLCloudBackup\Storage\LocalVault;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

defined('ABSPATH') || exit;

class FileArchiver
{
    /**
     * Standard directory/path exclusions.
     *
     * @return array<int, string>
     */
    public static function getDefaultExcludes(): array
    {
        return [
            '.git',
            '.svn',
            '.hg',
            '.github',
            '.agent',
            '.agents',
            '.vscode',
            '.antigravitycli',
            'node_modules',
            'cache',
            'et-cache',
            'w3tc-cache',
            'litespeed',
            'spl-backups', // Never backup the backups folder!
            'hd-backups',
            'upgrade-temp-backup',
            '_sftp_upload',
            'scratch',
        ];
    }

    /**
     * Locate system `zip` binary if `exec()` is enabled.
     */
    public static function findZipBinary(): ?string
    {
        if (!function_exists('exec')) {
            return null;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) {
            return null;
        }

        $candidates = ['zip', '/usr/bin/zip', '/usr/local/bin/zip', '/bin/zip'];
        foreach ($candidates as $bin) {
            $out = [];
            $code = 1;
            @exec(escapeshellcmd($bin) . ' -v 2>&1', $out, $code);
            if ($code === 0) {
                return $bin;
            }
        }

        return null;
    }

    /**
     * Archive website files to .zip with smart exclusions.
     * Uses Tier 1: System `zip` CLI (fast, 0 PHP RAM, immune to ulimit -n),
     * with automatic fallback to Tier 2: PHP ZipArchive.
     *
     * @param string $scope 'wp_content' (recommended) or 'full'
     * @param array<int, string> $customExcludes Additional folder or file names to skip
     * @param callable|null $onProgress Callback function(string $message, float $percent)
     * @return array<string, mixed>
     */
    public static function archive(string $scope = 'wp_content', array $customExcludes = [], ?callable $onProgress = null): array
    {
        $startTime = microtime(true);

        $filename = LocalVault::generateFilename($scope === 'full' ? 'full' : 'files', 'zip');
        $outputPath = LocalVault::getVaultPath() . $filename;

        $targetDir = ($scope === 'full') ? untrailingslashit(ABSPATH) : untrailingslashit(WP_CONTENT_DIR);
        if ($scope === 'full') {
            $parentDir = dirname($targetDir);
            if (basename($targetDir) === 'src' && @is_file($parentDir . '/wp-config.php') && @is_readable($parentDir)) {
                $targetDir = $parentDir;
            }
        }

        if (!is_dir($targetDir) || !is_readable($targetDir)) {
            return [
                'success' => false,
                'message' => sprintf(__('Thư mục sao lưu không thể đọc được: %s', 'spl-cloud-backup'), $targetDir),
            ];
        }

        $excludePatterns = array_values(array_unique(array_filter(array_merge(self::getDefaultExcludes(), $customExcludes))));

        // Tier 1: Try System `zip` CLI for high-speed native archiving
        $zipBin = self::findZipBinary();
        if ($zipBin !== null) {
            if ($onProgress) {
                $onProgress(__('Bắt đầu đóng gói mã nguồn siêu tốc bằng lệnh zip hệ thống...', 'spl-cloud-backup'), 10);
            }

            $cliRes = self::archiveWithCli($zipBin, $targetDir, $outputPath, $excludePatterns);
            if ($cliRes['success']) {
                $duration = round(microtime(true) - $startTime, 2);
                $fileSize = (int) filesize($outputPath);

                if ($onProgress) {
                    $onProgress(sprintf(__('Đóng gói hoàn tất (CLI): %s (%s)', 'spl-cloud-backup'), $filename, size_format($fileSize, 2)), 100);
                }

                return [
                    'success'       => true,
                    'engine'        => 'cli_zip',
                    'file_path'     => $outputPath,
                    'file_name'     => $filename,
                    'file_size'     => $fileSize,
                    'file_size_fmt' => size_format($fileSize, 2),
                    'files_count'   => $cliRes['files_count'] ?? 0,
                    'duration'      => $duration,
                    'message'       => sprintf(__('Đã đóng gói mã nguồn thành công bằng System Zip (%s trong %ss)', 'spl-cloud-backup'), size_format($fileSize, 2), $duration),
                ];
            }
        }

        // Tier 2: PHP ZipArchive Fallback
        if (!class_exists('ZipArchive')) {
            return [
                'success' => false,
                'message' => __('Máy chủ chưa cài đặt PHP Zip extension (ZipArchive) và không có lệnh zip hệ thống.', 'spl-cloud-backup'),
            ];
        }

        $zip = new ZipArchive();
        $status = $zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($status !== true) {
            return [
                'success' => false,
                'message' => sprintf(__('Không thể tạo tệp zip (Mã lỗi: %s)', 'spl-cloud-backup'), (string) $status),
            ];
        }

        if ($onProgress) {
            $onProgress(__('Bắt đầu quét danh sách tệp tin (PHP ZipArchive)...', 'spl-cloud-backup'), 5);
        }

        $dirIterator = new RecursiveDirectoryIterator($targetDir, RecursiveDirectoryIterator::SKIP_DOTS);
        // CATCH_GET_CHILD prevents unreadable subdirectories (e.g. root-owned dirs) from throwing UnexpectedValueException
        $iterator = new RecursiveIteratorIterator(
            $dirIterator,
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        $fileCount = 0;
        // Keep batch limit well below Linux default ulimit -n (1024) so ZipArchive::close() never hits EMFILE
        $batchLimit = 350;
        $batchCounter = 0;
        $normTarget = str_replace('\\', '/', $targetDir);

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            try {
                if (!$fileInfo->isFile() || !$fileInfo->isReadable()) {
                    continue;
                }

                $realPath = $fileInfo->getRealPath();
                if (!$realPath) {
                    continue;
                }

                // Normalise separators
                $normPath = str_replace('\\', '/', $realPath);

                // Check exclusion patterns
                if (self::isExcluded($normPath, $excludePatterns)) {
                    continue;
                }

                // Calculate relative path inside ZIP
                $relativePath = ltrim(substr($normPath, strlen($normTarget)), '/');
                if ($relativePath === '') {
                    continue;
                }

                // Skip huge or already compressed archive/sql dumps (> 25MB)
                $ext = strtolower($fileInfo->getExtension());
                if (in_array($ext, ['zip', 'tar', 'gz', 'rar', '7z', 'iso', 'sql'], true) && $fileInfo->getSize() > 25 * 1024 * 1024) {
                    continue;
                }

                $zip->addFile($realPath, $relativePath);
                // Store already-compressed binary media without redundant DEFLATE compression (10x-15x faster CPU & zero timeout)
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'mov', 'webm', 'mp3', 'woff', 'woff2', 'ttf', 'eot', 'pdf', 'zip', 'gz', 'rar', '7z'], true) && method_exists($zip, 'setCompressionName')) {
                    @$zip->setCompressionName($relativePath, ZipArchive::CM_STORE);
                }
                $fileCount++;
                $batchCounter++;

                // Periodically commit and re-open to stay safely under ulimit -n (1024 open files)
                if ($batchCounter >= $batchLimit) {
                    if (function_exists('set_time_limit')) {
                        @set_time_limit(300);
                    }

                    $zip->close();
                    $zip = new ZipArchive();
                    if ($zip->open($outputPath) !== true) {
                        break;
                    }
                    $batchCounter = 0;

                    if ($onProgress) {
                        $onProgress(sprintf(__('Đang nén tệp tin (đã gom %d tệp)...', 'spl-cloud-backup'), $fileCount), 50);
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        @$zip->close();

        $duration = round(microtime(true) - $startTime, 2);

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            if (file_exists($outputPath)) {
                @unlink($outputPath);
            }
            return [
                'success' => false,
                'message' => __('Quá trình nén hoàn thành nhưng tệp zip rỗng hoặc vượt giới hạn file mở của hệ điều hành.', 'spl-cloud-backup'),
            ];
        }

        $fileSize = (int) filesize($outputPath);

        if ($onProgress) {
            $onProgress(sprintf(__('Đóng gói hoàn tất: %s (%s, %d tệp)', 'spl-cloud-backup'), $filename, size_format($fileSize, 2), $fileCount), 100);
        }

        return [
            'success'       => true,
            'engine'        => 'php_ziparchive',
            'file_path'     => $outputPath,
            'file_name'     => $filename,
            'file_size'     => $fileSize,
            'file_size_fmt' => size_format($fileSize, 2),
            'files_count'   => $fileCount,
            'duration'      => $duration,
            'message'       => sprintf(__('Đã đóng gói thành công %d tệp tin (%s trong %ss)', 'spl-cloud-backup'), $fileCount, size_format($fileSize, 2), $duration),
        ];
    }

    /**
     * Archive using system `zip` CLI.
     *
     * @param string $zipBin
     * @param string $targetDir
     * @param string $outputPath
     * @param array<int, string> $excludePatterns
     * @return array<string, mixed>
     */
    private static function archiveWithCli(string $zipBin, string $targetDir, string $outputPath, array $excludePatterns): array
    {
        if (file_exists($outputPath)) {
            @unlink($outputPath);
        }

        $exArgs = [];
        foreach ($excludePatterns as $pat) {
            $pat = trim($pat, '/');
            if ($pat === '') {
                continue;
            }
            $exArgs[] = escapeshellarg("*/{$pat}/*");
            $exArgs[] = escapeshellarg("{$pat}/*");
            $exArgs[] = escapeshellarg("*/{$pat}");
            $exArgs[] = escapeshellarg($pat);
        }

        // Also exclude existing .zip / .sql.gz / .tar.gz archives
        $exArgs[] = escapeshellarg('*.zip');
        $exArgs[] = escapeshellarg('*.sql.gz');
        $exArgs[] = escapeshellarg('*.tar.gz');
        $exArgs[] = escapeshellarg('*.rar');

        $cmd = sprintf(
            'cd %s && %s -r -q -1 %s . -x %s 2>&1',
            escapeshellarg($targetDir),
            escapeshellcmd($zipBin),
            escapeshellarg($outputPath),
            implode(' ', $exArgs)
        );

        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);

        // Info-ZIP exit code 0 = normal, 18 = some unreadable files skipped (e.g. root-owned .user.ini)
        if (($code === 0 || $code === 18) && file_exists($outputPath) && filesize($outputPath) > 0) {
            return [
                'success'     => true,
                'files_count' => 0,
            ];
        }

        if (file_exists($outputPath)) {
            @unlink($outputPath);
        }

        return [
            'success' => false,
            'message' => implode("\n", $out),
        ];
    }

    /**
     * Test whether a normalized path matches any exclusion rule.
     *
     * @param string $path
     * @param array<int, string> $patterns
     */
    private static function isExcluded(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern, '/');
            if ($pattern === '') {
                continue;
            }

            if (str_contains($path, '/' . $pattern . '/') || str_ends_with($path, '/' . $pattern)) {
                return true;
            }
        }
        return false;
    }
}
