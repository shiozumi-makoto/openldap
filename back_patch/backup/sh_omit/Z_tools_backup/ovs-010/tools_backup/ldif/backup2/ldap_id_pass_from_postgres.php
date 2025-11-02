<?php

if ($argc === 1) {

	$target_column_1 = "srv03";
	$target_column_2 = "srv04";
	$target_column_3 = "srv05";


} else {
	if ($argc !== 4) {
    	echo "使用法: php sync_ldap_from_postgres.php srv04 srv04 srv5\n";
	    exit(1);
	}

	$target_column_1 = $argv[1];
	$target_column_2 = $argv[2];
	$target_column_3 = $argv[3];
}


$aliases_情報個人 = 'j';
$aliases_passwd_tnas = 'p';


$target_column_all = sprintf( '%s.%s = 1 or %s.%s = 1 or %s.%s = 1',
		$aliases_passwd_tnas,	$target_column_1, 
		$aliases_passwd_tnas,	$target_column_2, 
		$aliases_passwd_tnas,	$target_column_3
);

// LDAP設定
$ldap_host = "ldap://127.0.0.1";
$ldap_base = "dc=e-smile,dc=ne,dc=jp";
$ldap_user = "cn=admin,$ldap_base";
$ldap_pass = "es0356525566";

include "/var/www/happy/htdocs/ver401/user/all_user/nas_user_id.inc";

// TNAS名（ou名）は固定
$tnas_name = "Users";

if (!$tnas_name) {
    echo "Err! '$target_column' に対応するTNAS名が見つかりません\n";
    exit(1);
}

// NTLM変換関数
function ntlm_hash($password) {
    $utf16 = mb_convert_encoding($password, "UTF-16LE");
    return strtoupper(bin2hex(hash('md4', $utf16, true)));
}

// DB接続
$pdo = new PDO("pgsql:host=192.168.61.10;dbname=accounting", "esmile_user", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);


$sql  = "
SELECT 
    j.*,
    p.login_id,
    p.passwd_id,
    p.level_id,
    p.entry,
    p.srv01,
    p.srv02,
    p.srv03,
    p.srv04,
    p.srv05
FROM 
    public.\"情報個人\" AS j
INNER JOIN 
    public.passwd_tnas AS p
ON 
    j.cmp_id = p.cmp_id AND j.user_id = p.user_id
WHERE ( {$target_column_all} ) and ( p.user_id >= 100 or p.user_id = 1 ) 
ORDER BY j.cmp_id ASC, j.user_id ASC";


# $sql  = "SELECT cmp_id, user_id, login_id, passwd_id FROM public.passwd_tnas WHERE ( {$target_column_all} ) and user_id >= 100 ORDER BY cmp_id ASC, user_id ASC";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

#print_r($rows);
#echo $sql."\n";
#exit;

// LDAP接続
$ldapconn = ldap_connect($ldap_host);
ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
if (!$ldapconn || !ldap_bind($ldapconn, $ldap_user, $ldap_pass)) {
    echo "Err! LDAP接続エラー\n";
    exit(1);
}

// Samba SID を自動取得
$search = ldap_search($ldapconn, $ldap_base, "(objectClass=sambaDomain)");
if (!$search) {
    echo "Err! sambaDomain 検索失敗(a)\n";
    exit(1);
}
$entries = ldap_get_entries($ldapconn, $search);
if ($entries["count"] > 0 && isset($entries[0]["sambasid"][0])) {
    $domain_sid = $entries[0]["sambasid"][0];
    echo "SID! 取得したドメインSID: $domain_sid\n";
} else {
    echo "Err! sambaSID が取得できませんでした\n";
    exit(1);
}

// ユーザー登録処理

/*
dn: uid=abe,ou=Users,dc=e-smile,dc=ne,dc=jp
objectClass: inetOrgPerson
objectClass: posixAccount
objectClass: shadowAccount
objectClass: sambaSamAccount
cn: 阿部 太郎
sn: 阿部
givenName: 太郎
displayName: 阿部 太郎
uid: abe
uidNumber: 90103
gidNumber: 2009
homeDirectory: /home/09-0103-abe
loginShell: /bin/bash


dn: uid=abe,ou=Users,dc=e-smile,dc=ne,dc=jp
objectClass: inetOrgPerson
objectClass: posixAccount
objectClass: shadowAccount
objectClass: sambaSamAccount

cn: 09-0103-abe
sn: abe
uid: abe
uidNumber: 90103
gidNumber: 2009
homeDirectory: /home/09-0103-abe
*/


