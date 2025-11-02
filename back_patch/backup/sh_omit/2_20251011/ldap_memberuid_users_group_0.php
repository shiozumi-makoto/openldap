#!/usr/bin/php
<?php
declare(strict_types=1);

/**
 * 全ユーザー(ou=Users)を cn=users へ memberUid で所属させる。
 *
 * 接続仕様:
 *  - 環境変数 LDAP_URL / LDAP_URI / LDAPURI を優先
 *    * ldapi://%2Fvar%2Frun%2Fldapi → SASL/EXTERNAL で接続（パスワード不要）
 *    * ldap://host → StartTLS を必ず実行してから bind
 *    * ldaps://host → そのまま bind
 *  - BIND_DN / BIND_PW を使用（ldapi/EXTERNAL時は未使用）
 *
 * 使い方:
 *   php ldap_memberuid_users_group.php [--confirm]
 *
 * 必要な環境変数（なければ既定値）:
 *   LDAP_URL / LDAP_URI / LDAPURI     接続URI（例: ldapi://%2Fvar%2Frun%2Fldapi / ldaps://FQDN）
 *   BASE_DN / LDAP_BASE_DN            例: dc=e-smile,dc=ne,dc=jp
 *   PEOPLE_OU                         例: ou=Users,dc=e-smile,dc=ne,dc=jp（未指定なら BASE_DN から構築）
 *   GROUPS_OU                         例: ou=Groups,dc=e-smile,dc=ne,dc=jp（未指定なら BASE_DN から構築）
 *   USERS_GROUP_DN                    例: cn=users,ou=Groups,${BASE_DN}（未指定なら上記で自動）
 *   BIND_DN / BIND_PW                 管理者バインドに利用（ldapi/EXTERNAL時は不要）
 */

include '/usr/local/etc/openldap/tools/cli_colors.inc.php';
include '/usr/local/etc/openldap/tools/ldap_helpers.inc.php';
include '/usr/local/etc/openldap/tools/ldap_memberuid_utils.inc.php';


/* ==== メイン ==== */

// CLI想定
$argv = $argv ?? [];
$confirm = in_array('--confirm', $argv, true);

$baseDn       = envv('BASE_DN','LDAP_BASE_DN','dc=e-smile,dc=ne,dc=jp');
$people       = envv('PEOPLE_OU', null, "ou=Users,{$baseDn}");
$groups       = envv('GROUPS_OU', null, "ou=Groups,{$baseDn}");
$usersGroupDn = envv('USERS_GROUP_DN', null, "cn=users,{$groups}");

// URI 表示用（LDAP_URL / LDAP_URI / LDAPURI の順）
$uri = (function (): string {
    if (function_exists('env_first')) return (string)(env_first(['LDAP_URL','LDAP_URI','LDAPURI'], '') ?? '');
    $candidates = ['LDAP_URL','LDAP_URI','LDAPURI'];
    foreach ($candidates as $k) {
        $v = getenv($k);
        if ($v !== false && $v !== '') return (string)$v;
    }
    return '';
})();

$ds = null;
$t0 = microtime(true);

try {
    // 任意：LDAP拡張がない環境で早期失敗
    if (function_exists('assert_ldap_ext_loaded')) {
        assert_ldap_ext_loaded();
    }

    log_line(" === users_group START === ");
    log_line(sprintf(
        "[INFO] URI=%s\n       BASE_DN=%s PEOPLE_OU=%s GROUPS_OU=%s USERS_GROUP_DN=%s",
        show_or_mask($uri), $baseDn, $people, $groups, $usersGroupDn
    ));

    if (!$confirm) log_line(bold_red("[INFO] DRY-RUN: use --confirm to write changes"));

    // 接続＆認証
    if (function_exists('ldap_connect_with_fallback')) {
        // フォールバックあり版（ホストが分かるならヒントを入れてもOK）
        $hostHint = null; // 例: 'ovs-012.e-smile.local'
        $ds = ldap_connect_with_fallback($hostHint);
    } else {
        $ds = ldap_connect_secure();
    }

    if (function_exists('ldap_apply_recommended_options')) {
        ldap_apply_recommended_options($ds);
    }

    maybe_simple_bind($ds);

    // 取得
    $users = fetch_users($ds, $people);
    if (!is_array($users)) {
        throw new RuntimeException('fetch_users returned non-array');
    }

    $keep = 0; $planned = 0; $ok = 0; $err = 0;
    $seen = [];

    foreach ($users as $u) {
        $uid = $u['uid'] ?? '';
        if ($uid === '') {
            $err++;
            log_line("[WARN] skip: missing uid in user record");
            continue;
        }
        if (isset($seen[$uid])) { // 重複保険
            $keep++;
            continue;
        }
        $seen[$uid] = true;

        try {
            if (function_exists('ldap_retry')) {
                ldap_retry(function() use ($ds, $usersGroupDn, $uid, $confirm, &$planned, &$ok, &$keep) {
                    $didPlan = add_memberuid($ds, $usersGroupDn, $uid, $confirm);
                    if ($didPlan) {
                        $planned++;
                        if ($confirm) $ok++;
                    } else {
                        $keep++;
                    }
                });
            } else {
                $didPlan = add_memberuid($ds, $usersGroupDn, $uid, $confirm);
                if ($didPlan) {
                    $planned++;
                    if ($confirm) $ok++;
                } else {
                    $keep++;
                }
            }
        } catch (Throwable $e) {
            $err++;
            log_line(sprintf("[ERR ] memberUid add failed uid=%s: %s", $uid, $e->getMessage()));
        }
    }

    $sec = sprintf('%.3f', microtime(true) - $t0);
    log_line(sprintf("SUMMARY: group=users keep=%d planned=%d ok=%d err=%d time=%ss", $keep, $planned, $ok, $err, $sec));
    log_line("=== users_group DONE ===");
    exit($err ? 2 : 0);

} catch (Throwable $e) {
    log_line("[ERROR] " . $e->getMessage());
    exit(1);

} finally {
    if ($ds) {
        ldap_close_safely($ds);
    }
}

