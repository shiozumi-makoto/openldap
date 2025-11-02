<?php

/*

php add_all_uids_to_users_group.php

memberUid: を、追加する！

# users, Groups, e-smile.ne.jp
dn: cn=users,ou=Groups,dc=e-smile,dc=ne,dc=jp
objectClass: top
objectClass: posixGroup
cn: users
gidNumber: 100
description:: VU5JWOWFsemAmuOCsOODq+ODvOODlw==
memberUid: GameOver
memberUid: a_onuki
memberUid: a_sakurai
memberUid: abe

※）全て削除するには？！

ldapmodify -x -H ldap://192.168.61.2   -D "cn=admin,dc=e-smile,dc=ne,dc=jp"   -w es0356525566   -f delete_memberuid.ldif

ldapwhoami -x -H ldaps://ovs-012.e-smile.local -D "uid=shiozumi-makoto2,ou=Users,dc=e-smile,dc=ne,dc=jp" -w 'WDXzk7RP'

ldapwhoami -x -H ldapi:/// -D "uid=shiozumi-makoto2,ou=Users,dc=e-smile,dc=ne,dc=jp"

ldapsearch -x -H ldapi:/// -b "dc=e-smile,dc=ne,dc=jp" "uid=shiozumi-*" dn


"$LDAP_URL" -D "$BIND_DN" -w "$BIND_PW" \
  -b "$BASE_GROUPS" "(memberUid=shiozumi-makoto2)" dn || true

*/

// URI ldapi://%2Fvar%2Frun%2Fldapi ldaps://ovs-012.e-smile.local


# $ldap_host = "ldap://127.0.0.1";

$ldap_host = "ldaps://ovs-012.e-smile.local";
//$ldap_host = "ldapi:///";

// $ldap_host = "ldapi://%2Fvar%2Frun%2Fldapi";
$ldap_base = "dc=e-smile,dc=ne,dc=jp";
$ldap_user = "cn=admin,$ldap_base";
$ldap_pass = "es0356525566";
$group_dn = "cn=users,ou=Groups,$ldap_base";

/*
$conn = ldap_connect("ldapi://%2Fvar%2Frun%2Fldapi");
ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
ldap_set_option($conn, LDAP_OPT_X_SASL_MECH, "EXTERNAL");
if (!ldap_sasl_bind($conn, null, null, "EXTERNAL")) {
    die("SASL/EXTERNAL bind 失敗: ".ldap_error($conn)."\n");
}

exit;
*/

$ldapconn = ldap_connect($ldap_host);
ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
ldap_set_option($ldapconn, LDAP_OPT_X_SASL_MECH, "EXTERNAL");

/*
if (!ldap_sasl_bind($ldapconn, null, null, "EXTERNAL")) {
    die("SASL/EXTERNAL bind 失敗: ".ldap_error($ldapconn)."\n");
}
*/

if (!ldap_bind($ldapconn, $ldap_user, $ldap_pass)) {
    die("LDAPバインド失敗\n");
}

// ユーザー一覧取得
$search = ldap_search($ldapconn, "ou=Users,$ldap_base", "(objectClass=posixAccount)", ["uid"]);
$entries = ldap_get_entries($ldapconn, $search);

$uids = [];
for ($i = 0; $i < $entries["count"]; $i++) {
    if (isset($entries[$i]["uid"][0])) {
        $uids[] = $entries[$i]["uid"][0];
    }
}
sort($uids);

// 現在の memberUid を取得
$group_search = ldap_search($ldapconn, $group_dn, "(objectClass=posixGroup)", ["memberUid"]);
$group_entries = ldap_get_entries($ldapconn, $group_search);

$current_members = [];
if ($group_entries["count"] > 0 && isset($group_entries[0]["memberuid"])) {
    $current_members = $group_entries[0]["memberuid"];
    unset($current_members["count"]);
}

// ldapsearch -LLL -x -H ldaps://ovs-012.e-smile.local -b "cn=users,ou=Groups,dc=e-smile,dc=ne,dc=jp" "(objectClass=posixGroup)" "memberUid"

/*

ldapmodify -x -H ldaps://ovs-012.e-smile.local \
  -D "cn=Admin,dc=e-smile,dc=ne,dc=jp" -w 'es0356525566' <<'EOF'
dn: cn=users,ou=Groups,dc=e-smile,dc=ne,dc=jp
changetype: modify
delete: memberUid
memberUid: ooshita-2-shuuhei
memberUid: shiozumi-2-makoto
memberUid: shiozumi-3-makoto
memberUid: takahashi-2-ryouya
EOF
*/

// print_r($current_members);
// print_r($entries);

//echo $group_dn;
//exit;

/*
ldapdelete -x -H ldaps://ovs-012.e-smile.local \
  -D "cn=Admin,dc=e-smile,dc=ne,dc=jp" -w 'es0356525566' \
  "uid=shiozumi-2-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp" \
  "uid=shiozumi-3-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp" \
  "uid=takahashi-2-ryouya,ou=Users,dc=e-smile,dc=ne,dc=jp" \
  "uid=ooshita-2-shuuhei,ou=Users,dc=e-smile,dc=ne,dc=jp"

uid=shiozumi-2-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
uid=shiozumi-3-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
uid=takahashi-2-ryouya,ou=Users,dc=e-smile,dc=ne,dc=jp
uid=ooshita-2-shuuhei,ou=Users,dc=e-smile,dc=ne,dc=jp
*/

// 差分を抽出して追加
$new_members = array_diff($uids, $current_members);

//print_r($new_members);
//exit;

if (count($new_members) > 0) {
    $add = ["memberUid" => array_values($new_members)];
    if (ldap_mod_add($ldapconn, $group_dn, $add)) {
        echo "✅ users グループに追加完了: " . implode(", ", $new_members) . "\n";
    } else {
        echo "❌ 追加失敗: " . ldap_error($ldapconn) . "\n";
    }
} else {
    echo "💡 追加対象なし（全ユーザー登録済み）\n";
}

ldap_unbind($ldapconn);

echo 'ldapsearch -x -D "cn=admin,dc=e-smile,dc=ne,dc=jp" -w es0356525566 -b "ou=Groups,dc=e-smile,dc=ne,dc=jp" "cn=users"';
echo "\n";

?>
