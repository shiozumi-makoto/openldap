#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_id_pass_from_postgres_set.php
 *
 * - DB: accounting / public."情報個人" × public.passwd_tnas
 * - 条件: (srv01..srv05 のどれか = 1) AND (p.user_id >= 100 OR p.user_id = 1)
 * - level_id = 0 は対象に含め、表示上は 99 として扱う（err-cls 99）
 * - UID: 「姓かな」「名かな」必須 + 「ミドルネーム」任意
 *         kakasi(UTF-8) → 小文字 → 英数字以外除去 → uid="sei-mei" . mid（ミドルは末尾直結）
 * - LDAP: uid DN で upsert（inetOrgPerson,posixAccount,shadowAccount）
 * - DB: public.passwd_tnas.samba_id を uid で UPDATE（--confirm の時のみ）
 * - HOME: オプションで実体作成と /home/login へのリンク整備
 * - --init: 初期化モード。Upsertは行わず、DBの samba_id を NULL、
 *           (--ldap時は) People OU の該当 uid を削除 + 旧命名（sei-mei-2..99）も合わせて削除
 * - 表示: 旧ログ形式（Up! 行のみ、職位は色付き・10桁固定）/ 冒頭と末尾にバナー
 *
 * 優先度: CLI > ENV > 設定ファイル > 既定
 */

require __DIR__ . '/autoload.php';

use Tools\Lib\Config;
use Tools\Lib\CliUtil;
use Tools\Lib\LdapConnector;
use Tools\Lib\Env;
use Tools\Ldap\Support\GroupDef;
use Tools\Ldap\Support\LdapUtil;

//============================================================
// CLI/ENV スキーマ
//============================================================
$schema = [
    // 実行
    'help'       => ['cli'=>'help','type'=>'bool','default'=>false,'desc'=>'このヘルプを表示'],
    'confirm'    => ['cli'=>'confirm','type'=>'bool','default'=>false,'desc'=>'実行（未指定はDRY-RUN）'],
    'verbose'    => ['cli'=>'verbose','type'=>'bool','default'=>false,'desc'=>'詳細ログ'],
    'init'       => ['cli'=>'init','type'=>'bool','default'=>false,'desc'=>'初期化モード（DB samba_id を NULL、--ldap時は uid を削除）'],

	// Maildir専用
	'maildir-only'=> ['cli'=>'maildir-only','type'=>'bool','default'=>false,'desc'=>'Maildir のみ作成（home/link整備はスキップ）'],

    // LDAP
    'uri'        => ['cli'=>'uri','type'=>'string','env'=>'LDAP_URI','default'=>null,'desc'=>'ldap[s]/ldapi URI'],
    'ldapi'      => ['cli'=>'ldapi','type'=>'bool','default'=>false,'desc'=>'ldapi を使う'],
    'ldaps'      => ['cli'=>'ldaps','type'=>'bool','default'=>false,'desc'=>'ldaps を使う'],
    'starttls'   => ['cli'=>'starttls','type'=>'bool','default'=>false,'desc'=>'StartTLS を使う'],
    'bind_dn'    => ['cli'=>'bind-dn','type'=>'string','env'=>'LDAP_BIND_DN','default'=>null,'desc'=>'Bind DN'],
    'bind_pass'  => ['cli'=>'bind-pass','type'=>'string','env'=>'LDAP_BIND_PASS','secret'=>true,'default'=>null,'desc'=>'Bind パス'],
    'base_dn'    => ['cli'=>'base-dn','type'=>'string','env'=>'LDAP_BASE_DN','default'=>null,'desc'=>'Base DN'],

    // PostgreSQL
    'pg_host'    => ['cli'=>'pg-host','type'=>'string','env'=>'PGHOST','default'=>'127.0.0.1','desc'=>'Postgres ホスト'],
    'pg_port'    => ['cli'=>'pg-port','type'=>'int','env'=>'PGPORT','default'=>5432,'desc'=>'Postgres ポート'],
    'pg_user'    => ['cli'=>'pg-user','type'=>'string','env'=>'PGUSER','default'=>'postgres','desc'=>'Postgres ユーザ'],
    'pg_pass'    => ['cli'=>'pg-pass','type'=>'string','env'=>'PGPASSWORD','secret'=>true,'default'=>null,'desc'=>'Postgres パスワード'],
    'pg_db'      => ['cli'=>'pg-db','type'=>'string','env'=>'PGDATABASE','default'=>'accounting','desc'=>'DB名'],

    // フィルタ（旧式互換）
    'cmps'       => ['cli'=>'cmps','type'=>'string','default'=>null,'desc'=>'j.cmp_id IN (...)'],
    'users'      => ['cli'=>'users','type'=>'string','default'=>null,'desc'=>'j.user_id IN (...)'],
    'uids'       => ['cli'=>'uids','type'=>'string','default'=>null,'desc'=>'p.login_id IN (...)'],
    'likes'      => ['cli'=>'likes','type'=>'string','default'=>null,'desc'=>'p.login_id LIKE %...%（カンマ区切り）'],
    'where'      => ['cli'=>'where','type'=>'string','default'=>null,'desc'=>'追加 WHERE（AND結合）'],

    // 既定
    'ldap'       => ['cli'=>'ldap','type'=>'bool','default'=>false,'desc'=>'LDAP 更新を実施（--init時は削除）'],
    'home'       => ['cli'=>'home','type'=>'bool','default'=>false,'desc'=>'ホームディレクトリ整備も実施'],
    'home_root'  => ['cli'=>'home-root','type'=>'string','default'=>'/ovs012_home','desc'=>'ホーム実体ルート'],
    'shell'      => ['cli'=>'shell','type'=>'string','default'=>'/bin/bash','desc'=>'loginShell 既定'],
    'gid_users'  => ['cli'=>'gid-users','type'=>'int','default'=>100,'desc'=>'gidNumber 既定（users=100）'],
];

$cfg = Config::loadWithFile($argv, $schema, __DIR__ . '/inc/tools.conf');

if (!empty($cfg['help'])) {
    $prog = basename($_SERVER['argv'][0] ?? 'ldap_id_pass_from_postgres_set.php');
    echo CliUtil::buildHelp($schema, $prog, [
        'DRY-RUN' => "php {$prog} --ldapi --ldap --verbose",
        '実行'    => "php {$prog} --ldapi --ldap --confirm",
        '初期化'  => "php {$prog} --init --ldap --ldapi --confirm",
    ]);
    exit(0);
}

$APPLY = !empty($cfg['confirm']);
$VERB  = !empty($cfg['verbose']);
$INIT  = !empty($cfg['init']);
$DBG   = fn(string $m) => $VERB && print("[DBG] {$m}\n");

$ERR_LOG = __DIR__ . '/ldap_uid_errors.log';

