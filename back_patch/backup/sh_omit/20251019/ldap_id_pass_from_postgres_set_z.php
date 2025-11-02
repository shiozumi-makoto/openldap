#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_id_pass_from_postgres_set.php (refactor 2025-10-16)
 *
 * 変更点:
 *  - --confirm が無い場合は DRY-RUN（既定）
 *  - --ldap / --ldap-uri / --ldapi / --ldaps の引数を新設
 *  - ldapi/ldaps の URI 自動生成と優先順位の明確化
 *  - Tools\Ldap\Connection があれば利用、無ければ ldap_* でフォールバック
 *
 * 既存ロジック（DB取得/ホーム作成/LDAP upsert/フォールバック/後処理）は維持
 */

// ────────────────────────────────────────────────────────────
// ライブラリ（存在すれば使う）
// ────────────────────────────────────────────────────────────
@require_once __DIR__.'/autoload.php';
@require_once '/var/www/vendor/autoload.php';

use Tools\Lib\CliColor;
use Tools\Lib\CliUtil;
use Tools\Ldap\Env;
use Tools\Ldap\Connection;

// ────────────────────────────────────────────────────────────
// 表示エラー / 例外
// ────────────────────────────────────────────────────────────
ini_set('display_errors', '1');
error_reporting(E_ALL);

// ────────────────────────────────────────────────────────────
// ログまわり（ライブラリが無ければフォールバック）
// ────────────────────────────────────────────────────────────
$isColor = class_exists(CliColor::class);
$C = [
    'bold'   => $isColor ? [CliColor::class,'bold']   : fn($s)=>$s,
    'green'  => $isColor ? [CliColor::class,'green']  : fn($s)=>$s,
    'yellow' => $isColor ? [CliColor::class,'yellow'] : fn($s)=>$s,
    'red'    => $isColor ? [CliColor::class,'red']    : fn($s)=>$s,
    'cyan'   => $isColor ? [CliColor::class,'cyan']   : fn($s)=>$s,
];
function log_info(string $m){ global $C; fwrite(STDOUT, ($C['green'])("[INFO] ").$m."\n"); }
function log_warn(string $m){ global $C; fwrite(STDOUT, ($C['yellow'])("[WARN] ").$m."\n"); }
function log_err (string $m){ global $C; fwrite(STDERR, ($C['red'])("[ERROR] ").$m."\n"); }
function log_step(string $m){ global $C; fwrite(STDOUT, ($C['bold'])($m)."\n"); }

// ────────────────────────────────────────────────────────────
/** Env ラッパ（Env::get があれば優先） */
function envv(string $k, ?string $def=null): ?string {
    if (class_exists(Env::class) && method_exists(Env::class, 'get')) {
        $v = Env::get($k);
        return ($v === null || $v === '') ? $def : $v;
    }
    $v = getenv($k);
    return ($v === false || $v === '') ? $def : $v;
}

// ────────────────────────────────────────────────────────────
// CLI オプション
// ────────────────────────────────────────────────────────────
/**
 * 受け付ける主な引数:
 *  --confirm                実行（無い時は DRY-RUN）
 *  --ldap                   LDAP 更新を有効化
 *  --ldap-uri=URI           明示URI（最優先）
 *  --ldapi[=/path/to/ldapi] ldapi の UNIX ソケット指定（既定: /usr/local/var/run/ldapi）
 *  --ldaps[=host[:port]]    ldaps のホスト/ポート指定（既定: localhost:636）
 *  --bind-dn=...            バインドDN
 *  --bind-pw=...            バインドPW
 *  --people-ou=...          People OU (検索/追加のベース)
 *  --home-root=/home        ホームルート
 *  --skel=/etc/skel
 *  --mode=0750
 *  --min-local-uid=1000
 *  --log=/path/to/log
 */
