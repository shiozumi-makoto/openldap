#!/usr/bin/env php
<?php
/**
 * sync_mail_extension_from_ldap2.php
 * -----------------------------------------------------------------------------
 * 概要:
 *   LDAP からユーザーの mail を取得し、PostgreSQL public."情報個人メール拡張" に
 *   レコードを UPSERT（存在すれば UPDATE / 無ければ INSERT）する同期スクリプト。
 *
 * 同期モード（ベースDN・主キー解釈・書込先列が切替）:
 *   --People（別名 --Peple）:
 *     - base:  ou=People,dc=e-smile,dc=ne,dc=jp
 *     - 主キー: uid を "<cmp>-<user(3桁)>" と解釈し、cmp_id, user_id に分割
 *               例) "10-015" -> cmp_id=10, user_id=15
 *     - 書込列: "電子メールアドレスLDAP登録"
 *
 *   --Users:
 *     - base:  ou=Users,dc=e-smile,dc=ne,dc=jp
 *     - 主キー: uidNumber を "cmp_id * 10000 + user_id(4桁)" として分解
 *               例)  50101  -> (cmp_id=5,  user_id=0101)
 *                    120198 -> (cmp_id=12, user_id=0198)
 *               ※ 一般式: cmp_id = floor(uidNumber / 10000), user_id = uidNumber % 10000
 *     - 書込列: "電子メールアドレス自社サーバー"
 *
 * 取り扱い属性:
 *   - 読取:   mail（文字列・単一想定。複数値があれば先頭採用 or 結合方針は実装に依存）
 *   - 変換:   前後空白除去、Unicode 正規化（任意）、小文字化（任意）
 *   - 検証:   簡易メール形式チェック（任意; 不正形式はスキップ可能）
 *
 * 書込先テーブル（想定）: public."情報個人メール拡張"
 *   - キー列: cmp_id (int), user_id (int)
 *   - 値列:  "電子メールアドレスLDAP登録" (text), "電子メールアドレス自社サーバー" (text)
 *   - 監査列: updated_at, updated_by（存在する場合のみ更新）
 *   - UPSERT 例（概念）:
 *       INSERT INTO public."情報個人メール拡張"(cmp_id, user_id, "電子メールアドレスLDAP登録")
 *       VALUES (:cmp_id, :user_id, :mail)
 *       ON CONFLICT (cmp_id, user_id) DO UPDATE
 *         SET "電子メールアドレスLDAP登録" = EXCLUDED."電子メールアドレスLDAP登録",
 *             updated_at = NOW();
 *     ※ --Users の場合は上記の対象列が "電子メールアドレス自社サーバー" に変わる
 *
 * 実行フロー（概要）:
 *   1) CLI引数・設定ファイルを読む（--config など）
 *   2) LDAP 接続（ldapi:/// / ldaps:// など、Bind/証明書は環境依存）
 *   3) モード別に baseDN と検索フィルタを構築して mail, uid/uidNumber を取得
 *   4) 主キー（cmp_id, user_id）をモード別規則で算出
 *   5) PostgreSQL に UPSERT（--confirm が無い場合はドライラン）
 *   6) サマリ出力（対象件数・更新件数・スキップ件数・エラー件数）
 *
 * 代表的なオプション:
 *   --People / --Peple   People モードで実行（ベースDN/主キー/書込列が People 用に）
 *   --Users              Users モードで実行（ベースDN/主キー/書込列が Users 用に）
 *   --config=<path>      設定ファイル（例: /usr/local/etc/openldap/tools/tools.conf）
 *   --filter=<ldap>      LDAP 追加フィルタ（例: '(objectClass=inetOrgPerson)'）
 *   --limit=<N>          最大処理件数の上限（テスト用）
 *   --confirm            実際に DB へ書き込む（指定が無ければドライラン）
 *   -v/--verbose         ログ詳細化
 *   --debug              デバッグログを有効化
 *
 * 依存コンポーネント（例: プロジェクト内ライブラリ）:
 *   - Tools\Lib\Env, Tools\Lib\Config
 *   - Tools\Lib\CliUtil, Tools\Lib\CliColor
 *   - Tools\Lib\LdapConnector（もしくは LdapUtil / LdapConnector）
 *   - DB 接続: PDO (pdo_pgsql)
 *   - LDAP拡張: php-ldap
 *
 * 実行例:
 *   # People モード（LDAPのアドレスを "電子メールアドレスLDAP登録" に同期）
 *   php sync_mail_extension_from_ldap2.php \
 *       --People --confirm \
 *       --config=/usr/local/etc/openldap/tools/tools.conf
 *
 *   # Users モード（自社サーバー側のアドレスを "電子メールアドレス自社サーバー" に同期）
 *   php sync_mail_extension_from_ldap2.php --Users --confirm
 *
 * 運用上の注意:
 *   - デフォルトはドライラン。実書込は --confirm 必須。
 *   - 主キー解釈ルール（uid/uidNumber→cmp_id,user_id）に合致しないレコードはスキップ。
 *   - 既存値と同一なら UPDATE はスキップ（無駄な更新を避ける実装推奨）。
 *   - 大量更新時はトランザクション/バルクUPSERT/インデックス最適化を検討。
 *   - 文字列は可能なら小文字化・余分な空白除去・Unicode正規化で揺れを低減。
 *
 * 返り値（慣例）:
 *   - 0: 正常終了（エラー0件）
 *   - 1: 引数または設定ファイル不備
 *   - 2: LDAP接続/検索エラー
 *   - 3: DB接続/書込エラー
 *
 * © E-Smile Group. Internal use only.
 * -----------------------------------------------------------------------------
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
    // モード切替 people users（Ldap）onamae（PostgreSQL 由来）
    'people'      => ['cli'=>'People', 'type'=>'bool','default'=>false,'desc'=>'Peopleモード: uid="<cmp>-<user3桁>" を解析し "電子メールアドレスLDAP登録" へ'],
    'users'       => ['cli'=>'Users',  'type'=>'bool','default'=>false,'desc'=>'Usersモード: uidNumber から (cmp_id, user_id) を解析し "電子メールアドレス自社サーバー" へ'],
    'onamae'      => ['cli'=>'onamae','type'=>'bool','default'=>false,'desc'=>'Onamaeモード: public.passwd_mail（flag_id=1）を参照し "電子メールアドレスお名前ドットコム" へ'],
    'P'           => ['cli'=>'P', 'type'=>'bool','default'=>false,'desc'=>'--P エイリアス（--people と同じ）'],
    'p'           => ['cli'=>'p', 'type'=>'bool','default'=>false,'desc'=>'--P エイリアス（--people と同じ）'],
    'O'           => ['cli'=>'O', 'type'=>'bool','default'=>false,'desc'=>'--O エイリアス（--onamae と同じ）'],
    'o'           => ['cli'=>'o', 'type'=>'bool','default'=>false,'desc'=>'--O エイリアス（--onamae と同じ）'],
    'U'           => ['cli'=>'U', 'type'=>'bool','default'=>false,'desc'=>'--U エイリアス（--users  と同じ）'],
    'u'           => ['cli'=>'u', 'type'=>'bool','default'=>false,'desc'=>'--U エイリアス（--users  と同じ）'],
    // LDAP
    'ldap_uri'    => ['cli'=>'ldap-uri','type'=>'string','env'=>'LDAP_URI','default'=>null,'desc'=>'LDAP URI（既定: tools.conf または ldapi）'],
    'people_dn'   => ['cli'=>'people-dn','type'=>'string','default'=>'ou=People,dc=e-smile,dc=ne,dc=jp','desc'=>'Peopleモードの検索ベースDN'],
    'users_dn'    => ['cli'=>'users-dn', 'type'=>'string','default'=>'ou=Users,dc=e-smile,dc=ne,dc=jp',  'desc'=>'Usersモードの検索ベースDN'],
    // PostgreSQL
    'pg_dsn'      => ['cli'=>'pg-dsn','type'=>'string', 'env'=>'PG_DSN', 'default'=>null,'desc'=>'PostgreSQL DSN'],
    'pg_host'     => ['cli'=>'pg-host','type'=>'string','env'=>'PG_HOST','default'=>'127.0.0.1','desc'=>'PostgreSQL ホスト'],
    'pg_port'     => ['cli'=>'pg-port','type'=>'int',   'env'=>'PG_PORT','default'=>5432,'desc'=>'PostgreSQL ポート'],
    'pg_db'       => ['cli'=>'pg-db',  'type'=>'string','env'=>'PG_DB',  'default'=>'accounting','desc'=>'PostgreSQL DB名'],
    'pg_user'     => ['cli'=>'pg-user','type'=>'string','env'=>'PG_USER','default'=>'postgres','desc'=>'PostgreSQL ユーザー'],
    'pg_pass'     => ['cli'=>'pg-pass','type'=>'string','env'=>'PG_PASS','default'=>'','desc'=>'PostgreSQL パスワード'],
	// Table  
    'create_by'   => ['cli'=>'c-by','type'=>'string','env'=>'SCRIPT_USER','default'=>'ldap-sync','desc'=>'作成者 (create_by)'],
    'modified_by' => ['cli'=>'m-by','type'=>'string','env'=>'SCRIPT_USER','default'=>'ldap-sync','desc'=>'更新者 (modified_by)'],
];

$cfg = Config::loadWithFile($argv, $schema, null);

# print_r($cfg);
# exit;

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

	// print_r($ini);
}

//print_r($cfg);
//exit;

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
$modePeople = !empty($cfg['people']) || !empty($cfg['P']) || !empty($cfg['p']);
$modeUsers  = !empty($cfg['users'] ) || !empty($cfg['U']) || !empty($cfg['u']);
$modeOnamae = !empty($cfg['onamae']) || !empty($cfg['O']) || !empty($cfg['o']);

/*
var_dump($modePeople);
var_dump($modeUsers);
var_dump($modeOnamae);
*/
//exit;

