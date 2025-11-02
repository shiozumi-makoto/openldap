<?php
declare(strict_types=1);

namespace Tools\Ldap\Support;

/**
 * GroupDef
 *  - 事業グループ（users, esmile-dev, ...）
 *  - 職位クラス（adm-cls, ent-cls, err-cls ...）
 * の定義と判定ヘルパ
 */
final class GroupDef
{
    /**
     * 事業グループ → 固定 gidNumber
     * 例: users=100, social-dev=2012 など
     */
    public const BIZ_MAP = [
        'users'       => 100,
        'esmile-dev'  => 2001,
        'nicori-dev'  => 2002,
        'kindaka-dev' => 2003,
        'boj-dev'     => 2004,
        'e_game-dev'  => 2009,
        'solt-dev'    => 2010,
        'social-dev'  => 2012, // 実環境の例: gid=2012
    ];

    /**
     * 職位クラスの定義（基準 gid と許容レンジ）
     * ※トップ系の gid は 3000 番台で運用
     */
    public const CLASS_DEF = [
        // 管理者階層: level 1–2
        'adm-cls' => [
            'gid'     => 3001,
            'min'     => 1,
            'max'     => 2,
            'display' => 'Administrator Class (1–2) / 管理者階層',
        ],
        // エンタープライズ/営業等級: 例 10–30
        'ent-cls' => [
            'gid'     => 3020,
            'min'     => 10,
            'max'     => 30,
            'display' => 'Enterprise Class (10–30) / 営業等級',
        ],
        // エラー/未分類捕捉: 0–99（広め）
        'err-cls' => [
            'gid'     => 3099,
            'min'     => 0,
            'max'     => 99,
            'display' => 'Error Class (0–99) / エラー用',
        ],
    ];

    /**
     * 事業グループ名から情報を取得
     */
    public static function fromGroupName(string $name): ?array
    {
        $key = strtolower(trim($name));
        if (!array_key_exists($key, self::BIZ_MAP)) {
            return null;
        }
        return [
            'type'    => 'biz',
            'name'    => $key,
            'gid'     => self::BIZ_MAP[$key],
            'min'     => null,
            'max'     => null,
            'display' => strtoupper($key) . ' / 事業グループ',
        ];
    }

    /**
     * employeeType（例: "adm-cls 1", "ent-cls 20"）を解析
     */
    public static function fromEmployeeType(string $employeeType): ?array
    {
        $et = trim(preg_replace('/\s+/', ' ', $employeeType));
        if ($et === '') return null;

        // 例: "adm-cls 1" / "ent-cls 20" / "err-cls 99"
        if (!preg_match('/^([a-z][a-z0-9\-]*)\s+(\d{1,3})$/i', $et, $m)) {
            // 数字なしで "adm-cls" のみ等も許容: min/max付きで返す
            $nameOnly = strtolower($et);
            if (isset(self::CLASS_DEF[$nameOnly])) {
                $def = self::CLASS_DEF[$nameOnly];
                return [
                    'type'    => 'class',
                    'name'    => $nameOnly,
                    'gid'     => $def['gid'],
                    'min'     => $def['min'],
                    'max'     => $def['max'],
                    'display' => $def['display'],
                ];
            }
            return null;
        }

        $name = strtolower($m[1]);
        $num  = (int)$m[2];

        if (!isset(self::CLASS_DEF[$name])) {
            return null;
        }

        $def = self::CLASS_DEF[$name];
        return [
            'type'    => 'class',
            'name'    => $name,
            'gid'     => $def['gid'],
            'min'     => $def['min'],
            'max'     => $def['max'],
            'level'   => $num,
            'inRange' => ($num >= $def['min'] && $num <= $def['max']),
            'display' => $def['display'],
        ];
    }

    /**
     * level_id（1–21 等）から職位クラスを推定（必要なら使用）
     */
    public static function fromLevelId(int $levelId): ?array
    {
        // 便宜的なルール（必要に応じて現場の仕様に合わせて変更）
        if ($levelId >= 1 && $levelId <= 2) {
            $def = self::CLASS_DEF['adm-cls'];
            return [
                'type'    => 'class',
                'name'    => 'adm-cls',
                'gid'     => $def['gid'],
                'min'     => $def['min'],
                'max'     => $def['max'],
                'level'   => $levelId,
                'inRange' => true,
                'display' => $def['display'],
            ];
        }
        if ($levelId >= 10 && $levelId <= 30) {
            $def = self::CLASS_DEF['ent-cls'];
            return [
                'type'    => 'class',
                'name'    => 'ent-cls',
                'gid'     => $def['gid'],
                'min'     => $def['min'],
                'max'     => $def['max'],
                'level'   => $levelId,
                'inRange' => true,
                'display' => $def['display'],
            ];
        }
        // 想定外は err-cls にフォールバック
        $def = self::CLASS_DEF['err-cls'];
        return [
            'type'    => 'class',
            'name'    => 'err-cls',
            'gid'     => $def['gid'],
            'min'     => $def['min'],
            'max'     => $def['max'],
            'level'   => $levelId,
            'inRange' => ($levelId >= $def['min'] && $levelId <= $def['max']),
            'display' => $def['display'],
        ];
    }

    /**
     * ★ classify()
     * 与えられた値（グループ名 / employeeType / level_id）から
     * 事業 or 職位クラスを判定して配列を返す。
     *
     * 優先順位:
     *   1) employeeType が解析できればそれを返す
     *   2) groupName が BIZ_MAP にあれば事業グループを返す
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

        // 2) 事業グループ名（"social-dev" 等）
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
}
