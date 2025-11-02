<?php
declare(strict_types=1);

namespace Tools\Ldap\Support;

/**
 * LdapUtil
 *  - OpenLDAP + Samba 連携ユーティリティ
 *  - このファイルはライブラリ層です。ここで autoload を require しないこと。
 *
 * 提供メソッド（想定呼び出し側の API として安定化）:
 *   inferDomainSid($ds, string $baseDn): ?string
 *   collectGidRidPairs($ds, string $groupsDn, string $domSid): array{0:array<int,array{gid:int,rid:int}>,1:int[]}
 *   inferRidFormula(array $pairs): array{?int,?int}
 *   readEntries($ds, string $base, string $filter, array $attrs=['*']): array
 *   ensureGroupMapping(
 *       $ds,
 *       string   $dn,
 *       string   $sid,        // 完全な SID (例: S-1-5-21-....-1011)
 *       string   $display,    // 表示名（displayName 用）
 *       string   $type,       // 'domain' | 'local' | 'builtin'（sambaGroupType に対応）
 *       bool     $confirm,    // true で実書き込み、false は DRY-RUN
 *       callable $info,       // function(string $msg):void
 *       callable $warn        // function(string $msg):void
 *   ): void
 */
final class LdapUtil
{
    // ---- public: ドメイン SID 推定 ----------------------------------------

