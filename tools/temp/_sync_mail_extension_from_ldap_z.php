#!/usr/bin/env php
<?php
/**
 * sync_mail_extension_from_ldap2.php
 *
 * - LDAP から mail を取得し、PostgreSQL public."情報個人メール拡張" に UPSERT
 * - ベースDN/主キーの解釈と書込列はモードで切替:
 *     * --People (別名 --Peple):  base=ou=People,dc=e-smile,dc=ne,dc=jp
 *         - uid: "<cmp>-<user(3桁)>" → cmp_id,user_id に分割
 *         - 書込列: "電子メールアドレスLDAP登録"
 *     * --Users:   base=ou=Users,dc=e-smile,dc=ne,dc=jp
 *         - uidNumber: 例 50101 -> (cmp_id=5, user_id=0101), 120198 -> (12, 0198)
 *         - 書込列: "電子メールアドレス自社サーバー"
 *
 * 例:
 *   php sync_mail_extension_from_ldap2.php --People --confirm --config=/usr/local/etc/openldap/tools/tools.conf
 *   php sync_mail_extension_from_ldap2.php --Users  --confirm
 */

/*
CREATE TABLE IF NOT EXISTS public.passwd_mail
(
    cmp_id integer NOT NULL,
    user_id integer NOT NULL,
    flag_id integer NOT NULL,
    level_id integer NOT NULL,
    login_id character varying(128) COLLATE pg_catalog."default",
    passwd_id character varying(128) COLLATE pg_catalog."default",
    entry timestamp without time zone,
    domain01 integer,
    domain02 integer,
    domain03 integer,
    domain04 integer,
    domain05 integer,
    CONSTRAINT passwd_mail_pkey PRIMARY KEY (cmp_id, user_id)
)

domain01 = esmile-hd.jp
domain02 = web-esmile.biz
domain03 = e-smile.jp.net
domain04 = sol-tribehd.com
domain05 = web-esmile.biz


CREATE TABLE IF NOT EXISTS public.passwd_tnas
(
    cmp_id integer NOT NULL,
    user_id integer NOT NULL,
    level_id integer NOT NULL,
    login_id character varying(128) COLLATE pg_catalog."default",
    passwd_id character varying(128) COLLATE pg_catalog."default",
    entry timestamp without time zone,
    srv01 integer,
    srv02 integer,
    srv03 integer,
    srv04 integer,
    srv05 integer,
    samba_id character varying(128) COLLATE pg_catalog."default",
    CONSTRAINT passwd_tnas_pkey PRIMARY KEY (cmp_id, user_id)
)


dn: uid=takahashi-shinichi,ou=Users,dc=e-smile,dc=ne,dc=jp
mail: takahashi-shinichi@esmile-holdings.com

uidNumber:  50101 ->  5 - 0101
uidNumber: 120198 -> 12 - 0198
*/


require_once __DIR__ . '/autoload.php';

use Tools\Lib\Env;
use Tools\Lib\Config;
use Tools\Lib\CliColor as C;
use Tools\Lib\LdapConnector;

