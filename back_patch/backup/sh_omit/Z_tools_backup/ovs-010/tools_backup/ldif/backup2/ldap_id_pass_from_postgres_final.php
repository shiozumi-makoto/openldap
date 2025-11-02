<?php

if ($argc === 1) {
    $target_column_1 = "srv03";
    $target_column_2 = "srv04";
    $target_column_3 = "srv05";
} else {
    if ($argc !== 4) {
        echo "\n使用法: php sync_ldap_from_postgres.php srv04 srv04 srv05\n";
        exit(1);
    }
    $target_column_1 = $argv[1];
    $target_column_2 = $argv[2];
    $target_column_3 = $argv[3];
}

$aliases_情報個人 = 'j';
$aliases_passwd_tnas = 'p';

$target_column_all = sprintf(
    '%s.%s = 1 or %s.%s = 1 or %s.%s = 1',
    $aliases_passwd_tnas, $target_column_1,
    $aliases_passwd_tnas, $target_column_2,
    $aliases_passwd_tnas, $target_column_3
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

function kanaToRomaji($kana) {
    $kana = mb_convert_kana($kana, 'c'); // 全角→ひらがな（冗長対策）

    $map = [
        "きゃ" => "kya", "きゅ" => "kyu", "きょ" => "kyo",
        "しゃ" => "sha", "しゅ" => "shu", "しょ" => "sho",
        "ちゃ" => "cha", "ちゅ" => "chu", "ちょ" => "cho",
        "にゃ" => "nya", "にゅ" => "nyu", "にょ" => "nyo",
        "ひゃ" => "hya", "ひゅ" => "hyu", "ひょ" => "hyo",
        "みゃ" => "mya", "みゅ" => "myu", "みょ" => "myo",
        "りゃ" => "rya", "りゅ" => "ryu", "りょ" => "ryo",
        "ぎゃ" => "gya", "ぎゅ" => "gyu", "ぎょ" => "gyo",
        "じゃ" => "ja", "じゅ" => "ju", "じょ" => "jo",
        "びゃ" => "bya", "びゅ" => "byu", "びょ" => "byo",
        "ぴゃ" => "pya", "ぴゅ" => "pyu", "ぴょ" => "pyo",
        "ふぁ" => "fa", "ふぃ" => "fi", "ふぇ" => "fe", "ふぉ" => "fo",
        // 長音や特殊音は省略

        "あ"=>"a","い"=>"i","う"=>"u","え"=>"e","お"=>"o",
        "か"=>"ka","き"=>"ki","く"=>"ku","け"=>"ke","こ"=>"ko",
        "さ"=>"sa","し"=>"shi","す"=>"su","せ"=>"se","そ"=>"so",
        "た"=>"ta","ち"=>"chi","つ"=>"tsu","て"=>"te","と"=>"to",
        "な"=>"na","に"=>"ni","ぬ"=>"nu","ね"=>"ne","の"=>"no",
        "は"=>"ha","ひ"=>"hi","ふ"=>"fu","へ"=>"he","ほ"=>"ho",
        "ま"=>"ma","み"=>"mi","む"=>"mu","め"=>"me","も"=>"mo",
        "や"=>"ya","ゆ"=>"yu","よ"=>"yo",
        "ら"=>"ra","り"=>"ri","る"=>"ru","れ"=>"re","ろ"=>"ro",
        "わ"=>"wa","を"=>"wo","ん"=>"n",
        "が"=>"ga","ぎ"=>"gi","ぐ"=>"gu","げ"=>"ge","ご"=>"go",
        "ざ"=>"za","じ"=>"ji","ず"=>"zu","ぜ"=>"ze","ぞ"=>"zo",
        "だ"=>"da","ぢ"=>"ji","づ"=>"zu","で"=>"de","ど"=>"do",
        "ば"=>"ba","び"=>"bi","ぶ"=>"bu","べ"=>"be","ぼ"=>"bo",
        "ぱ"=>"pa","ぴ"=>"pi","ぷ"=>"pu","ぺ"=>"pe","ぽ"=>"po"
    ];

f
 
    $result = '';
    for ($i = 0; $i < mb_strlen($kana); ) {
        // 促音「っ」処理（次の1文字の先頭子音を重ねる）
        $char = mb_substr($kana, $i, 1);
        if ($char === 'っ') {
            $next = mb_substr($kana, $i + 1, 1);
            $romaji = $map[$next] ?? '';
            if ($romaji !== '') {
                $result .= substr($romaji, 0, 1); // 子音を1文字追加
            }
            $i++;
            continue;
        }

        // 2文字マッチ優先
        $pair = mb_substr($kana, $i, 2);
        if (isset($map[$pair])) {
            $result .= $map[$pair];
            $i += 2;
        } elseif (isset($map[$char])) {
            $result .= $map[$char];
            $i += 1;
        } else {
            $result .= $char; // 未知文字
            $i += 1;
        }
    }

    return strtolower($result);
}


/*
// ローマ字変換関数（kakasi利用）
function kanaToRomaji($kana) {
    $kana = escapeshellarg($kana);
    $romaji = shell_exec("echo $kana | kakasi -Ja -Ha -Ka -Ea -s");
    return trim(strtolower(str_replace(' ', '', $romaji)));
}
*/

// LDAP 削除関数
function deleteLdapUserAndHome($ldapconn, $uid, $ldap_base) {
    $dn = "uid=$uid,ou=Users,$ldap_base";
    if (@ldap_delete($ldapconn, $dn)) {
        echo "削除しました: $dn\n";
    } else {
        echo "削除失敗: $dn\n";
    }

    $homeDir = "/home/$uid";
    if (is_dir($homeDir)) {
        exec("rm -rf " . escapeshellarg($homeDir));
        echo "削除しました: $homeDir\n";
    }
}

// パスワードのNTLMハッシュ生成
function ntlm_hash($password) {
    $utf16 = mb_convert_encoding($password, 'UTF-16LE');
    return strtoupper(hash('md4', $utf16));
}

// ユーザーのLDAPエントリ作成関数
function createLdapUser($ldapconn, $uid, $cn, $password, $ldap_base, $home_path) {
    $dn = "uid=$uid,ou=Users,$ldap_base";
    $entry = [
        "objectClass" => ["top", "person", "organizationalPerson", "inetOrgPerson", "posixAccount", "sambaSamAccount"],
        "uid" => $uid,
        "cn" => $cn,
        "sn" => $cn,
        "userPassword" => "{MD5}" . base64_encode(pack("H*", md5($password))),
        "uidNumber" => rand(20000, 29999),
        "gidNumber" => 100,
        "homeDirectory" => $home_path,
        "loginShell" => "/bin/bash",
        "sambaSID" => "S-1-5-21-" . rand(1000000000, 2000000000),
        "sambaNTPassword" => ntlm_hash($password),
        "sambaAcctFlags" => "[U]"
    ];
    if (ldap_add($ldapconn, $dn, $entry)) {
        echo "作成しました: $dn\n";
    } else {
        echo "作成失敗: $dn\n";
    }
}

// ----------------------
// DB接続
// ----------------------

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
$users= $stmt->fetchAll(PDO::FETCH_ASSOC);

//print_r($users);
//echo $sql."\n";
//exit;


// ここから各ユーザー処理（$rowはPostgreSQLからの1レコードと想定）
foreach ($users as $row) {
    $old_uid = $row['login_id'];
    $familyKana = $row['姓かな'];
    $givenKana  = $row['名かな'];
    $password = $row['pass'] ?? 'default123';

    $uid = kanaToRomaji($familyKana) . '-' . kanaToRomaji($givenKana);
    $cn  = $row['姓'] . $row['名'];

    // LDAP接続
    $ldapconn = ldap_connect($ldap_host);
    ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
    if (!ldap_bind($ldapconn, $ldap_user, $ldap_pass)) {
        die("LDAPバインド失敗\n");
    }

//	古いアカウントが違うIDなら削除
//  if ($old_uid !== $uid) {
//      deleteLdapUserAndHome($ldapconn, $old_uid, $ldap_base);
//  }

    // 新規LDAPアカウント作成
    $romaji_name = $uid;
    $home_id = sprintf("%02d-%03d-%s", $row['cmp_id'], $row['user_id'], $romaji_name);
    $home_path = "/home/" . $home_id;
    createLdapUser($ldapconn, $uid, $cn, $password, $ldap_base, $home_path);

    ldap_unbind($ldapconn);
}

echo "End! LDAP 登録・更新が完了しました\n";
# echo 'ldapsearch -x -D "cn=admin,dc=e-smile,dc=ne,dc=jp" -w es0356525566 -b "ou=Users,dc=e-smile,dc=ne,dc=jp"';

