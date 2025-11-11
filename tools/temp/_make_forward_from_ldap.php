#!/usr/bin/env php
<?php
/**
 * make_forward_from_ldap.php
 * - LDAP から uid 単位/全件で旧メール (mail) を取得
 * - /home/%02d-%03d-* を探して .forward を生成
 * - 内容は 「\local@domain, old1@example.com, old2@...」
 *
 * 使い方:
 *   php make_forward_from_ldap.php --uid 9-102 [--domain esmile-holdings.com] [--confirm]
 *   php make_forward_from_ldap.php --all       [--domain esmile-holdings.com] [--confirm]
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

function arg_has($k){ foreach($GLOBALS['argv'] as $a){ if($a===$k) return true; } return false; }
function arg_val($k,$def=null){
    foreach($GLOBALS['argv'] as $i=>$a){
        if(strpos($a, $k.'=')===0){ return substr($a, strlen($k)+1); }
        if($a===$k && isset($GLOBALS['argv'][$i+1]) && $GLOBALS['argv'][$i+1][0] !== '-') return $GLOBALS['argv'][$i+1];
    }
    return $def;
}

$DO_ALL   = arg_has('--all');
$UID_ONE  = arg_val('--uid', null);
$CONFIRM  = arg_has('--confirm');
$DOMAIN   = arg_val('--domain', getenv('LOCAL_DOMAIN') ?: 'esmile-holdings.com');
$LDAP_URI = arg_val('--ldap-uri', getenv('LDAP_URI') ?: 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi');
$BASE_DN  = arg_val('--base-dn', getenv('LDAP_BASE_DN') ?: 'ou=People,dc=e-smile,dc=ne,dc=jp');

if(!$DO_ALL && !$UID_ONE){
    fwrite(STDERR, "Usage:\n  --uid 9-102 | --all を指定。任意: --domain <local-domain> --confirm --ldap-uri <uri> --base-dn <dn>\n");
    exit(2);
}

echo "=== .forward generator (DRY-RUN=".($CONFIRM?'OFF':'ON').") ===\n";
echo "LDAP: $LDAP_URI / $BASE_DN\n";
echo "LOCAL DOMAIN: $DOMAIN\n";

/* ---------- LDAP Bind ---------- */
$ds = @ldap_connect($LDAP_URI);
if(!$ds){ fwrite(STDERR, "ERROR: ldap_connect 失敗\n"); exit(1); }
ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);

if(strpos($LDAP_URI, 'ldapi://') === 0){
    if(!@ldap_sasl_bind($ds, NULL, NULL, 'EXTERNAL')){
        fwrite(STDERR, "ERROR: SASL/EXTERNAL bind 失敗\n"); exit(1);
    }
} else {
    $binddn = getenv('LDAP_BIND_DN') ?: '';
    $pass   = getenv('LDAP_BIND_PW') ?: '';
    if($binddn){
        if(!@ldap_bind($ds, $binddn, $pass)){
            fwrite(STDERR, "ERROR: ldap_bind 失敗\n"); exit(1);
        }
    } else {
        if(!@ldap_bind($ds)){
            fwrite(STDERR, "ERROR: anonymous bind 失敗（LDAP_BIND_DN を設定可）\n"); exit(1);
        }
    }
}

/* ---------- Search ---------- */
$filter = $DO_ALL ? '(mail=*)' : sprintf('(uid=%s)', my_ldap_escape($UID_ONE));
$attrs  = ['uid','mail'];
$sr = @ldap_search($ds, $BASE_DN, $filter, $attrs);
if(!$sr){ fwrite(STDERR, "ERROR: ldap_search 失敗 (filter=$filter)\n"); exit(1); }
$entries = ldap_get_entries($ds, $sr);
if($entries['count'] == 0){
    echo "0 entry.\n"; exit(0);
}

/* ---------- Process each entry ---------- */
for($i=0; $i<$entries['count']; $i++){
    $e = $entries[$i];

    $uid = isset($e['uid'][0]) ? $e['uid'][0] : null;
    if(!$uid){ continue; }

    if(!preg_match('/^(\d+)-(\d+)$/', $uid, $m)){
        echo "[SKIP] uid=$uid は想定形式ではありません\n";
        continue;
    }
    $cmp_id  = (int)$m[1];
    $user_id = (int)$m[2];

    // /home/%02d-%03d-* を探索
    $home_glob = sprintf('/home/%02d-%03d-*', $cmp_id, $user_id);
    $cands = glob($home_glob, GLOB_NOSORT);
    if(!$cands){
        echo "[WARN] ホーム未検出: pattern=$home_glob (uid=$uid)\n";
        continue;
    }
    $home = $cands[0];

    // 新アカウント名 = 「XX-YYY-」以降
    $base = basename($home);
    $pos  = strpos($base, '-', 0);
    $pos2 = ($pos!==false) ? strpos($base, '-', $pos+1) : false;
    $account = ($pos!==false && $pos2!==false) ? substr($base, $pos2+1) : $base;

    // 旧メール（mail 属性すべて）
    $oldMails = [];
    if(isset($e['mail'])){
        for($j=0; $j < $e['mail']['count']; $j++){
            $maddr = trim($e['mail'][$j]);
            if($maddr !== '') $oldMails[] = $maddr;
        }
    }
    $oldMails = array_values(array_unique($oldMails));

    if(empty($oldMails)){
        echo "[SKIP] mail属性なし uid=$uid ($home)\n";
        continue;
    }

    $localAddr = $account.'@'.$DOMAIN;

    // .forward 内容（ローカル保存 + 旧アドレスへコピー）
    $parts = array_merge(['\\'.$localAddr], $oldMails);
    $content = implode(', ', $parts)."\n";

    $forward = $home.'/.forward';

    echo "[PLAN] uid=$uid home=$home account=$account\n";
    echo "       .forward => ".$content;

    if($CONFIRM){
        if(file_put_contents($forward, $content) === false){
            echo "[ERROR] write failed: $forward\n";
            continue;
        }
        // 所有権・権限
        $pw = @posix_getpwnam($account);
        if($pw){
            @chown($forward, $pw['uid']);
            @chgrp($forward, $pw['gid']);
        }
        @chmod($forward, 0600);

        echo "[DONE] $forward を作成しました\n";
    } else {
        echo "[DRY-RUN] 書き込みなし（--confirm で実行）\n";
    }
}

ldap_unbind($ds);

/* ---------- Helpers ---------- */
/**
 * LDAP フィルタ用エスケープ（ext-ldap があればそれを使用）
 */
function my_ldap_escape(string $s): string {
    if(function_exists('ldap_escape')){
        // PHP ext-ldap が提供するフィルタ用エスケープ
        return ldap_escape($s, '', defined('LDAP_ESCAPE_FILTER') ? LDAP_ESCAPE_FILTER : 0);
    }
    // 簡易フィルタエスケープ（\ * ( ) NUL）
    $search  = ["\\",   "*",   "(",   ")",   "\x00"];
    $replace = ["\\5c", "\\2a","\\28","\\29","\\00"];
    return str_replace($search, $replace, $s);
}


