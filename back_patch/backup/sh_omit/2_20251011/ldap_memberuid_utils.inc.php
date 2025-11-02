<?php
declare(strict_types=1);

/**
 * ldap_memberuid_utils.inc.php
 * -------------------------------------------
 * LDAP グループ(memberUid属性)関連の基本操作ヘルパー
 *
 * 提供関数:
 *   - fetch_users($ds, $peopleDn)
 *       → ou=Users 配下から uid/dn 等を一覧取得
 *   - fetch_group_memberuids($ds, $groupDn)
 *       → posixGroup の memberUid 一覧を取得
 *   - add_memberuid($ds, $groupDn, $uid, $doWrite)
 *       → 指定uidを group の memberUid に追加（既存ならスキップ）
 *
 * 例:
 *   include '/usr/local/etc/openldap/tools/ldap_memberuid_utils.inc.php';
 *   $users = fetch_users($ds, 'ou=Users,dc=e-smile,dc=ne,dc=jp');
 *   foreach ($users as $u) add_memberuid($ds, 'cn=users,ou=Groups,dc=e-smile,dc=ne,dc=jp', $u['uid'], true);
 */

/** LDAP から全ユーザー取得（uid, dn, gidNumber 等を使う） */
if (!function_exists('fetch_users')) {
    function fetch_users(\LDAP\Connection $ds, string $peopleDn): array {
        $attrs = ['uid','gidNumber','uidNumber','cn'];
        $sr = @ldap_search($ds, $peopleDn, '(uid=*)', $attrs);
        if ($sr === false) throw new RuntimeException('search users failed: ' . ldap_error($ds));
        $entries = ldap_get_entries($ds, $sr);
        $users = [];
        for ($i = 0; $i < ($entries['count'] ?? 0); $i++) {
            $e = $entries[$i];
            if (empty($e['uid'][0]) || empty($e['dn'])) continue;
            $users[] = [
                'dn'        => $e['dn'],
                'uid'       => (string)$e['uid'][0],
                'gidNumber' => $e['gidnumber'][0] ?? null,
                'uidNumber' => $e['uidnumber'][0] ?? null,
                'cn'        => $e['cn'][0] ?? '',
            ];
        }
        return $users;
    }
}

/** グループの memberUid 現状取得 */
if (!function_exists('fetch_group_memberuids')) {
    function fetch_group_memberuids(\LDAP\Connection $ds, string $groupDn): array {
        $sr = @ldap_read($ds, $groupDn, '(objectClass=posixGroup)', ['memberUid','cn']);
        if ($sr === false) throw new RuntimeException('group read failed: ' . ldap_error($ds));
        $e = ldap_get_entries($ds, $sr);
        if (($e['count'] ?? 0) < 1) throw new RuntimeException("group not found: {$groupDn}");
        $arr = [];
        if (!empty($e[0]['memberuid'])) {
            for ($i = 0; $i < $e[0]['memberuid']['count']; $i++) {
                $arr[] = (string)$e[0]['memberuid'][$i];
            }
        }
        return $arr;
    }
}

/** memberUid を追加（既存ならスキップ） */
if (!function_exists('add_memberuid')) {
    function add_memberuid(\LDAP\Connection $ds, string $groupDn, string $uid, bool $doWrite): bool {
        $current = fetch_group_memberuids($ds, $groupDn);
        if (in_array($uid, $current, true)) return false;

        if (!$doWrite) return true; // ドライランなら「追加予定」

        $entry = ['memberUid' => $uid];
        if (!@ldap_mod_add($ds, $groupDn, $entry)) {
            $err = ldap_error($ds);
            if (stripos($err, 'Type or value exists') !== false) return false;
            throw new RuntimeException("memberUid add failed uid={$uid}: {$err}");
        }
        return true;
    }
}

