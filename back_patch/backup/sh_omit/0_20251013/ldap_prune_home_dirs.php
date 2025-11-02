#!/usr/bin/php
<?php
declare(strict_types=1);

/**
 * ldap_prune_home_dirs.php
 *
 * 概要:
 *   LDAP の homeDirectory 一覧とファイルシステムの /home 以下を突合し、
 *   LDAP に存在しないホームディレクトリを検出・削除する CLI ツール。
 *
 * ポリシー:
 *   - ローカル/root での実行は ldapi:/// + SASL/EXTERNAL（既定）
 *   - リモートは ldaps:// + Simple Bind（ldap:// 指定時は StartTLS を強制）
 *
 * 安全設計:
 *   - 既定 DRY-RUN（--confirm がない限り削除しない）
 *   - --delete-empty-only 指定時は「空ディレクトリのみ削除」
 *   - --age-days=N で N 日以上更新が無いディレクトリのみ対象
 *   - --protect でカンマ区切りのディレクトリ名を保護
 *
 * 使い方（例）:
 *   export BASE_DN='dc=e-smile,dc=ne,dc=jp'
 *   export LDAP_URL='ldapi:///'                      # 既定はコレ
 *   # export LDAP_URL='ldaps://ovs-012.e-smile.local'
 *   export BIND_DN='cn=Admin,dc=e-smile,dc=ne,dc=jp' # 非ldapi時
 *   export BIND_PW='(管理PW)'                        # 非ldapi時
 *
 *   php ldap_prune_home_dirs.php \\
 *       --home-root /home \\
 *       --users-dn "ou=Users,${BASE_DN}" \\
 *       --delete-empty-only \\
 *       --age-days 14 \\
 *       --protect "lost+found,.snapshot" \\
 *       --confirm
 *
 * 主なオプション:
 *   --home-root "<path>"     : ルート（既定: /home）
 *   --users-dn "<DN>"        : ユーザーツリー（既定: "ou=Users,${BASE_DN}"）
 *   --attr-home "homeDirectory|..." : LDAPのホーム属性（既定: homeDirectory）
 *   --regex "<pattern>"      : 監視対象ディレクトリ名の正規表現（例: '^\d{2}-\d{3}-')
 *   --delete-empty-only      : 空ディレクトリのみ削除
 *   --age-days N             : N日以上更新の無いもののみ対象
 *   --protect "a,b,c"        : 除外ディレクトリ名（カンマ区切り、basenameで比較）
 *   --dry-run                : 削除しないで表示（既定）
 *   --confirm                : 実際に削除
 */

const APP2 = 'ldap_prune_home_dirs';
ini_set('display_errors', '0');
error_reporting(E_ALL);

main2($argv);

