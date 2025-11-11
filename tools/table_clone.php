#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * table_clone.php
 *
 * 概要:
 *   - clone   : SRC→DST 複製（DSTをバックアップ→DELETE→SRC全件INSERT）
 *   - backup  : 指定のホスト/DB/テーブルをJSONバックアップ
 *   - restore : JSONバックアップから復元（DSTをバックアップ→DELETE→挿入）
 *
 * 使い方:
 *   php table_clone.php clone  <src_host> <db> <table> <dst_host> [--confirm] [--backup-dir=/path] [--config=/path/tools.conf] [--schema=public]
 *   php table_clone.php backup <host>     <db> <table>            [--backup-dir=/path] [--config=/path/tools.conf] [--schema=public]
 *   php table_clone.php restore <host>    <db> <table>            [--confirm] [--backup-dir=/path] [--file=/path/file.json] [--config=/path/tools.conf] [--schema=public]
 *
 * バックアップファイル名:
 *   <host>_<db>_<table>_YYYYMMDD_HHMMSS.json
 */

//============================================================
// ライブラリ読み込み
//============================================================
$AUTO_LIBS = [
    __DIR__ . '/Env.php',
    __DIR__ . '/CliUtil.php',
    __DIR__ . '/CliColor.php',
    __DIR__ . '/Config.php',
];
foreach ($AUTO_LIBS as $lib) {
    if (is_file($lib)) { require_once $lib; }
}

//============================================================
// 基本関数
//============================================================
function cinfo(string $m): void { fwrite(STDERR, "\033[36m[INFO]\033[0m  $m\n"); }
function cwarn(string $m): void { fwrite(STDERR, "\033[33m[WARN]\033[0m  $m\n"); }
function cgood(string $m): void { fwrite(STDERR, "\033[32m[OK]\033[0m    $m\n"); }
function cerr(string $m): void  { fwrite(STDERR, "\033[31m[ERR]\033[0m   $m\n"); }

function usage_exit(): void {
    $u = <<<TXT
Usage:
  php table_clone.php clone  <src_host> <db> <table> <dst_host> [--confirm] [--backup-dir=/path] [--config=/path/tools.conf] [--schema=public]
  php table_clone.php backup <host>     <db> <table>            [--backup-dir=/path] [--config=/path/tools.conf] [--schema=public]
  php table_clone.php restore <host>    <db> <table>            [--confirm] [--backup-dir=/path] [--file=/path/file.json] [--config=/path/tools.conf] [--schema=public]
TXT;
    fwrite(STDERR, $u . "\n");
    exit(1);
}

//============================================================
// 引数解析
//============================================================
$args = $argv;
array_shift($args);
if (count($args) < 1) usage_exit();

$mode = strtolower($args[0] ?? '');
if (!in_array($mode, ['clone','backup','restore'], true)) usage_exit();

$confirm    = false;
$configPath = '/usr/local/etc/openldap/tools/inc/tools.conf';
$schema     = 'public';
$backupDir  = null;
$restoreFile= null;

$positional = [];
for ($i=1; $i<count($args); $i++) {
    $a = $args[$i];
    if ($a === '--confirm') { $confirm = true; continue; }
    if (str_starts_with($a, '--config=')) { $configPath = substr($a, 9); continue; }
    if (str_starts_with($a, '--schema=')) { $schema     = substr($a, 9); continue; }
    if (str_starts_with($a, '--backup-dir=')) { $backupDir = rtrim(substr($a, 13), '/'); continue; }
    if (str_starts_with($a, '--file=')) { $restoreFile = substr($a, 7); continue; }
    $positional[] = $a;
}

switch ($mode) {
    case 'clone':
        if (count($positional) < 4) usage_exit();
        [$srcHost, $dbName, $table, $dstHost] = $positional;
        break;
    case 'backup':
        if (count($positional) < 3) usage_exit();
        [$host, $dbName, $table] = $positional;
        break;
    case 'restore':
        if (count($positional) < 3) usage_exit();
        [$host, $dbName, $table] = $positional;
        break;
}

//============================================================
// tools.conf の読込
//============================================================
$pg = [
    'pg_port' => 5432,
    'pg_user' => 'postgres',
    'pg_pass' => null,
];
if (is_file($configPath)) {
    $ini = parse_ini_file($configPath, true, INI_SCANNER_TYPED);
    if ($ini !== false && isset($ini['postgresql'])) {
        $pg['pg_port'] = (int)($ini['postgresql']['pg_port'] ?? $pg['pg_port']);
        $pg['pg_user'] = (string)($ini['postgresql']['pg_user'] ?? $pg['pg_user']);
        $pg['pg_pass'] = array_key_exists('pg_pass', $ini['postgresql'])
            ? (string)$ini['postgresql']['pg_pass']
            : null;
    }
} else {
    cwarn("config not found: $configPath (default pg_user/pg_port will be used)");
}

