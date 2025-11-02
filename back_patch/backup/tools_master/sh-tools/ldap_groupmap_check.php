#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_groupmap_check.php
 *   LDAP の posix グループ（cn/gidNumber）と Samba の groupmap を照合し、
 *   欠落や不整合（未登録・unixgroup不一致）を検出して、必要なら自動修正します。
 *
 * 使い方:
 *   # チェックのみ（DRY-RUN）
 *   php ldap_groupmap_check.php --verbose
 *
 *   # 修正を実行（--fix または --confirm）
 *   php ldap_groupmap_check.php --fix --verbose
 *
 * オプション:
 *   --ldap-uri=ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi   LDAP 接続URI (既定: ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi)
 *   --basedn=dc=e-smile,dc=ne,dc=jp                         ルートDN
 *   --group-base=ou=Groups                                  グループのベースDN (相対DN or FQDNどちらでもOK)
 *   --net=/usr/bin/net                                      net コマンドパス (既定: /usr/bin/net)
 *   --fix | --confirm                                       実際に net groupmap add/modify を実行
 *   --strict-unixgroup                                      既存groupmapの unix group 名も厳密照合（infoを追加取得）
 *   --filter=/regex/                                        LDAPグループ名の絞り込み（例: --filter='/(users|.*-dev|.*-cls|err-cls|tmp-cls)/'）
 *   --verbose                                               詳細ログ
 *
 * 期待動作:
 *   1) LDAP から posixGroup の cn / gidNumber 一覧を取得
 *   2) Samba の "net groupmap list" を取得し、NTグループ名一覧を解析
 *   3) (任意) --strict-unixgroup 指定時、各NTグループの unix group を "net groupmap info" で確認
 *   4) LDAPに存在するのに groupmapに無い → add
 *      groupmapはあるが unixgroup!=LDAPのcn → modify
 *   ※SIDやgidNumberの一致までは強制しません（SambaのSIDは既に運用されている前提）
 */

final class App
{
    private string $ldapUri            = 'ldapi://%2Fusr%2Flocal%2Fvar%2Frun%2Fldapi';
    private string $baseDn             = 'dc=e-smile,dc=ne,dc=jp';
    private string $groupBase          = 'ou=Groups'; // 相対指定OK
    private string $netCmd             = '/usr/bin/net';
    private bool   $doFix              = false;
    private bool   $strictUnixgroup    = false;
    private bool   $verbose            = false;
    private ?string $filterRegex       = null; // 例: '/(users|.*-dev|.*-cls|err-cls|tmp-cls)/'
    private array $env                 = [];

    public static function run(): int
    {
        $app = new self();
        return $app->main();
    }

