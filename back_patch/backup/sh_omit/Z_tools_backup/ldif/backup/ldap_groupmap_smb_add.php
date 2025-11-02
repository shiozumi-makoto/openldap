<?php
// LDAP接続情報
$ldap_host = "ldap://127.0.0.1";
$ldap_base = "ou=Groups,dc=e-smile,dc=ne,dc=jp";
$ldap_user = "cn=admin,dc=e-smile,dc=ne,dc=jp";
$ldap_pass = "es0356525566";

// LDAP接続
$ldap = ldap_connect($ldap_host);
ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
ldap_bind($ldap, $ldap_user, $ldap_pass);

// posixGroup の一覧を取得
$search = ldap_search($ldap, $ldap_base, "(objectClass=posixGroup)", ["cn"]);
$entries = ldap_get_entries($ldap, $search);
if ($entries["count"] == 0) {
    echo "LDAPに posixGroup が見つかりませんでした。\n";
    exit;
}

// 既存の groupmap を取得
exec("net groupmap list", $output);
$mapped = [];
foreach ($output as $line) {
    if (preg_match('/^(.*?)\s+\(S-1-5-21.*?\)\s+->\s+(.*?)$/', $line, $matches)) {
        $mapped[strtolower(trim($matches[1]))] = true;
    }
}

// グループごとに追加実行
for ($i = 0; $i < $entries["count"]; $i++) {
    $cn = $entries[$i]["cn"][0];
    $lower_cn = strtolower($cn);

    if (isset($mapped[$lower_cn])) {
        echo "[$cn] はすでにマッピングされています。スキップ。\n";
        continue;
    }

    $cmd = "net groupmap add ntgroup=\"$cn\" unixgroup=\"$cn\" type=domain";
    echo "マッピング中：$cmd\n";
    exec($cmd, $cmdout, $retval);
    if ($retval === 0) {
        echo "✅ [$cn] をマッピングしました。\n";
    } else {
        echo "⚠️ [$cn] のマッピングに失敗しました。\n";
    }
}

ldap_unbind($ldap);

/*

※）こちらは、samba との関連なので、groupを追加したら実行する。


[root@ovs-012 tools]# net groupmap list
esmile-dev (S-1-5-21-3566765955-3362818161-2431109675-1001) -> esmile-dev
nicori-dev (S-1-5-21-3566765955-3362818161-2431109675-1002) -> nicori-dev
kindaka-dev (S-1-5-21-3566765955-3362818161-2431109675-1003) -> kindaka-dev
boj-dev (S-1-5-21-3566765955-3362818161-2431109675-1004) -> boj-dev
e_game-dev (S-1-5-21-3566765955-3362818161-2431109675-1005) -> e_game-dev
solt-dev (S-1-5-21-3566765955-3362818161-2431109675-1006) -> solt-dev
social-dev (S-1-5-21-3566765955-3362818161-2431109675-1007) -> social-dev
*/

?>