//============================================================
// 冒頭バナー：実行コマンド
//============================================================
echo '  php ' . basename(__FILE__) . ' ' . implode(' ', array_slice($_SERVER['argv'], 1)) . "\n\n";

//============================================================
// DB 接続
//============================================================
$dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $cfg['pg_host'], $cfg['pg_port'], $cfg['pg_db']);
$pdo = new PDO($dsn, $cfg['pg_user'], $cfg['pg_pass'] ?? null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

//============================================================
// LDAP 接続
//============================================================
$ds = $baseDn = $uri = null;
$ldapMode = 'off';
$ldapStatus = '-';
$ldapProto = 'v3';

if ($cfg['ldap'] || $cfg['home'] || $INIT) {
//	print_r($cfg);
//	exit;
    [$ds, $baseDn, /*$groupsDn*/, $uri] = LdapConnector::connect($cfg, $DBG);
    $ldapMode = parse_ldap_mode((string)$uri);
    $ldapStatus = ($ds ? '? bind success ' : 'bind failed') . (empty($cfg['bind_dn']) ? '(anonymous)' : '');
    if (!$baseDn) { $baseDn = Env::str('LDAP_BASE_DN', 'dc=e-smile,dc=ne,dc=jp'); }
}

//============================================================
// LDAP Summary 出力
//============================================================
echo "=== LDAP connection summary ===\n";
printf(" Mode      : %s\n", $ldapMode);
printf(" URI       : %s\n", $uri ?? '-');
printf(" Status    : %s\n", $ldapStatus);
printf(" Protocol  : %s\n", $ldapProto);
echo "===============================\n\n";

//============================================================
// START バナー
//============================================================
$host = trim(gethostname() ?: php_uname('n'));
$skel = '/etc/skel';
$modeNum = 0750;
printf("=== START add-home(+LDAP) ===\n");
printf("HOST      : %s\n", $host);
printf("HOME_ROOT : %s\n", $cfg['home_root']);
printf("SKEL      : %s\n", $skel);
printf("MODE      : %04o (%d)\n", $modeNum, $modeNum);
printf("CONFIRM   : %s\n", $APPLY ? "YES (execute)" : "NO  (dry-run)");
printf("LDAP      : %s\n", ($cfg['ldap']?'enabled':'disabled'));
printf("log file  : %s\n", basename($ERR_LOG));
printf("local uid : %s\n", function_exists('posix_getuid')? (string)posix_getuid() : '-');
echo "----------------------------------------------\n";
printf("ldap_host : %s\n", $uri ?? '-');
printf("ldap_base : %s\n", $baseDn ?? '-');
printf("ldap_user : %s\n", $cfg['bind_dn'] ?? '-');
printf("ldap_pass : %s\n", mask_secret($cfg['bind_pass'] ?? Env::secret('LDAP_BIND_PASS', '********')));
echo "----------------------------------------------\n\n";

/*
# ----------------------
# ovs-009 も更新！
# ----------------------

php ldap_id_pass_from_postgres_set.php \
  --ldap \
  --ldaps \
  --uri=ldaps://ovs-009.e-smile.local:636 \
  --bind-dn="cn=Admin,dc=e-smile,dc=ne,dc=jp" \
  --bind-pass='es0356525566' \
  --base-dn="dc=e-smile,dc=ne,dc=jp" \
  --confirm \
  --verbose \
  --cmps=5 --users=101

env -u LDAPURI -u LDAP_URI -u LDAPCONF -u LDAPTLS_CACERT -u LDAPTLS_REQCERT \
  php ldap_id_pass_from_postgres_set.php \
    --ldap --home --confirm --verbose \
    --ldaps --uri=ldaps://ovs-009.e-smile.local:636 \
    --bind-dn="cn=Admin,dc=e-smile,dc=ne,dc=jp" \
    --bind-pass='es0356525566' \
    --base-dn="dc=e-smile,dc=ne,dc=jp" \
    --cmps=5 --users=101
    --uids=takahashi-ryouya2

php ldap_id_pass_from_postgres_set.php \
  --ldap --confirm --verbose --ldaps --uri=ldaps://ovs-009.e-smile.local:636 --bind-dn="cn=Admin,dc=e-smile,dc=ne,dc=jp" --bind-pass='es0356525566' --base-dn="dc=e-smile,dc=ne,dc=jp"
  --cmps=5
*/

//============================================================
// SQL 構築（旧フォーマット + 条件）
//============================================================

// 条件: (srv01..srv05 のどれか = 1) ※ level_id はフィルタしない（0 も対象）
$target_column_all = '
(
      COALESCE(p.srv01,0) = 1
   OR COALESCE(p.srv02,0) = 1
   OR COALESCE(p.srv03,0) = 1
   OR COALESCE(p.srv04,0) = 1
   OR COALESCE(p.srv05,0) = 1
)
';

$baseSql = "
SELECT 
    j.*,
    x.*,
    p.login_id,
    p.passwd_id,
    p.level_id,
    p.entry,
    p.srv01, p.srv02, p.srv03, p.srv04, p.srv05,
    p.samba_id
 FROM public.\"情報個人\" AS j
 JOIN public.\"情報個人メール拡張\" AS x
  ON j.cmp_id = x.cmp_id AND j.user_id = x.user_id
 JOIN public.passwd_tnas AS p
  ON j.cmp_id = p.cmp_id AND j.user_id = p.user_id
";

$F_CMPS   = csv_to_list($cfg['cmps'] ?? null);
$F_USERS  = csv_to_list($cfg['users'] ?? null);
$F_UIDS   = csv_to_list($cfg['uids'] ?? null);
$F_LIKES  = csv_to_list($cfg['likes'] ?? null);
$F_WHERE  = (string)($cfg['where'] ?? '');

$wheres = [];
$wheres[] = $target_column_all; // ← 余計な()で包まず、そのまま入れる
$wheres[] = "(p.user_id >= 100 OR p.user_id = 1)";

if ($F_CMPS)  { $wheres[] = "j.cmp_id  IN " . sql_in($F_CMPS, true); }
if ($F_USERS) { $wheres[] = "j.user_id IN " . sql_in($F_USERS, true); }
if ($F_UIDS)  { $wheres[] = "p.login_id IN " . sql_in($F_UIDS, false); }
if ($F_LIKES) {
    $likes = [];
    foreach ($F_LIKES as $pat) {
        $like = str_replace("'", "''", $pat);
        $likes[] = "p.login_id LIKE '%{$like}%'";
    }
    if ($likes) $wheres[] = '(' . implode(' OR ', $likes) . ')';
}
if ($F_WHERE !== '') $wheres[] = '(' . $F_WHERE . ')';

$whereSql = $wheres ? "WHERE\n  " . implode("\n  AND ", $wheres) . "\n" : "";
$orderSql = "ORDER BY j.cmp_id ASC, j.user_id ASC";
$sql = $baseSql . $whereSql . $orderSql;

$DBG("SQL=\n{$sql}");
echo "DB: fetch target rows …\n";
$rows = $pdo->query($sql)->fetchAll();
echo "[INFO] DB rows (pre-filter): " . count($rows) . "\n";
echo "[INFO] DB rows (post-filter): " . count($rows) . "\n";


//print_r($rows[0]);
//exit;


$domain_sid = LdapUtil::inferDomainSid($ds, $baseDn);

if (!$domain_sid) {
    echo "DomainSID を取得できませんでした。sambaDomain の有無や 'net getlocalsid' を確認してください。";
    exit(4);
}
echo "SID! 取得したドメインSID: $domain_sid\n";

//============================================================
// LDAP 付帯情報
//============================================================
if ($cfg['ldap'] || $cfg['home'] || $INIT) {
    if (!$baseDn) {
        $baseDn = Env::str('LDAP_BASE_DN', 'dc=e-smile,dc=ne,dc=jp');
        if ($baseDn) echo "[INFO] base-dn 未指定のため既定値を使用: {$baseDn}\n";
    }
    if (!$baseDn) { fwrite(STDERR, "[ERROR] base-dn は必須です\n"); exit(2); }

    $peopleDn = infer_people_ou($ds, (string)$baseDn, $DBG);
    if ($peopleDn) echo "[INFO] People OU: {$peopleDn}（自動検出）\n";

    $sid = detect_domain_sid($ds, (string)$baseDn);
    if ($sid) echo "[INFO] Domain SID: {$sid}\n";
}

//============================================================
// INIT モード（初期化：DB NULL化 + LDAP削除 + 旧命名掃除）
//============================================================
if ($INIT) {
    echo "=== INIT MODE ===\n";
    $pdo->beginTransaction();
    $psNull = $pdo->prepare('UPDATE public.passwd_tnas SET samba_id = NULL WHERE cmp_id = :cmp AND user_id = :uid');
    $delCnt = 0; $nulCnt = 0;

    foreach ($rows as $r) {
        $cmp  = (int)$r['cmp_id'];
        $uidn = (int)$r['user_id'];

        // DB: samba_id を NULL
        if ($APPLY) { $psNull->execute([':cmp'=>$cmp, ':uid'=>$uidn]); $nulCnt += $psNull->rowCount(); }

        // LDAP: --ldap の時のみ delete（DB参照 + 正式UID + 旧命名UID）
        if (!empty($cfg['ldap']) && $ds) {
            $cands = [];

            // 1) DB の samba_id を優先候補に
            $sidDb = trim((string)($r['samba_id'] ?? ''));
            if ($sidDb !== '') $cands[] = $sidDb;

            // 2) かなから正式 UID（sei-mei + mid直結）を再生成して候補追加
            $seiKana = trim((string)($r['姓かな'] ?? ''));
            $meiKana = trim((string)($r['名かな'] ?? ''));
            $midKana = trim((string)($r['ミドルネーム'] ?? ''));
            $legacy_base = null;
            if ($seiKana !== '' && $meiKana !== '') {
                $sei = sanitize_uid_part(kanaToRomaji($seiKana));
                $mei = sanitize_uid_part(kanaToRomaji($meiKana));
                $mid = $midKana !== '' ? sanitize_uid_part(kanaToRomaji($midKana)) : '';
                $uid_now = "{$sei}-{$mei}" . ($mid !== '' ? $mid : '');
                if ($uid_now !== '' && !in_array($uid_now, $cands, true)) $cands[] = $uid_now;

                // 3) ★ 旧命名（sei-mei-2..99）も候補に追加
                $legacy_base = "{$sei}-{$mei}";
                for ($i = 2; $i <= 99; $i++) {
                    $cand = "{$legacy_base}-{$i}";
                    if ($cand !== $uid_now && !in_array($cand, $cands, true)) {
                        $cands[] = $cand;
                    }
                }
            }

            // People OU 基点で削除実施
            $candUniq = array_unique($cands);
            foreach ($candUniq as $uidStr) {
                if ($uidStr === '') continue;
                if (preg_match('/^(admin|root)$/i', $uidStr)) {
                    fwrite(STDERR, "[SKIP] 危険IDのため削除しません: {$uidStr}\n");
                    continue;
                }
                $entry = ldap_find_by_uid($ds, (string)$baseDn, $uidStr, $DBG);
                if ($entry) {
                    $dn = $entry['dn'];
                    echo "  [DEL] {$dn}\n";
                    if ($APPLY && !@ldap_delete($ds, $dn)) {
                        log_ldap_error($ERR_LOG, $cmp, $uidn, $uidStr, 'delete', $dn, $ds);
                    } else {
                        $delCnt++;
                    }
                }
            }
        }
    }

    $APPLY ? $pdo->commit() : $pdo->rollBack();
    echo "★ INIT 完了: LDAP削除 {$delCnt} 件 / samba_id NULL化 {$nulCnt} 件 (" . ($APPLY?'EXECUTE':'DRY-RUN') . ")\n";
    echo "=== DONE ===\n";
    exit(0);
}

//============================================================
// Main Loop（Upsert モード）
//============================================================
$gidDefault  = (int)$cfg['gid_users'];
$homeRoot    = rtrim((string)$cfg['home_root'], '/');
$idx = 0; $updCount = 0;

$pdo->beginTransaction();
$psUpd = $pdo->prepare('UPDATE public.passwd_tnas SET samba_id = :sid WHERE cmp_id = :cmp AND user_id = :uid');

foreach ($rows as $r) {
    $idx++;

    $cmp  = (int)$r['cmp_id'];
    $uidn = (int)$r['user_id'];
    $lvl  = isset($r['level_id']) ? (int)$r['level_id'] : 0;
    if ($lvl === 0) { $lvl = 99; } // 表示上は err-cls 99 として扱う
// ---------------------------------------------------------------------------------- passwd_tnas と passwd_mail は、同じ！ 2025.11.5
    $pwd  = isset($r['passwd_id']) ? (string)$r['passwd_id'] : '';

//	print_r($r);	
//	exit;

    // かな列（必須）
    $seiKana = trim((string)($r['姓かな'] ?? ''));
    $meiKana = trim((string)($r['名かな'] ?? ''));
    $midKana = trim((string)($r['ミドルネーム'] ?? ''));

    if ($seiKana === '' || $meiKana === '') {
        log_kana_missing($ERR_LOG, $cmp, $uidn, $r);
        continue;
    }

    // kakasi(UTF-8) → サニタイズ → uid="sei-mei" + mid（直結）
    $sei = sanitize_uid_part(kanaToRomaji($seiKana));
    $mei = sanitize_uid_part(kanaToRomaji($meiKana));
    $mid = $midKana !== '' ? sanitize_uid_part(kanaToRomaji($midKana)) : '';
    $login = "{$sei}-{$mei}" . ($mid !== '' ? $mid : '');

	// 並び順の決定ロジック（お好みで）
	// 例: 指定がなければ 9999、社員は 1000、役員は 10 など…
	$displayOrderInt = ($mid === '' ? '0' : $mid);
	$displayNameOrder = mb_convert_kana($seiKana.$meiKana, 'C', 'UTF-8');

//echo $displayNameOrder;
//exit;

    $uidNumber = calc_uid_number($cmp, $uidn);
    $gidNumber = calc_gid_number($cmp);

//  $gidNumber = $gidDefault; // 必要なら GroupDef::fromLevelId($lvl)['gid'] に差し替え可
//  $gidNumber = GroupDef::fromLevelId($lvl)['gid'];
//echo $gidNumber;
//exit;

    $homeLink  = "/home/{$login}";
    $homeReal  = $homeRoot . '/' . sprintf('%02d-%03d-%s', $cmp, $uidn, $login);
    $jpName    = ((string)($r['姓'] ?? '')) .' '. ((string)($r['名'] ?? ''));
	$empType   = level_to_label($lvl);

//	echo $cmp. " " .$uidn . " " . $lvl . " " . $empType . "\n";
//	print_r(GroupDef::fromLevelId($lvl));
//	exit;  
//	continue;

    // 表示用ラベル（色 + 10桁固定）
    $lblRaw = level_to_label($lvl);
    $lblCol = colorize_level($lblRaw);
    $plain  = strip_ansi($lblCol);
    $padLen = 10 + strlen($lblCol) - strlen($plain);
    $lblDisp= str_pad($lblCol, $padLen);


    // Up! 行（旧フォーマット）
    printf(
        "Up!  [%3d] [%02d-%03d] [uid: %6d gid: %4d] [%s] [%-20s] [CON] 更新 [%-10s] [%s]\n",
        $idx, $cmp, $uidn, $uidNumber, $gidNumber, $lblDisp, $login, $pwd, $jpName
    );


//============================================================
//	再設定
//============================================================

    $cn         = sprintf('%s %s', $r['名'], $r['姓']);
    $sn         = sprintf('%s',    $r['姓']);
    $givenName  = sprintf('%s',    $r['名']);
    $homeLink 	= sprintf('/home/%02d-%03d-%s', $cmp, $uidn, $login);
//  $homeOld 	= sprintf('/home/%02d-%03d-%s', $cmp, $uidn, $r['login_id']);
    $sambaSID	= $domain_sid . "-" . $uidNumber;
    $sambaPrimaryGroupSID = $domain_sid . "-" . $gidNumber;
    $salt		= random_bytes(4);
    $ssha		= normalize_password($pwd);
    $ntlm		= ntlm_hash($pwd);
    $pwdLastSet = time();

    if (!preg_match('/^[0-9A-F]+$/', $ntlm)) {
        echo "Err! [$uid] NTLM hash の作成失敗\n";
        continue;
    }

	$primaryDomain	 = Env::str('MAIL_PRIMARY_DOMAIN', 'esmile-holdings.com');
	$extraDomainsCsv = Env::str('MAIL_EXTRA_DOMAINS', ''); // 例: "esmile-soltribe.com, esmile-systems.jp"
	$extraDomains	 = array_values(array_filter(array_map('trim', explode(',', $extraDomainsCsv))));

	$mailAddrs		 = [ sprintf('%s@%s', $login, $primaryDomain) ];
	foreach ($extraDomains as $dom) {
	     if ($dom !== '') $mailAddrs[] = sprintf('%s@%s', $login, $dom);
	}
	$mailAddrs = array_values(array_unique($mailAddrs));

	$mailAlternateAddress = [
		$r['携帯メール'], 							// makoto.shiozumi@docomo.ne.jp
		$r['自宅メール'],							// shiozumi.makoto@gmail.com
		$r['電子メールアドレスLDAP登録'],			// shiozumi@e-smile.ne.jp
		$r['電子メールアドレスお名前ドットコム'],	// shiozumi@esmile-hd.jp
		$r['電子メールアドレス自社サーバー']		// shiozumi-makoto@esmile-holdings.com
	];

	// 空要素を削除（null, 空文字, 0文字など）
	$mailAlternateAddress = array_filter($mailAlternateAddress, function($v) {
    	return isset($v) && $v !== '';
	});

	// （必要なら添字を振り直す）
	$mailAlternateAddress = array_values($mailAlternateAddress);

// ★ ここで mailAlternateAddress を mail にも追加！
//	$mailAddrs = array_merge($mailAddrs, $mailAlternateAddress);
//	$mailAddrs = array_values(array_unique($mailAddrs));

//	print_r($mailAlternateAddress);
//	print_r($mailAddrs);
//	exit;

/*
	[電子メールアドレス] => shiozumi@e-smile.ne.jp
    [携帯メール] => makoto.shiozumi@docomo.ne.jp
    [自宅メール] => shiozumi.makoto@gmail.com
    [電子メールアドレスLDAP登録] => shiozumi@e-smile.ne.jp
    [電子メールアドレスお名前ドットコム] => shiozumi@esmile-hd.jp
    [電子メールアドレス自社サーバー] => shiozumi-makoto@esmile-holdings.com
*/


/*
echo $cn."\n";
echo $sn."\n";
echo $pwd."\n";
echo $givenName."\n";
echo $homeLink."\n";
echo $homeOld."\n";
echo $sambaSID."\n";
echo $sambaPrimaryGroupSID."\n";
echo $ssha."\n";
echo $pwdLastSet."\n";
exit;
*/

/*
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
*/


// === Mail アドレス生成（環境変数/ini で制御）========================
// 既定ドメイン: MAIL_PRIMARY_DOMAIN（なければ esmile-holdings.com）
// 追加ドメイン: MAIL_EXTRA_DOMAINS（カンマ区切り、任意）
//
//  export MAIL_PRIMARY_DOMAIN=esmile-holdings.com
//  export MAIL_EXTRA_DOMAINS="esmile-soltribe.com, esmile-systems.jp"//
// =====================================================================

    // LDAP upsert（進捗の [MOD]/[ADD] 行は出さない）
    if (!empty($cfg['ldap'])) {

        $entry = ldap_find_by_uid($ds, (string)$baseDn, $login, $DBG);
        if ($entry) {

            // メール配列の正規化（重複排除・小文字化・並び安定）
            $norm_mails = static function($v): array {
                if ($v === null || $v === '') return [];
                $arr = is_array($v) ? $v : [$v];
                $arr = array_map('strtolower', array_map('trim', $arr));
                $arr = array_values(array_unique($arr));
                sort($arr);
                return $arr;
            };
    
            // ===== 既存あり → MODIFY（差分REPLACEのみ） =====
            $dn    = $entry['dn'];
            $attrs = $entry['attrs'] ?? [];
            if (!is_array($attrs)) $attrs = [];
    
            // ← ここが “$desired” 本体（build_modify_attrs は使わない）
            $desired = [
                'cn'                   => $cn ?? null,
                'sn'                   => $sn ?? null,
                'givenName'            => $givenName ?? null,
                'displayName'          => ($jpName ?? '') !== '' ? $jpName : ($login ?? null),
                'uidNumber'            => isset($uidNumber) ? (string)$uidNumber : null,
                'gidNumber'            => isset($gidNumber) ? (string)$gidNumber : null,
                'employeeType'         => $empType ?? null,
                'homeDirectory'        => $homeLink ?? null,
                'loginShell'           => isset($cfg['shell']) ? (string)$cfg['shell'] : null,
                // パスワードは変更時のみ入れる（ normalize_password が未変更時 null/'' を返すのが理想）
                'userPassword'         => ($pwd ?? '') !== '' ? normalize_password($pwd) : null,
                'mail'                 => $norm_mails($mailAddrs ?? []),
                'sambaSID'             => $sambaSID ?? null,
                'sambaNTPassword'      => $ntlm ?? null,
                'sambaAcctFlags'       => '[U          ]',
                'sambaPwdLastSet'      => $pwdLastSet ?? null,
                'sambaPrimaryGroupSID' => $sambaPrimaryGroupSID ?? null,
                'displayOrderInt'      => isset($displayOrderInt) ? (string)$displayOrderInt : null,
                'displayNameOrder'     => isset($displayNameOrder) ? (string)$displayNameOrder : null,
                'mailAlternateAddress' => $norm_mails($mailAlternateAddress ?? []),
            ];
    
            // 空値を除去（null/''/[] は投げない）
            $desired = array_filter(
                $desired,
                static fn($v) => !($v === null || $v === '' || (is_array($v) && $v === []))
            );

            // 差分を REPLACE に落とす
            $ops = build_ops_for_replace($attrs, $desired);  // ← ここで $desired が必ず配列
    
            if ($APPLY && $ops) {
                if (!@ldap_modify_batch($ds, $dn, $ops)) {
                    log_ldap_error($ERR_LOG, $cmp, $uidn, $login, 'modify-batch', $dn, $ds);
                    echo "Err Up! ------------------------------------------------------------- $dn\n";
                }
            }

/*
            $dn   = $entry['dn'];
            $mods = build_modify_attrs($entry['attrs'], [
                'cn'            => $cn,
                'sn'            => $sn,
                'givenName'     => $givenName,
                'displayName'   => $jpName ?: $login,
                'uidNumber'     => (string)$uidNumber,
                'gidNumber'     => (string)$gidNumber,
        		'employeeType'  => $empType,
                'homeDirectory' => $homeLink,
                'loginShell'    => (string)$cfg['shell'],
                'userPassword'  => normalize_password($pwd),
                'mail'          => $mailAddrs, // mail は multi-valued 属性。プライマリ＋（任意で）追加ドメインを付与
                'sambaSID'				=> $sambaSID,
                'sambaNTPassword'		=> $ntlm,
                'sambaAcctFlags' 		=> '[U          ]',
                'sambaPwdLastSet'		=> $pwdLastSet,
                'sambaPrimaryGroupSID'  => $sambaPrimaryGroupSID,
		        'displayOrderInt'       => (string)$displayOrderInt,
		        'displayNameOrder'      => (string)$displayNameOrder,
		        'mailAlternateAddress'  => $mailAlternateAddress,
            ], $DBG);

            if ($mods && $APPLY && !@ldap_modify($ds, $dn, $mods)) {
                log_ldap_error($ERR_LOG, $cmp, $uidn, $login, 'modify', $dn, $ds);
				echo "Err Up! ------------------------------------------------------------- $dn\n";
            }

*/

/*
			echo "<pre>";
			print_r($mods);
			echo $pwd;
			exit;
*/
//				print_r($entry['attrs']);
//				print_r($mods);
//				echo "update!! ------------------------------------------------------------- $empType password = $pwd = $displayOrderInt \n";
//				exit;

        } else {

            $dnBase = infer_people_ou($ds, (string)$baseDn, $DBG) ?: (string)$baseDn;
            $dn = "uid={$login}," . $dnBase;
            $attrs = [
                'objectClass'   => ['inetOrgPerson','posixAccount','shadowAccount','sambaSamAccount','emMailAux'],
                'uid'           => $login,
                'cn'            => $cn,
                'sn'            => $sn,
                'givenName'     => $givenName,
                'displayName'   => $jpName ?: $login,
                'uidNumber'     => (string)$uidNumber,
                'gidNumber'     => (string)$gidNumber,
        		'employeeType'  => $empType,
                'homeDirectory' => $homeLink,
                'loginShell'    => (string)$cfg['shell'],
                'userPassword'  => normalize_password($pwd) ?? null,
                'mail'          => $mailAddrs, // mail は multi-valued 属性。プライマリ＋（任意で）追加ドメインを付与
                'sambaSID'				=> $sambaSID,
                'sambaNTPassword'		=> $ntlm,
                'sambaAcctFlags' 		=> '[U          ]',
                'sambaPwdLastSet'		=> $pwdLastSet,
                'sambaPrimaryGroupSID'  => $sambaPrimaryGroupSID,
		        'displayOrderInt'       => (string)$displayOrderInt,
		        'displayNameOrder'      => (string)$displayNameOrder,
		        'mailAlternateAddress'  => $mailAlternateAddress,
            ];	


            if ($APPLY && !@ldap_add($ds, $dn, array_filter($attrs, fn($v)=>$v!==null && $v!==''))) {
                log_ldap_error($ERR_LOG, $cmp, $uidn, $login, 'add', $dn, $ds);
				echo "Err Add ------------------------------------------------------------- $dn\n";
            }

//				print_r($attrs);
//				echo "Nww Add ------------------------------------------------------------- $dn : $empType password = $pwd : $displayOrderInt app = $APPLY\n";
//				exit;

        }



    //============================================================
    // Thunderbird AddressBook 自動生成（ou=AddressBook）
    //============================================================
    if (!empty($cfg['ldap']) && $ds && !empty($mailAlternateAddress)) {
        $baseAB = 'ou=AddressBook,dc=e-smile,dc=ne,dc=jp';
        $chk = @ldap_search($ds, 'dc=e-smile,dc=ne,dc=jp', '(ou=AddressBook)');
        if (!$chk || ldap_count_entries($ds, $chk) === 0) {
            $ouEntry = [
                'objectClass' => ['top','organizationalUnit'],
                'ou' => 'AddressBook',
                'description' => 'Thunderbird用アドレス帳 (自動生成)'
            ];
            if ($APPLY) {
                @ldap_add($ds, $baseAB, $ouEntry);
                echo "  [ADD] ou=AddressBook を新規作成\n";
            } else {
                echo "  [DRY] would create ou=AddressBook\n";
            }
        }
    

    foreach ($mailAlternateAddress as $addr) {
        if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) continue;
        $dnAB = sprintf('mail=%s,%s', $addr, $baseAB);
    
        // 表示用の氏名（例: "塩住 誠"）
        // $displayName = trim($sn . ' ' . $givenName);
		$displayName = sprintf( "%d-%03d %s", $cmp, $uidn, ($jpName ?: $login) );

        // メールユーザー名部分をカッコで添える
        $cnAB = sprintf('%s (%s)', $displayName, preg_replace('/@.*/', '', $addr));


/*
        $entryAB = [
            'objectClass' => ['inetOrgPerson'],
            'cn'          => $cnAB,
            'displayName' => $displayName,   // ★ これを追加
            'sn'          => $sn ?: $displayName,
            'givenName'   => $givenName ?: '',
            'mail'        => $addr,
            'seeAlso'     => sprintf('uid=%s,ou=Users,dc=e-smile,dc=ne,dc=jp', $login),
        ];
*/

      $entryAB = [
          'objectClass' => ['inetOrgPerson'],
          'cn'          => $cnAB,
          'displayName' => $displayName,
//          'title'       => '役職',
//          'ou'          => '所属部署',
//          'description' => '備考',
          'sn'          => $sn ?: $displayName,
          'givenName'   => $givenName ?: '',
          'mail'        => $addr,
          'seeAlso'     => sprintf('uid=%s,ou=Users,dc=e-smile,dc=ne,dc=jp', $login),
      ];

//		print_r($entryAB);
//		exit;
    
        $exists = @ldap_read($ds, $dnAB, '(objectClass=inetOrgPerson)');
        if ($exists && ldap_count_entries($ds, $exists) > 0) {
            if ($APPLY) {
                @ldap_modify($ds, $dnAB, $entryAB);
//              echo "  [update] AddressBook: {$addr}\n";
            } else {
//              echo "  [DRY] would update AddressBook: {$addr}\n";
            }
        } else {
            if ($APPLY) {
                @ldap_add($ds, $dnAB, $entryAB);
//              echo "  [add] AddressBook: {$addr}\n";
            } else {
//              echo "  [DRY] would add AddressBook: {$addr}\n";
            }
        }
    }


	}


    //============================================================
    // Thunderbird AddressBook 自動生成（End!）
    //============================================================


//	print_r($mailAlternateAddress);
//	print_r($mailAddrs);
//	exit;


    }

    // HOME 整備
    if (!empty($cfg['home'])) {

		if ($cfg['maildir-only']) {
		    ensure_maildir($homeLink, $login, $APPLY, $DBG);
		} else {
	        ensure_home($homeReal, $homeLink, $login, $APPLY, $DBG, true);
		}
    }

    // DB: samba_id 更新
    if ($APPLY) {
        $psUpd->execute([':sid'=>$login, ':cmp'=>$cmp, ':uid'=>$uidn]);
        $updCount += $psUpd->rowCount();
    }

/*
echo "\n";
echo "-------------------------------------- " . $dn.$dnBase;
echo "\n";
echo "end!";
echo "\n";
exit;  
*/


}

