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

    // 事業グループ名 → description
    public const DESC_INFO = [ 
        'users'       => '[al:users]',
        'esmile-dev'  => '[01:esmile]',
        'nicori-dev'  => '[02:nicori]',
        'kindaka-dev' => '[03:kindaka]',
        'moepara-dev' => '[04:moepara]',
        'boj-dev'     => '[05:boj]',
        'e_game-dev'  => '[09:e_games]',
        'solt-dev'    => '[10:soltribe]',
        'social-dev'  => '[12:social]',
    ];

    // 職位クラス（employeeType / level_id レンジ対応）
    public const DEF = [
        [ 'name' => 'adm-cls', 'gid' => 3001, 'min' => 1,  'max' => 2,	 	'display' => 'Administrator Class (1–2) / 管理者階層',	'description' => '[01:level]'	],
        [ 'name' => 'dir-cls', 'gid' => 3003, 'min' => 3,  'max' => 4,	 	'display' => 'Director Class (3–4) / 取締役階層',		'description' => '[03:level]'	],
        [ 'name' => 'mgr-cls', 'gid' => 3005, 'min' => 5,  'max' => 5,	 	'display' => 'Manager Class (5) / 部門長',				'description' => '[05:level]'	],
        [ 'name' => 'mgs-cls', 'gid' => 3006, 'min' => 6,  'max' => 14,		'display' => 'Sub-Manager Class (6–14) / 課長・監督職',	'description' => '[10:level]'	],
        [ 'name' => 'stf-cls', 'gid' => 3015, 'min' => 15, 'max' => 19,		'display' => 'Staff Class (15–19) / 主任・一般社員',	'description' => '[15:level]'	],
        [ 'name' => 'ent-cls', 'gid' => 3020, 'min' => 20, 'max' => 20,		'display' => 'Entry Class (20) / 新入社員',				'description' => '[20:level]'	],
        [ 'name' => 'tmp-cls', 'gid' => 3021, 'min' => 21, 'max' => 98,		'display' => 'Temporary Class (21–98) / 派遣・退職者',	'description' => '[21:level]'	],
        [ 'name' => 'err-cls', 'gid' => 3099, 'min' => 99, 'max' => 9999,	'display' => 'Error Class (99) / 例外処理・未定義ID用',	'description' => '[99:level]'	],
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
     *
     * 旧: ハイフンも空白にしていたため "adm-cls" → "adm cls" になって不一致
     * 新: ハイフンは残す（スペースだけ正規化）
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
            // 必要なら警告ログを出す運用へ
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

    /**
     * グループ名（事業 or 職位）から定義配列を返す
     * - 職位クラス名（adm-cls 等）の場合：DEF の行を返す
     * - 事業グループ名（users, esmile-dev 等）の場合：
     *     ['name'=>..., 'gid'=>..., 'min'=>null, 'max'=>null, 'display'=>name] を返す
     */
    public static function fromGroupName(string $groupName): ?array {
        // 1) 職位クラス名
        foreach (self::DEF as $g) {
            if ($g['name'] === $groupName) return $g;
        }
        // 2) 事業グループ名
        $gid = self::bizNameToGid($groupName);
        if ($gid !== null) {
            return [
                'name'    => $groupName,
                'gid'     => $gid,
                'min'     => null,
                'max'     => null,
                'display' => $groupName,
            ];
        }
        return null;
    }

    /** グループDN生成（※実環境は ou=Group（単数形）） */
    public static function groupDnByName(string $groupName, string $baseDn): string {
        // 以前の "ou=Groups" だと "No such object (32)" になっていたため修正
        return "cn={$groupName},ou=Group,{$baseDn}";
    }

    /**
     * ★ classify()
     * 与えられた値（グループ名 / employeeType / level_id）から
     * 事業 or 職位クラスを判定して配列を返す。
     *
     * 優先順位:
     *   1) employeeType が解析できればそれを返す
     *   2) groupName が BIZ_MAP または職位クラス名に一致すれば返す
     *   3) levelId が与えられれば fromLevelId の結果を返す
     *   4) いずれも不可なら null
     */
    public static function classify(?string $groupName = null, ?string $employeeType = null, ?int $levelId = null): ?array
    {
        // 1) employeeType 優先（"adm-cls 1" 等）
        if ($employeeType !== null) {
            $r = self::fromEmployeeType($employeeType);
            if ($r !== null) return $r;
        }

        // 2) グループ名（事業 or 職位）
        if ($groupName !== null) {
            $r = self::fromGroupName($groupName);
            if ($r !== null) return $r;
        }

        // 3) level_id から職位クラス推定
        if ($levelId !== null) {
            return self::fromLevelId($levelId);
        }

        // 4) 判定不能
        return null;
    }


public static function all(): array
{
    $all = [];

    // 事業グループ
    foreach (self::BIZ_MAP as $name => $gid) {
        $all[] = [
            'type'    => 'biz',
            'name'    => $name,
            'gid'     => (int)$gid,
            'display' => $name,
			'description' => self::DESC_INFO[$name],
        ];
    }

    // 職位クラス
    foreach (self::DEF as $row) {
        $all[] = [
            'type'    => 'cls',
            'name'    => $row['name'],
            'gid'     => (int)$row['gid'],
            'min'     => (int)$row['min'],
            'max'     => (int)$row['max'],
            'display' => $row['display'],
			'description' => $row['description'],
        ];
    }
    return $all;
}


public static function all_id(): array
{

	$GROUP_DEF = self::all();
	$GROUP_DEF_MAP = [];

	foreach ($GROUP_DEF as $row) {
	    if (isset($row['gid'])) {
	        $GROUP_DEF_MAP[$row['gid']] = $row;
   		}
	}
    return $GROUP_DEF_MAP;
}


public static function all_group(): array
{
    $all = [];

    // 事業グループ
    foreach (self::BIZ_MAP as $name => $gid) {

		$prefix = (int)substr((string)$gid,-2);
		

        $all[$prefix] = [
            'type'    => 'biz',
            'name'    => $name,
            'gid'     => (int)$gid,
            'display' => $name,
			'description' => self::DESC_INFO[$name],
        ];

    }
    return $all;
}





}
