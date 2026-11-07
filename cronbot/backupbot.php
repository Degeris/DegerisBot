<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/function.php';
require_once dirname(__DIR__) . '/botapi.php';

function mirzaBackupDump(PDO $pdo, string $target): bool
{
    $fp = @fopen($target, 'wb');
    if (!$fp) return false;
    try {
        fwrite($fp, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as $row) {
            $nameRaw = (string)$row[0];
            $name = '`' . str_replace('`', '``', $nameRaw) . '`';
            $type = isset($row[1]) ? strtoupper((string)$row[1]) : 'BASE TABLE';
            $createRow = $pdo->query('SHOW CREATE ' . ($type === 'VIEW' ? 'VIEW ' : 'TABLE ') . $name)->fetch(PDO::FETCH_NUM);
            if (!$createRow || !isset($createRow[1])) continue;
            fwrite($fp, 'DROP ' . ($type === 'VIEW' ? 'VIEW' : 'TABLE') . ' IF EXISTS ' . $name . ";\n");
            $createSql = preg_replace('/utf8mb4_0900_[a-z0-9_]+/i', 'utf8mb4_unicode_ci', (string)$createRow[1]) ?? (string)$createRow[1];
            fwrite($fp, $createSql . ";\n\n");
            if ($type === 'VIEW') continue;

            $stmt = $pdo->query('SELECT * FROM ' . $name);
            $first = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($first === false) continue;
            $columns = array_map(static fn($c) => '`' . str_replace('`', '``', (string)$c) . '`', array_keys($first));
            $columnSql = implode(',', $columns);
            $rows = [$first];
            while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $rows[] = $r;
                if (count($rows) >= 250) {
                    $values = [];
                    foreach ($rows as $item) {
                        $cells = [];
                        foreach ($item as $cell) $cells[] = $cell === null ? 'NULL' : $pdo->quote((string)$cell);
                        $values[] = '(' . implode(',', $cells) . ')';
                    }
                    fwrite($fp, 'INSERT INTO ' . $name . ' (' . $columnSql . ") VALUES\n" . implode(",\n", $values) . ";\n");
                    $rows = [];
                }
            }
            if ($rows) {
                $values = [];
                foreach ($rows as $item) {
                    $cells = [];
                    foreach ($item as $cell) $cells[] = $cell === null ? 'NULL' : $pdo->quote((string)$cell);
                    $values[] = '(' . implode(',', $cells) . ')';
                }
                fwrite($fp, 'INSERT INTO ' . $name . ' (' . $columnSql . ") VALUES\n" . implode(",\n", $values) . ";\n");
            }
            fwrite($fp, "\n");
        }
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fp);
        return is_file($target) && filesize($target) > 0;
    } catch (Throwable $e) {
        fclose($fp); @unlink($target); error_log('[backup] ' . $e->getMessage()); return false;
    }
}

function mirzaRunBackup(): bool
{
    global $pdo;
    try {
        if (!($pdo instanceof PDO)) return false;
        $setting = select('setting', '*');
        $channel = trim((string)($setting['Channel_Report'] ?? ''));
        if (isTelegramChatIdEmpty($channel)) return false;

        $topicRow = select('topicid', 'idreport', 'report', 'backupfile', 'select');
        $topic = (int)($topicRow['idreport'] ?? 0);

        // Host-friendly backup: pure PDO dump, suitable for shared hosting.
        // Always build the temporary SQL file in the system temp directory so
        // cPanel directory permissions cannot prevent the backup from being created.
        $stamp = date('Y-m-d_H-i-s');
        $tmpDir = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $sql = $tmpDir . DIRECTORY_SEPARATOR . 'mirza_backup_' . $stamp . '_' . bin2hex(random_bytes(3)) . '.sql';
        if (!mirzaBackupDump($pdo, $sql)) {
            $errorMessage = '❌ ساخت فایل SQL بکاپ ناموفق بود.';
            $payload = ['chat_id' => $channel, 'text' => $errorMessage];
            if ($topic > 0) $payload['message_thread_id'] = $topic;
            telegram('sendmessage', $payload);
            return false;
        }

        $documentPayload = [
            'chat_id' => $channel,
            'document' => new CURLFile($sql, 'application/sql', basename($sql)),
            'caption' => '🗄️ بکاپ خام SQL ربات Degeris Pro 0.5.2\n⏱️ زمان‌بندی داخلی: هر 5 ساعت'
        ];
        if ($topic > 0) $documentPayload['message_thread_id'] = $topic;

        $result = telegram('sendDocument', $documentPayload);
        $ok = is_array($result) && !empty($result['ok']);

        // Keep the internal fallback scheduler synchronized with successful Cron
        // executions, so Cron + internal fallback cannot immediately duplicate a backup.
        if ($ok) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `mirza_internal_runtime` (
                    `job` varchar(100) NOT NULL,
                    `last_run` bigint(20) NOT NULL DEFAULT 0,
                    PRIMARY KEY (`job`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $stmt = $pdo->prepare("INSERT INTO `mirza_internal_runtime` (`job`,`last_run`) VALUES ('backup', :ts)
                    ON DUPLICATE KEY UPDATE `last_run` = VALUES(`last_run`)");
                $stmt->execute([':ts' => time()]);
            } catch (Throwable $stateError) {
                error_log('[backup-state] ' . $stateError->getMessage());
            }
        }

        @unlink($sql);
        return $ok;
    } catch (Throwable $e) {
        error_log('[backup] ' . $e->getMessage());
        return false;
    }
}

if (!defined('MIRZA_INTERNAL_BACKUP')) {
    mirzaRunBackup();
}
