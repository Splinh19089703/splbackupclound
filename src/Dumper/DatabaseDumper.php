<?php

namespace SPLCloudBackup\Dumper;

use SPLCloudBackup\Storage\LocalVault;

defined('ABSPATH') || exit;

class DatabaseDumper
{
    /**
     * Run database dump and compress to .sql.gz directly.
     *
     * @param callable|null $onProgress Optional callback function(string $message, float $percent)
     * @return array<string, mixed>
     */
    public static function dump(?callable $onProgress = null): array
    {
        $startTime = microtime(true);
        $filename = LocalVault::generateFilename('db', 'sql.gz');
        $outputPath = LocalVault::getVaultPath() . $filename;

        // Try Tier 1: System mysqldump + gzip
        if (self::canUseSystemExec()) {
            $cliResult = self::dumpWithCli($outputPath, $onProgress);
            if ($cliResult['success']) {
                $duration = round(microtime(true) - $startTime, 2);
                $fileSize = (int) filesize($outputPath);

                return [
                    'success'       => true,
                    'engine'        => 'cli',
                    'file_path'     => $outputPath,
                    'file_name'     => $filename,
                    'file_size'     => $fileSize,
                    'file_size_fmt' => size_format($fileSize, 2),
                    'duration'      => $duration,
                    'message'       => sprintf(__('Đã sao lưu CSDL thành công bằng mysqldump (%s trong %ss)', 'spl-cloud-backup'), size_format($fileSize, 2), $duration),
                ];
            }
        }

        // Tier 2: Pure PHP Streaming fallback
        $phpResult = self::dumpWithPhp($outputPath, $onProgress);
        $duration = round(microtime(true) - $startTime, 2);

        if ($phpResult['success']) {
            $fileSize = (int) filesize($outputPath);
            return [
                'success'       => true,
                'engine'        => 'php_streaming',
                'file_path'     => $outputPath,
                'file_name'     => $filename,
                'file_size'     => $fileSize,
                'file_size_fmt' => size_format($fileSize, 2),
                'tables_count'  => $phpResult['tables_count'],
                'rows_count'    => $phpResult['rows_count'],
                'duration'      => $duration,
                'message'       => sprintf(__('Đã sao lưu CSDL thành công bằng PHP Streaming (%s trong %ss, %d bảng)', 'spl-cloud-backup'), size_format($fileSize, 2), $duration, $phpResult['tables_count']),
            ];
        }

        // Cleanup failed partial file
        if (file_exists($outputPath)) {
            @unlink($outputPath);
        }

        return [
            'success'  => false,
            'message'  => $phpResult['message'] ?? __('Không thể sao lưu cơ sở dữ liệu.', 'spl-cloud-backup'),
            'duration' => $duration,
        ];
    }

    /**
     * Check if system command execution is available and safe.
     */
    private static function canUseSystemExec(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $disabled = explode(',', ini_get('disable_functions') ?: '');
        $disabled = array_map('trim', $disabled);

        if (in_array('exec', $disabled, true)) {
            return false;
        }

        return self::findMysqldumpBinary() !== null;
    }

    /**
     * Locate mysqldump binary on Linux / Windows.
     */
    private static function findMysqldumpBinary(): ?string
    {
        $candidates = [
            'mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
            '/www/server/mysql/bin/mysqldump',
            '/bin/mysqldump',
        ];

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $laragonGlobs = glob('C:/laragon/bin/mysql/*/bin/mysqldump.exe');
            if (!empty($laragonGlobs)) {
                $candidates = array_merge($laragonGlobs, $candidates);
            }
            $candidates[] = 'mysqldump.exe';
        }

        foreach ($candidates as $bin) {
            $out = [];
            $code = 1;
            @exec(escapeshellcmd($bin) . ' --version 2>&1', $out, $code);
            if ($code === 0) {
                return $bin;
            }
        }

