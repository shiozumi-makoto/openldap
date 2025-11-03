#!/usr/bin/env php
<?php
/**
 * ---------------------------------------------------------------------------
 * ldap_id_pass_from_postgres_set.php
 * ---------------------------------------------------------------------------
 * 【概要】
 *   PostgreSQL の社員マスタ情報を LDAP に同期し、
 *   アカウント情報（uid, パスワード, グループ, メールアドレス等）を登録する。
 *
 *   さらに Thunderbird 対応として、各ユーザーの mailAlternateAddress をもとに
 *   ou=AddressBook,dc=e-smile,dc=ne,dc=jp 以下に
 *   メールアドレス単位で軽量エントリを自動生成する。
 * ---------------------------------------------------------------------------
 * 【Thunderbird用 AddressBookエントリ構成】
 *   dn: mail=makoto.shiozumi@docomo.ne.jp,ou=AddressBook,dc=e-smile,dc=ne,dc=jp
 *   objectClass: inetOrgPerson
 *   cn: 塩住 誠 (docomo)
 *   sn: 塩住
 *   givenName: 誠
 *   mail: makoto.shiozumi@docomo.ne.jp
 *   seeAlso: uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp
 * ---------------------------------------------------------------------------
 */

require_once __DIR__ . '/autoload.php';
use Tools\Lib\Env;
use Tools\Lib\Config;
use Tools\Lib\LdapConnector;

// ======================================================
// 1) PostgreSQL から社員情報を取得
// ======================================================
$env = Env::load();
$cfg = Config::load();
$ldap = new LdapConnector();
$pg   = $env->getPgConnection();

$sql = "SELECT * FROM public.\"情報個人\" WHERE 削除フラグ = false ORDER BY 社員番号";
$res = pg_query($pg, $sql);
if (!$res) {
    echo "[ERROR] PostgreSQL query failed\n";
    exit(1);
}

while ($r = pg_fetch_assoc($res)) {
    $login  = $r['ログインID'];
    $uid    = $r['社員番号'];
    $cn     = $r['氏名'];
    $sn     = $r['姓'];
    $given  = $r['名'];
    $passwd = $r['初期パスワード'] ?? 'password';
    $primaryDomain = "esmile-holdings.com";
    $extraDomains  = ['e-smile.ne.jp', 'esmile-hd.jp', 'esmile-soltribe.jp'];

    // --------------------------------------------------
    // メール関連属性生成
    // --------------------------------------------------
    $mailAddrs = [ sprintf('%s@%s', $login, $primaryDomain) ];
    foreach ($extraDomains as $dom) {
        if ($dom !== '') $mailAddrs[] = sprintf('%s@%s', $login, $dom);
    }
    $mailAddrs = array_values(array_unique($mailAddrs));

    $mailAlternateAddress = [
        $r['携帯メール'],                          // makoto.shiozumi@docomo.ne.jp
        $r['自宅メール'],                          // shiozumi.makoto@gmail.com
        $r['電子メールアドレスLDAP登録'],          // shiozumi@e-smile.ne.jp
        $r['電子メールアドレスお名前ドットコム'],  // shiozumi@esmile-hd.jp
        $r['電子メールアドレス自社サーバー']        // shiozumi-makoto@esmile-holdings.com
    ];

    // 空要素を削除
    $mailAlternateAddress = array_filter($mailAlternateAddress, fn($v) => isset($v) && $v !== '');
    $mailAlternateAddress = array_values($mailAlternateAddress);

    // Thunderbird対応：mailAlternateAddress を mail にも統合
    $mailAddrs = array_merge($mailAddrs, $mailAlternateAddress);
    $mailAddrs = array_values(array_unique($mailAddrs));

    // --------------------------------------------------
    // LDAPエントリ本体
    // --------------------------------------------------
    $dn = sprintf('uid=%s,ou=Users,dc=e-smile,dc=ne,dc=jp', $login);
    $entry = [
        'objectClass' => ['inetOrgPerson','posixAccount','shadowAccount','sambaSamAccount','emMailAux'],
        'uid'         => $login,
        'cn'          => $cn,
        'sn'          => $sn,
        'givenName'   => $given,
        'displayName' => $cn,
        'uidNumber'   => $r['uidNumber'] ?? 10000 + (int)$uid,
        'gidNumber'   => $r['gidNumber'] ?? 2000,
        'homeDirectory' => sprintf('/home/%02d-%03d-%s', 1, $uid, $login),
        'loginShell'  => '/bin/bash',
        'employeeType'=> $r['社員区分'] ?? 'adm-cls 1',
        'mail'        => $mailAddrs,
        'mailAlternateAddress' => $mailAlternateAddress,
    ];

    // 既存チェック
    $search = $ldap->read($dn);
    if ($search) {
        echo "[UPDATE] {$dn}\n";
        $ldap->modify($dn, $entry);
    } else {
        echo "[ADD] {$dn}\n";
        $ldap->add($dn, $entry);
    }

    // ======================================================
    // 2) Thunderbird AddressBook 同期処理
    // ======================================================
    echo "[AddressBook] sync for {$login}\n";

    $baseAB = 'ou=AddressBook,dc=e-smile,dc=ne,dc=jp';
    $chk = @ldap_search($ldap->conn, 'dc=e-smile,dc=ne,dc=jp', '(ou=AddressBook)');
    if (!$chk || ldap_count_entries($ldap->conn, $chk) === 0) {
        $ouEntry = [
            'objectClass' => ['top','organizationalUnit'],
            'ou' => 'AddressBook',
            'description' => 'Thunderbird用アドレス帳 (自動生成)'
        ];
        @ldap_add($ldap->conn, $baseAB, $ouEntry);
    }

    foreach ($mailAlternateAddress as $addr) {
        if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) continue;
        $dnAB = sprintf('mail=%s,%s', $addr, $baseAB);
        $cnAB = sprintf('%s (%s)', $cn, preg_replace('/@.*/', '', $addr));
        $snAB = $sn ?: $cn;
        $gnAB = $given ?: '';

        $entryAB = [
            'objectClass' => ['inetOrgPerson'],
            'cn'          => $cnAB,
            'sn'          => $snAB,
            'givenName'   => $gnAB,
            'mail'        => $addr,
            'seeAlso'     => sprintf('uid=%s,ou=Users,dc=e-smile,dc=ne,dc=jp', $login),
        ];

        $exists = @ldap_read($ldap->conn, $dnAB, '(objectClass=inetOrgPerson)');
        if ($exists && ldap_count_entries($ldap->conn, $exists) > 0) {
            @ldap_modify($ldap->conn, $dnAB, $entryAB);
            echo "  [update] {$addr}\n";
        } else {
            @ldap_add($ldap->conn, $dnAB, $entryAB);
            echo "  [add] {$addr}\n";
        }
    }

    echo "--------------------------------------------------------\n";
}

pg_close($pg);
echo "[FINISHED] LDAP sync complete.\n";
exit(0);