$APPLY ? $pdo->commit() : $pdo->rollBack();

//============================================================
// フッター（旧式スタイル）
//============================================================
echo "\nLDAP 更新対象: " . count($rows) . " 件\n";
echo "[INFO] SQL OK: passwd_tnas.samba_id 補完 => {$updCount} 件\n\n";
echo "★ 完了: LDAP/HOME 同期 (" . ($APPLY ? "EXECUTE" : "DRY-RUN") . ") / 対象 " . count($rows) . " 件\n";
echo "★ 例: 検索確認\n";
if ($uri) {
    $bindDn = $cfg['bind_dn'] ?? 'cn=Admin,' . ($baseDn ?? '');
    $masked = '********';
    $ou = infer_people_ou($ds, (string)$baseDn, $DBG) ?: "ou=Users,{$baseDn}";
    echo "  ldapsearch -x -H {$uri} -D \"{$bindDn}\" -w {$masked} -b \"{$ou}\" \"(uid=*)\" dn\n";
}
echo "=== DONE ===\n";
exit(0);

//============================================================
// 関数群
//============================================================

/** モード推定: ldapi/ldaps/ldap */
function parse_ldap_mode(string $uri): string {
    if (str_starts_with($uri, 'ldapi://')) return 'ldapi';
    if (str_starts_with($uri, 'ldaps://')) return 'ldaps';
    if (str_starts_with($uri, 'ldap://'))  return 'ldap';
    return '-';
}

