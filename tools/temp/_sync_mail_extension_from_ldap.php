#!/usr/bin/env php
<?php
/**
 * sync_mail_extension_from_ldap.php
 * - LDAP(ou=People) の uid「<cmp>-<user3桁>」と mail を取得
 * - PostgreSQL public."情報個人メール拡張" に UPSERT（電子メールアドレスLDAP登録）
 * - 共有ライブラリ（autoload.php / Tools\Lib\*）準拠
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

// ===========================================================
// CLI定義（CLI > ENV > tools.conf > default）
// ===========================================================
$schema = [
    'help'        => ['cli'=>'help','type'=>'bool','default'=>false,'desc'=>'このヘルプを表示'],
    'confirm'     => ['cli'=>'confirm','type'=>'bool','default'=>false,'desc'=>'実際にDBへ書込（未指定はDRY-RUN）'],
    'config'      => ['cli'=>'config','type'=>'string','default'=>null,'desc'=>'INI設定ファイルパス（tools.conf等）'],

    // LDAP
    'ldap_uri'    => ['cli'=>'ldap-uri','type'=>'string','env'=>'LDAP_URI','default'=>null,'desc'=>'LDAP URI（既定: tools.conf または ldapi）'],
    'people_dn'   => ['cli'=>'people-dn','type'=>'string','default'=>'ou=People,dc=e-smile,dc=ne,dc=jp','desc'=>'LDAP 検索ベースDN'],

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

// tools.conf を取り込み（存在すれば既定値に反映）
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
    echo C::yellow("sync_mail_extension_from_ldap.php\n");
    echo C::cyan("LDAP → PostgreSQL(\"情報個人メール拡張\") へメールを同期\n\n");
    echo C::green("使用例:\n");
    echo "  php sync_mail_extension_from_ldap.php --config=/usr/local/etc/openldap/tools/tools.conf\n";
    echo "  php sync_mail_extension_from_ldap.php --confirm\n\n";
    echo C::green("オプション:\n");
    foreach ($schema as $key => $m) {
        $cli = isset($m['cli']) ? '--'.$m['cli'] : $key;
        $def = var_export($m['default'] ?? null, true);
        printf("  %-18s 既定:%-7s %s\n", $cli, $def, $m['desc'] ?? '');
    }
    echo C::yellow("\n※ デフォルトは DRY-RUN。書込は --confirm を指定\n");
    exit(0);
}

// DSN
$pgDsn = $cfg['pg_dsn'] ?: sprintf('pgsql:host=%s;port=%d;dbname=%s', $cfg['pg_host'], (int)$cfg['pg_port'], $cfg['pg_db']);

echo C::yellow("=== sync_mail_extension_from_ldap (DRY-RUN=".($cfg['confirm']?'OFF':'ON').") ===\n");
echo C::cyan("LDAP URI : ").($cfg['ldap_uri'] ?? '(ldapi)')."\n";
echo C::cyan("People DN: ").$cfg['people_dn']."\n";
echo C::cyan("PG DSN   : ").$pgDsn."\n\n";

// DB接続
try {
    $pdo = new PDO($pgDsn, (string)$cfg['pg_user'], (string)$cfg['pg_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, C::red("[DB] 接続失敗: ".$e->getMessage())."\n");
    exit(1);
}

// LDAP取得（Tools\Lib\LdapConnector に統一）
$peopleDn = (string)$cfg['people_dn'];
$records  = [];

try {
    [$ds] = LdapConnector::connect([
        'uri' => $cfg['ldap_uri'] ?? 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi',
    ]);
    $sr   = @ldap_search($ds, $peopleDn, '(uid=*)', ['uid','mail']);
    $ents = $sr ? @ldap_get_entries($ds, $sr) : false;

    if (is_array($ents) && isset($ents['count'])) {
        for ($i=0; $i<$ents['count']; $i++) {
            $uid  = $ents[$i]['uid'][0]  ?? null;
            $mail = $ents[$i]['mail'][0] ?? null;
            if ($uid && $mail) $records[] = [$uid, $mail];
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, C::red("[LDAP] 取得失敗: ".$e->getMessage())."\n");
    exit(1);
}

if (!$records) {
    echo C::yellow("LDAP から取得 0 件。終了。\n");
    exit(0);
}

echo C::blue(sprintf("LDAP hits: %d 件\n", count($records)));

// UPSERT
$sql = <<<SQL
INSERT INTO public."情報個人メール拡張" (
    cmp_id, user_id, "電子メールアドレスLDAP登録", modified, modified_by
) VALUES (:cmp_id, :user_id, :mail, now(), :by)
ON CONFLICT (cmp_id, user_id)
DO UPDATE SET
    "電子メールアドレスLDAP登録" = EXCLUDED."電子メールアドレスLDAP登録",
    modified = now(),
    modified_by = EXCLUDED.modified_by
SQL;

$stm = $pdo->prepare($sql);
$ok=$ng=$sk=0;

foreach ($records as [$uidStr, $mail]) {
    if (!preg_match('/^(\d+)-(\d{1,3})$/', $uidStr, $m)) {
        echo C::yellow("[SKIP] uid形式不正: {$uidStr}\n"); $sk++; continue;
    }
    $cmpId  = (int)$m[1];
    $userId = (int)$m[2];
    echo C::cyan(sprintf("[PLAN] %02d-%03d ← %s (uid=%s)\n", $cmpId, $userId, $mail, $uidStr));

    if (!$cfg['confirm']) continue;

    try {
        $stm->execute([
            ':cmp_id'  => $cmpId,
            ':user_id' => $userId,
            ':mail'    => $mail,
            ':by'      => (string)$cfg['modified_by'],
        ]);
        $ok++;
    } catch (Throwable $e) {
        $ng++;
        fwrite(STDERR, C::red("[NG] {$cmpId}-{$userId} {$mail}: ".$e->getMessage())."\n");
    }
}

echo C::yellow("=== 完了 ===\n");
echo C::green($cfg['confirm'] ? "OK: {$ok} / NG: {$ng} / SKIP(uid不正): {$sk}\n"
                               : "DRY-RUN（書込みなし）/ SKIP(uid不正): {$sk}\n");