foreach ($rows as $row) {

    $cmp_id = $row['cmp_id'];
    $user_id = $row['user_id'];
    $uid = $row['login_id'];
    $passwd = $row['passwd_id'];

    $dn = "uid=$uid,ou=$tnas_name,$ldap_base";
	$cn = sprintf('%s %s', $row['名'], $row['姓']);
	$sn = sprintf('%s', $row['姓']);
	$givenName = sprintf('%s', $row['名']);
	$displayName = sprintf('%s%s', $row['姓'], $row['名']);

    $homeDir = sprintf('/home/%02d-%03d-%s', $cmp_id, $user_id, $uid);

    $uidNumber = $cmp_id * 10000 + $user_id;
    $gidNumber = 2000 + $cmp_id;
    $sambaSID = $domain_sid . "-" . $uidNumber;
    $sambaPrimaryGroupSID = $domain_sid . "-" . $gidNumber;

    $salt = random_bytes(4);
    $ssha = '{SSHA}' . base64_encode(sha1($passwd . $salt, true) . $salt);
    $ntlm = ntlm_hash($passwd);
    $pwdLastSet = time();

    if (!preg_match('/^[0-9A-F]+$/', $ntlm)) {
        echo "Err! [$uid] NTLM hash の作成失敗\n";
        continue;
    }

    $entry = [
        "objectClass" => ["inetOrgPerson", "posixAccount", "shadowAccount", "sambaSamAccount"],
        "cn" => $cn,
        "sn" => $sn,
        "uid" => $uid,
        "givenName" => $givenName,
        "displayName" => $displayName,
        "uidNumber" => $uidNumber,
        "gidNumber" => $gidNumber,
        "homeDirectory" => $homeDir,
        "loginShell" => "/bin/bash",
        "userPassword" => $ssha,
        "sambaSID" => $sambaSID,
        "sambaNTPassword" => $ntlm,
        "sambaAcctFlags" => "[U          ]",
        "sambaPwdLastSet" => $pwdLastSet,
        "sambaPrimaryGroupSID" => $sambaPrimaryGroupSID
    ];

    // ホームディレクトリがなければ作成
    if (!is_dir($homeDir)) {
        if (mkdir($homeDir, 0700, true)) {
            echo "Mk!  [$uid] ホームディレクトリ $homeDir を作成しました\n";
            $owner = "$uidNumber:$gidNumber";
            $escapedDir = escapeshellarg($homeDir);
            $escapedOwner = escapeshellarg($owner);
            exec("chown -R $escapedOwner $escapedDir", $output, $ret);
            if ($ret === 0) {
                echo "Ch!  [$uid] 所有権 $owner に設定しました\n";
            } else {
                echo "Wrn! [$uid] 所有権の設定に失敗しました（手動確認推奨）\n";
            }
        } else {
            echo "Err! [$uid] ホームディレクトリ作成失敗（$homeDir）\n";
        }
    }

    $result = ldap_search($ldapconn, "ou=$tnas_name,$ldap_base", "(uid=$uid)");
    if (!$result) {
        echo "Err! [$uid] LDAP検索失敗(b)\n";
        continue;
    }

    $entries = ldap_get_entries($ldapconn, $result);

    if ($entries["count"] > 0) {
        $entry_update = $entry;
        unset($entry_update["uid"]);

        if (ldap_mod_replace($ldapconn, $dn, $entry_update)) {
			printf("Up! [%02d-%03d] [%-20s] 全属性（uid以外；更新）[%s]\n", $cmp_id, $user_id, $uid, $displayName);

        } else {
            echo "Err! [$uid] 更新失敗: " . ldap_error($ldapconn) . "\n";
        }
    } else {
        if (ldap_add($ldapconn, $dn, $entry)) {
            echo "Add! [$uid] を新規登録しました\n";
        } else {
            echo "Err! [$uid] 登録失敗: " . ldap_error($ldapconn) . "\n";
        }
    }
}

ldap_unbind($ldapconn);
echo "End! LDAP 登録・更新が完了しました\n";
# echo 'ldapsearch -x -D "cn=admin,dc=e-smile,dc=ne,dc=jp" -w es0356525566 -b "ou=Users,dc=e-smile,dc=ne,dc=jp"';