    /**
     * Domain SID を推定して返す（例: S-1-5-21-3566765955-3362818161-2431109675）
     *
     * 優先順:
     *  1) LDAP の sambaDomain エントリ (objectClass=sambaDomain) の sambaSID
     *  2) LDAP 内の sambaSID を持つ任意のエントリから末尾 RID を剥がして抽出
     *  3) `net getlocalsid` の出力から抽出（最終手段）
     *
     * @param resource $ds      ldap_connect したリソース
     * @param string   $baseDn  例: "dc=e-smile,dc=ne,dc=jp"
     * @return ?string          例: "S-1-5-21-3566-....-2431109675" / 取得不可なら null
     */
    public static function inferDomainSid($ds, string $baseDn): ?string
    {
        $isDomainSid = static function (string $sid): bool {
            // 典型的: S-1-5-21-aaa-bbb-ccc （ブロック数 = 6）
            return (bool)preg_match('/^S-\d+(?:-\d+){5}$/', $sid);
        };
        $stripRid = static function (string $sid): ?string {
            // S-...-RID → S-... （RID を落とす）
            if (preg_match('/^(S-\d+(?:-\d+){5})-\d+$/', $sid, $m)) {
                return $m[1];
            }
            return null;
        };

        // 1) sambaDomain を探す
        $sr = @ldap_search($ds, $baseDn, '(|(objectClass=sambaDomain)(sambaDomainName=*))', ['sambaSID', 'sambaDomainName']);
        if ($sr) {
            $entries = @ldap_get_entries($ds, $sr);
            if (is_array($entries) && isset($entries['count'])) {
                for ($i = 0; $i < $entries['count']; $i++) {
                    $sid = $entries[$i]['sambasid'][0] ?? null;
                    if (is_string($sid) && $sid !== '') {
                        if ($isDomainSid($sid)) return $sid;
                        $dom = $stripRid($sid);
                        if ($dom) return $dom;
                    }
                }
            }
        }

        // 2) 任意の sambaSID 付きエントリから抽出
        $sr2 = @ldap_search($ds, $baseDn, '(sambaSID=*)', ['sambaSID']);
        if ($sr2) {
            $entries = @ldap_get_entries($ds, $sr2);
            if (is_array($entries) && isset($entries['count'])) {
                for ($i = 0; $i < $entries['count']; $i++) {
                    $sid = $entries[$i]['sambasid'][0] ?? null;
                    if (!is_string($sid) || $sid === '') continue;
                    if ($isDomainSid($sid)) return $sid;
                    $dom = $stripRid($sid);
                    if ($dom) return $dom;
                }
            }
        }

        // 3) net getlocalsid（最終手段）
        $out = @shell_exec('net getlocalsid 2>/dev/null');
        if (is_string($out) && $out !== '') {
            if (preg_match('/S-\d+(?:-\d+){5}/', $out, $m)) {
                return $m[0];
            }
            if (preg_match('/(S-\d+(?:-\d+){5})-\d+/', $out, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    // ---- public: 既知の (gidNumber, rid) ペア収集 --------------------------

    /**
     * Groups OU に存在する posixGroup / sambaGroupMapping を走査し、
     * 既知の (gidNumber, rid) ペアを収集する。
     *
     * @param resource $ds
     * @param string   $groupsDn  例: "ou=Groups,dc=e-smile,dc=ne,dc=jp"
     * @param string   $domSid    inferDomainSid() で取得した Domain SID
     * @return array{0:array<int,array{gid:int,rid:int}>,1:int[]}  [pairs, orphanGids]
     *   - pairs:    rid を特定できた (gid, rid) の配列
     *   - orphanGids: rid が分からなかった gidNumber の配列
     */
    public static function collectGidRidPairs($ds, string $groupsDn, string $domSid): array
    {
        $pairs = [];
        $orph  = [];

        $attrs  = ['cn', 'gidNumber', 'sambaSID'];
        $sr     = @ldap_search($ds, $groupsDn, '(objectClass=posixGroup)', $attrs);
        $entries= $sr ? @ldap_get_entries($ds, $sr) : false;

        if (!is_array($entries) || !isset($entries['count'])) {
            return [$pairs, $orph];
        }

        for ($i = 0; $i < $entries['count']; $i++) {
            $e = $entries[$i];
            $gid = isset($e['gidnumber'][0]) ? (int)$e['gidnumber'][0] : null;
            if ($gid === null) continue;

            $sid = $e['sambasid'][0] ?? null;
            if (is_string($sid) && $sid !== '') {
                // 形式: "<domSid>-<rid>"
                if (strpos($sid, $domSid . '-') === 0) {
                    $ridStr = substr($sid, strlen($domSid) + 1);
                    if ($ridStr !== '' && ctype_digit($ridStr)) {
                        $pairs[] = ['gid' => $gid, 'rid' => (int)$ridStr];
                        continue;
                    }
                }
            }

            $orph[] = $gid;
        }

        return [$pairs, $orph];
    }

    // ---- public: RID 近似式推定 --------------------------------------------

    /**
     * 既知ペアから「rid = a*gid + b」の整数近似式を推定。
     * 代表ケース:
     *   - rid = gid + const
     *   - rid = 1*gid + b もしくは a=0 の定数加算のみ
     *
     * 推定アルゴリズム:
     *   1) データ数 2 以上なら、(a,b) の最小二乗法（a は四捨五入で整数化）
     *   2) データ数 1 なら、a=1, b=rid-gid と仮定
     *   3) データ数 0 なら、(null, null)
     *
     * @param array<int,array{gid:int,rid:int}> $pairs
     * @return array{?int,?int}  [a,b]
     */
    public static function inferRidFormula(array $pairs): array
    {
        $n = count($pairs);
        if ($n === 0) return [null, null];
        if ($n === 1) {
            $g = $pairs[0]['gid']; $r = $pairs[0]['rid'];
            return [1, $r - $g];
        }

        // 最小二乗: a = cov(g,r)/var(g), b = mean(r) - a*mean(g)
        $sumG = 0; $sumR = 0; $sumGG = 0; $sumGR = 0;
        foreach ($pairs as $p) {
            $g = (float)$p['gid'];
            $r = (float)$p['rid'];
            $sumG  += $g;
            $sumR  += $r;
            $sumGG += $g*$g;
            $sumGR += $g*$r;
        }
        $meanG = $sumG / $n;
        $meanR = $sumR / $n;
        $varG  = $sumGG - $n*$meanG*$meanG;

        if (abs($varG) < 1e-9) {
            // すべて同じ gid（あり得ない想定だがガード）
            return [null, null];
        }

        $aFloat = ($sumGR - $n*$meanG*$meanR) / $varG;
        $a = (int)round($aFloat);
        $b = (int)round($meanR - $a*$meanG);
        return [$a, $b];
    }

    // ---- public: 汎用 readEntries ------------------------------------------

    /**
     * LDAP 検索の薄いラッパ。結果を配列で返す（失敗時は空配列）。
     *
     * @param resource $ds
     * @param string   $base
     * @param string   $filter
     * @param array    $attrs
     * @return array<int,array<string,mixed>>
     */
    public static function readEntries($ds, string $base, string $filter, array $attrs = ['*']): array
    {
        $sr = @ldap_search($ds, $base, $filter, $attrs);
        if (!$sr) return [];
        $entries = @ldap_get_entries($ds, $sr);
        if (!is_array($entries) || !isset($entries['count'])) return [];

        $out = [];
        for ($i = 0; $i < $entries['count']; $i++) {
            $out[] = $entries[$i];
        }
        return $out;
    }

    // ---- public: sambaGroupMapping の整合付け -------------------------------

    /**
     * 指定 DN(posixGroup) に対し、sambaGroupMapping 属性を付与/更新する。
     *
     * - objectClass: sambaGroupMapping を追加（なければ）
     * - sambaSID:     指定 SID に差し替え
     * - sambaGroupType: 'domain'|'local'|'builtin' → 2|4|5
     * - displayName:  指定 display に差し替え（省略可なら空文字も可）
     *
     * @param resource $ds
     * @param string   $dn
     * @param string   $sid
     * @param string   $display
     * @param string   $type
     * @param bool     $confirm
     * @param callable $info
     * @param callable $warn
     * @return void
     */
    public static function ensureGroupMapping(
        $ds,
        string $dn,
        string $sid,
        string $display,
        string $type,
        bool $confirm,
        callable $info,
        callable $warn
    ): void {
        // 1) 現在値を取得
        $cur = self::readByDn($ds, $dn, ['objectClass','sambaSID','sambaGroupType','displayName']);
        if ($cur === null) {
            $warn(sprintf('[WARN] not found: %s', $dn));
            return;
        }

        $haveSgm = self::hasObjectClass($cur, 'sambaGroupMapping');
        $curSid  = self::firstString($cur, 'sambaSID');
        $curType = self::firstString($cur, 'sambaGroupType');
        $curDisp = self::firstString($cur, 'displayName');

        $wantType = self::mapGroupType($type);
        if ($wantType === null) {
            $warn(sprintf('[WARN] unknown sambaGroupType "%s" (dn=%s)', $type, $dn));
            return;
        }

        $modsAdd = [];
        $modsRep = [];

        if (!$haveSgm) {
            $modsAdd['objectClass'] = array_merge(
                self::getObjectClasses($cur),
                ['sambaGroupMapping']
            );
        }

        if ($curSid !== $sid) {
            $modsRep['sambaSID'] = [$sid];
        }

        if ($curType !== (string)$wantType) {
            $modsRep['sambaGroupType'] = [(string)$wantType];
        }

        if ($curDisp !== $display) {
            $modsRep['displayName'] = [$display];
        }

        if (empty($modsAdd) && empty($modsRep)) {
            $info(sprintf('[OK] up-to-date: %s (sid=%s type=%s display=%s)', $dn, $sid, $wantType, $display));
            return;
        }

        // 2) 変更の表示
        if (!empty($modsAdd)) {
            $info(sprintf('[MOD-ADD] %s add: %s', $dn, self::modsPreview($modsAdd)));
        }
        if (!empty($modsRep)) {
            $info(sprintf('[MOD-REPL] %s replace: %s', $dn, self::modsPreview($modsRep)));
        }

        // 3) 実行 or DRY-RUN
        if ($confirm) {
            if (!empty($modsAdd)) {
                // objectClass の追加は add ではなく replace が安全（既存+追加の配列で置換）
                $rc = @ldap_mod_replace($ds, $dn, ['objectClass' => $modsAdd['objectClass']]);
                if (!$rc) {
                    $warn(sprintf('[ERR] ldap_mod_replace(objectClass) failed: %s', @ldap_error($ds)));
                }
                unset($modsAdd['objectClass']);
            }
            if (!empty($modsAdd)) {
                // ここに到達することは通常ないが残しておく（他属性を add したい場合）
                $rc = @ldap_mod_add($ds, $dn, $modsAdd);
                if (!$rc) {
                    $warn(sprintf('[ERR] ldap_mod_add failed: %s', @ldap_error($ds)));
                }
            }
            if (!empty($modsRep)) {
                $rc = @ldap_mod_replace($ds, $dn, $modsRep);
                if (!$rc) {
                    $warn(sprintf('[ERR] ldap_mod_replace failed: %s', @ldap_error($ds)));
                }
            }
        } else {
            $info('[DRY-RUN] use --confirm to write changes');
        }
    }

    // =========================================================================
    // 内部ヘルパ
    // =========================================================================

    /**
     * DN で 1件取得（なければ null）
     * @param resource $ds
     * @param string   $dn
     * @param array    $attrs
     * @return ?array
     */
    private static function readByDn($ds, string $dn, array $attrs = ['*']): ?array
    {
        $sr = @ldap_read($ds, $dn, '(objectClass=*)', $attrs);
        if (!$sr) return null;
        $entries = @ldap_get_entries($ds, $sr);
        if (!is_array($entries) || ($entries['count'] ?? 0) < 1) return null;
        return $entries[0];
    }

    /** @param array $entry */
    private static function hasObjectClass(array $entry, string $oc): bool
    {
        $ocs = self::getObjectClasses($entry);
        foreach ($ocs as $v) {
            if (strcasecmp($v, $oc) === 0) return true;
        }
        return false;
    }

    /** @param array $entry */
    private static function getObjectClasses(array $entry): array
    {
        $res = [];
        if (isset($entry['objectclass']) && is_array($entry['objectclass'])) {
            $cnt = $entry['objectclass']['count'] ?? 0;
            for ($i = 0; $i < $cnt; $i++) {
                $res[] = (string)$entry['objectclass'][$i];
            }
        }
        return $res;
    }

    /** @param array $entry */
    private static function firstString(array $entry, string $attr): ?string
    {
        $key = strtolower($attr);
        if (!isset($entry[$key])) return null;
        $v = $entry[$key][0] ?? null;
        return is_string($v) ? $v : null;
    }

    /** 'domain'|'local'|'builtin' → sambaGroupType(2|4|5) */
    private static function mapGroupType(string $type): ?int
    {
        $t = strtolower(trim($type));
        switch ($t) {
            case 'domain':  return 2; // DOMAIN_GROUP
            case 'local':   return 4; // ALIAS(local group)
            case 'builtin': return 5; // BUILTIN
        }
        // 既に数値で渡ってきた場合の救済
        if (ctype_digit($t)) return (int)$t;
        return null;
    }

    /** 変更プレビュー用に簡潔に整形 */
    private static function modsPreview(array $mods): string
    {
        $parts = [];
        foreach ($mods as $k => $v) {
            if (is_array($v)) {
                $parts[] = sprintf('%s=[%s]', $k, implode(',', array_map('strval', $v)));
            } else {
                $parts[] = sprintf('%s=%s', $k, (string)$v);
            }
        }
        return implode(' ', $parts);
    }
}

