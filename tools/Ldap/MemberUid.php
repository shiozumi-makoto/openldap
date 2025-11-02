<?php
namespace Tools\Ldap;

use RuntimeException;

final class MemberUid {

/** LDAP から全ユーザー取得（uid, dn, gidNumber 等）
 *  $withClass=true のとき employeeType / level_id も取得して返す
 */
public static function fetchUsers(\LDAP\Connection $ds, string $peopleDn, bool $withClass = false): array {

    // 取得属性
    $attrs = ['uid','gidNumber','uidNumber','cn'];
    if ($withClass) {
        // LDAP は小文字キーで返るケースもあるので両方要求しておく
        $attrs = array_merge($attrs, ['employeeType','level_id']);
    }

    // posixAccount を対象にする（uid=* よりも意図が明確）
    $sr = @ldap_search($ds, $peopleDn, '(objectClass=posixAccount)', $attrs);
    if ($sr === false) {
        throw new \RuntimeException('search users failed: ' . ldap_error($ds));
    }

    $entries = ldap_get_entries($ds, $sr);
    $users   = [];

    for ($i = 0; $i < ($entries['count'] ?? 0); $i++) {
        $e = $entries[$i];
        if (empty($e['uid'][0]) || empty($e['dn'])) continue;

        // 基本属性
        $row = [
            'dn'        => $e['dn'],
            'uid'       => (string)$e['uid'][0],
            'gidNumber' => $e['gidnumber'][0] ?? null,
            'uidNumber' => $e['uidnumber'][0] ?? null,
            'cn'        => $e['cn'][0] ?? '',
        ];

        // 追加: 職位クラス関連（必要時のみ）
        if ($withClass) {
            // employeeType は小文字キーで返ることが多い
            $emp = $e['employeetype'][0] ?? ($e['employeeType'][0] ?? null);

            // 返却配列では表記を統一
            $row['employeeType'] = $emp !== null ? (string)$emp : null;
        }

        $users[] = $row;
    }
    return $users;
}

    /** LDAP から全ユーザー取得（uid, dn, gidNumber 等） */
/*
    public static function fetchUsers(\LDAP\Connection $ds, string $peopleDn): array {

        $attrs = ['uid','gidNumber','uidNumber','cn'];
        $sr = @ldap_search($ds, $peopleDn, '(uid=*)', $attrs);

        if ($sr === false) throw new RuntimeException('search users failed: ' . ldap_error($ds));
        $entries = ldap_get_entries($ds, $sr);
        $users = [];

        for ($i = 0; $i < ($entries['count'] ?? 0); $i++) {
            $e = $entries[$i];
            if (empty($e['uid'][0]) || empty($e['dn'])) continue;
            $users[] = [
                'dn'        => $e['dn'],
                'uid'       => (string)$e['uid'][0],
                'gidNumber' => $e['gidnumber'][0] ?? null,
                'uidNumber' => $e['uidnumber'][0] ?? null,
                'cn'        => $e['cn'][0] ?? '',
            ];
        }
        return $users;
    }
*/

    /** グループの memberUid 現状取得 */
    public static function fetchGroupMemberUids(\LDAP\Connection $ds, string $groupDn): array {
        $sr = @ldap_read($ds, $groupDn, '(objectClass=posixGroup)', ['memberUid','cn']);
        if ($sr === false) throw new RuntimeException('group read failed: ' . ldap_error($ds));
        $e = ldap_get_entries($ds, $sr);
        if (($e['count'] ?? 0) < 1) throw new RuntimeException("group not found: {$groupDn}");
        $arr = [];
        if (!empty($e[0]['memberuid'])) {
            for ($i = 0; $i < $e[0]['memberuid']['count']; $i++) {
                $arr[] = (string)$e[0]['memberuid'][$i];
            }
        }
        return $arr;
    }

    /** 
     * memberUid を追加（既存ならスキップ）
     * @param array<string,bool>|null $memberSet  既存 memberUid のセット（['alice'=>true,...]）
     *        null の場合は内部で1回だけ取得してセット化する（レガシー互換）
     */

/**
 * memberUid を追加（既存ならスキップ）
 * $memberSet は「uid => 回数」のカウンタセット:
 *   - 初期化時: 既存メンバーは 1
 *   - 重複に遭遇するたび +1
 *   - 新規追加に成功したら 1 をセット
 *
 * @param array<string,int>|null $memberSet  参照渡しのカウンタセット（nullなら初回のみ内部初期化）
 * @return bool  true=新規追加(またはDRY-RUNで追加予定), false=既存/競合
 */

