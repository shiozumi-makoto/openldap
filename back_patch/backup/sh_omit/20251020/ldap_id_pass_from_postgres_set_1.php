#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_id_pass_from_postgres_set.php
 * refactored: 2025-10-19
 *
 * 概要:
 *  - DB（accounting.passwd_tnas × 情報個人）から同期対象ユーザを取得
 *  - /home/%02d-%03d-%s のホーム作成/権限整備（DRY-RUN対応）
 *  - --ldap 指定時に LDAP upsert（inetOrgPerson + posixAccount + shadowAccount + sambaSamAccount）
 *  - 既存 objectClass を尊重し、必要分のみを安全に追加/更新（posix/shadow 衝突時はフォールバック）
 *  - 最後に passwd_tnas.samba_id を一括補完（空欄のみ）
 *  - フィルタスイッチ（--filter-uid/like/cmp/user/where）、ldapi/ldaps/OU 自動検出、uid 接頭辞
 *
 * 使い方（例）:
 *  # ドライラン
 *    php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php
 *
 *  # 実行 + LDAP反映（ldaps）
 *    HOME_ROOT=/home SKEL=/etc/skel MODE=0750 \
 *    LDAP_URL='ldaps://ovs-012.e-smile.local' \
 *    BIND_DN='cn=Admin,dc=e-smile,dc=ne,dc=jp' \
 *    BIND_PW='********' \
 *    PEOPLE_OU='ou=Users,dc=e-smile,dc=ne,dc=jp' \
 *    php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php --confirm --ldap
 *
 *  # 実行 + LDAP反映（ldapi）
 *    LDAP_URI='ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi' \
 *    php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php --confirm --ldap --ldapi
 *
 *  # 単一指定（暴発防止に --only=1 も推奨）
 *    php ldap_id_pass_from_postgres_set.php --confirm --ldap --ldapi \
 *      --filter-uid='ooshita-shuuhei2' --only=1
 *
 *  # uid を "12-168-ooshita-shuuhei2" のように接頭辞付きにしたい場合
 *    php ldap_id_pass_from_postgres_set.php --confirm --ldap --ldapi \
 *      --filter-cmp=12 --filter-user=168 --uid-prefix
 */

// ════════════════════════════════════════════════════════════
// オートロード & ライブラリ（存在すれば活用）
// ════════════════════════════════════════════════════════════
@require_once __DIR__ . '/autoload.php';

use Tools\Lib\CliColor;
use Tools\Ldap\Env;
use Tools\Ldap\Connection;
use Tools\Ldap\Support\GroupDef;

// ════════════════════════════════════════════════════════════
// ログ出力（色つき対応）
// ════════════════════════════════════════════════════════════
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

ini_set('display_errors', '1');
error_reporting(E_ALL);

// ════════════════════════════════════════════════════════════
/**
 * envv
 *  - 環境変数の取得（Tools\Ldap\Env::get があれば優先）
 *
 * @param string      $k   変数名
 * @param string|null $def 既定値
 * @return string|null     値（未設定/空は既定値）
 */
// ════════════════════════════════════════════════════════════
function envv(string $k, ?string $def=null): ?string {
    if (class_exists(Env::class) && method_exists(Env::class, 'get')) {
        $v = Env::get($k); if ($v !== null && $v !== '') return $v;
    }
    $v = getenv($k); if ($v !== false && $v !== '') return $v;
    return $def;
}

// ════════════════════════════════════════════════════════════
// CLIオプション
// ════════════════════════════════════════════════════════════
$options = getopt('', [
    'sql::','home-root::','skel::','mode::',
    'confirm','ldap','ldapi','uri::','log::','min-local-uid::',
    // 絞込
    'filter-uid::','filter-like::','filter-cmp::','filter-user::','where::',
    'list-only',
    // 暴発防止
    'only::',
    // UID 接頭辞
    'uid-prefix',
]);

// ════════════════════════════════════════════════════════════
// 実行ホスト制限（必要なら配列に追加/解除）
// ════════════════════════════════════════════════════════════
$ALLOWED_HOSTS = ['ovs-010','ovs-012'];
$hostname  = gethostname() ?: php_uname('n');
$shortHost = strtolower(preg_replace('/\..*$/', '', $hostname));
if (!in_array($shortHost, $ALLOWED_HOSTS, true)) {
    log_err("This script is allowed only on ovs-010 / ovs-012. (current: {$hostname})");
    exit(1);
}

// ════════════════════════════════════════════════════════════
/**
 * opt_to_array
 *  - getopt の返し値を配列化（null/空は空配列）
 */
// ════════════════════════════════════════════════════════════
function opt_to_array($val): array {
    if ($val === null) return [];
    if (is_array($val)) return array_values(array_filter($val, fn($v)=>$v!=='' && $v!==null));
    return $val!=='' ? [$val] : [];
}

// ════════════════════════════════════════════════════════════
/**
 * sql_in
 *  - 配列を SQL IN (...) に整形（文字列はクォート、数値は整数化）
 */
