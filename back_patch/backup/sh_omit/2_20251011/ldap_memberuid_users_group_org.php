#!/usr/bin/php
<?php
declare(strict_types=1);

/**
 * 全ユーザー(ou=Users)を cn=users へ memberUid で所属させる。
 *
 * 接続仕様:
 *  - 環境変数 LDAP_URL / LDAP_URI を優先
 *    * ldapi://%2Fvar%2Frun%2Fldapi → SASL/EXTERNAL で接続（パスワード不要）
 *    * ldap://host → StartTLS を必ず実行してから bind
 *    * ldaps://host → そのまま bind
 *  - BIND_DN / BIND_PW を使用（ldapi/EXTERNAL時は未使用）
 *
 * 使い方:
 *   php ldap_memberuid_users_group.php [--confirm]
 *
 * 必要な環境変数（なければ既定値）:
 *   LDAP_URL / LDAP_URI            接続URI（例: ldapi://%2Fvar%2Frun%2Fldapi もしくは ldaps://FQDN）
 *   BASE_DN / LDAP_BASE_DN         例: dc=e-smile,dc=ne,dc=jp
 *   PEOPLE_OU                      例: ou=Users,dc=e-smile,dc=ne,dc=jp（未指定なら BASE_DN から構築）
 *   GROUPS_OU                      例: ou=Groups,dc=e-smile,dc=ne,dc=jp（未指定なら BASE_DN から構築）
 *   USERS_GROUP_DN                 例: cn=users,ou=Groups,${BASE_DN}（未指定なら上記で自動）
 *   BIND_DN / BIND_PW              管理者バインドに利用（ldapi/EXTERNAL時は不要）
 */

function envv(string $key, ?string $alt = null, ?string $def = null): ?string {
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    if ($alt) {
        $v = getenv($alt);
        if ($v !== false && $v !== '') return $v;
    }
    return $def;
}

function env_first(array $keys, ?string $def = null): ?string {
    foreach ($keys as $k) {
        $v = getenv($k);
        if ($v !== false && $v !== '') return $v;
    }
    return $def;
}

function show_or_mask(?string $uri): string {
    // null または空白だけ（Unicode空白含む）ならマスク
    return ($uri === null || preg_match('/^\s*$/u', $uri))
        ? '[*** 未設定 ***]'
        : $uri;
}

function is_ldapi(string $uri): bool { return str_starts_with($uri, 'ldapi://'); }
function is_ldaps(string $uri): bool { return str_starts_with($uri, 'ldaps://'); }
function is_ldap_plain(string $uri): bool { return str_starts_with($uri, 'ldap://'); }

function ldap_connect_secure(): \LDAP\Connection {
//  $uri = envv('LDAP_URL','LDAP_URI','');
	$uri = env_first(['LDAP_URL', 'LDAP_URI', 'LDAPURI']);
    $ds = ldap_connect($uri);

    if (!$ds) throw new RuntimeException("ldap_connect failed: $uri");

    ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);

    if (is_ldapi($uri)) {
        // EXTERNAL (root実行前提)
        if (!@ldap_sasl_bind($ds, NULL, NULL, 'EXTERNAL')) {
            throw new RuntimeException('SASL EXTERNAL bind failed: '.ldap_error($ds));
        }
        // 以降の ldap_bind は不要
        return $ds;
    }

    if (is_ldap_plain($uri)) {
        // StartTLS を必須に
        if (!@ldap_start_tls($ds)) {
            throw new RuntimeException('StartTLS failed: '.ldap_error($ds));
        }
    }

    // ldaps:// はそのまま（後で simples bind）
    return $ds;
}

/** simples bind（ldapi/EXTERNALのときは NOOP） */
function maybe_simple_bind(\LDAP\Connection $ds): void {
//  $uri = envv('LDAP_URL','LDAP_URI','');
	$uri = env_first(['LDAP_URL', 'LDAP_URI', 'LDAPURI']);
    if (is_ldapi($uri)) return; // 既に EXTERNAL bind 済み

    $bindDn = envv('BIND_DN', null, 'cn=Admin,dc=e-smile,dc=ne,dc=jp');
    $bindPw = envv('BIND_PW', 'LDAP_ADMIN_PW', '');

//  $bindPw = envv('BIND_PW', 'LDAP_ADMIN_PW', 'es0356525566');
//	echo $bindDn;
//	echo $bindPw;
//	print_r($ds);
//	exit;

    if ($bindDn === '' || $bindPw === '') {
        throw new RuntimeException('BIND_DN/BIND_PW is required for non-ldapi connections.');
    }
    if (!@ldap_bind($ds, $bindDn, $bindPw)) {
        throw new RuntimeException('simple bind failed: '.ldap_error($ds));
    }
}