// ========== CLI定義（CLI > ENV > tools.conf > default） ==========
$schema = [
    'help'        => ['cli'=>'help',   'type'=>'bool','default'=>false,'desc'=>'このヘルプを表示'],
    'confirm'     => ['cli'=>'confirm','type'=>'bool','default'=>false,'desc'=>'実際にDBへ書込（既定はDRY-RUN）'],
    'config'      => ['cli'=>'config', 'type'=>'string','default'=>null,'desc'=>'INI設定ファイルパス（tools.conf等）'],

    // モード切替（どちらか必須）
    'people'      => ['cli'=>'People', 'type'=>'bool','default'=>false,'desc'=>'Peopleモード: uid="<cmp>-<user3桁>" を解析し "電子メールアドレスLDAP登録" へ'],
    'peple'       => ['cli'=>'Peple',  'type'=>'bool','default'=>false,'desc'=>'Peopleの別名（タイプミス救済）'],
    'users'       => ['cli'=>'Users',  'type'=>'bool','default'=>false,'desc'=>'Usersモード: uidNumber から (cmp_id, user_id) を解析し "電子メールアドレス自社サーバー" へ'],
    // Onamae（PostgreSQL 由来）
    'onamae'     => ['cli'=>'onamae','type'=>'bool','default'=>false,'desc'=>'Onamaeモード: public.passwd_mail（flag_id=1）を参照し "電子メールアドレスお名前ドットコム" へ'],
    'O'          => ['cli'=>'O',     'type'=>'bool','default'=>false,'desc'=>'--O エイリアス（--onamae と同じ）'],

    // LDAP
    'ldap_uri'    => ['cli'=>'ldap-uri','type'=>'string','env'=>'LDAP_URI','default'=>null,'desc'=>'LDAP URI（既定: tools.conf または ldapi）'],
    'people_dn'   => ['cli'=>'people-dn','type'=>'string','default'=>'ou=People,dc=e-smile,dc=ne,dc=jp','desc'=>'Peopleモードの検索ベースDN'],
    'users_dn'    => ['cli'=>'users-dn', 'type'=>'string','default'=>'ou=Users,dc=e-smile,dc=ne,dc=jp',  'desc'=>'Usersモードの検索ベースDN'],

    // PostgreSQL
    'pg_dsn'      => ['cli'=>'pg-dsn','type'=>'string','env'=>'PG_DSN','default'=>null,'desc'=>'PostgreSQL DSN'],
    'pg_host'     => ['cli'=>'pg-host','type'=>'string','env'=>'PG_HOST','default'=>'127.0.0.1','desc'=>'PostgreSQL ホスト'],
    'pg_port'     => ['cli'=>'pg-port','type'=>'int',   'env'=>'PG_PORT','default'=>5432,'desc'=>'PostgreSQL ポート'],
    'pg_db'       => ['cli'=>'pg-db',  'type'=>'string','env'=>'PG_DB',  'default'=>'accounting','desc'=>'PostgreSQL DB名'],
    'pg_user'     => ['cli'=>'pg-user','type'=>'string','env'=>'PG_USER','default'=>'postgres','desc'=>'PostgreSQL ユーザー'],
    'pg_pass'     => ['cli'=>'pg-pass','type'=>'string','env'=>'PG_PASS','default'=>'','desc'=>'PostgreSQL パスワード'],

    'modified_by' => ['cli'=>'by','type'=>'string','env'=>'SCRIPT_USER','default'=>'ldap-sync','desc'=>'更新者 (modified_by)'],
];

$cfg = Config::loadWithFile($argv, $schema, null);

// tools.conf 取込
if (($cfg['config'] ?? null) && is_file($cfg['config'])) {
    $ini = parse_ini_file($cfg['config'], true, INI_SCANNER_TYPED) ?: [];
    if (!empty($ini['ldap']['uri']) && empty($cfg['ldap_uri'])) {
        $cfg['ldap_uri'] = (string)$ini['ldap']['uri'];
    }
    if (!empty($ini['postgresql'])) {
        foreach (['pg_host','pg_port','pg_user','pg_db'] as $k) {
            if (isset($ini['postgresql'][$k]) && ($cfg[$k]===null || $cfg[$k]==='')) {
                $cfg[$k] = $ini['postgresql'][$k];
            }
        }
    }
}

// --help
if (!empty($cfg['help'])) {
    echo C::yellow("sync_mail_extension_from_ldap2.php\n");
    echo C::cyan("LDAP → PostgreSQL(\"情報個人メール拡張\") メール同期（People/Users切替）\n\n");
    echo C::green("使用例:\n");
    echo "  php sync_mail_extension_from_ldap2.php --People --confirm --config=/usr/local/etc/openldap/tools/tools.conf\n";
    echo "  php sync_mail_extension_from_ldap2.php --Users  --confirm\n";
    echo "  php sync_mail_extension_from_ldap2.php --O      --confirm    # passwd_mail（flag_id=1）由来\n\n";
    echo C::green("主なオプション:\n");
    foreach ($schema as $key => $m) {
        $cli = isset($m['cli']) ? '--'.$m['cli'] : $key;
        $def = var_export($m['default'] ?? null, true);
        printf("  %-18s 既定:%-7s %s\n", $cli, $def, $m['desc'] ?? '');
    }
    echo C::yellow("\n※ 既定は DRY-RUN。書込は --confirm を指定\n");
    exit(0);
}

// モード判定
$modePeople = !empty($cfg['people']) || !empty($cfg['peple']);
$modeUsers  = !empty($cfg['users']);
$modeOnamae = !empty($cfg['onamae']) || !empty($cfg['O']);
if ( ($modePeople + $modeUsers + $modeOnamae) != 1 ) {
    fwrite(STDERR, C::red("エラー: --People / --Users / --O のいずれか1つを指定してください。--help 参照。\n"));
    exit(2);
}

