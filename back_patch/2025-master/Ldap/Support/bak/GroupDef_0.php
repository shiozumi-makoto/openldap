<?php
declare(strict_types=1);

namespace Tools\Ldap\Support;

/**
 * GroupDef.php
 *  - 事業グループ（users, esmile-dev, ...）
 *  - 職位クラスグループ（adm-cls, ...） を定義し、検索支援を提供
 */
final class GroupDef
{
    // 事業グループ名 → gidNumber
    public const BIZ_MAP = [
        'users'       => 100,
        'esmile-dev'  => 2001,
        'nicori-dev'  => 2002,
        'kindaka-dev' => 2003,
        'boj-dev'     => 2005,
        'e_game-dev'  => 2009,
        'solt-dev'    => 2010,
        'social-dev'  => 2012,
    ];

    // 職位クラス（employeeType / level_id レンジ対応）
    public const DEF = [
        [ 'name' => 'adm-cls', 'gid' => 3001, 'min' => 1,  'max' => 2,
          'display' => 'Administrator Class (1–2) / 管理者階層' ],
        [ 'name' => 'dir-cls', 'gid' => 3003, 'min' => 3,  'max' => 4,
          'display' => 'Director Class (3–4) / 取締役階層' ],
        [ 'name' => 'mgr-cls', 'gid' => 3006, 'min' => 5,  'max' => 5,
          'display' => 'Manager Class (5) / 部門長' ],
        [ 'name' => 'mgs-cls', 'gid' => 3016, 'min' => 6,  'max' => 14,
          'display' => 'Sub-Manager Class (6–14) / 課長・監督職' ],
        [ 'name' => 'stf-cls', 'gid' => 3020, 'min' => 15, 'max' => 19,
          'display' => 'Staff Class (15–19) / 主任・一般社員' ],
        [ 'name' => 'ent-cls', 'gid' => 3021, 'min' => 20, 'max' => 20,
          'display' => 'Entry Class (20) / 新入社員' ],
        [ 'name' => 'tmp-cls', 'gid' => 3099, 'min' => 21, 'max' => 98,
          'display' => 'Temporary Class (21–98) / 派遣・退職者' ],
        [ 'name' => 'err-cls', 'gid' => 3099, 'min' => 99, 'max' => 9999,
          'display' => 'Error Class (99) / 例外処理・未定義ID用' ],
    ];

    /** 事業グループ：名前→gidNumber */
    public static function bizNameToGid(string $name): ?int {
        return self::BIZ_MAP[$name] ?? null;
    }

    /** 事業グループ：gidNumber→名前（逆引き） */
    public static function bizGidToName(int $gid): ?string {
        foreach (self::BIZ_MAP as $n => $g) {
            if ($g === $gid) return $n;
        }
        return null;
    }

