<?php

if ($argc === 1) {

    $target_column_1 = "srv03";
    $target_column_2 = "srv04";
    $target_column_3 = "srv05";

} else {
    if ($argc !== 4) {
        echo "使用法: php ldap_id_pass_from_postgres_set.php srv04 srv04 srv05\n";
        exit(1);
    }

    $target_column_1 = $argv[1];
    $target_column_2 = $argv[2];
    $target_column_3 = $argv[3];
}

$aliases_情報個人   = 'j';
$aliases_passwd_tnas = 'p';

$target_column_all = sprintf('%s.%s = 1 or %s.%s = 1 or %s.%s = 1',
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

// -----------------------
// TNAS名（ou名）は固定
// -----------------------
$tnas_name = "Users";

if (!$tnas_name) {
    echo "Err! '$target_column' に対応するTNAS名が見つかりません\n";
    exit(1);
}

// -----------------------
//
// -----------------------
function kanaToRomaji($kana) {
    $kana = escapeshellarg($kana);
    $romaji = shell_exec("echo $kana | iconv -f UTF-8 -t EUC-JP | kakasi -Ja -Ha -Ka -Ea -s -r | iconv -f EUC-JP -t UTF-8");
    $romaji = strtolower(str_replace([" ", "'"], '', trim($romaji)));
    return $romaji;
}

// NTLM変換関数
function ntlm_hash($password) {
    $utf16 = mb_convert_encoding($password, "UTF-16LE");
    return strtoupper(bin2hex(hash('md4', $utf16, true)));
}

// === DB接続（env/.pgpass対応）===
$pgHost = getenv('PGHOST')     ?: '127.0.0.1';
$pgPort = getenv('PGPORT')     ?: '5432';
$pgDb   = getenv('PGDATABASE') ?: 'accounting';
$pgUser = getenv('PGUSER')     ?: 'postgres';
$pgPass = getenv('PGPASSWORD'); // 未設定なら null のまま => .pgpass 使用可

$dsn = "pgsql:host={$pgHost};port={$pgPort};dbname={$pgDb}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

$pdo = isset($pgPass)
    ? new PDO($dsn, $pgUser, $pgPass, $options)
    : new PDO($dsn, $pgUser, null,    $options);

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

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// ★ 追加: DB一括更新用に (cmp_id, user_id, uid) を貯める配列
$sambaUpdates = [];

foreach ($rows as $row) {

    $cmp_id = $row['cmp_id'];
    $user_id = $row['user_id'];

    $familyKana = $row['姓かな'];
    $givenKana  = $row['名かな'];
    $middleName = trim($row['ミドルネーム']); // 同姓同名の重複回避に使う

    $uid = kanaToRomaji($familyKana) . '-' . kanaToRomaji($givenKana) . $middleName;

    // --- uid 正規化 ---
    $uid = strtolower($uid);
    $uid = preg_replace('/[^a-z0-9-]/', '', $uid); // 許可: 英小文字・数字・ハイフン
    $uid = trim($uid, '-');                        // 先頭/末尾ハイフン除去

    // フォールバック: 無効なら login_id を使う
    if ($uid === '' || $uid === '-' || strlen($uid) < 2) {
        $fallback = strtolower($row['login_id'] ?? '');
        $fallback = preg_replace('/[^a-z0-9-]/', '', $fallback);
        $fallback = trim($fallback, '-');
        if ($fallback !== '' && $fallback !== '-' && strlen($fallback) >= 2) {
            $uid = $fallback;
            echo "Wrn! 生成uidが無効のため login_id にフォールバック: {$uid}\n";
        } else {
            echo "Wrn! 無効なuid（cmp_id={$cmp_id}, user_id={$user_id}）→ スキップ\n";
            continue; // これ以上は処理しない
        }
    }

    $passwd = $row['passwd_id'];
    $dn = "uid=$uid,ou=$tnas_name,$ldap_base";

    $cn          = sprintf('%s %s', $row['名'], $row['姓']);
    $sn          = sprintf('%s',     $row['姓']);
    $givenName   = sprintf('%s',     $row['名']);
    $displayName = sprintf('%s%s',   $row['姓'], $row['名']);

    $homeDir = sprintf('/home/%02d-%03d-%s', $cmp_id, $user_id, $uid);
    $homeOld = sprintf('/home/%02d-%03d-%s', $cmp_id, $user_id, $row['login_id']);

    $uidNumber = $cmp_id * 10000 + $user_id;
    $gidNumber = 2000 + $cmp_id;
    $sambaSID  = $domain_sid . "-" . $uidNumber;
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

    // -------------------------------------
    // ホームディレクトリがなければ作成
    // -------------------------------------
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
            printf("Up! [%02d-%03d] [%-20s] 全属性（uid以外；更新）[%s] [%s......] \n", $cmp_id, $user_id, $uid, $displayName, substr($passwd,0,3));
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

    // ★ 追加: このユーザーの (cmp_id, user_id, uid) を DB更新バッファに積む
    $sambaUpdates[] = [$cmp_id, $user_id, $uid];
}

ldap_unbind($ldapconn);

echo "End! LDAP 登録・更新が完了しました\n";
echo 'ldapsearch -x -D "cn=admin,dc=e-smile,dc=ne,dc=jp" -w es0356525566 -b "ou=Users,dc=e-smile,dc=ne,dc=jp"';
echo "\n";

// =============================================================
// ★ 追加: ここから DBで passwd_tnas.samba_id を「最後にまとめて」一括反映
//   - 既存の samba_id が NULL または '' の行だけ補完（上書きしない）
//   - 生成済みの $sambaUpdates (cmp_id, user_id, uid) を VALUES で束ねて UPDATE ... FROM
//   - 1回のトランザクションでコミット
// =============================================================
// === ここから一括反映 ===
if (!empty($sambaUpdates)) {
    try {
        // 1) プレースホルダを「型付き」にする
        $placeholders = [];
        $params = [];
        foreach ($sambaUpdates as $row) {
            // (cmp_id int, user_id int, kakashi_id text)
            $placeholders[] = "(?::integer, ?::integer, ?::text)";
            $params[] = (int)$row[0];   // cmp_id
            $params[] = (int)$row[1];   // user_id
            $params[] = (string)$row[2]; // kakashi_id (= uid)
        }
        $valuesSql = implode(", ", $placeholders);

        // 2) 型は VALUES 側で付けたので、AS v(...) は列名だけ
        $sqlUpdate = "
            UPDATE public.passwd_tnas AS t
               SET samba_id = v.kakashi_id
              FROM (VALUES {$valuesSql}) AS v(cmp_id, user_id, kakashi_id)
             WHERE t.cmp_id  = v.cmp_id
               AND t.user_id = v.user_id
               AND (t.samba_id IS NULL OR t.samba_id = '')
        ";

        // // 上書き版にしたい場合（空でも既存でも更新）:
        // $sqlUpdate = "
        //     UPDATE public.passwd_tnas AS t
        //        SET samba_id = v.kakashi_id
        //       FROM (VALUES {$valuesSql}) AS v(cmp_id, user_id, kakashi_id)
        //      WHERE t.cmp_id  = v.cmp_id
        //        AND t.user_id = v.user_id
        // ";

        $pdo->beginTransaction();
        $stmt = $pdo->prepare($sqlUpdate);
        $stmt->execute($params);
        $pdo->commit();

        echo "OK! passwd_tnas.samba_id を一括補完しました（" . count($sambaUpdates) . "件 対象）。\n";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo "Err! samba_id 一括更新に失敗: " . $e->getMessage() . "\n";
    }
} else {
    echo "Info: 更新対象の samba_id 補完データがありませんでした。\n";
}