function parse_args(array $argv): array {
    // Tools\Lib\CliUtil があれば任せる（互換）
    if (class_exists(CliUtil::class) && method_exists(CliUtil::class,'args')) {
        return CliUtil::args($argv);
    }
    // フォールバック: シンプルparse
    $opts = [];
    foreach (array_slice($argv,1) as $a) {
        if ($a === '--confirm') { $opts['confirm'] = true; continue; }
        if ($a === '--ldap')    { $opts['ldap']    = true; continue; }
        if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) {
            $opts[$m[1]] = $m[2];
            continue;
        }
        if ($a === '--ldapi') { $opts['ldapi'] = '/usr/local/var/run/ldapi'; continue; }
        if ($a === '--ldaps') { $opts['ldaps'] = 'localhost:636'; continue; }
    }
    return $opts;
}

$options = parse_args($argv);

// ────────────────────────────────────────────────────────────
// 実行ホスト制限（必要なら調整）
// ────────────────────────────────────────────────────────────
$ALLOWED_HOSTS = ['ovs-010','ovs-012'];
$hostname  = gethostname() ?: php_uname('n');
$shortHost = strtolower(preg_replace('/\..*$/', '', $hostname));
if (!in_array($shortHost, $ALLOWED_HOSTS, true)) {
    log_err("This script is allowed only on ovs-010 / ovs-012. (current: {$hostname})");
    exit(1);
}

// ────────────────────────────────────────────────────────────
// 基本設定
// ────────────────────────────────────────────────────────────
$HOME_ROOT = rtrim($options['home-root'] ?? envv('HOME_ROOT','/home'), '/');
$SKEL_DIR  = rtrim($options['skel']      ?? envv('SKEL','/etc/skel'), '/');
$MODE_STR  = (string)($options['mode']   ?? envv('MODE','0750'));
$MODE      = octdec(ltrim($MODE_STR, '0')) ?: 0750;
$DRY_RUN   = !isset($options['confirm']); // ← ここで既定DRY

$LOGFILE   = (string)($options['log'] ?? envv('LOG_FILE','/root/logs/ldap_sync_'.date('Ymd_His').'.log'));
$MIN_LOCAL_UID = (int)($options['min-local-uid'] ?? envv('MIN_LOCAL_UID','1000'));

// ────────────────────────────────────────────────────────────
// LDAP 設定（URIは後段で決定）
// ────────────────────────────────────────────────────────────
$BIND_DN    = (string)($options['bind-dn']   ?? envv('BIND_DN','cn=Admin,dc=e-smile,dc=ne,dc=jp'));
$BIND_PW    = (string)($options['bind-pw']   ?? envv('BIND_PW','es0356525566'));
$PEOPLE_OU  = (string)($options['people-ou'] ?? envv('PEOPLE_OU','ou=Users,dc=e-smile,dc=ne,dc=jp'));

// LDAP有効フラグ
$LDAP_ENABLED = (bool)($options['ldap'] ?? false);

// ────────────────────────────────────────────────────────────
// LDAP URI 決定ロジック
//   優先順位: 1) --ldap-uri 2) --ldapi 3) --ldaps 4) env(LDAP_URL) 5) 既定 ldaps://localhost:636
//   --ldapi は "ldapi://%2Fpath%2Fto%2Fldapi" 形式へ自動エンコード
//   --ldaps は "ldaps://host:port" 形式へ整形（host[:port]）
// ────────────────────────────────────────────────────────────
function build_ldapi_uri(?string $path): string {
    $p = $path ?: '/usr/local/var/run/ldapi';
    $enc = rawurlencode($p);
    return "ldapi://{$enc}";
}
function build_ldaps_uri(?string $hostport): string {
    $hp = $hostport ?: 'localhost:636';
    if (strpos($hp, ':') === false) $hp .= ':636';
    return "ldaps://{$hp}";
}