/* ================================================================ */
function main2(array $argv): void {
    [$opt, $errors] = parseOptions2($argv);
    if ($errors) { fwrite(STDERR, implode("\n", $errors)."\n"); exit(1); }
    if (!empty($opt['help'])) { echoHelp2(); exit(0); }

    $homeRoot  = rtrim($opt['home-root'] ?? '/home', '/');
    $usersDn   = $opt['users-dn'] ?? ("ou=Users,".(getenv('BASE_DN') ?: 'dc=e-smile,dc=ne,dc=jp'));
    $attrHome  = $opt['attr-home'] ?? 'homeDirectory';
    $regex     = $opt['regex'] ?? ''; // 例: '^\d{2}-\d{3}-'
    $dryRun    = empty($opt['confirm']);
    $emptyOnly = !empty($opt['delete-empty-only']);
    $ageDays   = isset($opt['age-days']) ? max(0, (int)$opt['age-days']) : 0;
    $protect   = [];
    if (!empty($opt['protect'])) {
        foreach (explode(',', (string)$opt['protect']) as $x) {
            $x = trim($x);
            if ($x !== '') $protect[$x] = true;
        }
    }

    if (!is_dir($homeRoot)) {
        logErr2("home-root not found: $homeRoot");
        exit(1);
    }

    // LDAP 接続
    $ldap = ldap_connect_unified2(
        url: getenv('LDAP_URL') ?: (getenv('LDAP_URI') ?: 'ldapi:///'),
        baseDn: getenv('BASE_DN') ?: 'dc=e-smile,dc=ne,dc=jp',
        bindDn: getenv('BIND_DN') ?: '',
        bindPw: getenv('BIND_PW') ?: '',
        fallbackLdaps: getenv('FALLBACK_LDAPS_URL') ?: 'ldaps://ovs-012.e-smile.local'
    );

    // LDAP 側の有効ディレクトリ集合を作る
    $valid = fetchValidHomeDirs($ldap, $usersDn, $attrHome);

    // FS 側の候補列挙
    $dh = opendir($homeRoot);
    if ($dh === false) { logErr2("opendir failed: $homeRoot"); exit(1); }

    $target = [];
    while (($entry = readdir($dh)) !== false) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $homeRoot.'/'.$entry;
        if (!is_dir($full)) continue;

        // 保護対象
        if (isset($protect[$entry])) {
            logInfo2("protect: $entry");
            continue;
        }
        // 正規表現フィルタ
        if ($regex !== '' && preg_match('/'.$regex.'/', $entry) !== 1) {
            continue;
        }
        // LDAPで参照されているか？
        if (isset($valid[$full])) {
            continue;
        }
        // 更新時間フィルタ
        if ($ageDays > 0) {
            $mtime = @filemtime($full);
            if ($mtime !== false) {
                $days = (time() - $mtime) / 86400;
                if ($days < $ageDays) {
                    logInfo2("skip by age: $entry (last: ".sprintf('%.1f', $days)."d)");
                    continue;
                }
            }
        }
        // 空ディレクトリのみ？
        if ($emptyOnly && !isDirEmpty($full)) {
            logInfo2("skip non-empty: $entry");
            continue;
        }
        $target[] = $full;
    }
    closedir($dh);

    // 実行
    $ok=0; $skipped=0; $ng=0;
    foreach ($target as $d) {
        if ($dryRun) {
            logInfo2("DRY-RUN delete: $d");
            $skipped++; continue;
        }
        if (!@rrmdir($d)) {
            $ng++; logErr2("delete failed: $d");
            continue;
        }
        $ok++; logInfo2("deleted: $d");
    }

    logInfo2("SUMMARY: ok=$ok dryrun=$skipped ng=$ng");
    exit($ng > 0 ? 1 : 0);
}

/* ================================================================ */
/* Options / Help */
function parseOptions2(array $argv): array {
    $short = '';
    $long = [
        'help',
        'home-root:',
        'users-dn:',
        'attr-home:',
        'regex:',
        'delete-empty-only',
        'age-days:',
        'protect:',
        'dry-run',
        'confirm',
    ];
    $opt = getopt($short, $long);
    return [$opt, []];
}

function echoHelp2(): void {
    $base = getenv('BASE_DN') ?: 'dc=e-smile,dc=ne,dc=jp';
    echo <<<HELP
{$GLOBALS['argv'][0]} - LDAPに存在しないホームディレクトリの整理ツール

[接続ポリシー]
  - ldapi:/// は SASL/EXTERNAL（root想定）、失敗時 ldaps:// に自動フォールバック
  - ldap:// は StartTLS を強制してから Bind
  - ldaps:// はそのまま Bind

[環境変数]
  BASE_DN="$base"
  LDAP_URL="ldapi:///" | "ldaps://host" | "ldap://host"
  BIND_DN="cn=Admin,${base}"               # 非ldapi時に必須
  BIND_PW="(PW)"                            # 非ldapi時に必須
  FALLBACK_LDAPS_URL="ldaps://ovs-012.e-smile.local"

[主なオプション]
  --home-root "<path>"   既定: /home
  --users-dn "<DN>"      既定: "ou=Users,${base}"
  --attr-home "<attr>"   既定: homeDirectory
  --regex "<pattern>"    ディレクトリ名フィルタ用の正規表現
  --delete-empty-only    空ディレクトリのみ削除
  --age-days N           N日以上更新の無いもののみ対象
  --protect "a,b,c"      除外（basename一致）
  --dry-run              削除せず表示（既定）
  --confirm              実際に削除

[例]
  php {$GLOBALS['argv'][0]} --home-root /home --users-dn "ou=Users,${base}" \\
      --regex '^\\d{2}-\\d{3}-' --delete-empty-only --age-days 7 --protect "lost+found" --confirm

HELP;
}