if ( ($modePeople + $modeUsers + $modeOnamae) != 1 ) {
    fwrite(STDERR, C::red("エラー: --People[P] / --Users[U] / --Onamae[O] のいずれか1つを指定してください。--help 参照。\n"));
    exit(2);
}

// DSN
/*
$pgHost = (string)($cfg['postgresql']['pg_host'] ?? '127.0.0.1');
$pgPort = (int)($cfg['postgresql']['pg_port'] ?? 5432);
$pgUser = (string)($cfg['postgresql']['pg_user'] ?? 'postgres');
$pgDb   = (string)($cfg['postgresql']['pg_db']   ?? 'accounting');
*/

$pgHost = (string)($cfg['pg_host'] ?? '127.0.0.1');
$pgPort = (int)($cfg['pg_port'] ?? 5432);
$pgUser = (string)($cfg['pg_user'] ?? 'postgres');
$pgDb   = (string)($cfg['pg_db']   ?? 'accounting');
$pgPass = Env::str('PGPASSWORD', null); // 必要なら export PGPASSWORD=... で

$dsn = "pgsql:host={$pgHost};port={$pgPort};dbname={$pgDb}";

# echo $dsn."\n";
# exit;

try {
    $pdo = new PDO($dsn, $pgUser, $pgPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, C::red("DB接続エラー: ".$e->getMessage()).PHP_EOL);
    exit(2);
}

