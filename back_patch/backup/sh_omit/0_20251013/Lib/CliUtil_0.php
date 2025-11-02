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

    /**
     * コンストラクタ
     * - 明示的な map を渡せばそれを使用
     * - ldap オプションがあれば、LDAP から取得して使用
     * - どちらも無ければ shared（あれば）→ default の順で使用
     *
     * $opts = [
     *   'map'  => ['group' => gid, ...],            // 明示マップ
     *   'ldap' => [
     *       'conn'     => \LDAP\Connection,         // 既存接続（あればこちら優先）
     *       'uri'      => 'ldaps://host',           // conn が無い場合に利用
     *       'bind_dn'  => 'cn=Admin,...',
     *       'bind_pw'  => 'secret',
     *       'base_dn'  => 'ou=Groups,dc=...,dc=...',
     *       'filter'   => '(gidNumber=*)',          // 省略可
     *       'attrs'    => ['cn','gidNumber'],       // 省略可
     *   ]
     * ]
     */
    public function __construct(?array $opts = null)
    {
        $opts ??= [];

        // 1) map が直渡しならそれを使う
        if (!empty($opts['map']) && is_array($opts['map'])) {
            $this->ldapGroupMap = $opts['map'];
            return;
        }

        // 2) LDAP 指定があれば LDAP から取得
        if (!empty($opts['ldap']) && is_array($opts['ldap'])) {
            $ldap = $opts['ldap'];
            $map  = null;

            if (!empty($ldap['conn']) && $ldap['conn'] instanceof \LDAP\Connection) {
                $map = self::fetchGroupMapFromLdap($ldap['conn'], $ldap['base_dn'] ?? '', $ldap['filter'] ?? '(gidNumber=*)', $ldap['attrs'] ?? ['cn','gidNumber']);
            } elseif (!empty($ldap['uri']) && !empty($ldap['bind_dn']) && array_key_exists('bind_pw', $ldap) && !empty($ldap['base_dn'])) {
                $ds = @ldap_connect($ldap['uri']);
                if ($ds === false) {
                    throw new \RuntimeException("ldap_connect failed: {$ldap['uri']}");
                }
                ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
                if (!@ldap_bind($ds, $ldap['bind_dn'], (string)$ldap['bind_pw'])) {
                    throw new \RuntimeException("ldap_bind failed: " . ldap_error($ds));
                }
                try {
                    $map = self::fetchGroupMapFromLdap(
                        $ds,
                        $ldap['base_dn'],
                        $ldap['filter'] ?? '(gidNumber=*)',
                        $ldap['attrs']  ?? ['cn','gidNumber']
                    );
                } finally {
                    @ldap_unbind($ds);
                }
            }

            if (is_array($map) && $map) {
                $this->ldapGroupMap = $map;
                return;
            }
            // LDAPに失敗したらフォールバック
        }

        // 3) 共有マップがあればそれ、なければデフォルト
        $this->ldapGroupMap = self::$sharedGroupMap ?? self::$defaultGroupMap;
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

    /** 共有マップを LDAP で初期化する静的メソッド（既存接続） */
    public static function initFromLdap(\LDAP\Connection $ds, string $baseDn, string $filter = '(gidNumber=*)', array $attrs = ['cn','gidNumber']): void
    {
        self::$sharedGroupMap = self::fetchGroupMapFromLdap($ds, $baseDn, $filter, $attrs);
    }

    /** LDAP から「cn → gidNumber」の連想配列を作るヘルパ（共通実装） */
    private static function fetchGroupMapFromLdap(\LDAP\Connection $ds, string $baseDn, string $filter, array $attrs): array
    {
        $map = [];
        $sr = @ldap_search($ds, $baseDn, $filter, $attrs);
        if ($sr === false) {
            throw new \RuntimeException('ldap_search failed: ' . ldap_error($ds));
        }
        $entries = ldap_get_entries($ds, $sr);
        $count = (int)($entries['count'] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            $e = $entries[$i];
            // cn は必須、gidNumber がないエントリはスキップ
            $cn  = $e['cn'][0]         ?? null;
            $gid = $e['gidnumber'][0]  ?? null;
            if ($cn !== null && ctype_digit((string)$gid)) {
                $map[(string)$cn] = (int)$gid;
            }
        }
        return $map;
    }

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

    /** 静的：共有マップから取得（init 済み/LDAP 済み→なければ default）
	 * 
	 * $group = users, esmile-dev, nicori-dev
	 * return = 100, 2001, 2002
     * debug print
		print_r(self::$defaultGroupMap);
		print_r(self::$sharedGroupMap);
		echo "group = ". $group . " -> " . $map[$group];
		echo "\n";
     */
    public static function gidByGroup(string $group): int|string|null
    {
		// table が空なら初期化！
		self::$sharedGroupMap === null && self::initGroupMap(null);

        $map = self::$sharedGroupMap ?? self::$defaultGroupMap;

        return $map[$group] ?? null;
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

    /** インスタンス：このインスタンスのマップから取得（配列で返す） */
    public function gidByGroupLdap(string $group): ?array
    {
        // 数値文字列なら int化して、名前を逆引き
        if (ctype_digit($group)) {
            $gid = (int)$group;
			if( $name = array_search($gid, $this->ldapGroupMap, true)) {
	            return [
//    	            'name' => $name ?: (string)$group,
    	            'name' => $name,
        	        'gid'  => $gid,
	            ];
			}
        } else {
	        // 通常のグループ名の場合
    	    $gid = $this->ldapGroupMap[$group] ?? null;

        	if ($gid !== null) {
	   	        return [
    		        'name' => $group,
	        	    'gid'  => (int)$gid,
		        ];
			}
		}
		// error !!
		return null;
    }


    /** インスタンスの現在マップを取得（デバッグ/表示用） */
    public function getGroupMap(): array
    {
        return $this->groupMap;
    }
}