// ════════════════════════════════════════════════════════════
function sql_in(array $vals, bool $asNumber=false): string {
    $vals = array_values(array_unique($vals));
    if (!$vals) return '(NULL)';
    $escaped = array_map(
        fn($v)=>$asNumber ? (string)intval($v) : "'".str_replace("'", "''",(string)$v)."'", $vals
    );
    return '('.implode(',', $escaped).')';
}

// ──────────────────────────────────────────────────────────── ★ここから
/**
 * build_postal_address
 *  - 住所部品から postalAddress を生成（inetOrgPerson の慣習に合わせ「$」区切り）
 *
 * @param ?string $country 国または地域
 * @param ?string $pref    都道府県
 * @param ?string $city    市区町村
 * @param ?string $addr    番地
 * @param ?string $zip     郵便番号（先頭に付与、例: "〒103-0011"）
 * @return ?string         "〒xxx$東京都中央区...$Japan" のような複数行表現。全て空なら null
 */
/*
function build_postal_address(?string $country, ?string $pref, ?string $city, ?string $addr, ?string $zip=null): ?string {
    $line1 = $zip ? ('〒'.trim($zip)) : null;
    $line2 = trim(implode('', array_filter([$pref ?? '', $city ?? '', $addr ?? ''], fn($x)=>$x!=='')));
    $line3 = $country ? trim($country) : null;
    $lines = array_values(array_filter([$line1, $line2, $line3], fn($x)=>$x && $x!==''));
    return $lines ? implode(' $ ', $lines) : null;
}
*/

/**
 * put_if
 *  - 値が空でない場合のみ LDAP 属性配列に追加
 *
 * @param array  &$attrs 属性配列（参照渡し）
 * @param string  $attr  属性名
 * @param ?mixed  $val   値（null/空文字は無視）
 * @return void
 */
function put_if(array &$attrs, string $attr, $val): void {
    if ($val === null) return;
    if (is_string($val)) { $val = trim($val); if ($val === '') return; }
    $attrs[$attr] = $val;
}

/**
 * sanitize_phone
 *  - 電話番号を LDAP TelephoneNumber 構文に合うよう可能な範囲で正規化。
 *  - 全角→半角、全角記号→半角、許容文字以外は削除。
 *  - 結果が空なら null を返す（投入スキップ用）。
 */
function sanitize_phone(?string $v): ?string {
    if ($v===null) return null;
    // 全角→半角（英数記号/スペース）
    $v = mb_convert_kana($v, 'asKV');
    // 許容: 数字, 空白, + ( ) - # x
    $v = preg_replace('/[^0-9\+\-\(\)x# ]+/', '', $v ?? '');
    // 空白の正規化
    $v = trim(preg_replace('/\s+/', ' ', $v));
    return $v !== '' ? $v : null;
}

/**
 * sanitize_postal_code
 *  - 郵便番号の安全化（半角化 + 数字とハイフンのみ）
 */
function sanitize_postal_code(?string $v): ?string {
    if ($v===null) return null;
    $v = mb_convert_kana($v, 'asKV');
    $v = preg_replace('/[^0-9\-]+/', '', $v);
    $v = trim($v);
    return $v !== '' ? $v : null;
}

/**
 * build_postal_address_safe
 *  - 〒は付けず、半角化し、制御/不可視文字を除去。
 *  - `$` 区切りは維持。
 */
function build_postal_address_safe(?string $country, ?string $pref, ?string $city, ?string $addr): ?string {
    $norm = function($s){
        if ($s===null) return null;
        $s = mb_convert_kana($s, 'asKV');        // 全角→半角
        $s = preg_replace('/[[:cntrl:]]+/', '', $s); // 制御文字除去
        $s = trim($s);
        return $s !== '' ? $s : null;
    };
    $parts = array_values(array_filter([
        $norm($pref),
        $norm($city),
        $norm($addr),
        $norm($country),
    ]));
    return $parts ? implode(' $ ', $parts) : null;
}

/**
 * strip_contact_attrs
 *  - 連絡先/住所系属性を $entry から除去（フォールバック用）
 */
function strip_contact_attrs(array &$entry): void {
    foreach (['mail','telephoneNumber','mobile','postalAddress','postalCode','st','l','street'] as $k) {
        unset($entry[$k]);
    }
}

// ──────────────────────────────────────────────────────────── ★ここまで

// ════════════════════════════════════════════════════════════
// 主要設定
// ════════════════════════════════════════════════════════════
$HOME_ROOT = rtrim($options['home-root'] ?? envv('HOME_ROOT','/home'), '/');
$SKEL_DIR  = rtrim($options['skel']      ?? envv('SKEL','/etc/skel'), '/');
$MODE_STR  = (string)($options['mode']   ?? envv('MODE','0750'));
$MODE      = octdec(ltrim($MODE_STR, '0')) ?: 0750;

