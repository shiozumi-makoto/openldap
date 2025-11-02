#!/usr/bin/php
<?php
declare(strict_types=1);

/**
 * ldap_groupmap_smb_add.php
 * --------------------------
 * posixGroup → Samba groupmap 反映ツール（自動登録）
 *
 * 特徴:
 *  - ldapi:// → まず SASL/EXTERNAL を試す。失敗したら自動で LDAPS へ再接続し simple bind
 *  - ldap://  → StartTLS を必須化
 *  - ldaps:// → そのまま SSL 接続
 *  - --confirm 無し：ドライラン（LDAP 読み取りのみ・匿名 bind 可）
 *
 * 環境変数:
 *   LDAP_URL / LDAP_URI        … LDAP 接続 URI（例: ldapi://%2Fvar%2Frun%2Fldapi）
 *   BASE_DN / LDAP_BASE_DN     … dc=e-smile,dc=ne,dc=jp
 *   GROUPS_OU                  … 例: ou=Groups,${BASE_DN}
 *   BIND_DN / BIND_PW          … 管理者資格情報（ldapi の EXTERNAL 失敗時フォールバック、ldaps/ldap 用）
 *   DOM_SID_PREFIX             … S-1-5-21-...
 *   FALLBACK_LDAPS_URL         … ldapi 失敗時の再接続先（既定: ldaps://ovs-012.e-smile.local）
 */

function envv(string $k, ?string $alt = null, ?string $def = null): ?string {
    $v = getenv($k);
    if ($v !== false && $v !== '') return $v;
    if ($alt) {
        $v = getenv($alt);
        if ($v !== false && $v !== '') return $v;
    }
    return $def;
}

function is_ldapi(string $u): bool { return str_starts_with($u, 'ldapi://'); }
function is_ldaps(string $u): bool { return str_starts_with($u, 'ldaps://'); }
function is_ldap_plain(string $u): bool { return str_starts_with($u, 'ldap://'); }

function normalize_ldapi_uri(string $uri): string {
    // ldapi:/// を実ソケットに解決（よくある候補）
    if ($uri === 'ldapi:///') {
        $candidates = [
            '/var/run/ldapi',
            '/run/ldapi',
            '/usr/local/openldap-2.6.10/var/run/ldapi',
        ];
        foreach ($candidates as $p) {
            if (@filetype($p) === 'socket' || file_exists($p)) {
                return 'ldapi://' . rawurlencode($p);
            }
        }
    }
    return $uri;
}

/** 接続（bind前）。ldap:// はここで StartTLS 開始。ldapi はここでは bind しない。*/
function ldap_connect_secure(string $uri): \LDAP\Connection {
    $uri = normalize_ldapi_uri($uri);
    $ds = @ldap_connect($uri);
    if (!$ds) throw new RuntimeException("ldap_connect failed: $uri");
    ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);

    if (is_ldap_plain($uri)) {
        if (!@ldap_start_tls($ds)) {
            throw new RuntimeException('StartTLS failed: ' . ldap_error($ds));
        }
    }
    // ldaps:// はそのまま、ldapi:// は bind を後段で実施
    return $ds;
}

/** ldapi は EXTERNAL を試行→失敗時は LDAPS に切替。非 ldapi は simple/匿名 bind。*/
function bind_with_fallback(\LDAP\Connection &$ds, bool $confirm, string $uri): void {
    $bindDn = envv('BIND_DN', null, 'cn=Admin,dc=e-smile,dc=ne,dc=jp');
    $bindPw = envv('BIND_PW', 'LDAP_ADMIN_PW', '');

    if (is_ldapi($uri)) {
        // 1) EXTERNAL を試す
        if (@ldap_sasl_bind($ds, NULL, NULL, "EXTERNAL")) {
            return; // 成功
        }
        // 2) 失敗 → LDAPS に切替
        $fb = envv('FALLBACK_LDAPS_URL', null, 'ldaps://ovs-012.e-smile.local');
        fwrite(STDOUT, "[INFO] ldapi EXTERNAL failed; falling back to {$fb}\n");

        // 再接続
        $ds = ldap_connect_secure($fb);

        // ドライランは資格が無ければ匿名 bind（検索のみ）
        if (!$confirm && ($bindDn === '' || $bindPw === '')) {
            @ldap_bind($ds);
            return;
        }
        if ($bindDn === '' || $bindPw === '') {
            throw new RuntimeException('BIND_DN/BIND_PW is required for fallback LDAPS.');
        }
        if (!@ldap_bind($ds, $bindDn, $bindPw)) {
            throw new RuntimeException('fallback LDAPS bind failed: ' . ldap_error($ds));
        }
        return;
    }

    // 非 ldapi
    if (!$confirm && ($bindDn === '' || $bindPw === '')) { @ldap_bind($ds); return; }
    if ($bindDn === '' || $bindPw === '') {
        throw new RuntimeException('BIND_DN/BIND_PW is required for non-ldapi connections.');
    }
    if (!@ldap_bind($ds, $bindDn, $bindPw)) {
        throw new RuntimeException('simple bind failed: ' . ldap_error($ds));
    }
}