/** LDAP から全ユーザー取得（uid, dn, gidNumber を使う） */
function fetch_users(\LDAP\Connection $ds, string $peopleDn): array {
    $attrs = ['uid','gidNumber','uidNumber','cn'];
    $sr = @ldap_search($ds, $peopleDn, '(uid=*)', $attrs);
    if ($sr === false) throw new RuntimeException('search users failed: '.ldap_error($ds));
    $entries = ldap_get_entries($ds, $sr);
    $users = [];
    for ($i=0; $i < $entries['count']; $i++) {
        $e = $entries[$i];
        if (empty($e['uid'][0]) || empty($e['dn'])) continue;
        $users[] = [
            'dn' => $e['dn'],
            'uid' => $e['uid'][0],
            'gidNumber' => $e['gidnumber'][0] ?? null,
            'uidNumber' => $e['uidnumber'][0] ?? null,
            'cn' => $e['cn'][0] ?? '',
        ];
    }
    return $users;
}

/** グループの memberUid 現状取得 */
function fetch_group_memberuids(\LDAP\Connection $ds, string $groupDn): array {
    $sr = @ldap_read($ds, $groupDn, '(objectClass=posixGroup)', ['memberUid','cn']);
    if ($sr === false) throw new RuntimeException('group read failed: '.ldap_error($ds));
    $e = ldap_get_entries($ds, $sr);
    if ($e['count'] < 1) throw new RuntimeException("group not found: $groupDn");
    $arr = [];
    if (!empty($e[0]['memberuid'])) {
        for ($i=0; $i < $e[0]['memberuid']['count']; $i++) {
            $arr[] = $e[0]['memberuid'][$i];
        }
    }
    return $arr;
}

/** memberUid を追加（既存ならスキップ） */
function add_memberuid(\LDAP\Connection $ds, string $groupDn, string $uid, bool $doWrite): bool {
    // 既存チェック
    $current = fetch_group_memberuids($ds, $groupDn);
    if (in_array($uid, $current, true)) return false;

    if (!$doWrite) return true; // ドライランなら「追加予定」

    $entry = ['memberUid' => $uid];
    // 追加
    if (!@ldap_mod_add($ds, $groupDn, $entry)) {
        $err = ldap_error($ds);
        // 競合（他プロセスが先に追加）なら実質OK扱い
        if (stripos($err, 'Type or value exists') !== false) return false;
        throw new RuntimeException("memberUid add failed uid={$uid}: {$err}");
    }
    return true;
}

/**
 * ▼ ログ出力リダイレクト例
 *
 *  php script.php > out.log         標準出力だけを out.log に書く（エラーは画面に出る）
 *  php script.php 2> err.log        エラーだけを err.log に書く（標準出力は画面に出る）
 *  php script.php > all.log 2>&1    標準出力とエラーの両方を all.log にまとめる
 *  php script.php > /dev/null 2>&1  すべての出力を捨てる（静かに実行）
 *
 *  （補足）
 *   - STDOUT: 正常出力
 *   - STDERR: エラー出力
 */

function log_line(string $msg): void { fwrite(STDOUT, $msg.(str_ends_with($msg,"\n")?'':"\n")); }

/**
 * ▼ パスワードマスク！
 *
 */

function mask_pw(?string $pw): string { return ($pw!==null && $pw!=='') ? '********' : ''; }

/* ==== メイン ==== */

$confirm = in_array('--confirm', $argv, true);

$baseDn  = envv('BASE_DN','LDAP_BASE_DN','dc=e-smile,dc=ne,dc=jp');
$people  = envv('PEOPLE_OU', null, "ou=Users,{$baseDn}");
$groups  = envv('GROUPS_OU', null, "ou=Groups,{$baseDn}");
$usersGroupDn = envv('USERS_GROUP_DN', null, "cn=users,{$groups}");

/*
echo "\n";
echo $baseDn;
echo "\n";
echo $people;
echo "\n";
echo $groups;
echo "\n";
echo $usersGroupDn;
echo "\n";
*/

/*
export LDAP_URI='ldapi:///'
export LDAP_URI='ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'
unset  LDAP_CONF LDAP_URI
*/

try {
    $uri = envv('LDAP_URL','LDAP_URI','');
//	$uri = env_first(['LDAP_URL', 'LDAP_URI', 'LDAPURI']);

    log_line(" === users_group START === ");
	log_line(sprintf("[INFO] URI=%s \n       BASE_DN=%s PEOPLE_OU=%s GROUPS_OU=%s", show_or_mask($uri), $baseDn, $people, $groups));
    if (!$confirm) log_line("[INFO] DRY-RUN: use --confirm to write changes");

    $ds = ldap_connect_secure();

    maybe_simple_bind($ds);

    $users = fetch_users($ds, $people);
    $keep = 0; $addTargets = 0; $ok = 0; $err = 0;

    foreach ($users as $u) {
        $uid = $u['uid'];
        try {
            $planned = add_memberuid($ds, $usersGroupDn, $uid, $confirm);
            if ($planned) {
                $addTargets++;
                if ($confirm) $ok++;
            } else {
                $keep++;
            }
        } catch (Throwable $e) {
            $err++;
            log_line(sprintf("[ERR ] memberUid add failed uid=%s: %s", $uid, $e->getMessage()));
        }
    }

    log_line(sprintf("SUMMARY: group=users keep=%d addTargets=%d ok=%d err=%d", $keep, $addTargets, $ok, $err));
    log_line("=== users_group DONE ===");
    exit($err ? 2 : 0);
} catch (Throwable $e) {
    log_line("[ERROR] ".$e->getMessage());
    exit(1);
}