$DRY_RUN   = !isset($options['confirm']);
$LOGFILE   = (string)($options['log'] ?? 'temp.log');
$MIN_LOCAL_UID   = (int)($options['min-local-uid'] ?? 1000);
$UID_WITH_PREFIX = isset($options['uid-prefix']);
$ONLY_LIMIT      = isset($options['only']) ? max(0, (int)$options['only']) : 0;

// LDAP
$BIND_DN    = envv('BIND_DN',   'cn=Admin,dc=e-smile,dc=ne,dc=jp');
$BIND_PW    = envv('BIND_PW',   'es0356525566');
$PEOPLE_OU  = envv('PEOPLE_OU', ''); // 空なら後で自動検出

$URI_OPT      = $options['uri'] ?? null;
$LDAP_URI_ENV = envv('LDAP_URI', null);
$LDAP_URL     = $URI_OPT ?: ($LDAP_URI_ENV ?: envv('LDAP_URL', null));
if (isset($options['ldapi']) && !$LDAP_URL) $LDAP_URL = 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi';
if (!$LDAP_URL) $LDAP_URL = 'ldaps://ovs-012.e-smile.local';
$LDAP_ENABLED = (isset($options['ldap']) && $LDAP_URL && $BIND_DN);

// ベースサフィックス
$LDAP_BS = preg_replace('/^[^,]+,/', '', $BIND_DN);

// サーバ対象カラム
$aliases_情報個人    = 'j';
$aliases_passwd_tnas = 'p';
$target_column_all = sprintf('%s.srv03 = 1 OR %s.srv04 = 1 OR %s.srv05 = 1',
    $aliases_passwd_tnas, $aliases_passwd_tnas, $aliases_passwd_tnas);

// 絞込入力
$F_UIDS   = opt_to_array($options['filter-uid']  ?? null);
$F_LIKES  = opt_to_array($options['filter-like'] ?? null);
$F_CMPS   = opt_to_array($options['filter-cmp']  ?? null);
$F_USERS  = opt_to_array($options['filter-user'] ?? null);
$F_WHERE  = (string)($options['where'] ?? '');
$LIST_ONLY = isset($options['list-only']);

// ════════════════════════════════════════════════════════════
// 起動見出し
// ════════════════════════════════════════════════════════════
echo "\n=== START add-home(+LDAP) ===\n";
printf("HOST      : %s\n", $hostname);
printf("HOME_ROOT : %s\n", $HOME_ROOT);
printf("SKEL      : %s\n", $SKEL_DIR);
printf("MODE      : %s (%d)\n", $MODE_STR, $MODE);
printf("CONFIRM   : %s (%s)\n", $DRY_RUN ? 'NO' : 'YES', $DRY_RUN ? 'dry-run' : 'execute');
printf("LDAP      : %s\n", $LDAP_ENABLED ? 'enabled' : 'disabled');
echo "log file  : {$LOGFILE}\n";
echo "local uid : {$MIN_LOCAL_UID}\n";
echo "----------------------------------------------\n";
echo "ldap_host : {$LDAP_URL}\n";
echo "ldap_base : {$LDAP_BS}\n";
echo "ldap_user : {$BIND_DN}\n";
echo "ldap_pass : ".(strlen($BIND_PW)?'********':'(empty)')."\n";
echo "----------------------------------------------\n\n";

// ════════════════════════════════════════════════════════════
/**
 * kanaToRomaji
 *  - kakasi を使って仮名をローマ字へ（半角・小文字化、スペース/アポストロフィ除去）
 */
// ════════════════════════════════════════════════════════════
function kanaToRomaji(string $kana): string {
    $arg = escapeshellarg($kana);
    $romaji = shell_exec("echo $arg | iconv -f UTF-8 -t EUC-JP | kakasi -Ja -Ha -Ka -Ea -s -r | iconv -f EUC-JP -t UTF-8");
    $romaji = strtolower(str_replace([" ", "'"], '', trim((string)$romaji)));
    return $romaji;
}

// ════════════════════════════════════════════════════════════
/**
 * ntlm_hash
 *  - NTハッシュ（MD4(UTF-16LE(password))→hex大文字）
 */
// ════════════════════════════════════════════════════════════
function ntlm_hash(string $password): string {
    $utf16 = mb_convert_encoding($password, "UTF-16LE");
    return strtoupper(bin2hex(hash('md4', $utf16, true)));
}

// ════════════════════════════════════════════════════════════
/**
 * make_ssha
 *  - {SSHA} 4byte salt
 */
// ════════════════════════════════════════════════════════════
function make_ssha(string $plain): string {
    $salt = random_bytes(4);
    return '{SSHA}' . base64_encode(sha1($plain . $salt, true) . $salt);
}

// ════════════════════════════════════════════════════════════
// DB 接続 & 取得
// ════════════════════════════════════════════════════════════
$pgHost = envv('PGHOST','127.0.0.1');
$pgPort = envv('PGPORT','5432');
$pgDb   = envv('PGDATABASE','accounting');
$pgUser = envv('PGUSER','postgres');
$pgPass = getenv('PGPASSWORD') ?: null;

