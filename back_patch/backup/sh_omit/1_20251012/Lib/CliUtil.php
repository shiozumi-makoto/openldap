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
    private array $groupMap;

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
            $this->groupMap = $opts['map'];
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
                $this->groupMap = $map;
                return;
            }
            // LDAPに失敗したらフォールバック
        }

        // 3) 共有マップがあればそれ、なければデフォルト
        $this->groupMap = self::$sharedGroupMap ?? self::$defaultGroupMap;
    }

    /** ====== 共有マップ（静的）を外部からセット：手動 init を残す ====== */
    public static function init(array $groupMap): void
    {
        self::$sharedGroupMap = $groupMap;
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

    /** 静的：共有マップから取得（init 済み/LDAP 済み→なければ default） */
    public static function gidByGroup(string $group): ?int
    {
        $map = self::$sharedGroupMap ?? self::$defaultGroupMap;
        return $map[$group] ?? null;
    }

    /** インスタンス：このインスタンスのマップから取得（配列で返す） */

    public function gidByGroupLocal(string $group): ?array
    {
        // 数値文字列なら int化して、名前を逆引き
        if (ctype_digit($group)) {
            $gid = (int)$group;
			if( $name = array_search($gid, $this->groupMap, true)) {
	            return [
//    	            'name' => $name ?: (string)$group,
    	            'name' => $name,
        	        'gid'  => $gid,
	            ];
			}

        } else {
   
	        // 通常のグループ名の場合
    	    $gid = $this->groupMap[$group] ?? null;

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


    /** インスタンス：このインスタンスのマップから取得
	public function gidByGroupLocal(string $group): ?int
	{
	    // 数値文字列なら、その値を int に変換して返す
	    if (ctype_digit($group)) {
	        return (int)$group;
	    }

	    // 通常のグループ名の場合はマップから検索
	    return $this->groupMap[$group] ?? null;
	}
 */


    /** インスタンスの現在マップを取得（デバッグ/表示用） */
    public function getGroupMap(): array
    {
        return $this->groupMap;
    }
}