/** CSV → 配列 */
function csv_to_list(?string $csv): array {
    if ($csv === null || trim($csv) === '') return [];
    $parts = array_map('trim', explode(',', $csv));
    return array_values(array_filter($parts, fn($v)=>$v!==''));
}

/** IN 句生成 */
function sql_in(array $vals, bool $isNumeric): string {
    if ($isNumeric) {
        $nums = array_map(fn($v)=>(string)(int)$v, $vals);
        return '(' . implode(',', $nums) . ')';
    } else {
        $qs = [];
        foreach ($vals as $v) $qs[] = "'" . str_replace("'", "''", (string)$v) . "'";
        return '(' . implode(',', $qs) . ')';
    }
}

/** kakasi(UTF-8) で仮名→ローマ字（小文字化・空白/アポストロフィ除去） */
function kanaToRomaji(string $kana): string {
    $arg = escapeshellarg($kana);
    $out = shell_exec("echo $arg | kakasi -i utf8 -o utf8 -Ja -Ha -Ka -Ea -s -r") ?? '';
    $rom = strtolower(str_replace([' ', "'"], '', trim((string)$out)));
    return $rom;
}

// NTLM変換関数
function ntlm_hash($password) {
    $utf16 = mb_convert_encoding($password, "UTF-16LE");
    return strtoupper(bin2hex(hash('md4', $utf16, true)));
}