$dsn = "pgsql:host={$pgHost};port={$pgPort};dbname={$pgDb}";
$pdo = new PDO($dsn, $pgUser, $pgPass, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);

$baseSql = "
SELECT 
    j.*,
    p.login_id,
    p.passwd_id,
    p.level_id,
    p.entry,
    p.srv01, p.srv02, p.srv03, p.srv04, p.srv05
FROM public.\"情報個人\" AS j
JOIN public.passwd_tnas AS p
  ON j.cmp_id = p.cmp_id AND j.user_id = p.user_id
";

$wheres = [];
$wheres[] = "( {$target_column_all} )";
$wheres[] = "(p.user_id >= 100 OR p.user_id = 1)";

if ($F_CMPS)  $wheres[] = "j.cmp_id  IN ".sql_in($F_CMPS, true);
if ($F_USERS) $wheres[] = "j.user_id IN ".sql_in($F_USERS, true);
if ($F_UIDS)  $wheres[] = "p.login_id IN ".sql_in($F_UIDS, false);
if ($F_LIKES){
    $likes=[]; foreach($F_LIKES as $pat){ $like=str_replace("'","''",$pat); $likes[]="p.login_id LIKE '%{$like}%'"; }
    if($likes) $wheres[]='('.implode(' OR ',$likes).')';
}
if ($F_WHERE!=='') $wheres[]='('.$F_WHERE.')';

$whereSql = $wheres ? "WHERE\n  ".implode("\n  AND ", $wheres)."\n" : "";
$orderSql = "ORDER BY j.cmp_id ASC, j.user_id ASC";
$sql = $options['sql'] ?? ($baseSql.$whereSql.$orderSql);

log_step("DB: fetch target rows …");
$rows = $pdo->query($sql)->fetchAll();
log_info("DB rows (pre-filter): ".count($rows));

// ★ PHP側でも --filter-uid を厳密適用
if ($F_UIDS) {
    $want = array_map('strtolower', $F_UIDS);
    $rows = array_values(array_filter($rows, function($row) use($want){
        $familyKana = (string)$row['姓かな'];
        $givenKana  = (string)$row['名かな'];
        $middleName = trim((string)($row['ミドルネーム'] ?? ''));
        $uid_plain = strtolower(preg_replace('/[^a-z0-9-]/', '', trim(
            kanaToRomaji($familyKana) . '-' . kanaToRomaji($givenKana) . $middleName, '-'
        )));
        $login = strtolower(preg_replace('/[^a-z0-9-]/', '', (string)($row['login_id'] ?? '')));
        return in_array($uid_plain, $want, true) || in_array($login, $want, true);
    }));
}
log_info("DB rows (post-filter): ".count($rows));

if ($LIST_ONLY) {
    foreach ($rows as $r) {
        $cmp_id=(int)$r['cmp_id']; $user_id=(int)$r['user_id'];
        $uid_plain = strtolower(preg_replace('/[^a-z0-9-]/', '', trim(
            kanaToRomaji((string)$r['姓かな']).'-'.kanaToRomaji((string)$r['名かな']).(string)($r['ミドルネーム']??''), '-'
        )));
        $login = strtolower(preg_replace('/[^a-z0-9-]/', '', (string)($r['login_id'] ?? '')));
        $uid_show = $UID_WITH_PREFIX ? sprintf('%02d-%03d-%s',$cmp_id,$user_id,$uid_plain) : $uid_plain;
        printf("%02d-%03d  uid=%s  login_id=%s  name=%s%s\n",
            $cmp_id, $user_id, $uid_show, $login, (string)$r['姓'], (string)$r['名']
        );
    }
    exit(0);
}
if (!$rows) { log_warn("対象0件です。フィルタ条件を見直してください。"); exit(0); }
if ($ONLY_LIMIT>0 && count($rows)>$ONLY_LIMIT) {
    log_err("抽出件数 ".count($rows)." 件 > --only={$ONLY_LIMIT}。安全のため中止。条件を絞るか --only を増やしてください。");
    exit(1);
}

// ════════════════════════════════════════════════════════════
/**
 * LDAP 接続ユーティリティ
 */
// ════════════════════════════════════════════════════════════
$ldap = ['link'=>null,'base'=>$LDAP_BS,'people_ou'=>$PEOPLE_OU,'using_tools_connection'=>false,'url'=>$LDAP_URL];

/**
 * ldap_open_and_bind
 *  - Tools\Ldap\Connection があれば優先し、なければ ext-ldap を使用
 *  - ldapi の場合は SASL/EXTERNAL を試行
 */