    public static function add(
        \LDAP\Connection $ds,
        string $groupDn,
        string $uid,
        bool $doWrite,
        ?array &$memberSet = null
    ): bool {
    
        // ① セットが無ければ一度だけ作る（互換用の怠惰初期化）
        if ($memberSet === null) {
            $current = self::fetchGroupMemberUids($ds, $groupDn);
            $memberSet = array_fill_keys($current, 1);
//			echo " ----------------------------------------------- 1st \n";
        }

        // ② 既にメンバーならスキップ（LDAP I/O なし）
        if (isset($memberSet[$uid])) {
			$memberSet[$uid] = (int)($memberSet[$uid] ?? 0) + 1;   // ← 常に int へ
            return false;
        }
    
        // ③ ドライランなら「追加予定」だけ返す（キャッシュは更新しない）
        if (!$doWrite) {
            return true;
        }
    
        // ④ 追加実行
        $entry = ['memberUid' => $uid];
        if (!@ldap_mod_add($ds, $groupDn, $entry)) {
            $err = ldap_error($ds);
            if (stripos($err, 'Type or value exists') !== false) {
                // 同時更新で既に入っていた場合はキャッシュ整合を取っておく
                $memberSet[$uid] = true;
                return false;
            }
            throw new RuntimeException("memberUid add failed uid={$uid}: {$err}");
        }
    
        // ⑤ 成功したらキャッシュ更新
        $memberSet[$uid] = 1;
        return true;
    }

    /** memberUid を追加（既存ならスキップ） */
/*
    public static function add(\LDAP\Connection $ds, string $groupDn, string $uid, bool $doWrite): bool {

        $current = self::fetchGroupMemberUids($ds, $groupDn);
        if (in_array($uid, $current, true)) return false;
        if (!$doWrite) return true; // ドライラン

        $entry = ['memberUid' => $uid];
        if (!@ldap_mod_add($ds, $groupDn, $entry)) {
            $err = ldap_error($ds);
            if (stripos($err, 'Type or value exists') !== false) return false;
            throw new RuntimeException("memberUid add failed uid={$uid}: {$err}");
        }
        return true;
    }
*/

   /**
     * グループの memberUid を全削除（初期化）
     *
     * @param \LDAP\Connection $ds
     * @param string           $groupDn   例: "cn=users,ou=Groups,dc=example,dc=com"
     * @param bool             $doWrite   false=DRY-RUN（実際には削除しない）
     * @return array{count:int,uids:array} 削除対象件数と対象UID一覧（DRY-RUN時も返す）
     *
     * 実装メモ:
     * - 中身が空なら何もしません（count=0）
     * - OpenLDAP等では ldap_mod_del に値配列を渡すのが安定
     * - 属性ごと消す場合は ['memberUid'=>[]] でも可ですが、値配列方式で広く互換
     */
    // 既存のメソッドを置き換え
    public static function deleteGroupMemberUids(
        \LDAP\Connection $ds,
        string $groupDn,
        bool $doWrite,
        bool $doInit
    ): array {
        // 初期化スイッチがOFFなら何もしない
        if (!$doInit) {
            return ['count' => 0, 'uids' => [], 'skipped' => true];
        }
    
        $current = self::fetchGroupMemberUids($ds, $groupDn); // ['alice','bob',...]
        $count   = count($current);
    
        if ($count === 0) {
            return ['count' => 0, 'uids' => [], 'skipped' => false];
        }
    
        if (!$doWrite) {
            // DRY-RUN: 対象だけ返す
            return ['count' => $count, 'uids' => $current, 'skipped' => false];
        }
    
        // 値列挙で削除（互換性重視）
        $entry = ['memberUid' => array_values($current)];
        if (!@ldap_mod_del($ds, $groupDn, $entry)) {
            // 置換で空にするフォールバック（ACLによってはこっちが通る）
            if (!@ldap_mod_replace($ds, $groupDn, ['memberUid' => []])) {
                throw new \RuntimeException('memberUid delete failed: ' . ldap_error($ds));
            }
        }
    
        return ['count' => $count, 'uids' => $current, 'skipped' => false];
    }