/** UID パーツを英数字だけに。空なら 'x' */
function sanitize_uid_part(string $s): string {
    $t = preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
    return $t !== '' ? $t : 'x';
}

/** uidNumber: cmp_id*10000 + user_id */
function calc_uid_number(int $cmpId, int $userId): int {
    return $cmpId * 10000 + $userId;
}

/** uidNumber: cmp_id + 2000 */
function calc_gid_number(int $cmpId): int {
    return $cmpId + 2000;
}

/** People/Users OU 推測 */
function infer_people_ou(?\LDAP\Connection $ds, string $baseDn, callable $dbg): ?string {
    $dn = "ou=Users,$baseDn";
    $dbg("[DBG] 固定OUを使用: $dn");
    return $dn;
}

/*
function infer_people_ou(?\LDAP\Connection $ds, string $baseDn, callable $dbg): ?string {
    if (!$ds) return null;
    $res = @ldap_search($ds, $baseDn, '(|(ou=People)(ou=Users))', ['ou','dn'], 0, 2, 5);
    if ($res && ($entry = ldap_first_entry($ds, $res))) {
        $dn = ldap_get_dn($ds, $entry);
        return $dn ?: null;
    }
    return null;
}
*/


/** Domain SID 検出（Samba） */
function detect_domain_sid(?\LDAP\Connection $ds, string $baseDn): ?string {
    if (!$ds) return null;
    $res = @ldap_search($ds, $baseDn, '(objectClass=sambaDomain)', ['sambaSID'], 0, 1, 5);
    if (!$res) return null;
    $entry = @ldap_first_entry($ds, $res);
    if (!$entry) return null;
    $vals = @ldap_get_attributes($ds, $entry);
    if (!$vals || empty($vals['sambaSID'][0])) return null;
    return (string)$vals['sambaSID'][0];
}

