<?php
$ldap_host = "ldap://127.0.0.1";
$ldap_base = "dc=e-smile,dc=ne,dc=jp";
$ldap_user = "cn=admin,$ldap_base";
$ldap_pass = "es0356525566";

$ldap = ldap_connect($ldap_host);
ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
ldap_bind($ldap, $ldap_user, $ldap_pass);

// ① 全ユーザーを取得
$users = ldap_search($ldap, "ou=Users,$ldap_base", "(objectClass=posixAccount)");
$user_entries = ldap_get_entries($ldap, $users);

// ② グループ一覧をマッピング（gidNumber → dn & memberUid）
$groups = ldap_search($ldap, "ou=Groups,$ldap_base", "(objectClass=posixGroup)");
$group_entries = ldap_get_entries($ldap, $groups);
$group_map = [];

foreach ($group_entries as $group) {
    if (!isset($group['gidnumber'][0]) || !isset($group['dn'])) continue;

    $gid = $group['gidnumber'][0];
    $group_dn = $group['dn'];
    $existing_members = [];

    if (isset($group['memberuid'])) {
        for ($i = 0; $i < $group['memberuid']['count']; $i++) {
            $existing_members[] = strtolower($group['memberuid'][$i]);
        }
    }

    $group_map[$gid] = [
        'dn' => $group_dn,
        'members' => $existing_members
    ];
}

// ③ 各ユーザーの gidNumber をもとに追加
foreach ($user_entries as $entry) {
    if (!isset($entry['uid'][0]) || !isset($entry['gidnumber'][0])) continue;

    $uid = $entry['uid'][0];
    $gid = $entry['gidnumber'][0];

    if (!isset($group_map[$gid])) {
        echo "グループ gid=$gid が存在しません（スキップ: $uid）\n";
        continue;
    }

    $group_dn = $group_map[$gid]['dn'];
    $existing = $group_map[$gid]['members'];

    if (in_array(strtolower($uid), $existing)) {
        echo "[$uid] は既に [$group_dn] に登録済み\n";
        continue;
    }

    // memberUid を追加
    $mod = ['memberUid' => [$uid]];
    if (@ldap_mod_add($ldap, $group_dn, $mod)) {
        echo "[$uid] を [$group_dn] に追加しました\n";
        // キャッシュ更新
        $group_map[$gid]['members'][] = strtolower($uid);
    } else {
        echo "[$uid] の追加に失敗しました（重複やエラーの可能性）\n";
    }
}

ldap_unbind($ldap);

// Sambaのキャッシュを自動でクリア（必要に応じて）
if (posix_geteuid() === 0) {
    echo "Sambaキャッシュをフラッシュします...\n";
    system('/usr/bin/net cache flush');
} else {
    echo "Sambaキャッシュのフラッシュは root 権限でのみ可能です\n";
}



/*

各ユーザーのグループを設定する。


ldapsearch -x -LLL -D "cn=admin,dc=e-smile,dc=ne,dc=jp" -w es0356525566 -b "ou=Groups,dc=e-smile,dc=ne,dc=jp" "(cn=users)"
ldapsearch -x -LLL -D "cn=admin,dc=e-smile,dc=ne,dc=jp" -w es0356525566 -b "ou=Groups,dc=e-smile,dc=ne,dc=jp" "(cn=esmile-dev)"
ldapsearch -x -LLL -D "cn=admin,dc=e-smile,dc=ne,dc=jp" -w es0356525566 -b "ou=Groups,dc=e-smile,dc=ne,dc=jp" "(cn=nicori-dev)"
ldapsearch -x -LLL -D "cn=admin,dc=e-smile,dc=ne,dc=jp" -w es0356525566 -b "ou=Groups,dc=e-smile,dc=ne,dc=jp" "(cn=kindaka-dev)"
ldapsearch -x -LLL -D "cn=admin,dc=e-smile,dc=ne,dc=jp" -w es0356525566 -b "ou=Groups,dc=e-smile,dc=ne,dc=jp" "(cn=boj-dev)"
ldapsearch -x -LLL -D "cn=admin,dc=e-smile,dc=ne,dc=jp" -w es0356525566 -b "ou=Groups,dc=e-smile,dc=ne,dc=jp" "(cn=e_game-dev)"
ldapsearch -x -LLL -D "cn=admin,dc=e-smile,dc=ne,dc=jp" -w es0356525566 -b "ou=Groups,dc=e-smile,dc=ne,dc=jp" "(cn=solt-dev)"
ldapsearch -x -LLL -D "cn=admin,dc=e-smile,dc=ne,dc=jp" -w es0356525566 -b "ou=Groups,dc=e-smile,dc=ne,dc=jp" "(cn=social-dev)"

ldapsearch -x -LLL -D "cn=admin,dc=e-smile,dc=ne,dc=jp" -w es0356525566 \
-b "ou=Groups,dc=e-smile,dc=ne,dc=jp" "objectClass=posixGroup" dn | awk '/^dn:/ {print}'


dn: cn=esmile-dev,ou=Groups,dc=e-smile,dc=ne,dc=jp
dn: cn=nicori-dev,ou=Groups,dc=e-smile,dc=ne,dc=jp
dn: cn=kindaka-dev,ou=Groups,dc=e-smile,dc=ne,dc=jp
dn: cn=boj-dev,ou=Groups,dc=e-smile,dc=ne,dc=jp
dn: cn=e_game-dev,ou=Groups,dc=e-smile,dc=ne,dc=jp
dn: cn=solt-dev,ou=Groups,dc=e-smile,dc=ne,dc=jp
dn: cn=social-dev,ou=Groups,dc=e-smile,dc=ne,dc=jp
dn: cn=users,ou=Groups,dc=e-smile,dc=ne,dc=jp
*/
?>