        return null;
    }

    /**
     * Locate gzip binary for direct shell pipeline.
     */
    private static function findGzipBinary(): ?string
    {
        $candidates = ['gzip', '/bin/gzip', '/usr/bin/gzip', '/usr/local/bin/gzip'];
        foreach ($candidates as $bin) {
            $out = [];
            $code = 1;
            @exec(escapeshellcmd($bin) . ' --version 2>&1', $out, $code);
            if ($code === 0) {
                return $bin;
            }
        }
        return null;
    }

    /**
     * Dump via mysqldump binary.
     *
     * @param callable|null $onProgress
     * @return array<string, mixed>
     */
    private static function dumpWithCli(string $outputPath, ?callable $onProgress): array
    {
        global $wpdb;

        $mysqldumpBin = self::findMysqldumpBinary();
        if (!$mysqldumpBin) {
            return ['success' => false, 'message' => __('Không tìm thấy lệnh mysqldump.', 'spl-cloud-backup')];
        }

        if ($onProgress) {
            $onProgress(__('Bắt đầu sao lưu bằng lệnh mysqldump hệ thống...', 'spl-cloud-backup'), 10);
        }

        $parsedHost = $wpdb->parse_db_host(DB_HOST);
        $host = $parsedHost ? $parsedHost[0] : DB_HOST;
        $port = $parsedHost ? $parsedHost[1] : null;
        $socket = $parsedHost ? $parsedHost[2] : null;
        $isIpv6 = $parsedHost ? $parsedHost[3] : false;

        if ($isIpv6 && !str_starts_with($host, '[')) {
            $host = "[{$host}]";
        }

        $connArgs = ['--host=' . escapeshellarg($host)];
        if (!empty($socket)) {
            $connArgs[] = '--socket=' . escapeshellarg((string) $socket);
        } elseif (!empty($port)) {
            $connArgs[] = '--port=' . escapeshellarg((string) $port);
        }

        $connArgs[] = '--user=' . escapeshellarg(DB_USER);
        $connArgs[] = '--password=' . escapeshellarg(DB_PASSWORD);

        $dbName = escapeshellarg(DB_NAME);
        $uid = uniqid('', true);
        $tempErr = sys_get_temp_dir() . '/spl_dump_err_' . $uid . '.log';

        $gzipBin = self::findGzipBinary();

        // Tier 1: Direct pipe into gzip (Lightning fast: 1-3 seconds, 0 RAM, 0 disk overhead)
        if ($gzipBin) {
            $cmd = sprintf(
                '%s %s --single-transaction --quick --routines --triggers --skip-lock-tables %s 2> %s | %s -c > %s',
                escapeshellcmd($mysqldumpBin),
                implode(' ', $connArgs),
                $dbName,
                escapeshellarg($tempErr),
                escapeshellcmd($gzipBin),
                escapeshellarg($outputPath)
            );

            $out = [];
            $code = 1;
            @exec($cmd, $out, $code);

            $errOutput = file_exists($tempErr) ? trim((string) file_get_contents($tempErr)) : '';
            if (file_exists($tempErr)) {
                @unlink($tempErr);
            }

            if ($code === 0 && file_exists($outputPath) && filesize($outputPath) > 0) {
                return ['success' => true];
            }
        }

        // Tier 2: Dump to temporary .sql then compress with PHP wb4 (fast)
        $tempSql = sys_get_temp_dir() . '/spl_dump_' . $uid . '.sql';
        $cmd = sprintf(
            '%s %s --single-transaction --quick --routines --triggers --skip-lock-tables %s > %s 2> %s',
            escapeshellcmd($mysqldumpBin),
            implode(' ', $connArgs),
            $dbName,
            escapeshellarg($tempSql),
            escapeshellarg($tempErr)
        );

        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);

        $errOutput = file_exists($tempErr) ? trim((string) file_get_contents($tempErr)) : '';
        if (file_exists($tempErr)) {
            @unlink($tempErr);
        }

        if ($code !== 0 || !file_exists($tempSql) || filesize($tempSql) === 0) {
            if (file_exists($tempSql)) {
                @unlink($tempSql);
            }
            return ['success' => false, 'message' => $errOutput ?: implode("\n", $out)];
        }

        if ($onProgress) {
            $onProgress(__('Đang nén cơ sở dữ liệu với gzip...', 'spl-cloud-backup'), 60);
        }

        // Compress tempSql into $outputPath (.sql.gz) using wb4 (fast compression)
        $in = fopen($tempSql, 'rb');
        $outGz = gzopen($outputPath, 'wb4');

        if ($in && $outGz) {
            while (!feof($in)) {
                $chunk = fread($in, 2 * 1024 * 1024); // 2MB chunks
                if ($chunk !== false) {
                    gzwrite($outGz, $chunk);
                }
            }
            fclose($in);
            gzclose($outGz);
            @unlink($tempSql);

            return ['success' => true];
        }

        if ($in) {
            fclose($in);
        }
        if ($outGz) {
            gzclose($outGz);
        }
        if (file_exists($tempSql)) {
            @unlink($tempSql);
        }

        return ['success' => false, 'message' => __('Lỗi nén tệp tin CSDL sau khi dump.', 'spl-cloud-backup')];
    }

    /**
     * Pure PHP streaming dump without storing large strings in memory.
     * Optimized for speed: Level 4 compression, 2500 batch size, addslashes, and bloat log capping.
     *
     * @param callable|null $onProgress
     * @return array<string, mixed>
     */
    private static function dumpWithPhp(string $outputPath, ?callable $onProgress): array
    {
        global $wpdb;

        if ($onProgress) {
            $onProgress(__('Bắt đầu sao lưu bằng PHP Streaming Dumper...', 'spl-cloud-backup'), 5);
        }

        if (!function_exists('gzopen')) {
            return ['success' => false, 'message' => __('PHP Zlib extension chưa được kích hoạt trên server.', 'spl-cloud-backup')];
        }

        // Use wb4 (fast) instead of wb9 (ultra slow)
        $gz = gzopen($outputPath, 'wb4');
        if (!$gz) {
            return ['success' => false, 'message' => __('Không thể tạo tệp nén sao lưu CSDL tại thư mục vault.', 'spl-cloud-backup')];
        }

        // Write SQL Header
        $header = "-- SPL Cloud Backup - Pure PHP Streaming Dump\n"
                . "-- Host: " . DB_HOST . " | Database: " . DB_NAME . "\n"
                . "-- Generation Time: " . gmdate('Y-m-d H:i:s') . " GMT\n"
                . "-- WordPress Version: " . get_bloginfo('version') . "\n\n"
                . "SET FOREIGN_KEY_CHECKS = 0;\n"
                . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
                . "SET time_zone = '+00:00';\n"
                . "SET NAMES utf8mb4;\n\n";

        gzwrite($gz, $header);

        // Get list of all tables
        $tables = $wpdb->get_col("SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE'");
        if (empty($tables)) {
            gzclose($gz);
            return ['success' => false, 'message' => __('Không tìm thấy bảng nào trong cơ sở dữ liệu.', 'spl-cloud-backup')];
        }

        $totalTables = count($tables);
        $totalRows = 0;

        foreach ($tables as $index => $table) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }

            $percent = round(10 + (($index / $totalTables) * 80), 1);
            if ($onProgress) {
                $onProgress(sprintf(__('Đang xử lý bảng %s (%d/%d)...', 'spl-cloud-backup'), $table, $index + 1, $totalTables), $percent);
            }

            // Write Table Structure
            $createTable = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            if (!$createTable || !isset($createTable[1])) {
                continue;
            }

            $tableSql = "\n-- --------------------------------------------------------\n"
                      . "-- Table structure for `{$table}`\n"
                      . "-- --------------------------------------------------------\n"
                      . "DROP TABLE IF EXISTS `{$table}`;\n"
                      . $createTable[1] . ";\n\n";

            gzwrite($gz, $tableSql);

            // Count rows
            $rowCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            if ($rowCount === 0) {
                continue;
            }

            // Cap massive log tables (e.g. actionscheduler_logs) to prevent timeout
            $isLogTable = (bool) preg_match('/actionscheduler_logs$/i', $table);
            if ($isLogTable && $rowCount > 1000) {
                $rowCount = 1000;
            }

            $totalRows += $rowCount;

            // Stream rows in batches
            $batchSize = 2500;
            $offset = 0;

            gzwrite($gz, "-- Dumping data for `{$table}` ({$rowCount} rows)\n");

            while ($offset < $rowCount) {
                if (function_exists('set_time_limit')) {
                    @set_time_limit(300);
                }

                $query = $isLogTable
                    ? "SELECT * FROM `{$table}` ORDER BY 1 DESC LIMIT {$batchSize}"
                    : "SELECT * FROM `{$table}` LIMIT {$offset}, {$batchSize}";

                $rows = $wpdb->get_results($query, ARRAY_A);
                if (empty($rows)) {
                    break;
                }

                $insertHeader = "INSERT INTO `{$table}` VALUES \n";
                $valueLines = [];

                foreach ($rows as $row) {
                    $escapedValues = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $escapedValues[] = 'NULL';
                        } else {
                            $escapedValues[] = "'" . addslashes((string) $val) . "'";
                        }
                    }
                    $valueLines[] = '(' . implode(', ', $escapedValues) . ')';
                }

                $insertSql = $insertHeader . implode(",\n", $valueLines) . ";\n";
                gzwrite($gz, $insertSql);

                $offset += $batchSize;
                unset($rows, $valueLines, $insertSql);

                if ($isLogTable) {
                    break;
                }
            }

            gzwrite($gz, "\n");
        }

        // Footer
        $footer = "\nSET FOREIGN_KEY_CHECKS = 1;\n"
                . "-- Backup completed at: " . gmdate('Y-m-d H:i:s') . " GMT\n";
        gzwrite($gz, $footer);
        gzclose($gz);

        if ($onProgress) {
            $onProgress(__('Nén và hoàn tất xuất CSDL thành công!', 'spl-cloud-backup'), 95);
        }

        return [
            'success'      => true,
            'tables_count' => $totalTables,
            'rows_count'   => $totalRows,
        ];
    }
}