//============================================================
// バックアップディレクトリ設定
//============================================================
if ($backupDir === null || $backupDir === '') {
    $backupDir = __DIR__;
}
if (!is_dir($backupDir)) {
    if (!mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
        cerr("failed to create backup dir: $backupDir");
        exit(1);
    }
}

//============================================================
// DB 接続関数（指定形式）
//============================================================
function pg_pdo_from_cfg(array $cfg): PDO {
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s',
        $cfg['pg_host'], $cfg['pg_port'], $cfg['pg_db']);
    return new PDO($dsn, $cfg['pg_user'], $cfg['pg_pass'] ?? null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

//============================================================
// カラム取得
//============================================================
function fetch_columns(PDO $pdo, string $schema, string $table): array {
    $sql = <<<SQL
SELECT column_name
FROM information_schema.columns
WHERE table_schema = :schema AND table_name = :table
ORDER BY ordinal_position;
SQL;
    $st = $pdo->prepare($sql);
    $st->execute([':schema'=>$schema, ':table'=>$table]);
    return array_map(fn($r)=>$r['column_name'], $st->fetchAll());
}

//============================================================
// JSON バックアップ
//============================================================
function backup_table_to_json(PDO $pdo, string $schema, string $table, string $outfile): int {
    $sql = sprintf('SELECT * FROM "%s"."%s"', $schema, $table);
    $st  = $pdo->query($sql);
    $rows = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) $rows[] = $r;

    $json = json_encode([
        'schema' => $schema,
        'table'  => $table,
        'count'  => count($rows),
        'rows'   => $rows,
        'created_at' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) throw new RuntimeException('json_encode failed');

    if (file_put_contents($outfile, $json) === false) {
        throw new RuntimeException("failed to write: $outfile");
    }
    return count($rows);
}

//============================================================
// JSON リストア（DELETE→INSERT）
//============================================================
function restore_from_json(PDO $pdo, string $schema, string $table, string $jsonFile, bool $confirm): int {
    if (!is_file($jsonFile)) throw new RuntimeException("json not found: $jsonFile");
    $data = json_decode(file_get_contents($jsonFile), true);
    if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows'])) {
        throw new RuntimeException("invalid json format: $jsonFile");
    }
    $rows = $data['rows'];
    $cols = !empty($rows) ? array_keys($rows[0]) : [];

    if ($confirm) $pdo->beginTransaction();
    try {
        $pdo->exec(sprintf('DELETE FROM "%s"."%s"', $schema, $table));
        if (!empty($cols)) {
            $colList  = '"' . implode('","', $cols) . '"';
            $bindList = ':' . implode(',:', $cols);
            $sqlIns   = sprintf('INSERT INTO "%s"."%s" (%s) VALUES (%s)', $schema, $table, $colList, $bindList);
            $stIns    = $confirm ? $pdo->prepare($sqlIns) : null;
            $n=0;
            foreach ($rows as $r) {
                $n++;
                if ($confirm) {
                    $params=[];
                    foreach ($cols as $c) { $params[":$c"] = $r[$c] ?? null; }
                    $stIns->execute($params);
                }
            }
        }
        if ($confirm) $pdo->commit();
    } catch (Throwable $e) {
        if ($confirm && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return count($rows);
}

//============================================================
// 最新バックアップファイル検索
//============================================================
function find_latest_backup(string $dir, string $host, string $db, string $table): ?string {
    $prefix = sprintf('%s_%s_%s_', $host, $db, $table);
    $files  = glob(rtrim($dir,'/').'/*.json');
    $cand   = array_values(array_filter($files, fn($f)=>str_contains(basename($f), $prefix)));
    if (empty($cand)) return null;
    usort($cand, fn($a,$b)=>strcmp($b,$a));
    return $cand[0];
}

//============================================================
// 実行本体
//============================================================
try {
    if ($mode === 'backup') {
        cinfo("BACKUP  host=$host db=$dbName table=$schema.$table user={$pg['pg_user']}");
        $cfg = [
            'pg_host' => $host,
            'pg_port' => $pg['pg_port'],
            'pg_db'   => $dbName,
            'pg_user' => $pg['pg_user'],
            'pg_pass' => $pg['pg_pass'],
        ];

        $pdo = pg_pdo_from_cfg($cfg);

//		print_r($cfg);
//		exit;

        $date = date('Ymd_His');
        $outfile = sprintf('%s/%s_%s_%s_%s.json', $backupDir, $host, $dbName, $table, $date);
        $count = backup_table_to_json($pdo, $schema, $table, $outfile);
        cgood("Backup completed: $outfile (rows=$count)");
        exit(0);
    }

    if ($mode === 'restore') {
        cinfo("RESTORE host=$host db=$dbName table=$schema.$table user={$pg['pg_user']}");
        $cfgDst = [
            'pg_host' => $host,
            'pg_port' => $pg['pg_port'],
            'pg_db'   => $dbName,
            'pg_user' => $pg['pg_user'],
            'pg_pass' => $pg['pg_pass'],
        ];
        $pdo = pg_pdo_from_cfg($cfgDst);
        $date = date('Ymd_His');
        $bkDst = sprintf('%s/%s_%s_%s_%s.json', $backupDir, $host, $dbName, $table, $date);
        if ($confirm) {
            $bkRows = backup_table_to_json($pdo, $schema, $table, $bkDst);
            cgood("DST backup: $bkDst (rows=$bkRows)");
        } else {
            cinfo("DRY-RUN: would backup DST to $bkDst");
        }

        if ($restoreFile === null) {
            $restoreFile = find_latest_backup($backupDir, $host, $dbName, $table);
            if ($restoreFile === null) {
                throw new RuntimeException("no backup file found in $backupDir for {$host}_{$dbName}_{$table}_*.json");
            }
            cinfo("Use latest backup: $restoreFile");
        }
        $rows = restore_from_json($pdo, $schema, $table, $restoreFile, $confirm);
        if ($confirm) cgood("Restored rows: $rows"); else cinfo("DRY-RUN: would restore rows = $rows");
        exit(0);
    }

    if ($mode === 'clone') {
        cinfo("CLONE  SRC: host=$srcHost db=$dbName table=$schema.$table user={$pg['pg_user']}");
        cinfo("       DST: host=$dstHost db=$dbName table=$schema.$table user={$pg['pg_user']}");
        $cfgSrc = [
            'pg_host' => $srcHost,
            'pg_port' => $pg['pg_port'],
            'pg_db'   => $dbName,
            'pg_user' => $pg['pg_user'],
            'pg_pass' => $pg['pg_pass'],
        ];
        $cfgDst = [
            'pg_host' => $dstHost,
            'pg_port' => $pg['pg_port'],
            'pg_db'   => $dbName,
            'pg_user' => $pg['pg_user'],
            'pg_pass' => $pg['pg_pass'],
        ];
        $pdoSrc = pg_pdo_from_cfg($cfgSrc);
        $pdoDst = pg_pdo_from_cfg($cfgDst);

        $date = date('Ymd_His');
        $dstBackup = sprintf('%s/%s_%s_%s_%s.json', $backupDir, $dstHost, $dbName, $table, $date);
        if ($confirm) {
            $bkRows = backup_table_to_json($pdoDst, $schema, $table, $dstBackup);
            cgood("DST backup: $dstBackup (rows=$bkRows)");
        } else {
            cinfo("DRY-RUN: would backup DST to $dstBackup");
        }

        $cols = fetch_columns($pdoSrc, $schema, $table);
        if (empty($cols)) throw new RuntimeException("no columns found on SRC $schema.$table");

        $sqlFetch = sprintf('SELECT * FROM "%s"."%s"', $schema, $table);
        $stSrc    = $pdoSrc->query($sqlFetch);

        if ($confirm) $pdoDst->beginTransaction();
        try {
            cinfo("DELETE on DST ...");
            if ($confirm) {
                $pdoDst->exec(sprintf('DELETE FROM "%s"."%s"', $schema, $table));
            }

            $colList  = '"' . implode('","', $cols) . '"';
            $bindList = ':' . implode(',:', $cols);
            $sqlIns   = sprintf('INSERT INTO "%s"."%s" (%s) VALUES (%s)', $schema, $table, $colList, $bindList);
            $stIns    = $confirm ? $pdoDst->prepare($sqlIns) : null;

            $n=0;
            while ($row = $stSrc->fetch(PDO::FETCH_ASSOC)) {
                $n++;
                if ($confirm) {
                    $params=[];
                    foreach ($cols as $c) { $params[":$c"] = $row[$c] ?? null; }
                    $stIns->execute($params);
                }
                if (($n % 1000) === 0) cinfo("... inserted $n rows");
            }
            if ($confirm) $pdoDst->commit();
            if ($confirm) cgood("Cloned rows: $n"); else cinfo("DRY-RUN: would clone rows = $n");
        } catch (Throwable $e) {
            if ($confirm && $pdoDst->inTransaction()) $pdoDst->rollBack();
            throw $e;
        }
        exit(0);
    }

    usage_exit();
} catch (Throwable $e) {
    cerr($e->getMessage());
    exit(1);
}

