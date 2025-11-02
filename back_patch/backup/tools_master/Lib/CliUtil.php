<?php
declare(strict_types=1);

namespace Tools\Lib;

class CliUtil
{
    /** 既定の静的マップ（何も初期化されない場合のフォールバック） */
    private static array $defaultGroupMap = [
        'users'       => 100,
        'esmile-dev'  => 2001,
        'nicori-dev'  => 2002,
        'kindaka-dev' => 2003,
        'boj-dev'     => 2005,
        'e_game-dev'  => 2009,
        'solt-dev'    => 2010,
        'social-dev'  => 2012,
    ];

    /** 静的に共有する実運用マップ（init / LDAPで上書き可） */
    private static ?array $sharedGroupMap = null;

    /** インスタンス専用マップ（コンストラクタで作る個別の写し） */
	// groupMap -> ldapGroupMap
    private array $ldapGroupMap;

    /** ========== CLI 引数パーサ（既存） ========== */
    public static function args(array $argv): array
    {
        $res = [];
        foreach ($argv as $arg) {
            if (!str_starts_with($arg, '--')) continue;
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$key, $val] = explode('=', $arg, 2);
                $res[$key] = $val;
            } else {
                $res[$arg] = true;
            }
        }
        return $res;
    }

    /** ====== 共有マップ（静的）を外部からセット：手動 init を残す ====== */

/*
Array
(
    [users]      => 100
    [esmile-dev] => 2001
    [nicori-dev] => 2002
    [kindaka-dev]=> 2003
    [boj-dev]    => 2005
    [e_game-dev] => 2009
    [solt-dev]   => 2010
    [social-dev] => 2012
    [100]  => users
    [2001] => esmile-dev
    [2002] => nicori-dev
    [2003] => kindaka-dev
    [2005] => boj-dev
    [2009] => e_game-dev
    [2010] => solt-dev
    [2012] => social-dev
)
*/

    public static function initGroupMap(array $groupMap = null): void
    {
        $mapTemp = $groupMap ?? self::$defaultGroupMap;
	
		// 1) キーと値を入れ替え（すべて文字列化）
		$flipped = [];
		foreach ($mapTemp as $k => $v) {
		    $flipped[(string)$v] = (string)$k;  // キーも値も文字列化
		}

		// 2) マージ（連想配列の結合）
		//   array_merge はキーが数字だとリインデックスされるので注意。
		//   この場合は「+」演算子を使う方が安全です。
		$merged = $mapTemp + $flipped;

		// 3) 結果確認
//		print_r($merged);
//		exit;

		self::$sharedGroupMap = $merged;
    }


    /** 静的：共有マップから取得
	 * 
     */
    public static function gidByGroupLocalNameGid(string $group = ''): ?array
    {
        $gid   = null;
        $name  = null;
        $found = false;
    
        // テーブルが空なら初期化
        if (self::$sharedGroupMap === null) {
            self::initGroupMap(null);
        }
    
        if (ctype_digit($group)) {
            $gid   = (int)$group;
            $name  = array_search($gid, self::$sharedGroupMap, true);
            $found = ($name !== false);
        } elseif ($group !== '') {
            $name  = $group;
            $gid   = self::$sharedGroupMap[$group] ?? null;
            $found = ($gid !== null);
        }
    
        if (!$found) {
            // エラーメッセージ出力（stderrに統一）
            fwrite(STDERR, "[ERROR] グループ {$group} が見つかりません。\n");
    
            // 必要であれば、利用可能なグループ一覧を一部表示
            $keys = array_keys(self::$sharedGroupMap);
            $sample = implode(', ', array_slice($keys, 0, 5));
            fwrite(STDERR, "        利用可能なグループ例: {$sample} ...\n");
    
            exit(2);
        }
    
        return [
            'flag' => $found,
            'gid'  => $gid,
            'name' => $name
        ];
    }

// -- end
}