    private function main(): int
    {
        $this->parseArgs();

        $groupBaseDn = $this->normalizeGroupBaseDn($this->groupBase, $this->baseDn);

        $this->vlog('[INFO] LDAP URI     : %s', $this->ldapUri);
        $this->vlog('[INFO] Base DN      : %s', $this->baseDn);
        $this->vlog('[INFO] Group Base DN: %s', $groupBaseDn);
        $this->vlog('[INFO] net command  : %s', $this->netCmd);
        $this->vlog('[INFO] Mode         : %s', $this->doFix ? 'FIX (実行)' : 'DRY-RUN (検出のみ)');
        $this->vlog('[INFO] Strict UG    : %s', $this->strictUnixgroup ? 'ON' : 'OFF');
        if ($this->filterRegex) {
            $this->vlog('[INFO] Filter      : %s', $this->filterRegex);
        }

        // 1) LDAP から posixGroup map 取得: [cn => gidNumber]
        $ldapGroups = $this->fetchLdapPosixGroups($groupBaseDn); // [cn => gid, ...]
        if ($this->filterRegex) {
            $ldapGroups = array_filter($ldapGroups, function($cn) {
                return (bool)preg_match($this->filterRegex, $cn);
            }, ARRAY_FILTER_USE_KEY);
        }
        if (empty($ldapGroups)) {
            $this->log('[WARN] 対象となる LDAP グループが見つかりませんでした。終了します。');
            return 0;
        }

        // 2) Samba groupmap list 取得: [ntgroup => sid]
        $mapList = $this->fetchSambaGroupmapList();
        // 3) (任意) unixgroupの実体も取得: [ntgroup => 'unixgroup' => name]
        $unixgroupMap = [];
        if ($this->strictUnixgroup) {
            foreach (array_keys($mapList) as $ntg) {
                $info = $this->fetchSambaGroupmapInfo($ntg);
                if ($info['unixgroup'] ?? null) {
                    $unixgroupMap[$ntg] = $info['unixgroup'];
                }
            }
        }

        // 4) 照合
        $needAdd   = []; // 未登録 → add対象
        $needMod   = []; // unixgroup不一致 → modify対象
        $allOk     = []; // OK

        foreach ($ldapGroups as $cn => $gid) {
            $exists = array_key_exists($cn, $mapList); // ntgroup として存在？
            if (!$exists) {
                $needAdd[$cn] = $gid;
                continue;
            }
            if ($this->strictUnixgroup) {
                $ug = $unixgroupMap[$cn] ?? null;
                if ($ug !== null && $ug !== $cn) {
                    $needMod[$cn] = ['current_unixgroup' => $ug, 'desired_unixgroup' => $cn, 'gid' => $gid];
                    continue;
                }
            }
            $allOk[$cn] = $gid;
        }

        $this->printSummary($ldapGroups, $mapList, $needAdd, $needMod, $allOk);

        // 5) 修正実行
        if ($this->doFix) {
            $errors = 0;

            foreach ($needAdd as $cn => $gid) {
                // 既定ポリシー: ntgroup = cn, unixgroup = cn, type=domain
                $cmd = sprintf('%s groupmap add ntgroup="%s" unixgroup="%s" type=domain',
                    escapeshellcmd($this->netCmd),
                    $cn, $cn
                );
                $this->log('[FIX:add] %s', $cmd);
                $rc = $this->system($cmd, $out, $err);
                if ($rc !== 0) {
                    $this->log("[ERROR] add 失敗: ntgroup=%s : %s", $cn, trim($err ?: $out));
                    $errors++;
                }
            }

            foreach ($needMod as $cn => $row) {
                $cmd = sprintf('%s groupmap modify ntgroup="%s" unixgroup="%s"',
                    escapeshellcmd($this->netCmd),
                    $cn, $cn
                );
                $this->log('[FIX:modify] %s', $cmd);
                $rc = $this->system($cmd, $out, $err);
                if ($rc !== 0) {
                    $this->log("[ERROR] modify 失敗: ntgroup=%s : %s", $cn, trim($err ?: $out));
                    $errors++;
                }
            }

            if ($errors === 0) {
                $this->log('[DONE] すべての修正が完了しました。');
                return 0;
            } else {
                $this->log('[WARN] 修正中に %d 件のエラーが発生しました。', $errors);
                return 2;
            }
        } else {
            if (empty($needAdd) && empty($needMod)) {
                $this->log('[OK] すべて整合しています（修正不要）。');
            } else {
                $this->log('[NOTE] DRY-RUN のため、修正は実行していません。--fix を付与すると反映します。');
            }
            return 0;
        }
    }

    // ===== Helpers =====

    private function parseArgs(): void
    {
        $opt = getopt('', [
            'ldap-uri::',
            'basedn::',
            'group-base::',
            'net::',
            'fix',
            'confirm',
            'strict-unixgroup',
            'filter::',
            'verbose',
            'help',
        ]);

        if (isset($opt['help'])) {
            $this->showHelp();
            exit(0);
        }
        if (isset($opt['ldap-uri']) && is_string($opt['ldap-uri']) && $opt['ldap-uri'] !== '') {
            $this->ldapUri = $opt['ldap-uri'];
        }
        if (isset($opt['basedn']) && is_string($opt['basedn']) && $opt['basedn'] !== '') {
            $this->baseDn = $opt['basedn'];
        }
        if (isset($opt['group-base']) && is_string($opt['group-base']) && $opt['group-base'] !== '') {
            $this->groupBase = $opt['group-base'];
        }
        if (isset($opt['net']) && is_string($opt['net']) && $opt['net'] !== '') {
            $this->netCmd = $opt['net'];
        }
        if (isset($opt['fix']) || isset($opt['confirm'])) {
            $this->doFix = true;
        }
        if (isset($opt['strict-unixgroup'])) {
            $this->strictUnixgroup = true;
        }
        if (isset($opt['verbose'])) {
            $this->verbose = true;
        }
        if (isset($opt['filter']) && is_string($opt['filter']) && $opt['filter'] !== '') {
            // 例: --filter='/(users|.*-dev|.*-cls|err-cls|tmp-cls)/'
            $this->filterRegex = $opt['filter'];
        }
    }

