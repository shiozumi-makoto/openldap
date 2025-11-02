<?php
declare(strict_types=1);

(function (): void {

    // --- 引数パース（--help 系なら出力して exit） ---
    $argvList = array_map('strval', $GLOBALS['argv'] ?? []);
    $wantHelp = false;
    foreach (['--help','-h','help','/?','?'] as $h) {
        if (in_array($h, $argvList, true)) { $wantHelp = true; break; }
    }
    if (!$wantHelp) return;

    // --- 端末が TTY かどうか（パイプ/リダイレクト時は色を切る） ---
    $isTty = function (): bool {
        if (!defined('STDOUT')) return false;
        return function_exists('posix_isatty') ? @posix_isatty(STDOUT) : true;
    };

    // --- 色クラスの有無 + TTY で色出力可否を決定 ---
    $useColor = $isTty() && class_exists(\Tools\Lib\CliColor::class);

    // --- 色付け関数（常にクロージャにする：配列コーラブルは NG） ---
    $B = $useColor
        ? static fn(string $s): string => \Tools\Lib\CliColor::bold($s)
        : static fn(string $s): string => $s;
    $Y = $useColor
        ? static fn(string $s): string => \Tools\Lib\CliColor::yellow($s)
        : static fn(string $s): string => $s;
    $G = $useColor
        ? static fn(string $s): string => \Tools\Lib\CliColor::green($s)
        : static fn(string $s): string => $s;
    $C = $useColor
        ? static fn(string $s): string => \Tools\Lib\CliColor::cyan($s)
        : static fn(string $s): string => $s;

    // --- 既定値を実際の計算で表示（Env::get が無ければ素の getenv） ---
    $envGet = function(string $k, ?string $alt=null, ?string $def=null): ?string {
        if (class_exists(\Tools\Ldap\Env::class)) {
            return \Tools\Ldap\Env::get($k, $alt, $def);
        }
        $v = getenv($k); if ($v!==false && $v!=='') return $v;
        if ($alt) { $v = getenv($alt); if ($v!==false && $v!=='') return $v; }
        return $def;
    };

    $baseDn   = $envGet('BASE_DN','LDAP_BASE_DN','dc=e-smile,dc=ne,dc=jp');
    $peopleOu = $envGet('PEOPLE_OU', null, "ou=Users,{$baseDn}");
    $groupOu  = $envGet('GROUPS_OU',  null, "ou=Groups,{$baseDn}");
    $usersDn  = $envGet('USERS_GROUP_DN', null, "cn=users,{$groupOu}");
    $ldapUrl  = $envGet('LDAP_URL','LDAP_URI', $envGet('LDAPURI', null, 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi'));

    // --- 見出し・強調語は先に色付けして変数化（Heredoc 内で呼び出し不可のため） ---
    $H_NAME   = $B('NAME');
    $H_SYN    = $B('SYNOPSIS');
    $H_DESC   = $B('DESCRIPTION');
    $H_OPTS   = $B('OPTIONS');
    $H_ENV    = $B('ENVIRONMENT');
    $H_CONN   = $B('CONNECTION POLICY');
    $H_DEFS   = $B('DEFAULTS (resolved now)');
    $H_EXS    = $B('EXAMPLES');
    $H_MAP1   = $B('対応表 (事業グループ → gidNumber)');
    $H_MAP2   = $B('対応表2 (employeeType / level_id → gidNumber)');
    $T_DRY    = $Y('DRY-RUN');
    $T_INIT   = $Y('--init');
    $T_ATTR   = $G('attrs=memberUid');
    $T_EMP    = $C('employeeType');

    // --- 本文（Heredoc では変数差し込みのみ。関数呼び出しは不可） ---
    $text = <<<TXT
{$H_NAME}
    ldap_memberuid_users_group.php ? 事業グループと職位クラスの memberUid を同期する

{$H_SYN}
    php ldap_memberuid_users_group.php \\
        [--group=NAME|GID] [--confirm] [--init] [--list] [--inc] [--no-cls] \\
        [--ldapi | --ldaps[=host:port] | --ldap=host:port] \\
        [--bind-dn=... --bind-pw=...] [--base-dn=...]

{$H_DESC}
    1) --group=NAME|GID で指定した「事業グループ」の memberUid を、
       「ユーザーの gidNumber == そのグループの gidNumber」を基準に同期します。
    2) 追加で、ユーザーの {$T_EMP} / level_id を参照し、該当する「職位クラス」グループへも uid を同期します
       （デフォルト有効。--no-cls で抑止）。
    - {$T_DRY}: --confirm を付けない限り、変更は一切行いません（計画のみ表示）。
    - {$T_INIT}: 事業グループ側の memberUid を初期化（全削除）してから再登録します。
    - {$T_EMP} は "adm-cls 1" / "adm-cls1" / "adm-cls-1" / "adm-cls" 等を柔軟に受理します
      （name 優先。数値があればレンジ整合を確認）。

{$H_OPTS}
    --group=users or gid   書き換え対象の事業グループを指定。
                           グループ名または gidNumber のどちらでも可。
                           例: --group=users, --group=solt-dev, --group=2010

{$H_MAP1}
        users        → 100
        esmile-dev   → 2001
        nicori-dev   → 2002
        kindaka-dev  → 2003
        boj-dev      → 2005
        e_game-dev   → 2009
        solt-dev     → 2010
        social-dev   → 2012

    --confirm              変更を確定反映。未指定時は DRY-RUN。
    --init                 初期化（memberUid 全削除）を有効化。未指定時はスキップ。※事業グループのみ
    --list                 memberUid の登録リストを表示。
    --inc                  include file リストを表示（互換用途）。
    --no-cls               {$T_EMP}/level_id による「職位クラス」側同期を行わない。

{$H_MAP2}
        adm-cls  → 3001    (level 1–2)
        dir-cls  → 3003    (level 3–4)
        mgr-cls  → 3006    (level 5)
        mgs-cls  → 3016    (level 6–14)
        stf-cls  → 3020    (level 15–19)
        ent-cls  → 3021    (level 20)
        tmp-cls  → 3099    (level 21–98)
        err-cls  → 3099    (level 99+ / 未定義用)

    接続切替:
    --ldapi                既定: ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi
    --ldaps[=host:port]   例: --ldaps, --ldaps=ovs-012.e-smile.local:636
    --ldap=host:port      例: --ldap=127.0.0.1:389
    --bind-dn=..., --bind-pw=...
    --base-dn=...         既定: dc=e-smile,dc=ne,dc=jp

{$H_ENV}
    LDAP_URL / LDAP_URI / LDAPURI   接続URI（例: ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi / ldaps://FQDN）
    BASE_DN / LDAP_BASE_DN          例: dc=e-smile,dc=ne,dc=jp
    PEOPLE_OU                       例: ou=Users,\$BASE_DN（未指定時は自動）
    GROUPS_OU                       例: ou=Groups,\$BASE_DN（未指定時は自動）
    USERS_GROUP_DN                  例: cn=users,\$GROUPS_OU（未指定時は自動）
    BIND_DN / BIND_PW / LDAP_ADMIN_PW
                                    非ldapi時の simple bind に使用
    INIT_MEMBERUIDS                 "1" で --init と同等（任意）

{$H_CONN}
    ldapi://...   SASL/EXTERNAL（パスワード不要）
    ldap://...    StartTLS 必須（サーバ側設定に従う）。失敗時は例外。
    ldaps://...   そのままTLS接続。
    いずれも、対象グループエントリの {$T_ATTR} への write 権を ACL で付与してください。

{$H_DEFS}
    LDAP_URL:        {$ldapUrl}
    BASE_DN:         {$baseDn}
    PEOPLE_OU:       {$peopleOu}
    GROUPS_OU:       {$groupOu}
    USERS_GROUP_DN:  {$usersDn}

{$H_EXS}
    # 追加のみ（DRY-RUN）
    php ldap_memberuid_users_group.php --ldapi --group=users

    # 追加のみ（確定反映）
    php ldap_memberuid_users_group.php --ldapi --confirm --group=social-dev

    # 初期化してから再登録（DRY-RUN）
    php ldap_memberuid_users_group.php --ldapi --init --group=solt-dev

    # 初期化してから再登録（確定反映）
    php ldap_memberuid_users_group.php --ldapi --init --confirm --group=2010

    # 職位クラス側への同期を無効化
    php ldap_memberuid_users_group.php --ldapi --no-cls --group=users

    # Simple bind で実行
    php ldap_memberuid_users_group.php \\
      --ldaps=ovs-012.e-smile.local:636 \\
      --bind-dn="cn=Admin,dc=e-smile,dc=ne,dc=jp" --bind-pw="******" \\
      --confirm --group=users

    # 一括ループ（事業グループ全体）
    BASE_DIR=/usr/local/etc/openldap/tools
    for g in users esmile-dev nicori-dev kindaka-dev boj-dev e_game-dev solt-dev social-dev; do
      php ${BASE_DIR}/ldap_memberuid_users_group.php --ldapi --confirm --init --list --group="$g"
    done

TXT;

    echo $text, "\n";
    exit(0);
})();


/**
 * 共通関数群（ldap_memberuid_users_group 系）
 */

if (!function_exists('println')) {
    function println(string $msg = ''): void {
        echo $msg . PHP_EOL;
    }
}

if (!function_exists('fatal')) {
    function fatal(string $msg, int $code = 1): void {
        fwrite(STDERR, "[ERROR] {$msg}\n");
        exit($code);
    }
}