/** ログ出力 */
function log_line(string $m): void { fwrite(STDOUT, $m . (str_ends_with($m, "\n") ? '' : "\n")); }

/** 既存 groupmap を取得 */
function get_groupmap_existing(): array {
    $result = [];
    $cmd = ['net', 'groupmap', 'list'];
    exec(implode(' ', $cmd) . ' 2>/dev/null', $lines, $rc);
    if ($rc !== 0) return $result;
    foreach ($lines as $line) {
        if (preg_match('/^(.+?)\s+\(S-1-5-[^)]+\)\s+->/', $line, $m)) {
            $result[trim($m[1])] = true;
        } elseif (preg_match('/^(.+?)\s+->/', $line, $m)) {
            $result[trim($m[1])] = true;
        }
    }
    return $result;
}

/** LDAP から posixGroup 一覧を取得 */
function fetch_posix_groups(\LDAP\Connection $ds, string $groupsDn): array {
    $attrs = ['cn','gidNumber'];
    $sr = @ldap_search($ds, $groupsDn, '(objectClass=posixGroup)', $attrs);
    if ($sr === false) throw new RuntimeException('search groups failed: '.ldap_error($ds));
    $entries = ldap_get_entries($ds, $sr);
    $list = [];
    for ($i=0; $i < $entries['count']; $i++) {
        $e = $entries[$i];
        if (empty($e['cn'][0])) continue;
        $list[] = [
            'dn' => $e['dn'],
            'cn' => $e['cn'][0],
            'gidNumber' => $e['gidnumber'][0] ?? null,
        ];
    }
    return $list;
}

/** 実行関数（net groupmap add） */
function do_groupmap_add(string $cn, ?string $unixGroup, ?string $sidPrefix, bool $confirm): array {
    $cmd = ['net','groupmap','add','ntgroup='.$cn];
    if ($unixGroup && $unixGroup !== '') $cmd[] = 'unixgroup='.$unixGroup;
    $cmd[] = 'type=domain';
    $result = ['cmd' => implode(' ', array_map('escapeshellarg', $cmd))];

    if (!$confirm) return $result; // ドライラン

    exec(implode(' ', $cmd) . ' 2>&1', $out, $rc);
    $result['out'] = implode("\n", $out);
    $result['rc']  = $rc;
    return $result;
}

/* ==== メイン ==== */
$confirm  = in_array('--confirm', $argv, true);
$baseDn   = envv('BASE_DN','LDAP_BASE_DN','dc=e-smile,dc=ne,dc=jp');
$groupsDn = envv('GROUPS_OU', null, "ou=Groups,{$baseDn}");
$uri      = envv('LDAP_URL','LDAP_URI','ldaps://ovs-012.e-smile.local');
$domSid   = envv('DOM_SID_PREFIX', null, null);
$haveNet  = trim((string)@shell_exec('command -v net')) !== '';

log_line("=== groupmap START ===");
log_line(sprintf("HOST=%s GROUPS_DN=%s CONFIRM=%s HAVE_NET=%s",
    gethostname(), $groupsDn, $confirm ? 'YES' : 'NO', $haveNet ? 'YES' : 'NO'));

if (!$haveNet) {
    log_line("[SKIP] 'net' command not found; skipping groupmap");
    exit(0);
}

try {
    $ds = ldap_connect_secure($uri);
    bind_with_fallback($ds, $confirm, $uri);

    $existing = get_groupmap_existing();
    $groups   = fetch_posix_groups($ds, $groupsDn);

    $addPlan= 0; $ok=0; $keep=0; $err=0;
    foreach ($groups as $g) {
        $cn = $g['cn'];
        if (isset($existing[$cn])) { $keep++; continue; }

        $addPlan++;
        $res = do_groupmap_add($cn, $cn, $domSid, $confirm);
        if ($confirm) {
            if (($res['rc'] ?? 1) === 0) {
                $ok++;
            } else {
                $err++;
                log_line(sprintf("[ERR ] groupmap add ntgroup=%s rc=%d out=%s",
                    $cn, $res['rc'] ?? -1, trim((string)($res['out'] ?? ''))));
            }
        } else {
            log_line("[DRY] ".$res['cmd']);
        }
    }

    $summary = sprintf("SUMMARY: planned=%d ok=%d keep=%d err=%d (uri=%s)",
        $addPlan, $ok, $keep, $err, $uri);
    log_line($summary);
    log_line("=== groupmap DONE ===");
    exit($err ? 2 : 0);

} catch (Throwable $e) {
    log_line("[ERROR] ".$e->getMessage());
    exit(1);
}


