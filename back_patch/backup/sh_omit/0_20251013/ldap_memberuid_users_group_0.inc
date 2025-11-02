<?php
declare(strict_types=1);

(function (): void {

    $argvList = array_map('strval', $GLOBALS['argv'] ?? []);
    $wantHelp = false;
    foreach (['--help','-h','help','/?','?'] as $h) {
        if (in_array($h, $argvList, true)) { $wantHelp = true; break; }
    }
    if (!$wantHelp) return;

    $useColor = class_exists(\Tools\Lib\CliColor::class);

    $B = $useColor ? [\Tools\Lib\CliColor::class,'bold'] : fn(string $s)=>$s;
    $Y = $useColor ? [\Tools\Lib\CliColor::class,'yellow'] : fn(string $s)=>$s;
    $G = $useColor ? [\Tools\Lib\CliColor::class,'green']  : fn(string $s)=>$s;

    // 既定値を実際の計算で表示（Env::get が無ければ素の getenv）
    $envGet = function(string $k, ?string $alt=null, ?string $def=null): ?string {
        if (class_exists(\Tools\Ldap\Env::class)) {
            return \Tools\Ldap\Env::get($k, $alt, $def);
        }
        $v = getenv($k); if ($v!==false && $v!=='') return $v;
        if ($alt) { $v = getenv($alt); if ($v!==false && $v!=='') return $v; }
        return $def;
    };

    $baseDn  = $envGet('BASE_DN','LDAP_BASE_DN','dc=e-smile,dc=ne,dc=jp');
    $people  = $envGet('PEOPLE_OU', null, "ou=Users,{$baseDn}");
    $groups  = $envGet('GROUPS_OU',  null, "ou=Groups,{$baseDn}");
    $usersDn = $envGet('USERS_GROUP_DN', null, "cn=users,{$groups}");
    $ldapUrl = $envGet('LDAP_URL','LDAP_URI', $envGet('LDAPURI'));

    $text = <<<TXT
{$B('NAME')}
    ldap_memberuid_users_group.php — 全ユーザー(ou=Users)を cn=users の memberUid に所属させる

{$B('SYNOPSIS')}
    php ldap_memberuid_users_group.php [--init] [--confirm] [--list] [--help|-h|?|/?]

{$B('DESCRIPTION')}
    - ユーザー一覧(ou=Users)を取得し、cn=users の memberUid に未所属の uid を追加します。
    - {$Y('DRY-RUN')}: --confirm を付けない限り、変更は一切行いません（計画のみ表示）。
    - {$Y('--init')}: 先に cn=users の memberUid を初期化（全削除）してから再登録します。

{$B('OPTIONS')}

    --group=users or gid 書き換え対象のグループを指定
                         グループ名または gidNumber のどちらでも可
                         例: --group=users, --group=solt-dev, --group=2010
    
    対応表:
        users        → 100
        esmile-dev   → 2001
        nicori-dev   → 2002
        kindaka-dev  → 2003
        boj-dev      → 2005
        e_game-dev   → 2009
        solt-dev     → 2010
        social-dev   → 2012

    --confirm     変更を確定反映。未指定時は DRY-RUN。
    --init        初期化（memberUid 全削除）を有効化。未指定時はスキップ。
    --list        memberUid の登録リストを表示。
    --help, -h, ?, /?  このヘルプを表示。

{$B('ENVIRONMENT')}
    LDAP_URL / LDAP_URI / LDAPURI   接続URI（例: ldapi://%2Fvar%2Frun%2Fldapi / ldaps://FQDN）
    BASE_DN / LDAP_BASE_DN          例: dc=e-smile,dc=ne,dc=jp
    PEOPLE_OU                       例: ou=Users,\$BASE_DN（未指定時は自動）
    GROUPS_OU                       例: ou=Groups,\$BASE_DN（未指定時は自動）
    USERS_GROUP_DN                  例: cn=users,\$GROUPS_OU（未指定時は自動）
    BIND_DN / BIND_PW / LDAP_ADMIN_PW
                                    非ldapi時の simple bind に使用
    INIT_MEMBERUIDS                 "1" で --init と同等（任意）

{$B('CONNECTION POLICY')}
    ldapi://...   SASL/EXTERNAL（パスワード不要）
    ldap://...    StartTLS を必須。失敗時は例外。
    ldaps://...   そのままTLS接続。
    いずれも、必要なら {$G('attrs=memberUid')} への write 権を ACL で付与してください。

{$B('DEFAULTS (resolved now)')}
    LDAP_URL:        {$ldapUrl}
    BASE_DN:         {$baseDn}
    PEOPLE_OU:       {$people}
    GROUPS_OU:       {$groups}
    USERS_GROUP_DN:  {$usersDn}

{$B('EXAMPLES')}
    # 追加のみ（DRY-RUN）
    php ldap_memberuid_users_group.php

    # 追加のみ（確定反映）
    php ldap_memberuid_users_group.php --confirm

    # 初期化してから再登録（DRY-RUN）
    php ldap_memberuid_users_group.php --init

    # 初期化してから再登録（確定反映）
    php ldap_memberuid_users_group.php --init --confirm

TXT;
    echo "{$text} \n";
    exit(0);
})();