/** uid=??? 検索 */
function ldap_find_by_uid(\LDAP\Connection $ds, string $baseDn, string $login, callable $dbg): ?array {
    $filter = sprintf('(uid=%s)', ldap_escape($login, '', LDAP_ESCAPE_FILTER));
    $res = @ldap_search($ds, $baseDn, $filter, ['*'], 0, 1, 10);
    if (!$res) return null;
    $entry = ldap_first_entry($ds, $res);
    if (!$entry) return null;
    return ['dn'=>ldap_get_dn($ds, $entry),'attrs'=>ldap_get_attributes($ds, $entry)];
}

/** 既存属性との差分を mods に */
function build_modify_attrs(array $current, array $desired, callable $dbg): array {
    $mods = [];
    foreach ($desired as $k => $v) {
        if ($v === null || $v === '') continue;
        $cur = ldap_attr_first($current, $k);
        if ($cur === null || $cur !== $v) $mods[$k] = $v;
    }
    return $mods;
}

/** LDAP属性の値を1つ取得（size配列を吸収） */
function ldap_attr_first(array $attrs, string $key): ?string {
    if (!isset($attrs[$key])) return null;
    $v = $attrs[$key];
    if (is_array($v)) {
        if (isset($v['count']) && $v['count'] > 0 && isset($v[0])) return (string)$v[0];
        foreach ($v as $vv) if (is_string($vv)) return $vv;
        return null;
    }
    if (is_string($v)) return $v;
    return null;
}

