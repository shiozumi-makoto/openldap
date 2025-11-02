<?php
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
		//print_r($merged);
		//exit;
		self::$sharedGroupMap = $merged;
    }


    /** 静的：共有マップから取得
	 * 
	 * 確認用 
	 * 
	print_r(self::$defaultGroupMap);
	print_r(self::$sharedGroupMap);
	print_r(self::$ldapGroupMap);
	 * 
	 * 
	echo "name: $name gid: $gid [FLAG] ";
	var_dump($flag);
	exit;
	 * 
	 * 
     */

    public static function gidByGroupLocalNameGid(string $group=''): ?array
    {
	    $gid   = null;
	    $name  = null;
	    $found = false;

		// table が空なら初期化！
		self::$sharedGroupMap === null && self::initGroupMap(null);

        if (ctype_digit($group)) {
            $gid   = (int)$group;
	        $name  = array_search($gid, self::$sharedGroupMap, true);
	        $found = ($name !== false);  // ← 修正点：true＝見つかった
		} else if($group!=='') {
			$name  = $group;
	        $gid   = self::$sharedGroupMap[$group] ?? null;
	        $found = ($gid !== null);
		}

	    return [ 'flag' => $found, 'gid'  => $gid, 'name' => $name ];
    }


}