    private function showHelp(): void
    {
        echo <<<HELP
Usage:
  php ldap_groupmap_check.php [--fix] [--strict-unixgroup] [--verbose]
                              [--ldap-uri=ldapi://...] [--basedn=...] [--group-base=...]
                              [--net=/usr/bin/net] [--filter='/regex/']

Examples:
  php ldap_groupmap_check.php --verbose
  php ldap_groupmap_check.php --fix --strict-unixgroup --filter='/(users|.*-dev|.*-cls|err-cls|tmp-cls)/'

HELP;
    }

    private function normalizeGroupBaseDn(string $groupBase, string $baseDn): string
    {
        $gb = trim($groupBase);
        if ($gb === '') return $baseDn;
        // groupBase が完全DNならそのまま、相対DNならベースに連結
        return (stripos($gb, 'dc=') !== false || stripos($gb, 'cn=') !== false || stripos($gb, 'ou=') !== false)
            ? $gb
            : sprintf('%s,%s', $gb, $baseDn);
    }

    /**
     * LDAP から posixGroup の cn/gidNumber 一覧を取得
     * @return array<string,int> [cn => gidNumber]
     */
    private function fetchLdapPosixGroups(string $groupBaseDn): array
    {
        $cmd = sprintf(
            'ldapsearch -LLL -Y EXTERNAL -H %s -b %s \'(objectClass=posixGroup)\' cn gidNumber 2>/dev/null',
            escapeshellarg($this->ldapUri),
            escapeshellarg($groupBaseDn)
        );
        $this->vlog('[RUN] %s', $cmd);
        $rc = $this->system($cmd, $out, $err);
        if ($rc !== 0) {
            $this->log('[ERROR] ldapsearch 失敗: %s', trim($err ?: $out));
            return [];
        }
        $lines = preg_split('/\R/u', $out);
        $cn = null;
        $gid = null;
        $map = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (stripos($line, 'cn: ') === 0) {
                $cn = trim(substr($line, 4));
            } elseif (stripos($line, 'gidNumber: ') === 0) {
                $gid = (int)trim(substr($line, 11));
            } elseif (stripos($line, 'dn: ') === 0) {
                // next entry; reset markers (handled when cn+gid set)
                $cn = null;
                $gid = null;
            }

            if ($cn !== null && $gid !== null) {
                $map[$cn] = $gid;
                $cn = null;
                $gid = null;
            }
        }
        ksort($map, SORT_NATURAL);
        return $map;
    }

    /**
     * Samba の groupmap list を取得
     * @return array<string,string> [ntgroup => SID]
     */
    private function fetchSambaGroupmapList(): array
    {
        $cmd = sprintf('%s groupmap list 2>/dev/null', escapeshellcmd($this->netCmd));
        $this->vlog('[RUN] %s', $cmd);
        $rc = $this->system($cmd, $out, $err);
        if ($rc !== 0) {
            $this->log('[ERROR] net groupmap list 失敗: %s', trim($err ?: $out));
            return [];
        }
        $map = [];
        foreach (preg_split('/\R/u', $out) as $line) {
            // 例: "users (S-1-5-21-...-1008) -> users"
            if (!preg_match('/^(.*)\s+\((S-[^)]+)\)\s+->\s+(.*)$/u', trim($line), $m)) {
                continue;
            }
            $ntgroup = trim($m[3]); // 右辺（-> の右）が net ntgroup 名
            $sid     = trim($m[2]);
            $map[$ntgroup] = $sid;
        }
        ksort($map, SORT_NATURAL);
        return $map;
    }

