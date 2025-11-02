<?php
declare(strict_types=1);

namespace Tools\Ldap\Support;

/**
 * LDAP まわりの共通ヘルパ
 */
final class LdapUtil
{
    /** DN が存在するか */
    public static function dnExists($ds, string $dn): bool
    {
        $sr = @ldap_read($ds, $dn, '(objectClass=*)', ['dn']);
        if ($sr === false) return false;
        $e = ldap_get_entries($ds, $sr);
        return ($e && ($e['count'] ?? 0) > 0);
    }

    /** ベース + フィルタで配列取得 */
    public static function readEntries($ds, string $base, string $filter, array $attrs=['*']): array
    {
        $sr = @ldap_search($ds, $base, $filter, $attrs);
        if ($sr === false) return [];
        $res = ldap_get_entries($ds, $sr);
        $out = [];
        for ($i=0; $i<($res['count'] ?? 0); $i++) $out[] = $res[$i];
        return $out;
    }

    /** Users/Groups の sambaSID から Domain SID を推定 */
    public static function inferDomainSid($ds, string $baseDn): ?string
    {
        foreach (['ou=Users','ou=Groups'] as $ou) {
            $entries = self::readEntries($ds, "{$ou},{$baseDn}", '(sambaSID=*)', ['sambaSID']);
            if ($entries) {
                $sid = $entries[0]['sambasid'][0] ?? null;
                if ($sid && strpos($sid,'-') !== false) {
                    $parts = explode('-', $sid);
                    array_pop($parts); // RID を捨てる
                    return implode('-', $parts);
                }
            }
        }
        return null;
    }

    /** 既存の (gid, rid) と RID リストを収集 */
    public static function collectGidRidPairs($ds, string $groupsDn, string $domSid): array
    {
        $existing = self::readEntries(
            $ds, $groupsDn,
            '(&(objectClass=posixGroup)(|(objectClass=sambaGroupMapping)(sambaSID=*)))',
            ['gidNumber','sambaSID','cn']
        );
        $ridList = []; $pairs = [];
        foreach ($existing as $e) {
            $sid = $e['sambasid'][0] ?? null;
            $gid = isset($e['gidnumber'][0]) ? (int)$e['gidnumber'][0] : null;
            if ($sid && preg_match('#^'.preg_quote($domSid,'#').'\-(\d+)$#', $sid, $m)) {
                $rid = (int)$m[1];
                $ridList[] = $rid;
                if ($gid !== null) $pairs[] = ['gid'=>$gid,'rid'=>$rid];
            }
        }
        sort($ridList, SORT_NUMERIC);
        return [$pairs, $ridList];
    }

    /** rid = a*gid + b（整数）。全点合致時のみ採用 */
    public static function inferRidFormula(array $pairs): array
    {
        $n = count($pairs);
        if ($n < 2) return [null, null];

        for ($i=0; $i<$n-1; $i++) {
            for ($j=$i+1; $j<$n; $j++) {
                $g1=$pairs[$i]; $g2=$pairs[$j];
                $dg = $g2['gid'] - $g1['gid'];
                $dr = $g2['rid'] - $g1['rid'];
                if ($dg === 0) continue;
                if ($dr % $dg !== 0) continue; // a が整数でない
                $a = (int)($dr / $dg);
                $b = $g1['rid'] - $a * $g1['gid'];
                // 全点検証
                $ok = true;
                foreach ($pairs as $p) {
                    if ($a * $p['gid'] + $b !== $p['rid']) { $ok = false; break; }
                }
                if ($ok) return [$a,$b];
            }
        }
        return [null, null];
    }

    /** sambaGroupMapping を確実に付与（段階適用に対応） */
    public static function ensureGroupMapping($ds, string $dn, string $sid, string $display, string $type, bool $confirm, callable $info, callable $warn): void
    {
        $entry = [
            'objectClass'    => ['top','posixGroup','sambaGroupMapping'],
            'displayName'    => $display,
            'sambaGroupType' => $type,
            'sambaSID'       => $sid,
        ];

        if (!$confirm) { $info("DRY: MAP dn=$dn sid=$sid type=$type displayName=\"$display\""); return; }

        if (@ldap_modify($ds, $dn, $entry)) { $info("MAP ADD: dn=$dn sid=$sid type=$type"); return; }

        // objectClass 追加でこける環境向けフォールバック
        $warn("modify failed on $dn (".ldap_error($ds).") -> try stepwise");
        @ldap_mod_add($ds, $dn, ['objectClass' => ['sambaGroupMapping']]);
        @ldap_mod_replace($ds, $dn, [
            'displayName'    => $display,
            'sambaGroupType' => $type,
            'sambaSID'       => $sid,
        ]);
        $info("MAP ADD(stepwise): dn=$dn sid=$sid");
    }
}