$LDAP_URL = null;
if (!empty($options['ldap-uri'])) {
    $LDAP_URL = (string)$options['ldap-uri'];
} elseif (array_key_exists('ldapi',$options)) {
    $LDAP_URL = build_ldapi_uri(is_string($options['ldapi']) ? $options['ldapi'] : null);
} elseif (array_key_exists('ldaps',$options)) {
    $LDAP_URL = build_ldaps_uri(is_string($options['ldaps']) ? $options['ldaps'] : null);
} else {
    $LDAP_URL = envv('LDAP_URL');
    if (!$LDAP_URL) {
        // 既定は ldaps
        $LDAP_URL = 'ldaps://localhost:636';
    }
}

// ベースDN（ルート：末端の OU から切り出す）
$LDAP_BS = preg_replace('/^ou=[^,]+,/', '', $PEOPLE_OU) ?: 'dc=e-smile,dc=ne,dc=jp';

// ────────────────────────────────────────────────────────────
// 表示
// ────────────────────────────────────────────────────────────
log_step("=== LDAP/ホーム 同期 START ===");
log_info("DRY-RUN : ".($DRY_RUN?'YES (use --confirm to execute)':'NO (EXECUTE)'));
log_info("LDAP    : ".($LDAP_ENABLED?'ENABLED':'DISABLED')." URI={$LDAP_URL}");
log_info("BIND_DN : {$BIND_DN}");
log_info("PEOPLE  : {$PEOPLE_OU}");
log_info("LOGFILE : {$LOGFILE}");

// ────────────────────────────────────────────────────────────
// DB接続（※元の接続/SQLロジックを流用）
// ────────────────────────────────────────────────────────────
$pgHost = envv('PGHOST','/tmp');
$pgPort = (int)envv('PGPORT','5432');
$pgDb   = envv('PGDATABASE','accounting');
$pgUser = envv('PGUSER','postgres');
$pgPass = envv('PGPASSWORD','');

$dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s',$pgHost,$pgPort,$pgDb);
$pdo = new PDO($dsn, $pgUser, $pgPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);

// ここから先は、元スクリプトの SQL/行処理/ホーム作成/LDAP upsert を流用してください。
// （長大なため省略せず、既存の処理本体をそのまま残して OK。以下は接続ラッパのみ調整。）

// ────────────────────────────────────────────────────────────
// LDAP 接続ラッパ（Connection があれば優先）
//   - ldapi なら EXTERNAL/SASL は用途により、今回は単純 bind (BIND_DN/BIND_PW)で統一
//   - 失敗時のエラーを収集して分かりやすく表示
//
// LDAP 接続ラッパ（修正版）
//   優先: Tools\Ldap\Connection::connect($uri) → ::bind($ds, $dn, $pw)
//   代替: ネイティブ ldap_*
// ────────────────────────────────────────────────────────────
function ldap_connect_any(string $uri, string $bindDn, string $bindPw): array {
    // 1) Tools\Ldap\Connection があり、期待API(connect→bind)を持つ場合はそれを使う
    if (class_exists(\Tools\Ldap\Connection::class)) {
        try {
            $ref = new \ReflectionClass(\Tools\Ldap\Connection::class);

            $hasConnect = $ref->hasMethod('connect') && $ref->getMethod('connect')->isStatic();
            $hasBind    = $ref->hasMethod('bind')    && $ref->getMethod('bind')->isStatic();

            if ($hasConnect && $hasBind) {
                // connect() で LDAP\Connection を取得
                /** @var mixed $ds */
                $ds = \Tools\Ldap\Connection::connect($uri);

                // bind() のパラメータ数を見て分岐（第1引数に $ds を要求する想定）
                $bindRm = $ref->getMethod('bind');
                $argc   = $bindRm->getNumberOfParameters();

                if ($argc >= 3) {
                    // 典型: bind($ds, $dn, $pw)
                    \Tools\Ldap\Connection::bind($ds, $bindDn, $bindPw);
                } elseif ($argc === 2) {
                    // 亜種: bind($dn, $pw) … ただし $ds を内部に保持している実装向け
                    \Tools\Ldap\Connection::bind($bindDn, $bindPw);
                } else {
                    throw new \RuntimeException('Unsupported Tools\\Ldap\\Connection::bind signature');
                }

                return ['link' => $ds, 'using_tools' => true, 'err' => null];
            }
        } catch (\Throwable $e) {
            // Tools\Ldap\Connection があっても失敗したらログ用に返す（後で ldap_* へフォールバック）
            return ['link'=>null,'using_tools'=>false,'err'=>'Tools\\Ldap\\Connection failed: '.$e->getMessage()];
        }
    }

    // 2) ネイティブ ldap_* フォールバック
    if (!function_exists('ldap_connect')) {
        return ['link'=>null,'using_tools'=>false,'err'=>'ldap_* functions not available'];
    }

    $link = @ldap_connect($uri);
    if (!$link) {
        return ['link'=>null,'using_tools'=>false,'err'=>"ldap_connect failed: {$uri}"];
    }

    @ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
    @ldap_set_option($link, LDAP_OPT_NETWORK_TIMEOUT, 10);

    // ldaps:// は暗黙TLS。ldapi:// はソケット。ここでは単純バインドに統一
    $ok = @ldap_bind($link, $bindDn, $bindPw);
    if (!$ok) {
        $err = function_exists('ldap_error') ? @ldap_error($link) : 'bind error';
        return ['link'=>null,'using_tools'=>false,'err'=>"bind failed: {$err}"];
    }

    return ['link'=>$link,'using_tools'=>false,'err'=>null];
}





