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
        if (!function_exists('exec') || !function_exists('proc_open')) {
            return false;
        }

        $disabled = explode(',', ini_get('disable_functions') ?: '');
        $disabled = array_map('trim', $disabled);

        if (in_array('exec', $disabled, true) || in_array('proc_open', $disabled, true)) {
            return false;
        }

        // Check if mysqldump binary responds
        $out = [];
        $code = 1;
        @exec('mysqldump --version 2>&1', $out, $code);

        return $code === 0;
    }

    /**
     * Dump via mysqldump binary.
     *
     * @param callable|null $onProgress
     * @return array<string, mixed>
     */
    private static function dumpWithCli(string $outputPath, ?callable $onProgress): array
    {
        if ($onProgress) {
            $onProgress(__('Bắt đầu sao lưu bằng lệnh mysqldump hệ thống...', 'spl-cloud-backup'), 10);
        }

        $host = DB_HOST;
        $port = 3306;
        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);
        }

        $user = escapeshellarg(DB_USER);
        $pass = escapeshellarg(DB_PASSWORD);
        $dbName = escapeshellarg(DB_NAME);
        $hostArg = escapeshellarg($host);
        $portArg = escapeshellarg((string) $port);

        $tempSql = sys_get_temp_dir() . '/spl_dump_' . uniqid('', true) . '.sql';

        $cmd = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s --single-transaction --quick --routines --triggers --skip-lock-tables %s > %s 2>&1',
            $hostArg,
            $portArg,
            $user,
            $pass,
            $dbName,
            escapeshellarg($tempSql)
        );

        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);

        if ($code !== 0 || !file_exists($tempSql) || filesize($tempSql) === 0) {
            if (file_exists($tempSql)) {
                @unlink($tempSql);
            }
            return ['success' => false, 'message' => implode("\n", $out)];
        }

        if ($onProgress) {
            $onProgress(__('Đang nén cơ sở dữ liệu với gzip...', 'spl-cloud-backup'), 60);
        }

        // Compress tempSql into $outputPath (.sql.gz)
        $in = fopen($tempSql, 'rb');
        $outGz = gzopen($outputPath, 'wb9');

        if ($in && $outGz) {
            while (!feof($in)) {
                $chunk = fread($in, 1024 * 1024); // 1MB chunks
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

        $gz = gzopen($outputPath, 'wb9');
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

            $totalRows += $rowCount;

            // Stream rows in batches
            $batchSize = 1000;
            $offset = 0;

            gzwrite($gz, "-- Dumping data for `{$table}` ({$rowCount} rows)\n");

            while ($offset < $rowCount) {
                if (function_exists('set_time_limit')) {
                    @set_time_limit(300);
                }

                $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT {$offset}, {$batchSize}", ARRAY_A);
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
                        } elseif (is_numeric($val) && !str_starts_with((string)$val, '0')) {
                            $escapedValues[] = $val;
                        } else {
                            $escapedValues[] = "'" . esc_sql($val) . "'";
                        }
                    }
                    $valueLines[] = '(' . implode(', ', $escapedValues) . ')';
                }

                $insertSql = $insertHeader . implode(",\n", $valueLines) . ";\n";
                gzwrite($gz, $insertSql);

                $offset += $batchSize;
                unset($rows, $valueLines, $insertSql);
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
