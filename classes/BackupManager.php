<?php
require_once __DIR__ . '/Database.php';
class BackupManager {
    public function create(): string {
        $privateRoot = getenv('LOCALAPPDATA') ?: (getenv('HOME') ?: sys_get_temp_dir());
        $directory = getenv('EGGLOGAN_BACKUP_DIR') ?: $privateRoot . '/Egglogan/backups';
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Private backup directory is unavailable.');
        $resolved = str_replace('\\', '/', realpath($directory));
        foreach ([realpath(__DIR__ . '/..'), realpath($_SERVER['DOCUMENT_ROOT'] ?? '')] as $public) {
            if (!$public) continue;
            $public = rtrim(str_replace('\\', '/', $public), '/');
            if (strcasecmp($resolved, $public) === 0 || stripos($resolved . '/', $public . '/') === 0) throw new RuntimeException('Choose a backup directory outside the web root.');
        }
        $filename = 'egglogan_backup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sql';
        $path = $directory . '/' . $filename;
        $stream = fopen($path . '.tmp', 'xb');
        if (!$stream) throw new RuntimeException('Backup file could not be opened.');
        $pdo = Database::getInstance()->getPdo();
        try {
            $pdo->beginTransaction();
            fwrite($stream, "-- Egglogan backup\nSET FOREIGN_KEY_CHECKS=0;\n");
            foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $tableRow) {
                $table = '`' . str_replace('`', '``', $tableRow[0]) . '`';
                $schema = $pdo->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1];
                fwrite($stream, 'DROP TABLE IF EXISTS ' . $table . ";\n" . $schema . ";\n");
                $rows = $pdo->query('SELECT * FROM ' . $table);
                while ($row = $rows->fetch(PDO::FETCH_NUM)) {
                    $values = array_map(static fn($value) => $value === null ? 'NULL' : $pdo->quote((string)$value), $row);
                    if (fwrite($stream, 'INSERT INTO ' . $table . ' VALUES (' . implode(',', $values) . ");\n") === false) throw new RuntimeException('Backup write failed.');
                }
            }
            $databaseName = $pdo->query('SELECT DATABASE()')->fetchColumn();
            foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM) as $viewRow) {
                $view = '`' . str_replace('`', '``', $viewRow[0]) . '`';
                $definition = $pdo->query('SHOW CREATE VIEW ' . $view)->fetch(PDO::FETCH_ASSOC)['Create View'];
                $definition = str_replace('`' . $databaseName . '`.', '', $definition);
                $definition = preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $definition);
                fwrite($stream, 'DROP VIEW IF EXISTS ' . $view . ";\n" . $definition . ";\n");
            }
            fwrite($stream, "SET FOREIGN_KEY_CHECKS=1;\n");
            $pdo->commit(); fclose($stream);
            if (!rename($path . '.tmp', $path)) throw new RuntimeException('Backup could not be finalized.');
            return $filename;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (is_resource($stream)) fclose($stream);
            if (is_file($path . '.tmp')) unlink($path . '.tmp');
            throw $error;
        }
    }
}