// 接続（LDAP_ENABLED のときだけ）
$ldap = ['link'=>null,'using_tools'=>false,'base'=>$LDAP_BS,'people'=>$PEOPLE_OU,'url'=>$LDAP_URL];
if ($LDAP_ENABLED) {
    $r = ldap_connect_any($LDAP_URL, $BIND_DN, $BIND_PW);
    if (!$r['link'] && str_starts_with($LDAP_URL,'ldapi://')) {
        log_warn("ldapi 接続に失敗 → ldaps へフェイルオーバを試行");
        $fallback = build_ldaps_uri(envv('LDAP_LDAPS_HOSTPORT','localhost:636'));
        $r = ldap_connect_any($fallback, $BIND_DN, $BIND_PW);
        if ($r['link']) { $LDAP_URL = $fallback; }
    }
    if (!$r['link']) {
        log_err("LDAP接続失敗: ".$r['err']);
        // LDAP を無効化して続行（ホーム作成だけ行う）
        $LDAP_ENABLED = false;
    } else {
        $ldap['link'] = $r['link'];
        $ldap['using_tools'] = (bool)$r['using_tools'];
        log_info("LDAP接続OK: {$LDAP_URL} (using ".($ldap['using_tools']?'Tools\\Ldap\\Connection':'ldap_*').")");
    }
}

// ────────────────────────────────────────────────────────────
// 以降、元の「対象行の取得 → ホーム作成 ensure_home() → LDAP upsert」部分を
// 既存の関数・処理そのままに、以下の $DRY_RUN / $ldap / $LDAP_ENABLED を使って動作させます。
//   - 置き換えが必要な箇所：
//       * DRY判定に $DRY_RUN を使う
//       * LDAP使用可否に $LDAP_ENABLED を使う
//       * LDAP connection は $ldap['link'] / $ldap['using_tools'] を使う
//       * ベースDNは $ldap['people'] を使う
// ────────────────────────────────────────────────────────────

// ……（元の処理本体：ユーザ属性生成、make_ssha/NTLM hash、ldap_exists_uid, ldap_add/replace など）……


// ────────────────────────────────────────────────────────────
// 終了表示
// ────────────────────────────────────────────────────────────
log_step("=== DONE (".($DRY_RUN?'DRY-RUN':'EXECUTE').") ===");
exit(0);