    /**
     * 個別の groupmap info を取得
     * @return array{sid?:string,ntgroup?:string,unixgroup?:string,type?:string}
     */
    private function fetchSambaGroupmapInfo(string $ntgroup): array
    {
        $cmd = sprintf('%s groupmap info ntgroup=%s 2>/dev/null',
            escapeshellcmd($this->netCmd),
            escapeshellarg($ntgroup)
        );
        $this->vlog('[RUN] %s', $cmd);
        $rc = $this->system($cmd, $out, $err);
        if ($rc !== 0) {
            $this->vlog('[WARN] groupmap info 取得失敗: %s : %s', $ntgroup, trim($err ?: $out));
            return [];
        }
        $info = [];
        foreach (preg_split('/\R/u', $out) as $line) {
            $line = trim($line);
            if (stripos($line, 'SID:') === 0)        $info['sid']       = trim(substr($line, 4));
            if (stripos($line, 'NT Group:') === 0)   $info['ntgroup']   = trim(substr($line, 9));
            if (stripos($line, 'Unix group:') === 0) $info['unixgroup'] = trim(substr($line, 11));
            if (stripos($line, 'Type:') === 0)       $info['type']      = trim(substr($line, 5));
        }
        return $info;
    }

    private function printSummary(array $ldapGroups, array $mapList, array $needAdd, array $needMod, array $allOk): void
    {
        $this->log("------------------------------------------------------------");
        $this->log("[SUMMARY] LDAP posixGroup: %d 件 / Samba groupmap: %d 件", count($ldapGroups), count($mapList));
        $this->log("[SUMMARY] OK: %d, ADD: %d, MODIFY: %d", count($allOk), count($needAdd), count($needMod));
        $this->log("------------------------------------------------------------");

        if (!empty($needAdd)) {
            $this->log("[ADD] groupmap 未登録 (ntgroup=cn, unixgroup=cn, type=domain で追加予定):");
            foreach ($needAdd as $cn => $gid) {
                $this->log("  - %s (gid=%d)", $cn, $gid);
            }
        }
        if (!empty($needMod)) {
            $this->log("[MODIFY] unixgroup 不一致 (unixgroup を cn に合わせる予定):");
            foreach ($needMod as $cn => $row) {
                $this->log("  - %s (gid=%d) unixgroup: %s -> %s",
                    $cn, $row['gid'], $row['current_unixgroup'], $row['desired_unixgroup']);
            }
        }
        if (!empty($allOk) && $this->verbose) {
            $this->log("[OK] 整合しているグループ（抜粋）:");
            $cnt = 0;
            foreach ($allOk as $cn => $gid) {
                $this->log("  - %s (gid=%d)%s",
                    $cn, $gid, array_key_exists($cn, $mapList) ? "" : " [WARN: groupmapに見つからない?]"
                );
                if (++$cnt >= 20) { // 出力しすぎ防止
                    $this->log("  ... and more");
                    break;
                }
            }
        }

        // 代表的な「想定ターゲット」を見落としていないかのヒント（users, *-dev, *-cls, tmp/err）
        $targetsHint = '/(users|.*-dev|.*-cls|err-cls|tmp-cls)/';
        if (!$this->filterRegex || $this->filterRegex !== $targetsHint) {
            $this->vlog("[HINT] もし対象を絞る場合は --filter='%s' を使えます。", $targetsHint);
        }
    }

    private function system(string $cmd, ?string &$stdout = null, ?string &$stderr = null): int
    {
        $desc = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $desc, $pipes, null, $this->env);
        if (!\is_resource($proc)) {
            $stderr = 'proc_open failed';
            return 1;
        }
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $status = proc_close($proc);
        return \is_int($status) ? $status : 1;
    }

    private function log(string $fmt, ...$args): void
    {
        fprintf(STDOUT, $fmt . PHP_EOL, ...$args);
    }

    private function vlog(string $fmt, ...$args): void
    {
        if ($this->verbose) {
            $this->log($fmt, ...$args);
        }
    }
}

exit(App::run());

