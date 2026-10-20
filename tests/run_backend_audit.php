<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/database.php';
$root = dirname(__DIR__);
$source = DatabaseConnection::connect();
$name = 'egglogan_audit_' . bin2hex(random_bytes(6));
if (!preg_match('/^egglogan_audit_[a-f0-9]{12}$/', $name)) throw new RuntimeException('Unsafe audit database name');
$temp = sys_get_temp_dir() . '/' . $name;
mkdir($temp, 0700); mkdir($temp . '/sessions', 0700);
$server = null;
try {
    $source->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $source->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($source->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $entry) {
        $table = '`' . str_replace('`', '``', $entry[0]) . '`';
        $ddl = $source->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1];
        $ddl = preg_replace('/^CREATE TABLE `[^`]+`/', 'CREATE TABLE `' . $name . '`.' . $table, $ddl, 1);
        $source->exec($ddl);
    }
    $sourceDatabase = $source->query('SELECT DATABASE()')->fetchColumn();
    foreach ($source->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM) as $entry) {
        $viewName = '`' . str_replace('`', '``', $entry[0]) . '`';
        $ddl = $source->query('SHOW CREATE VIEW ' . $viewName)->fetch(PDO::FETCH_ASSOC)['Create View'];
        $ddl = str_replace('`' . $sourceDatabase . '`.', '`' . $name . '`.', $ddl);
        $ddl = str_replace('VIEW ' . $viewName, 'VIEW `' . $name . '`.' . $viewName, $ddl);
        $source->exec($ddl);
    }
    $source->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (['egg_sizes', 'system_settings'] as $table) $source->exec('INSERT INTO `' . $name . '`.`' . $table . '` SELECT * FROM `' . $table . '`');
    $source->exec("UPDATE `{$name}`.system_settings SET setting_value = '0' WHERE setting_key = 'auto_retrain'");
    putenv('DB_NAME=' . $name); putenv('EGGLOGAN_BACKUP_DIR=' . $temp . '/backups'); putenv('EGGLOGAN_TEST_SESSION_DIR=' . $temp . '/sessions');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); $address = stream_socket_get_name($socket, false); fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $temp . '/sessions', '-S', $address, '-t', $root], [0 => ['pipe', 'r'], 1 => ['file', $temp . '/server.log', 'a'], 2 => ['file', $temp . '/server.log', 'a']], $serverPipes, $root);
    if (!is_resource($server)) throw new RuntimeException('Audit HTTP server could not start');
    fclose($serverPipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) { $probe = @fsockopen(explode(':', $address)[0], (int)explode(':', $address)[1], $errno, $error, .1); if ($probe) { fclose($probe); $ready = true; break; } usleep(100000); }
    if (!$ready) throw new RuntimeException('Audit server did not become ready');
    $worker = proc_open([PHP_BINARY, __DIR__ . '/backend_workflows.php', 'http://' . $address], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($worker);
    echo $output; if ($errors) fwrite(STDERR, $errors); if ($status !== 0) throw new RuntimeException('Backend audit failed');
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $source->exec('SET FOREIGN_KEY_CHECKS=1');
    $source->exec('DROP DATABASE IF EXISTS `' . $name . '_restore`');
    $source->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    if (realpath(dirname($temp)) === realpath(sys_get_temp_dir()) && basename($temp) === $name) {
        foreach (['sessions', 'backups'] as $folder) { if (is_dir($temp . '/' . $folder)) { foreach (glob($temp . '/' . $folder . '/*') as $file) if (is_file($file)) unlink($file); rmdir($temp . '/' . $folder); } }
        if (is_file($temp . '/server.log')) unlink($temp . '/server.log'); rmdir($temp);
    }
}
echo "Isolated audit databases, sessions and backup fixtures removed.\n";
