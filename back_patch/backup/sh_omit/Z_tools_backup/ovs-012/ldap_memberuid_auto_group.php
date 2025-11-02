#!/usr/bin/php
<?php
declare(strict_types=1);

/**
 * 各ユーザーの gidNumber に基づき、対応する posixGroup へ memberUid を自動追加。
 *
 * 接続仕様・使い方は users_group と同様:
 *   php ldap_memberuid_auto_group.php [--confirm]
 *
 * 参照環境変数:
 *   LDAP_URL / LDAP_URI
 *   BASE_DN / LDAP_BASE_DN
 *   PEOPLE_OU（既定: ou=Users,${BASE_DN}）
 *   GROUPS_OU（既定: ou=Groups,${BASE_DN}）
 *   BIND_DN / BIND_PW（ldapi/EXTERNAL時は不要）
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

function is_ldapi(string $uri): bool { return str_starts_with($uri, 'ldapi://'); }
function is_ldaps(string $uri): bool { return str_starts_with($uri, 'ldaps://'); }
function is_ldap_plain(string $uri): bool { return str_starts_with($uri, 'ldap://'); }

function ldap_connect_secure(): \LDAP\Connection {
    $uri = envv('LDAP_URL', 'LDAP_URI', 'ldaps://ovs-012.e-smile.local');
    $ds = ldap_connect($uri);
    if (!$ds) throw new RuntimeException("ldap_connect failed: $uri");
    ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);

    if (is_ldapi($uri)) {
        if (!@ldap_sasl_bind($ds, NULL, NULL, 'EXTERNAL')) {
            throw new RuntimeException('SASL EXTERNAL bind failed: '.ldap_error($ds));
        }
        return $ds;
    }
    if (is_ldap_plain($uri)) {
        if (!@ldap_start_tls($ds)) {
            throw new RuntimeException('StartTLS failed: '.ldap_error($ds));
        }
    }
    return $ds;
}

function maybe_simple_bind(\LDAP\Connection $ds): void {
    $uri = envv('LDAP_URL', 'LDAP_URI', '');
    if (is_ldapi($uri)) return;
    $bindDn = envv('BIND_DN', null, 'cn=Admin,dc=e-smile,dc=ne,dc=jp');
    $bindPw = envv('BIND_PW', 'LDAP_ADMIN_PW', '');
    if ($bindDn === '' || $bindPw === '') {
        throw new RuntimeException('BIND_DN/BIND_PW is required for non-ldapi connections.');
    }
    if (!@ldap_bind($ds, $bindDn, $bindPw)) {
        throw new RuntimeException('simple bind failed: '.ldap_error($ds));
    }
}

/** ユーザー取得 */
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

/** gidNumber → グループDN を解決（posixGroup.gidNumber=gid の cn を取得→DN生成） */
function resolve_group_by_gid(\LDAP\Connection $ds, string $groupsDn, string $gid): ?array {
    $filter = sprintf('(gidNumber=%s)', ldap_escape($gid, '', LDAP_ESCAPE_FILTER));
    $sr = @ldap_search($ds, $groupsDn, "(&(objectClass=posixGroup)$filter)", ['cn','memberUid']);
    if ($sr === false) throw new RuntimeException('search group by gidNumber failed: '.ldap_error($ds));
    $entries = ldap_get_entries($ds, $sr);
    if ($entries['count'] < 1) return null;
    $dn = $entries[0]['dn'];
    $cn = $entries[0]['cn'][0] ?? '';
    $members = [];
    if (!empty($entries[0]['memberuid'])) {
        for ($i=0; $i < $entries[0]['memberuid']['count']; $i++) {
            $members[] = $entries[0]['memberuid'][$i];
        }
    }
    return ['dn'=>$dn, 'cn'=>$cn, 'members'=>$members];
}

/** memberUid 追加（既存ならスキップ） */
function add_memberuid(\LDAP\Connection $ds, string $groupDn, string $uid, bool $doWrite): bool {
    $sr = @ldap_read($ds, $groupDn, '(objectClass=posixGroup)', ['memberUid']);
    if ($sr === false) throw new RuntimeException('group read failed: '.ldap_error($ds));
    $e = ldap_get_entries($ds, $sr);
    if ($e['count'] < 1) throw new RuntimeException("group not found: $groupDn");

    $exists = false;
    if (!empty($e[0]['memberuid'])) {
        for ($i=0; $i < $e[0]['memberuid']['count']; $i++) {
            if ($e[0]['memberuid'][$i] === $uid) { $exists = true; break; }
        }
    }
    if ($exists) return false;
    if (!$doWrite) return true;

    $entry = ['memberUid' => $uid];
    if (!@ldap_mod_add($ds, $groupDn, $entry)) {
        $err = ldap_error($ds);
        if (stripos($err, 'Type or value exists') !== false) return false;
        throw new RuntimeException("memberUid add failed uid={$uid}: {$err}");
    }
    return true;
}

function log_line(string $msg): void { fwrite(STDOUT, $msg.(str_ends_with($msg,"\n")?'':"\n")); }

/* ==== メイン ==== */
$confirm = in_array('--confirm', $argv, true);
$baseDn  = envv('BASE_DN','LDAP_BASE_DN','dc=e-smile,dc=ne,dc=jp');
$people  = envv('PEOPLE_OU', null, "ou=Users,{$baseDn}");
$groups  = envv('GROUPS_OU', null, "ou=Groups,{$baseDn}");

try {
    $uri = envv('LDAP_URL','LDAP_URI','');
    log_line("=== auto_group START ===");
    log_line(sprintf("[INFO] URI=%s BASE_DN=%s PEOPLE_OU=%s GROUPS_OU=%s", $uri, $baseDn, $people, $groups));
    if (!$confirm) log_line("[INFO] DRY-RUN: use --confirm to write changes");

    $ds = ldap_connect_secure();
    maybe_simple_bind($ds);

    $users = fetch_users($ds, $people);
    $keep=0; $add=0; $err=0;

    foreach ($users as $u) {
        $uid = $u['uid'];
        $gid = $u['gidNumber'] ?? null;
        if ($gid === null || $gid === '') { $keep++; continue; }

        try {
            $grp = resolve_group_by_gid($ds, $groups, $gid);
            if ($grp === null) { $keep++; continue; }
            $planned = add_memberuid($ds, $grp['dn'], $uid, $confirm);
            if ($planned) {
                $add++;
            } else {
                $keep++;
            }
        } catch (Throwable $e) {
            $err++;
            log_line(sprintf("[ERR ] memberUid add failed uid=%s: %s", $uid, $e->getMessage()));
        }
    }

    $summary = sprintf("SUMMARY: keep=%d add(dry+ok)=%d ok=%d err=%d missingGroup=%d",
                       $keep, $add, $confirm ? $add : 0, $err, 0);
    log_line($summary);
    log_line("=== auto_group DONE ===");
    exit($err ? 2 : 0);
} catch (Throwable $e) {
    log_line("[ERROR] ".$e->getMessage());
    exit(1);
}


