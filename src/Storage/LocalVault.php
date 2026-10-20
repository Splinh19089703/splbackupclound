<?php

namespace SPLCloudBackup\Storage;

defined('ABSPATH') || exit;

class LocalVault
{
    private static ?string $vaultDir = null;

    /**
     * Get the absolute path to the local backup vault directory.
     */
    public static function getVaultPath(): string
    {
        if (self::$vaultDir !== null) {
            return self::$vaultDir;
        }

        $uploadDir = wp_upload_dir();
        $baseDir = trailingslashit($uploadDir['basedir']) . 'spl-backups/';

        self::$vaultDir = $baseDir;
        self::ensureVaultExists();

        return self::$vaultDir;
    }

    /**
     * Ensure the backup directory exists and is heavily locked down against public access.
     */
    public static function ensureVaultExists(): bool
    {
        $uploadDir = wp_upload_dir();
        $dir = trailingslashit($uploadDir['basedir']) . 'spl-backups/';

        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        // 1. Apache / LiteSpeed protection (.htaccess)
        $htaccess = $dir . '.htaccess';
        if (!file_exists($htaccess)) {
            $rules = "# SPL Cloud Backup - Deny all direct web access\n"
                   . "<IfModule !mod_authz_core.c>\n"
                   . "    Order deny,allow\n"
                   . "    Deny from all\n"
                   . "</IfModule>\n"
                   . "<IfModule mod_authz_core.c>\n"
                   . "    Require all denied\n"
                   . "</IfModule>\n";
            file_put_contents($htaccess, $rules);
        }

        // 2. Index.php silence guard
        $indexPhp = $dir . 'index.php';
        if (!file_exists($indexPhp)) {
            file_put_contents($indexPhp, "<?php\n// Silence is golden.\nexit;\n");
        }

        // 3. IIS web.config guard
        $webConfig = $dir . 'web.config';
        if (!file_exists($webConfig)) {
            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                 . "<configuration>\n"
                 . "  <system.webServer>\n"
                 . "    <authorization>\n"
                 . "      <deny users=\"*\" />\n"
                 . "    </authorization>\n"
                 . "  </system.webServer>\n"
                 . "</configuration>";
            file_put_contents($webConfig, $xml);
        }

        return is_writable($dir);
    }

    /**
     * Generate standard backup filename.
     *
     * @param string $type 'db', 'files', 'full'
     * @param string $extension 'sql.gz', 'zip'
     */
    public static function generateFilename(string $type, string $extension): string
    {
        $siteName = sanitize_title(get_bloginfo('name')) ?: 'site';
        $timestamp = gmdate('Y-m-d-His');
        $random = wp_generate_password(6, false, false);
        return sprintf('%s-%s-%s-%s.%s', $siteName, $type, $timestamp, $random, ltrim($extension, '.'));
    }

    /**
     * Safely resolve path to a file inside the vault (protects against path traversal).
     */
    public static function getFilePath(string $filename): ?string
    {
        $cleanName = basename($filename);
        $fullPath = self::getVaultPath() . $cleanName;

        if (file_exists($fullPath) && is_file($fullPath)) {
            return $fullPath;
        }

        return null;
    }

    /**
     * Delete a backup file safely.
     */
    public static function deleteFile(string $filename): bool
    {
        $path = self::getFilePath($filename);
        if ($path && file_exists($path)) {
            return @unlink($path);
        }
        return false;
    }

    /**
     * List all local backup files sorted by modification time (newest first).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listBackups(): array
    {
        $dir = self::getVaultPath();
        if (!is_dir($dir)) {
            return [];
        }

        $files = scandir($dir);
        if ($files === false) {
            return [];
        }

        $results = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === 'index.php' || $file === '.htaccess' || $file === 'web.config') {
                continue;
            }

            $path = $dir . $file;
            if (!is_file($path)) {
                continue;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['gz', 'zip', 'sql'], true)) {
                continue;
            }

            $size = (int) filesize($path);
            $mtime = (int) filemtime($path);

            // Determine type
            $type = 'unknown';
            if (str_contains($file, '-db-')) {
                $type = 'Database';
            } elseif (str_contains($file, '-files-')) {
                $type = 'Source/Files';
            } elseif (str_contains($file, '-full-')) {
                $type = 'Full Website';
            }

            $results[] = [
                'filename'    => $file,
                'path'        => $path,
                'size'        => $size,
                'size_human'  => size_format($size, 2),
                'mtime'       => $mtime,
                'date_human'  => wp_date('Y-m-d H:i:s', $mtime),
                'type'        => $type,
            ];
        }

        usort($results, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

        return $results;
    }

    /**
     * Clean old local backups exceeding the maximum allowed count.
     */
    public static function purgeOldBackups(int $keepCount): int
    {
        if ($keepCount <= 0) {
            $keepCount = 0;
        }

        $backups = self::listBackups();
        if (count($backups) <= $keepCount) {
            return 0;
        }

        $toDelete = array_slice($backups, $keepCount);
        $deleted = 0;
        foreach ($toDelete as $item) {
            if (self::deleteFile($item['filename'])) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