   /**
     * memberUid の遭遇回数セット（uid => int）を分類して返す。
     *  1 = 新規追加、2 = 登録済、それ以外 = 「？」扱い。
     *
     * @param array<string,int|bool> $memberSet  uid => 回数（true等が紛れてもOK）
     * @return array{
     *   新規追加: string[],
     *   登録済: string[],
     *   ？: string[],
     *   __counts: array{新規追加:int, 登録済:int, ？:int, total:int}
     * }
     */
    public static function memberGroupGet(array $memberSet): array
    {
        $groups = [
            '新規追加' => [],
            '登録済'   => [],
            '？'       => [],
        ];

        foreach ($memberSet as $uid => $count) {
            $n = (int)$count; // true/false 混入対策
            if ($n === 1) {
                $groups['新規追加'][] = (string)$uid;
            } elseif ($n === 2) {
                $groups['登録済'][]   = (string)$uid;
            } else {
                $groups['？'][]       = (string)$uid;
            }
        }

        $groups['__counts'] = [
            '新規追加' => count($groups['新規追加']),
            '登録済'   => count($groups['登録済']),
            '？'       => count($groups['？']),
            'total'    => count($memberSet),
        ];

        return $groups;
    }


    /**
     * memberGroup のきれいな表示
     * 期待する入力:
     *  [
     *    '新規追加' => string[],
     *    '登録済'   => string[],
     *    '？'       => string[],
     *    '__counts' => ['新規追加'=>int,'登録済'=>int,'？'=>int,'total'=>int]
     *  ]
     */
    public static function printMemberGroup(array $memberGroup): void
    {
        $useColor = class_exists(\Tools\Lib\CliColor::class);
        $B  = $useColor ? [\Tools\Lib\CliColor::class,'bold']    : fn($s)=>$s;
        $G  = $useColor ? [\Tools\Lib\CliColor::class,'green']   : fn($s)=>$s;
        $Y  = $useColor ? [\Tools\Lib\CliColor::class,'yellow']  : fn($s)=>$s;
        $R  = $useColor ? [\Tools\Lib\CliColor::class,'red']     : fn($s)=>$s;
        $C2 = $useColor ? [\Tools\Lib\CliColor::class,'cyan']    : fn($s)=>$s;
    
        // グリッド表示（cols列で均等に並べる）
        $printGrid = function(array $items, int $cols = 3, int $pad = 24) {
            if (empty($items)) { echo "  (none)\n"; return; }
            $rows = (int)ceil(count($items) / $cols);
            for ($r=0; $r<$rows; $r++) {
                $line = '';
                for ($c=0; $c<$cols; $c++) {
                    $i = $r + $c*$rows;
                    if ($i < count($items)) {
                        $cell = " - ".$items[$i];
                        // 右端セルはパディング不要
                        $line .= ($c === $cols-1) ? $cell : str_pad($cell, $pad);
                    }
                }
                echo $line, "\n";
            }
        };
    
        // 見出し
        echo $B("=== memberUid 結果 ==="), "\n";
    
        // サマリー
        $cnt = $memberGroup['__counts'] ?? ['新規追加'=>0,'登録済'=>0,'？'=>0,'total'=>0];
        printf("  合計: %s, %s: %d, %s: %d, %s: %d\n",
            $C2($cnt['total'] ?? 0),
            $G('新規追加'), $cnt['新規追加'] ?? 0,
            $Y('登録済'),   $cnt['登録済']   ?? 0,
            $R('？'),       $cnt['？']       ?? 0
        );
        echo "\n";
    
        // セクション別
        $sections = [
            ['label'=>'新規追加', 'color'=>$G],
            ['label'=>'登録済',   'color'=>$Y],
            ['label'=>'？',       'color'=>$R],
        ];
        foreach ($sections as $sec) {
            $label = $sec['label'];
            $color = $sec['color'];
            $list  = $memberGroup[$label] ?? [];
            echo $B($color("[$label]")), " (", count($list), ")\n";
            $printGrid($list, 3, 26);
            echo "\n";
        }
    }

}