    /**
     * employeeType を柔軟にパース
     * 例: "adm-cls 1", "adm-cls1", "adm-cls-1", " ADM-CLS   2 " → ['adm-cls', 1 or 2]

		// 旧: ハイフンも空白にしていたため "adm-cls" → "adm cls" になって不一致
		// $s = preg_replace('/[[:space:]\-]+/u', ' ', $s ?? '');
		// 新: ハイフンは残す（スペースだけ正規化）

     */
public static function parseEmployeeType(?string $raw): ?array {
    if ($raw === null) return null;

    // 正規化：小文字化 + 余分な空白を1つに
    $s = trim(mb_strtolower($raw));
    $s = preg_replace('/[[:space:]]+/u', ' ', $s);
    $s = trim($s);
    if ($s === '') return null;

    // "<name> <num>" 例: "adm-cls 1"
    if (preg_match('/^([a-z][a-z0-9_-]*)\s+(\d{1,4})$/u', $s, $m)) {
        return [$m[1], (int)$m[2]];
    }
    // "<name>-<num>" または "<name><num>" 例: "adm-cls-1", "adm-cls1"
    if (preg_match('/^([a-z][a-z0-9_-]*?)(?:\-)?(\d{1,4})$/u', $s, $m)) {
        return [$m[1], (int)$m[2]];
    }
    // 名前のみ 例: "adm-cls"
    return [$s, null];
}


/*
    public static function parseEmployeeType(?string $raw): ?array {

		$s = preg_replace('/[[:space:]]+/u', ' ', $s ?? '');
		$s = trim($s);

		// "<name> <num>" 形式（name は英小文字・数字・アンダースコア・ハイフンを許容）
		if (preg_match('/^([a-z][a-z0-9_-]*)\s+(\d{1,4})$/u', $s, $m)) {
		    $name = $m[1];
		    $num  = (int)$m[2];
		    return [$name, $num];
		}
		// "<name><sep><num>" 形式（区切りは省略 or ハイフン1個）
		if (preg_match('/^([a-z][a-z0-9_-]*?)(?:\-)?(\d{1,4})$/u', $s, $m)) {
		    $name = $m[1];
		    $num  = (int)$m[2];
		    return [$name, $num];
		}

	// 名前のみ
		return [$s, null];
    }
*/


/*
    public static function parseEmployeeType(?string $raw): ?array {
        if (!$raw) return null;
        $s = trim(mb_strtolower($raw));
        // 区切りを正規化（空白・ハイフンをスペースに）
        $s = preg_replace('/[[:space:]\-]+/u', ' ', $s ?? '');
        $s = trim($s);
        if ($s === '') return null;

        // パターン1: "<name> <num>"
        if (preg_match('/^([a-z0-9_]+(?:\s?[a-z]+)?)\s+(\d{1,4})$/', $s, $m)) {
            $name = trim($m[1]);
            $num  = (int)$m[2];
            return [$name, $num];
        }
        // パターン2: "<name><num>"（スペース無し）
        if (preg_match('/^([a-z][a-z0-9_]+?)(\d{1,4})$/', $s, $m)) {
            $name = trim($m[1]);
            $num  = (int)$m[2];
            return [$name, $num];
        }
        // パターン3: 名前のみ
        return [$s, null];
    }
*/

    /** 職位クラス：employeeType（柔軟受理） → 定義配列 */
    public static function fromEmployeeType(?string $employeeType): ?array {
        $parsed = self::parseEmployeeType($employeeType);
        if (!$parsed) return null;
        [$name, $num] = $parsed;

        // 名前一致を優先
        $hit = null;
        foreach (self::DEF as $g) {
            if ($g['name'] === $name) { $hit = $g; break; }
        }
        if (!$hit) return null;

        // 数値があれば整合チェック（範囲外でも name 優先で通す）
        if ($num !== null && ($num < $hit['min'] || $num > $hit['max'])) {
            // もし範囲外でも、次のフォールバックで level_id 判定すれば辻褄は合うため、ここでは警告だけにしたい場合はログ出力へ
            // fprintf(STDERR, "[WARN] employeeType level %d is out of range for %s (%d-%d)\n", $num, $name, $hit['min'], $hit['max']);
        }
        return $hit;
    }

    /** 職位クラス：level_id → 定義配列 */
    public static function fromLevelId(?int $levelId): ?array {
        if ($levelId === null) return null;
        foreach (self::DEF as $g) {
            if ($levelId >= $g['min'] && $levelId <= $g['max']) return $g;
        }
        return null;
    }

    /** 職位クラス：gid → 定義配列（逆引き） */
    public static function clsFromGid(int $gid): ?array {
        foreach (self::DEF as $g) {
            if ($g['gid'] === $gid) return $g;
        }
        return null;
    }

    /** グループDN生成（ou=Groups 固定想定） */
    public static function groupDnByName(string $groupName, string $baseDn): string {
        return "cn={$groupName},ou=Groups,{$baseDn}";
    }
}