function ldap_open_and_bind(array &$ldap, string $bindDn, string $bindPw, bool $wantExternal=false): bool {
    if (class_exists(Connection::class)) {
        $conn = method_exists(Connection::class,'open') ? Connection::open($ldap['url'])
               : (method_exists(Connection::class,'connect') ? Connection::connect($ldap['url']) : null);
        if ($conn) {
            $ok = $wantExternal && method_exists($conn,'saslExternal') ? $conn->saslExternal()
               : (method_exists($conn,'bind') ? $conn->bind($bindDn,$bindPw) : false);
            if ($ok){ $ldap['link']=$conn; $ldap['using_tools_connection']=true; return true; }
        }
    }
    $link = @ldap_connect($ldap['url']); if (!$link) return false;
    @ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
    @ldap_set_option($link, LDAP_OPT_REFERRALS, 0);
    if ($wantExternal && function_exists('ldap_sasl_bind')) {
        putenv('LDAPTLS_REQCERT=never');
        $ok = @ldap_sasl_bind($link, null, null, 'EXTERNAL');
    } else {
        $ok = @ldap_bind($link, $bindDn, $bindPw);
    }
    if ($ok){ $ldap['link']=$link; return true; }
    return false;
}
function ldap_search_arr($link,$base,$filter,$attrs=['*'],$usingTools=false){
    if ($usingTools && method_exists($link,'search')) {
        $sr = $link->search($base,$filter,$attrs); if(!$sr) return [];
        return $sr->entries ?? [];
    }
    $sr = @ldap_search($link,$base,$filter,$attrs); if(!$sr) return [];
    $e = @ldap_get_entries($link,$sr); if(!is_array($e)) return [];
    return $e;
}
function ldap_exists_uid($link,$base,$uid,$usingTools): bool {
    $e = ldap_search_arr($link,$base,"(uid={$uid})",['dn'],$usingTools);
    if (isset($e['count'])) return ($e['count']??0) > 0;
    return is_array($e) && count($e)>0;
}
function ldap_add_entry($link,$dn,$entry,$usingTools): bool {
    if ($usingTools && method_exists($link,'add')) return (bool)$link->add($dn,$entry);
    return @ldap_add($link,$dn,$entry);
}
function ldap_replace_entry($link,$dn,$entry,$usingTools): bool {
    if ($usingTools && method_exists($link,'modifyReplace')) return (bool)$link->modifyReplace($dn,$entry);
    return @ldap_mod_replace($link,$dn,$entry);
}
function ldap_last_error_str($link,$usingTools): string {
    if ($usingTools && method_exists($link,'lastError')) {
        $e=$link->lastError(); return is_string($e)?$e:json_encode($e,JSON_UNESCAPED_UNICODE);
    }
    return function_exists('ldap_error') ? ldap_error($link) : 'unknown';
}

// LDAP 接続 & OU/SID 準備
$domain_sid = null;
if ($LDAP_ENABLED){
    log_step("LDAP: connect & bind …");
    $ld_ok = ldap_open_and_bind($ldap,$BIND_DN,$BIND_PW, str_starts_with($LDAP_URL,'ldapi://'));
    if (!$ld_ok && str_starts_with($LDAP_URL,'ldapi://')) {
        $ldap['url'] = 'ldaps://ovs-012.e-smile.local';
        log_warn("ldapi bind failed; falling back to {$ldap['url']} …");
        $ld_ok = ldap_open_and_bind($ldap,$BIND_DN,$BIND_PW,false);
    }
    if (!$ld_ok){ log_err("LDAP接続/バインド失敗（URL={$LDAP_URL} / DN={$BIND_DN}）"); exit(1); }

    // People OU 自動検出（未指定時）
    if ($ldap['people_ou']==='') {
        $candidates = ["ou=Users,{$LDAP_BS}","ou=People,{$LDAP_BS}"];
        $found=null;
        foreach($candidates as $cand){
            $e = ldap_search_arr($ldap['link'],$cand,'(objectClass=organizationalUnit)',['ou'],$ldap['using_tools_connection']);
            if ($e){ $found=$cand; break; }
        }
        if (!$found){
            log_err("People OU を自動検出できませんでした。PEOPLE_OU を環境変数で明示してください。");
            exit(1);
        }
        $ldap['people_ou'] = $found;
        log_info("People OU: {$ldap['people_ou']}（自動検出）");
    } else {
        $ldap['people_ou'] = $PEOPLE_OU;
        log_info("People OU: {$ldap['people_ou']}（指定）");
    }

    // ドメインSID 取得
    $e = ldap_search_arr($ldap['link'],$ldap['base'],"(objectClass=sambaDomain)",['sambaSID'],$ldap['using_tools_connection']);
    if ($e){
        if (isset($e['count'])) { if ($e['count']>0) $domain_sid = $e[0]['sambasid'][0] ?? null; }
        elseif (is_array($e) && !empty($e)) { $domain_sid = $e[0]['sambaSID'][0] ?? null; }
    }
    if (!$domain_sid){ log_err("sambaDomain の sambaSID を取得できませんでした"); exit(1); }
    log_info("Domain SID: {$domain_sid}");
}

// ════════════════════════════════════════════════════════════
/**
 * ensure_home
 *  - ホームディレクトリの作成・権限整備（DRY-RUN対応）
 *
 * @param string $dir     目標ディレクトリ
 * @param int    $uidNum  所有ユーザ（数値 UID）
 * @param int    $gidNum  所有グループ（数値 GID）
 * @param bool   $dryRun  true なら作成せずログのみ
 * @param int    $modeOct パーミッション（例: 0750）
 */
