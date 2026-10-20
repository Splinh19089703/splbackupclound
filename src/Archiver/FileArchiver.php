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
     * Archive website files to .zip with smart exclusions.
     *
     * @param string $scope 'wp_content' (recommended) or 'full'
     * @param array<int, string> $customExcludes Additional folder or file names to skip
     * @param callable|null $onProgress Callback function(string $message, float $percent)
     * @return array<string, mixed>
     */
    public static function archive(string $scope = 'wp_content', array $customExcludes = [], ?callable $onProgress = null): array
    {
        $startTime = microtime(true);

        if (!class_exists('ZipArchive')) {
            return [
                'success' => false,
                'message' => __('Máy chủ chưa cài đặt PHP Zip extension (ZipArchive).', 'spl-cloud-backup'),
            ];
        }

        $filename = LocalVault::generateFilename($scope === 'full' ? 'full' : 'files', 'zip');
        $outputPath = LocalVault::getVaultPath() . $filename;

        $targetDir = ($scope === 'full') ? untrailingslashit(ABSPATH) : untrailingslashit(WP_CONTENT_DIR);

        if (!is_dir($targetDir) || !is_readable($targetDir)) {
            return [
                'success' => false,
                'message' => sprintf(__('Thư mục sao lưu không thể đọc được: %s', 'spl-cloud-backup'), $targetDir),
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
            $onProgress(__('Bắt đầu quét danh sách tệp tin...', 'spl-cloud-backup'), 5);
        }

        // Standard exclusions
        $defaultExcludes = [
            '.git',
            '.svn',
            '.hg',
            '.github',
            '.agent',
            '.agents',
            'node_modules',
            'cache',
            'et-cache',
            'w3tc-cache',
            'litespeed',
            'spl-backups', // Never backup the backups folder!
            'hd-backups',
        ];

        $excludePatterns = array_unique(array_merge($defaultExcludes, $customExcludes));

        $dirIterator = new RecursiveDirectoryIterator($targetDir, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::LEAVES_ONLY);

        $fileCount = 0;
        $batchLimit = 1500;
        $batchCounter = 0;

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
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
            $normTarget = str_replace('\\', '/', $targetDir);
            $relativePath = ltrim(substr($normPath, strlen($normTarget)), '/');

            // Skip huge or already compressed archive files at root/uploads
            $ext = strtolower($fileInfo->getExtension());
            if (in_array($ext, ['zip', 'tar', 'gz', 'rar', '7z', 'iso'], true) && $fileInfo->getSize() > 30 * 1024 * 1024) {
                continue;
            }

            $zip->addFile($realPath, $relativePath);
            $fileCount++;
            $batchCounter++;

            // Periodically commit and re-open to conserve memory and file handles
            if ($batchCounter >= $batchLimit) {
                if (function_exists('set_time_limit')) {
                    @set_time_limit(300);
                }

                $zip->close();
                $zip = new ZipArchive();
                $zip->open($outputPath);
                $batchCounter = 0;

                if ($onProgress) {
                    $onProgress(sprintf(__('Đang nén tệp tin (đã gom %d tệp)...', 'spl-cloud-backup'), $fileCount), 50);
                }
            }
        }

        $zip->close();

        $duration = round(microtime(true) - $startTime, 2);

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            if (file_exists($outputPath)) {
                @unlink($outputPath);
            }
            return [
                'success' => false,
                'message' => __('Quá trình nén hoàn thành nhưng tệp zip rỗng.', 'spl-cloud-backup'),
            ];
        }

        $fileSize = (int) filesize($outputPath);

        if ($onProgress) {
            $onProgress(sprintf(__('Đóng gói hoàn tất: %s (%s, %d tệp)', 'spl-cloud-backup'), $filename, size_format($fileSize, 2), $fileCount), 100);
        }

        return [
            'success'       => true,
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