/* ================================================================ */
/* LDAP */
function ldap_connect_unified2(string $url, string $baseDn, string $bindDn, string $bindPw, string $fallbackLdaps) {
    $conn = @ldap_connect($url);
    if (!$conn) { logErr2("[LDAP] connect failed: $url"); exit(1); }
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

    $scheme = parseScheme2($url);
    if ($scheme === 'ldap') {
        if (!@ldap_start_tls($conn)) {
            logErr2("[LDAP] StartTLS failed: ".ldap_error($conn));
            exit(1);
        }
    }
    if ($scheme === 'ldapi') {
        if (!@ldap_sasl_bind($conn, NULL, NULL, 'EXTERNAL')) {
            logInfo2("[LDAP] ldapi EXTERNAL failed; falling back to $fallbackLdaps");
            @ldap_unbind($conn);
            $conn = @ldap_connect($fallbackLdaps);
            if (!$conn) { logErr2("[LDAP] fallback connect failed: $fallbackLdaps"); exit(1); }
            ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
            if (!@ldap_bind($conn, $bindDn, $bindPw)) {
                logErr2("[LDAP] fallback bind failed: ".ldap_error($conn));
                exit(1);
            }
            return $conn;
        }
        return $conn;
    }
    // ldaps / ldap(StartTLS済) → Simple Bind
    if ($bindDn === '' || $bindPw === '') {
        logErr2("[LDAP] BIND_DN/BIND_PW required for non-ldapi connection.");
        exit(1);
    }
    if (!@ldap_bind($conn, $bindDn, $bindPw)) {
        logErr2("[LDAP] bind failed: ".ldap_error($conn));
        exit(1);
    }
    return $conn;
}

function parseScheme2(string $url): string {
    $u = strtolower($url);
    if (str_starts_with2($u, 'ldapi://')) return 'ldapi';
    if (str_starts_with2($u, 'ldaps://')) return 'ldaps';
    if (str_starts_with2($u, 'ldap://'))  return 'ldap';
    return 'ldap';
}

function fetchValidHomeDirs($ldap, string $usersDn, string $attrHome): array {
    $res = [];
    $attrs = [$attrHome];
    $sr = @ldap_search($ldap, $usersDn, "(objectClass=posixAccount)", $attrs);
    if ($sr === false) { logErr2("[LDAP] search failed: ".ldap_error($ldap)); exit(1); }
    $entries = ldap_get_entries($ldap, $sr);
    if (!isset($entries['count'])) return $res;
    for ($i=0; $i<$entries['count']; $i++) {
        $e = $entries[$i];
        if (!isset($e[strtolower($attrHome)][0])) continue;
        $dir = rtrim((string)$e[strtolower($attrHome)][0], '/');
        if ($dir !== '') $res[$dir] = true;
    }
    return $res;
}

/* ================================================================ */
/* FS helpers */
function isDirEmpty(string $dir): bool {
    $h = opendir($dir);
    if ($h === false) return false;
    $empty = true;
    while (($e = readdir($h)) !== false) {
        if ($e === '.' || $e === '..') continue;
        $empty = false; break;
    }
    closedir($h);
    return $empty;
}

function rrmdir(string $dir): bool {
    if (!is_dir($dir)) return @unlink($dir);
    $items = scandir($dir);
    if ($items === false) return false;
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        $full = $dir.'/'.$it;
        if (is_dir($full) && !is_link($full)) {
            if (!rrmdir($full)) return false;
        } else {
            if (!@unlink($full)) return false;
        }
    }
    return @rmdir($dir);
}

/* ================================================================ */
/* Utils */
function logInfo2(string $m): void { fwrite(STDOUT, "[INFO] $m\n"); }
function logErr2(string $m): void  { fwrite(STDERR, "[ERROR] $m\n"); }

function str_starts_with2(string $haystack, string $needle): bool {
    return substr($haystack, 0, strlen($needle)) === $needle;
}