// ════════════════════════════════════════════════════════════
function ensure_home(string $dir, int $uidNum, int $gidNum, bool $dryRun, int $modeOct=0750): void {
    if (is_dir($dir)) return;
    if ($dryRun){ log_info("DRY: mkdir {$dir}"); return; }
    if (!@mkdir($dir, $modeOct, true)) { log_warn("ホーム作成失敗: {$dir}"); return; }
    @chmod($dir, $modeOct); @chown($dir, $uidNum); @chgrp($dir, $gidNum);
    log_info("HOME作成: {$dir} (owner={$uidNum}:{$gidNum})");
}

// ════════════════════════════════════════════════════════════
// メインループ（DB → HOME → LDAP upsert）
// ════════════════════════════════════════════════════════════
$sambaUpdates=[]; $done=0; $total=0;

foreach ($rows as $idx=>$row) {
    $cmp_id  = (int)$row['cmp_id'];
    $user_id = (int)$row['user_id'];

    $familyKana = (string)$row['姓かな'];
    $givenKana  = (string)$row['名かな'];
    $middleName = trim((string)($row['ミドルネーム'] ?? ''));

    $uid_plain = strtolower(preg_replace('/[^a-z0-9-]/', '', trim(
        kanaToRomaji($familyKana) . '-' . kanaToRomaji($givenKana) . $middleName, '-'
    )));
    // login_id フォールバック
    if ($uid_plain==='' || $uid_plain==='-' || strlen($uid_plain)<2){
        $fallback = strtolower(preg_replace('/[^a-z0-9-]/','',(string)($row['login_id'] ?? '')));
        if ($fallback!=='' && $fallback!=='-' && strlen($fallback)>=2) {
            $uid_plain = $fallback; log_warn("uid生成→login_id にフォールバック: {$uid_plain}");
        } else { log_warn("無効なuid（cmp_id={$cmp_id}, user_id={$user_id}）→ スキップ"); continue; }
    }
    $uid = $UID_WITH_PREFIX ? sprintf('%02d-%03d-%s',$cmp_id,$user_id,$uid_plain) : $uid_plain;

    $passwd      = (string)$row['passwd_id'];

    // 表示系
    $sei = (string)($row['姓'] ?? '');
    $mei = (string)($row['名'] ?? '');
    $displayName = $sei . $mei;       // ご要望: 「姓＋名」（スペース無し）
    $cn          = $mei . ' ' . $sei; // 既存どおり（"名 姓"）

    $sn        = $sei;
    $givenName = $mei;

    $homeDir   = sprintf('%s/%02d-%03d-%s', $HOME_ROOT, $cmp_id, $user_id, $uid_plain);
    $uidNumber = $cmp_id * 10000 + $user_id;
    $gidNumber = 2000 + $cmp_id;

    ensure_home($homeDir,$uidNumber,$gidNumber,$DRY_RUN,$MODE);

    // ベース属性
    $entryBase = [
        "cn"            => $cn,
        "sn"            => $sn,
        "uid"           => $uid,
        "givenName"     => $givenName,
        "displayName"   => $displayName,
        "uidNumber"     => $uidNumber,
        "gidNumber"     => $gidNumber,
        "homeDirectory" => $homeDir,
        "loginShell"    => "/bin/bash",
    ];
    $entryPass = ["userPassword"=>make_ssha($passwd)];
    $entrySamba = [];
    if ($domain_sid){
        $entrySamba = [
            "sambaSID"             => $domain_sid . "-" . $uidNumber,
            "sambaNTPassword"      => ntlm_hash($passwd),
            "sambaAcctFlags"       => "[U          ]",
            "sambaPwdLastSet"      => time(),
            "sambaPrimaryGroupSID" => $domain_sid . "-" . $gidNumber,
        ];
    }
    $objectClass = ["inetOrgPerson","posixAccount","shadowAccount"];
    if ($domain_sid) $objectClass[]="sambaSamAccount";

    $entry = array_merge(["objectClass"=>$objectClass],$entryBase,$entryPass,$entrySamba);

    // ──────────────────────────────────────────────────────── ★ここから

// level_id → employeeType（例: "adm-cls 1"）
$levelId = (int)($row['level_id'] ?? 0);
$levelId = ($levelId === 0) ? 99 : $levelId;

$grp     = \Tools\Ldap\Support\GroupDef::classify($levelId) ?? '';
// $employeeType = trim(($grp !== '' ? $grp : '') . ($levelId ? (" " . $levelId) : ''));
$employeeType = trim(($grp !== '' ? $grp . ' ' : '') . (string)$levelId);
put_if($entry, 'employeeType', $employeeType);

// 情報個人 由来の連絡先・住所（正規化してから投入）
$mail    = $row['電子メールアドレス'] ?? null;
$tel_raw = $row['電話番号']       ?? null;
$mob_raw = $row['携帯電話']       ?? null;

$tel    = sanitize_phone($tel_raw);
$mobile = sanitize_phone($mob_raw);

// 住所系（postalCodeは別 sanitze、postalAddress は安全版ビルド）
$country = $row['国または地域'] ?? null;
$pref    = $row['都道府県']     ?? null;
$city    = $row['市区町村']     ?? null;
$addr    = $row['番地']         ?? null;
$zip     = sanitize_postal_code($row['郵便番号'] ?? null);

$postalAddress = build_postal_address_safe($country, $pref, $city, $addr);

// inetOrgPerson の一般的属性に反映（空はスキップ）
put_if($entry, 'mail',            $mail);
put_if($entry, 'telephoneNumber', $tel);
put_if($entry, 'mobile',          $mobile);
put_if($entry, 'postalAddress',   $postalAddress);

// 補助属性
put_if($entry, 'postalCode', $zip);   // 郵便番号
put_if($entry, 'st',         $pref);  // 都道府県（半角化は build_postal_address_safe 側で実施）
put_if($entry, 'l',          $city);  // 市区町村
put_if($entry, 'street',     $addr);  // 番地

/*
    // level_id → employeeType（例: "adm-cls 1"）
    $levelId = (int)($row['level_id'] ?? 0);
    $grp     = \Tools\Ldap\Support\GroupDef::classify($levelId) ?? '';
    $employeeType = trim(($grp !== '' ? $grp : '') . ($levelId ? (" " . $levelId) : ''));
    put_if($entry, 'employeeType', $employeeType);

    // 情報個人 由来の連絡先・住所
    $mail   = $row['電子メールアドレス'] ?? null;
    $tel    = $row['電話番号']       ?? null;
    $mobile = $row['携帯電話']       ?? null;

    // 住所系
    $country = $row['国または地域'] ?? null;
    $pref    = $row['都道府県']     ?? null;
    $city    = $row['市区町村']     ?? null;
    $addr    = $row['番地']         ?? null;
    $zip     = $row['郵便番号']     ?? null;

    $postalAddress = build_postal_address($country, $pref, $city, $addr, $zip);

    // inetOrgPerson の一般的属性に反映（空はスキップ）
    put_if($entry, 'mail',            $mail);
    put_if($entry, 'telephoneNumber', $tel);
    put_if($entry, 'mobile',          $mobile);
    put_if($entry, 'postalAddress',   $postalAddress);

    // 検索性向上のための補助属性
    put_if($entry, 'postalCode', $zip);   // 郵便番号
    put_if($entry, 'st',         $pref);  // 都道府県
    put_if($entry, 'l',          $city);  // 市区町村
    put_if($entry, 'street',     $addr);  // 番地
*/

    // ──────────────────────────────────────────────────────── ★ここまで

    // ──────────────────────────────────────────────────────── ★ここから
    // ──────────────────────────────────────────────────────── ★ここから
    // LDAP upsert
    if ($LDAP_ENABLED){
        $peopleBase = $ldap['people_ou'];
        $dn = "uid={$uid},{$peopleBase}";
        $exists = ldap_exists_uid($ldap['link'],$peopleBase,$uid,$ldap['using_tools_connection']);

        if ($DRY_RUN){
            if ($exists){
                printf("Up!  [%3d] [%02d-%03d] [%-10s] [%-20s] [DRY] 更新 [%s......] [%s]\n",
                    $idx+1,$cmp_id,$user_id,$employeeType,$uid,substr($passwd,0,3),$displayName);
            } else {
                printf("Add! [%3d] [%02d-%03d] [%-10s] [%-20s] [DRY] 追加 [%s......] [%s]\n",
                    $idx+1,$cmp_id,$user_id,$employeeType,$uid,substr($passwd,0,3),$displayName);
            }
        } else {
            if ($exists){
                $entryUpdate = $entry; unset($entryUpdate['uid']);

                // 1st try: そのまま置換
                $ok = ldap_replace_entry($ldap['link'],$dn,$entryUpdate,$ldap['using_tools_connection']);

                // 2nd try: 連絡先ブロック除去でリトライ（Invalid syntax 主犯切り分け）
                if (!$ok){
                    $fallback1 = $entryUpdate;
                    if (function_exists('strip_contact_attrs')) { strip_contact_attrs($fallback1); }
                    else {
                        foreach (['mail','telephoneNumber','mobile','postalAddress','postalCode','st','l','street'] as $k) unset($fallback1[$k]);
                    }
                    $ok = ldap_replace_entry($ldap['link'],$dn,$fallback1,$ldap['using_tools_connection']);
                    if ($ok) {
                        log_warn("[$uid] contact attrs caused Invalid syntax → updated without contact attrs");
                    }
                }

                // 3rd try: 既存どおり posix/shadow を間引くフォールバック
                if (!$ok){
                    $fallback2 = $entryUpdate;
                    if (isset($fallback2['objectClass']) && is_array($fallback2['objectClass'])){
                        $fallback2['objectClass'] = array_values(array_diff($fallback2['objectClass'],['posixAccount','shadowAccount']));
                    }
                    $ok = ldap_replace_entry($ldap['link'],$dn,$fallback2,$ldap['using_tools_connection']);
                }

                if ($ok){
                    printf("Up!  [%3d] [%02d-%03d] [%-10s] [%-20s] [CON] 更新 [%s......] [%s]\n",
                        $idx+1,$cmp_id,$user_id,$employeeType,$uid,substr($passwd,0,3),$displayName);
                } else {
                    echo "Err! [$uid] 更新失敗: ".ldap_last_error_str($ldap['link'],$ldap['using_tools_connection'])."\n";
                }
            } else {
                // 追加フロー
                // 1st try: そのまま追加
                $ok = ldap_add_entry($ldap['link'],$dn,$entry,$ldap['using_tools_connection']);

                // 2nd try: 連絡先ブロック除去でリトライ
                if (!$ok){
                    $fallback1 = $entry;
                    if (function_exists('strip_contact_attrs')) { strip_contact_attrs($fallback1); }
                    else {
                        foreach (['mail','telephoneNumber','mobile','postalAddress','postalCode','st','l','street'] as $k) unset($fallback1[$k]);
                    }
                    $ok = ldap_add_entry($ldap['link'],$dn,$fallback1,$ldap['using_tools_connection']);
                    if ($ok) {
                        log_warn("[$uid] contact attrs caused Invalid syntax → added without contact attrs");
                    }
                }

                // 3rd try: 既存どおり posix/shadow を間引くフォールバック
                if (!$ok){
                    $fallback2 = $entry;
                    if (isset($fallback2['objectClass']) && is_array($fallback2['objectClass'])){
                        $fallback2['objectClass'] = array_values(array_diff($fallback2['objectClass'],['posixAccount','shadowAccount']));
                    }
                    $ok = ldap_add_entry($ldap['link'],$dn,$fallback2,$ldap['using_tools_connection']);
                }

                if ($ok){
                    printf("Add! [%3d] [%02d-%03d] [%-10s] [%-20s] [CON] 追加 [%s......] [%s]\n",
                        $idx+1,$cmp_id,$user_id,$employeeType,$uid,substr($passwd,0,3),$displayName);
                } else {
                    echo "Err! [$uid] 追加失敗: ".ldap_last_error_str($ldap['link'],$ldap['using_tools_connection'])."\n";
                }
            }
        }
    }
    // ──────────────────────────────────────────────────────── ★ここまで

    $sambaUpdates[] = [$cmp_id,$user_id,$uid];
    $total++;
}