// 画面情報
/*
echo C::cyan("[INFO] DB connected: {$pgHost}:{$pgPort} db={$pgDb} user={$pgUser}").PHP_EOL;
echo C::yellow("=== sync_mail_extension_from_ldap2 (DRY-RUN=".( $cfg['confirm'] ? 'OFF' : 'ON' ).") ===\n");
echo C::cyan("MODE    : ").($modePeople ? 'People (→ 電子メールアドレスLDAP登録)' : ($modeUsers ? 'Users (→ 電子メールアドレス自社サーバー)' : 'Onamae (→ 電子メールアドレスお名前ドットコム)'))."\n";
echo C::cyan("LDAP URI: ").($modeOnamae ? '(N/A for --O)' : ($cfg['ldap_uri'] ?? '(ldapi)'))."\n";
echo C::cyan("Base DN : ").($modeOnamae ? '(N/A for --O)' : ($modePeople ? $cfg['people_dn'] : $cfg['users_dn']))."\n";
*/

//
// 画面情報
//
$infoText  = "\n";
$infoText .= C::cyan("[INFO] DB connected: {$pgHost}:{$pgPort} db={$pgDb} user={$pgUser}") . PHP_EOL;
$infoText .= C::yellow("=== sync_mail_extension_from_ldap (DRY-RUN=" . ($cfg['confirm'] ? 'OFF' : 'ON') . ") ===\n");
$infoText .= C::cyan("MODE    : ") .
             ($modePeople ? 'People (→ 電子メールアドレスLDAP登録)'
              : ($modeUsers ? 'Users (→ 電子メールアドレス自社サーバー)'
                : 'Onamae (→ 電子メールアドレスお名前ドットコム)')) . "\n";