/** 平文は SSHA に、{SCHEME} 付きはそのまま、空は変更なし */
function normalize_password(?string $raw): ?string {
    if ($raw === null) return null;
    $raw = trim($raw);
    if ($raw === '') return null;
    if (preg_match('/^\{\w+\}/', $raw)) return $raw;
    $salt = random_bytes(8);
    $hash = sha1($raw . $salt, true);
    return '{SSHA}' . base64_encode($hash . $salt);
}

/** HOME 作成/リンク整備 */
function ensure_home(string $real, string $link, string $login, bool $apply, callable $dbg, bool $withMaildir=true): void {
    // 実体
    if (!is_dir($real)) {
        if ($apply) {
            if (!@mkdir($real, 0770, true)) { fwrite(STDERR, "[ERROR] mkdir失敗: {$real}\n"); }
            else { echo "  [MKD] {$real}\n"; }
        } else {
            echo "  [DRY] mkdir {$real}\n";
        }
    } else {
        $dbg("exists: {$real}");
    }
    // /home はシンボリックリンク
    if (is_link($link)) {
        $to = readlink($link);
        if ($to !== $real) {
            if ($apply) {
                @unlink($link);
                if (@symlink($real, $link)) echo "  [LNK] {$link} -> {$real}\n";
                else fwrite(STDERR, "[ERROR] symlink失敗: {$link}\n");
            } else {
                echo "  [DRY] relink {$link} -> {$real}\n";
            }
        } else {
            $dbg("link ok: {$link} -> {$to}");
        }
    } elseif (file_exists($link) && !is_link($link)) {
        fwrite(STDERR, "[WARN] /home 側に衝突: {$link}\n");
    } else {
        if ($apply) {
            if (@symlink($real, $link)) echo "  [LNK] {$link} -> {$real}\n";
            else fwrite(STDERR, "[ERROR] symlink失敗: {$link}\n");
        } else {
            echo "  [DRY] link {$link} -> {$real}\n";
        }
    }

    // Maildir 準備（任意）
    if ($withMaildir) {
        ensure_maildir($real, $login, $apply, $dbg);
    }
}


