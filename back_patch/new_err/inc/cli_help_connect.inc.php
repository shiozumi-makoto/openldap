<?php
declare(strict_types=1);

/**
 * cli_help_connect.inc.php
 *  - 共通：接続方法サンプルのヘルパ（全LDAP関連ツールで再利用）
 *  - 表示側スクリプト名（例: ldap_memberuid_users_group.php）を受け取り、
 *    その名前でサンプルコマンドを生成する
 */

/** 推奨: 実行中のプログラム名を推定（拡張子込み） */
function cli_prog_name(): string {
    // 優先: argv[0]（CLIでの呼び出し経路）
    $a0 = $_SERVER['argv'][0] ?? '';
    if ($a0 !== '') return basename($a0);

    // 予備: SCRIPT_NAME / SCRIPT_FILENAME（環境による）
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($sn !== '') return basename($sn);

    $sf = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if ($sf !== '') return basename($sf);

    // 最後の手段: この関数ファイル自身
    return basename(__FILE__);
}

/** 接続方法サンプル文字列を返す（呼び出し元で echo する） */
function cli_connect_samples(?string $prog = null): string {
    $p = $prog ?: cli_prog_name();
    // 引数の見栄え調整（.php が付いていなければ追加）
    if (!preg_match('/\.php$/i', $p)) $p .= '.php';

    return <<<TXT

接続方法サンプル:
  # ローカル ldapi ソケット接続（推奨・EXTERNAL認証）
  php {$p} --list --ldapi

  # LDAPS 接続（ホスト指定・BIND_DNとパスワードが必要）
  BIND_DN='cn=Admin,dc=e-smile,dc=ne,dc=jp' \\
  BIND_PW='********' \\
  php {$p} --list --ldaps=ovs-012.e-smile.local

  # フルURI指定（ポート番号を明示する場合）
  php {$p} --list --uri=ldaps://ovs-012.e-smile.local:636 --option=*****

  # 既存の ldapi/ldaps/uri の環境変数が強制されてしまう場合（上書きしたい）
  env -u LDAPURI -u LDAP_URI -u LDAP_URL \\
  php {$p} --uri=ldaps://ovs-012.e-smile.local:636

TXT;
}