$infoText .= C::cyan("LDAP URI: ") . ($modeOnamae ? '(N/A for --Onamae: -O: -o)' : ($cfg['ldap_uri'] ?? '(ldapi)')) . "\n";
$infoText .= C::cyan("Base DN : ") . ($modeOnamae ? '(N/A for --Onamae: -O: -o)' : ($modePeople ? $cfg['people_dn'] : $cfg['users_dn'])) . "\n\n";

// 出力
echo $infoText;
//exit;


// レコード配列
$records = []; // [ [cmp_id(int), user_id(int), mail(string)] , ... ]
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

	// -------------------------------------------------------------------------- login_id 列の参照は、passwd_tnas から、passwd_mail に変更！
	//  t.login_id,   pm.login_id as login_id_other
	//  pm.login_id,  t.login_id  as login_id_other
	//
    // login_id とドメインフラグを JOIN して取得
	//
    $sql = <<<SQL
SELECT
  pm.cmp_id, pm.user_id, pm.flag_id,
  pm.domain01, pm.domain02, pm.domain03, pm.domain04, pm.domain05,
  pm.login_id,  t.login_id  as login_id_other, t.samba_id 
FROM public.passwd_mail AS pm
JOIN public.passwd_tnas AS t
  ON t.cmp_id = pm.cmp_id AND t.user_id = pm.user_id
WHERE pm.flag_id = 1 and pm.cmp_id = 5 -- and pm.user_id = 101
ORDER BY pm.cmp_id, pm.user_id
SQL;

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/* --------------------------------------------------------------------------- takahahi or takahahi-ryoya */
/*
*/

//echo $sql;
//print_r($domainOrder);
//print_r($rows);
//echo "\n\n";
//exit;

	/* ----------------------------------------------------------------
		$domainMap を参照して、domain01〜domain05 の各カラムを調べ、
		値が 1 のときに対応するドメイン名を配列に追加するには、次のように書けます👇
    ---------------------------------------------------------------- */
	foreach ($rows as &$row) {
        $domain_names = [];
    
        foreach ($domainMap as $key => $domain_name) {
            if (!empty($row[$key]) && (int)$row[$key] === 1) {
                $domain_names = $domain_name;
    			break;
            }
        }
    
        // domain_name 配列を行データに追加
        $row['domain_name'] = $domain_names;
    }


// 一致していないものだけ抽出
$unmatched = array_filter($rows, function($row) {
    return $row['login_id'] !== $row['login_id_other'];
});

// キーを0から振り直す（なくても動くが見やすくなる）
$unmatched = array_values($unmatched);

// 確認用出力
//print_r($rows);

// 結果を表示
foreach ($unmatched as $key => $r) {
    printf("[%3d] cmp_id=%2d user_id=%d login_id [passwd_mail.login_id] [New] = %20s@%-16s >>> %-14s [old] [ passwd_tnas.login_id ] [ passwd_tnas.samba ] %-20s\n",
        $key,
        $r['cmp_id'],
        $r['user_id'],
        $r['login_id'],			// passwd_mail	     -- 新規アドレス@ドメイン
        $r['domain_name'],
        $r['login_id_other'],	// passed_tans.login -- TNASのID
        $r['samba_id'],			// passed_tans.samba -- ここに統一して、passwd_mail.login_id に上書きする。
    );
}

// exit;
// 確認用出力
//print_r($rows);
//exit;
/*
    [1] => Array
        (
            [cmp_id] => 5
            [user_id] => 101
            [flag_id] => 1
            [domain01] => 0
            [domain02] => 0
            [domain03] => 1
            [domain04] => 0
            [domain05] => 0
            [login_id] => takahashi-ryoya
            [login_id_other] => takahashi
            [domain_name] => e-smile.jp.net
        )
*/

//	print_r($rows);
//	exit;

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
        echo C::boldBlue( "=== 未登録候補（同時に 1 の列が複数） ===" ). "\n";
        foreach ($extrasOut as $x) {
            $key = sprintf('%d-%04d (%s)', $x['cmp_id'], $x['user_id'], $x['login_id']);
            echo "- {$key}\n";
            echo "  main:   {$x['main']}\n";
            echo "  others: ".implode(', ', $x['candidates'])."\n";
        }
    }


// ----------------------------------------------------------
/*
	print_r($records);
	exit;
*/

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
        fwrite(STDERR, C::red("[LDAP] 取得失敗: ".$e->getMessage())."\n");
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
    cmp_id, user_id, "%s", created, created_by, modified, modified_by
) VALUES (:cmp_id, :user_id, :mail, now(), :by, now(), :by)
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


//
// 画面情報
//
echo $infoText;