/** Maildir を作成/権限整備（cur/new/tmp & 所有者） */
function ensure_maildir(string $homeReal, string $login, bool $apply, callable $dbg): void {
    $maildir = rtrim($homeReal, '/').'/Maildir';
    $need    = [$maildir, "$maildir/cur", "$maildir/new", "$maildir/tmp"];

    // 所有者解決
    $uid = $gid = null;
    if (function_exists('posix_getpwnam')) {
        if ($pw = @posix_getpwnam($login)) {
            $uid = $pw['uid'] ?? null;
            $gid = $pw['gid'] ?? null;
        }
    }

    foreach ($need as $d) {
        if (!is_dir($d)) {
            if ($apply) {
                if (!@mkdir($d, 0700, true)) {
                    fwrite(STDERR, "[ERROR] Maildir mkdir失敗: {$d}\n");
                } else {
                    echo "  [MKD] {$d}\n";
                }
            } else {
                echo "  [DRY] mkdir {$d}\n";
            }
        } else {
            $dbg("exists: {$d}");
            // 既存でもパーミッションを整える（0700 推奨）
            if ($apply) { @chmod($d, 0700); }
        }
    }

    // 所有者調整（root 実行が前提）
    if ($uid !== null && $gid !== null) {
        // Maildir 配下のみ（home 全体に触りたくない場合）
        if ($apply) {
            // 再帰 chown
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($maildir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ([$maildir] as $top) { @chown($top, $uid); @chgrp($top, $gid); }
            foreach ($it as $path => $info) {
                @chown($path, $uid);
                @chgrp($path, $gid);
            }
            echo "  [OWN] chown -R {$login}:{$gid} {$maildir}\n";
        } else {
            echo "  [DRY] chown -R {$login}:{$gid} {$maildir}\n";
        }
    } else {
        fwrite(STDERR, "[WARN] 所有者未解決（posix_getpwnam 失敗）: {$login}\n");
    }

    // （任意）Sieve の最小雛形
    $sieveDir  = rtrim($homeReal,'/').'/.sieve';
    $sieveFile = rtrim($homeReal,'/').'/.dovecot.sieve';
    if (!is_dir($sieveDir)) {
        if ($apply) {
				@mkdir($sieveDir, 0700);
				echo "  [MKD] {$sieveDir}\n";
				if ($uid!==null && $gid!==null) { 
					@chown($sieveDir,$uid); @chgrp($sieveDir,$gid);
				}
		} else { echo "  [DRY] mkdir {$sieveDir}\n"; }
    }
    if (!file_exists($sieveFile)) {
        $content = "require [\"fileinto\"];\n# 例: 迷惑メールをJunkへ\n#if header :contains \"X-Spam-Flag\" \"YES\" { fileinto \"Junk\"; stop; }\n";
        if ($apply) {
            if (@file_put_contents($sieveFile, $content) !== false) {
                @chmod($sieveFile, 0600);
                if ($uid!==null && $gid!==null) { @chown($sieveFile,$uid); @chgrp($sieveFile,$gid); }
                echo "  [NEW] {$sieveFile}\n";
            } else {
                fwrite(STDERR, "[ERROR] Sieve 雛形作成失敗: {$sieveFile}\n");
            }
        } else {
            echo "  [DRY] create {$sieveFile}\n";
        }
    }
}	



/** 職位ラベル（GroupDef 利用） */
function level_to_label(?int $lv): string {
    if ($lv === null) return 'cls 0';
    $def = GroupDef::fromLevelId($lv);
    if ($def) return sprintf('%s %d', $def['name'], $lv);
    return 'cls ' . $lv;
}

/** 色付け（adm=赤, dir=青, mgr=マゼンタ, mgs/stf=シアン, ent=緑, err=明赤） */
function colorize_level(string $label): string {
    $map = [
        'adm-cls' => "\033[31m",
        'dir-cls' => "\033[34m",
        'mgr-cls' => "\033[35m",
        'mgs-cls' => "\033[36m",
        'stf-cls' => "\033[36m",
        'ent-cls' => "\033[32m",
        'err-cls' => "\033[91m",
    ];
    foreach ($map as $k=>$c) {
        if (str_starts_with($label, $k)) {
            return $c . $label . "\033[0m";
        }
    }
    return $label;
}

/** ANSIカラーコードを除去（表示幅計算用） */
function strip_ansi(string $s): string {
    return preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $s);
}

/** パスワードの先頭3文字＋……表示 */
function mask_pwd3(?string $pw): string {
    if ($pw === null) return '--------';
    $pw = (string)$pw;
    if ($pw === '')  return '--------';
    return mb_substr($pw, 0, 3) . '......';
}

/** かな欠落ログ */
function log_kana_missing(string $file, int $cmp, int $uid, array $row): void {
    $line = sprintf("[%s] cmp=%d user=%d reason=missing_kana\n", date('Y-m-d H:i:s'), $cmp, $uid);
    @file_put_contents($file, $line, FILE_APPEND);
    fwrite(STDERR, "[SKIP] かな列不足: cmp={$cmp} user={$uid}\n");
}

/** LDAPエラーログ */
function log_ldap_error(string $f, int $cmp, int $uid, string $login, string $op, string $dn, \LDAP\Connection $ds): void {
    $code = @ldap_errno($ds); $msg = @ldap_error($ds);
    $line = sprintf("[%s] cmp=%d user=%d op=%s uid=%s dn=\"%s\" code=%d msg=%s\n",
        date('Y-m-d H:i:s'), $cmp, $uid, $op, $login, $dn, (int)$code, (string)$msg);
    @file_put_contents($f, $line, FILE_APPEND);
}

/** 秘匿表示 */
function mask_secret(?string $v): string {
    if ($v === null || $v === '') return '--------';
    return '********';
}




/*
// 1) 既存属性($entry['attrs']) と 望ましい属性($desired) を突き合わせて
//    "変わるものだけ" を REPLACE する ops に落とす
*/

// 受け取りは null 許容にして空配列へ補正
function build_ops_for_replace(array $currentAttrs, ?array $desired): array {
    $desired = $desired ?? [];
    $ops = [];

    // 正規化: 配列は各要素を trim＋string化、空は除外、重複除去、並び安定
    $norm = function($v) {
        if (is_array($v)) {
            $vv = array_map(function($x){
                // 文字列はそのまま、数値等のスカラーは文字列化、その他は空に
                if (is_string($x)) return trim($x);
                if (is_scalar($x)) return trim((string)$x);
                return '';
            }, $v);
            // 空を除去
            $vv = array_values(array_filter($vv, static fn($s) => $s !== ''));
            // 重複除去＆ソート
            $vv = array_values(array_unique($vv, SORT_REGULAR));
            sort($vv, SORT_STRING);
            return $vv;
        }
        // 単値
        if (is_string($v)) return trim($v);
        if (is_scalar($v)) return trim((string)$v);
        return '';
    };

    // 既存属性を正規化（比較用にキーは小文字化）
    $cur = [];
    foreach ($currentAttrs as $k => $v) {
        if ($k === 'dn') continue;
        $cur[strtolower((string)$k)] = $norm($v);
    }

    foreach ($desired as $k => $v) {
        $attr = strtolower((string)$k);
        // 空値はそもそも対象外
        if ($v === null || $v === '' || (is_array($v) && $v === [])) continue;

        $val = $norm($v);

        // mail系は小文字化。ただし **必ず文字列化** してから。
        if ($attr === 'mail' || $attr === 'mailalternateaddress') {
            if (is_array($val)) {
                $val = array_map(static fn($s) => strtolower((string)$s), $val);
            } else {
                $val = strtolower((string)$val);
            }
            // 重複除去＆並び安定（再度）
            $val = is_array($val) ? array_values(array_unique($val)) : $val;
            if (is_array($val)) sort($val, SORT_STRING);
        }

        // SambaNTPassword は大文字HEX統一
        if ($attr === 'sambantpassword' && is_string($val)) {
            $val = strtoupper($val);
        }

        $was = $cur[$attr] ?? null;

        // 等価判定（配列は JSON 比較、単値は文字列化して比較）
        $same = (is_array($val) || is_array($was))
              ? (json_encode($val) === json_encode($was))
              : ((string)$val === (string)$was);

        if (!$same) {
            $ops[] = [
                'attrib'  => $k,
                'modtype' => LDAP_MODIFY_BATCH_REPLACE,
                'values'  => is_array($val) ? $val : [(string)$val],
            ];
        }
    }

    return $ops;
}