// DSN
$pgDsn = $cfg['pg_dsn'] ?: sprintf('pgsql:host=%s;port=%d;dbname=%s', $cfg['pg_host'], (int)$cfg['pg_port'], $cfg['pg_db']);

// 画面情報
echo C::yellow("=== sync_mail_extension_from_ldap2 (DRY-RUN=".($cfg['confirm']?'OFF':'ON').") ===\n");
echo C::cyan("MODE    : ").($modePeople ? 'People (→ 電子メールアドレスLDAP登録)' : ($modeUsers ? 'Users (→ 電子メールアドレス自社サーバー)' : 'Onamae (→ 電子メールアドレスお名前ドットコム)'))."
";

/*
} catch (Throwable $e) {
    fwrite(STDERR, C::red("[DB] 接続失敗: ".$e->getMessage())."\n");
    exit(1);
}
*/

// レコード配列
$records = []; // [ [cmp_id(int), user_id(int), mail(string)] , ... ]

if ($modeOnamae) {
    // --O: PostgreSQL public.passwd_mail から作成
    // ドメイン優先順（sync_mail_extension_from_ldap.php と同等に）
    $domainMap = [
        'domain01' => 'esmile-hd.jp',
        'domain02' => 'web-esmile.biz',
        'domain03' => 'e-smile.jp.net',
        'domain04' => 'sol-tribehd.com',
        'domain05' => 'web-esmile.biz',
    ];
    $domainOrder = array_keys($domainMap);

    // login_id とドメインフラグを JOIN して取得
    $sql = "SELECT pm.cmp_id, pm.user_id, pm.flag_id, pm.domain01, pm.domain02, pm.domain03, pm.domain04, pm.domain05, p.login_id\n            FROM public.passwd_mail pm\n            JOIN public.passwd_tnas p ON p.cmp_id=pm.cmp_id AND p.user_id=pm.user_id\n            WHERE pm.flag_id=1";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $cmpId = (int)$r['cmp_id'];
        $userId = (int)$r['user_id'];
        $login  = trim((string)($r['login_id'] ?? ''));
        if ($login === '') { continue; }
        $email = null;
        foreach ($domainOrder as $k) {
            if ((int)($r[$k] ?? 0) === 1) { $email = $login.'@'.$domainMap[$k]; break; }
        }
        if ($email) { $records[] = [$cmpId, $userId, $email]; }
    }
} else {
    // レコード配列
$records = []; // [ [cmp_id(int), user_id(int), mail(string)] , ... ]

if ($modeOnamae) {
    // --O: PostgreSQL public.passwd_mail から作成
    // ドメイン優先順（sync_mail_extension_from_ldap.php と同等に）
    $domainMap = [
        'domain01' => 'esmile-hd.jp',
        'domain02' => 'web-esmile.biz',
        'domain03' => 'e-smile.jp.net',
        'domain04' => 'sol-tribehd.com',
        'domain05' => 'web-esmile.biz',
    ];
    $domainOrder = array_keys($domainMap);

    // login_id とドメインフラグを JOIN して取得
    $sql = <<<SQL
SELECT
  pm.cmp_id, pm.user_id, pm.flag_id,
  pm.domain01, pm.domain02, pm.domain03, pm.domain04, pm.domain05,
  t.login_id
FROM public.passwd_mail AS pm
JOIN public.passwd_tnas AS t
  ON t.cmp_id = pm.cmp_id AND t.user_id = pm.user_id
WHERE pm.flag_id = 1
ORDER BY pm.cmp_id, pm.user_id
SQL;
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $extrasOut = [];

    foreach ($rows as $r) {
        $cmpId = (int)$r['cmp_id'];
        $userId = (int)$r['user_id'];
        $login = trim((string)($r['login_id'] ?? ''));
        if ($login === '') { continue; }

        $candidates = [];
        foreach ($domainOrder as $k) {
            $v = (int)($r[$k] ?? 0);
            if ($v === 1) {
                $candidates[] = $login . '@' . $domainMap[$k];
            }
        }
        if (!$candidates) { continue; }

        $main = $candidates[0];
        if (count($candidates) > 1) {
            $extrasOut[] = [
                'cmp_id' => $cmpId,
                'user_id'=> $userId,
                'login_id'=>$login,
                'main' => $main,
                'candidates' => array_slice($candidates, 1),
            ];
        }
        $records[] = [$cmpId, $userId, $main];
    }

    if (!empty($cfg['verbose']) && $extrasOut) {
        echo C::boldBlue(\"=== 未登録候補（同時に 1 の列が複数） ===\");	//;.\"\\n\";
        foreach ($extrasOut as $x) {
            $key = sprintf('%d-%04d (%s)', $x['cmp_id'], $x['user_id'], $x['login_id']);
            echo \"- {$key}\\n\";
            echo \"  main:   {$x['main']}\\n\";
            echo \"  others: \".implode(', ', $x['candidates']).\"\\n\";
        }
    }
} else {
    // LDAP検索
    $baseDn  = (string)($modePeople ? $cfg['people_dn'] : $cfg['users_dn']);

    try {
        [$ds] = LdapConnector::connect([
            'uri' => $cfg['ldap_uri'] ?? 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi',
        ]);

        if ($modePeople) {
            // People: uid = "<cmp>-<user3桁>"
            $sr = ldap_search($ds, $baseDn, '(mail=*)', ['uid','mail']);
            $entries = ldap_get_entries($ds, $sr);
            for ($i=0; $i<$entries['count']; $i++) {
                $e = $entries[$i];
                $uid = (string)($e['uid'][0] ?? '');
                $mail = (string)($e['mail'][0] ?? '');
                if ($uid === '' || $mail === '') continue;
                if (!preg_match('/^(\\d+)-(\\d{3})$/', $uid, $m)) continue;
                $cmpId = (int)$m[1];
                $userId = (int)$m[2];
                $records[] = [$cmpId, $userId, $mail];
            }
        } else {
            // Users: uidNumber → (cmp_id, user_id)
            $sr = ldap_search($ds, $baseDn, '(mail=*)', ['uidnumber','mail']);
            $entries = ldap_get_entries($ds, $sr);
            for ($i=0; $i<$entries['count']; $i++) {
                $e = $entries[$i];
                $uidNum = (int)($e['uidnumber'][0] ?? 0);
                $mail = (string)($e['mail'][0] ?? '');
                if ($uidNum <= 0 || $mail === '') continue;
                // 50101 => 5 - 0101 / 120198 => 12 - 0198
                $cmpId = (int)floor($uidNum / 10000);
                $userId = (int)($uidNum % 10000);
                $records[] = [$cmpId, $userId, $mail];
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, C::red(\"[LDAP] 取得失敗: \".$e->getMessage()).\"\\n\");
        // 続行するが、records が空なら後続で 0 件終了
    }
}
if (!$records) {
    echo C::yellow("取得 0 件。終了。\n");
    exit(0);
}

$label = $modeOnamae ? 'DB' : 'LDAP';
echo C::blue(sprintf("%s hits: %d 件\n", $label, count($records)));

// 書込列（モードで固定）
$targetColumn = $modePeople ? '電子メールアドレスLDAP登録' : ($modeUsers ? '電子メールアドレス自社サーバー' : '電子メールアドレスお名前ドットコム');

// UPSERT（列名はホワイトリストから選んだ $targetColumn のみ動的に展開）
$sql = sprintf(<<<SQL
INSERT INTO public."情報個人メール拡張" (
    cmp_id, user_id, "%s", modified, modified_by
) VALUES (:cmp_id, :user_id, :mail, now(), :by)
ON CONFLICT (cmp_id, user_id)
DO UPDATE SET
    "%s"    = EXCLUDED."%s",
    modified = now(),
    modified_by = EXCLUDED.modified_by
SQL, $targetColumn, $targetColumn, $targetColumn);

$stm = $pdo->prepare($sql);

$ok=$ng=$sk=0;
foreach ($records as [$cmpId, $userId, $mail]) {
    echo C::cyan(sprintf("[PLAN] %02d-%04d ← %s\n", $cmpId, $userId, $mail));

    if (!$cfg['confirm']) { $sk++; continue; } // DRY-RUN: 計上はSKIP扱いにする

    try {
        $stm->execute([
            ':cmp_id'  => (int)$cmpId,
            ':user_id' => (int)$userId,
            ':mail'    => (string)$mail,
            ':by'      => (string)$cfg['modified_by'],
        ]);
        $ok++;
    } catch (Throwable $e) {
        $ng++;
        fwrite(STDERR, C::red("[NG] {$cmpId}-{$userId} {$mail}: ".$e->getMessage())."\n");
    }
}

echo C::yellow("=== 完了 ===\n");
if ($cfg['confirm']) {
    echo C::green("OK: {$ok} / NG: {$ng} / DRY-SKIP: {$sk}\n");
} else {
    echo C::green("DRY-RUN（書込みなし）/ 計画表示件数: ".count($records)."（SKIP: {$sk}）\n");
}