log_step("LDAP 更新対象: {$total} 件");
if ($LDAP_ENABLED && !$DRY_RUN){
    if (!$ldap['using_tools_connection'] && is_resource($ldap['link'])) @ldap_unbind($ldap['link']);
    elseif ($ldap['using_tools_connection'] && method_exists($ldap['link'],'close')) $ldap['link']->close();
}

// ════════════════════════════════════════════════════════════
// passwd_tnas.samba_id 一括補完（空欄のみ）
// ════════════════════════════════════════════════════════════
if ($sambaUpdates){
    if ($DRY_RUN){
        log_info("DRY: SQL 一括更新 passwd_tnas.samba_id（".count($sambaUpdates)." 件）");
    } else {
        try{
            $placeholders=[]; $params=[];
            foreach($sambaUpdates as $r){
                $placeholders[]="(?::integer, ?::integer, ?::text)";
                $params[]=(int)$r[0]; $params[]=(int)$r[1]; $params[]=(string)$r[2];
            }
            $valuesSql=implode(", ",$placeholders);
            $sqlUpdate="
                UPDATE public.passwd_tnas AS t
                   SET samba_id = v.kakashi_id
                  FROM (VALUES {$valuesSql}) AS v(cmp_id, user_id, kakashi_id)
                 WHERE t.cmp_id  = v.cmp_id
                   AND t.user_id = v.user_id
                   AND (t.samba_id IS NULL OR t.samba_id = '')
            ";
            $pdo->beginTransaction();
            $stmt=$pdo->prepare($sqlUpdate);
            $stmt->execute($params);
            $pdo->commit();
            log_info("SQL OK: passwd_tnas.samba_id 補完 => ".count($sambaUpdates)." 件");
        } catch(Throwable $e){
            if ($pdo->inTransaction()) $pdo->rollBack();
            log_err("samba_id 一括更新失敗: ".$e->getMessage());
        }
    }
} else {
    log_info("samba_id 補完対象なし");
}

echo "\n★ 完了: LDAP/HOME 同期 ".($DRY_RUN?'(DRY-RUN)':'(EXECUTE)')." / 対象 {$total} 件\n";
if ($LDAP_ENABLED){
    echo "★ 例: 検索確認\n";
    echo "  ldapsearch -x -H {$LDAP_URL} -D \"{$BIND_DN}\" -w ******** -b \"{$ldap['people_ou']}\" \"(uid=*)\" dn\n";
}
echo "=== DONE ===\n";

