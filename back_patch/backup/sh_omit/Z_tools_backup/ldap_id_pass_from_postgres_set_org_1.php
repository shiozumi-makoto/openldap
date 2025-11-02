#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ldap_id_pass_from_postgres_set.php (kakasi外部ツール対応 & /home/%02d-%03d-%s)
 *
 * - DB: public.passwd_tnas + public."情報個人"
 * - UID: 「姓かな」「名かな」（+ミドルネームがあればそれも）を kakasi でローマ字化して "last-first" 形式
 * - HOME: /home/%02d-%03d-%s （CC-XXX-uid）
 * - samba_id: 生成UIDで毎回上書き
 * - LDAP: 既存→属性置換、無ければ追加（userPassword はロック {CRYPT}!）
 *
 * 実行例:
 *   export PGHOST=127.0.0.1 PGPORT=5432 PGDATABASE=accounting PGUSER=postgres
 *   VERBOSE=1 DRY_RUN=1 \
 *   LDAP_URI="ldap://127.0.0.1" BASE_DN="dc=e-smile,dc=ne,dc=jp" \
 *   BIND_DN="cn=Admin,dc=e-smile,dc=ne,dc=jp" BIND_PW="********" \
 *   DOM_SID_PREFIX="S-1-5-21-XXXXXXXXXX" \
 *   php /usr/local/etc/openldap/tools/ldap_id_pass_from_postgres_set.php --cmp-user 12-168
 */

final class Cfg {
    // LDAP
    public string $ldapUri;
    public string $baseDn;
    public string $peopleOu;
    public string $bindDn;
    public string $bindPw;
    public string $domSidPrefix;

    // DB
    public string $pgDsn;
    public string $pgUser;
    public ?string $pgPass;

    // Tables
    public string $tnasTable;
    public string $jinfoTable;

    // Behavior
    public bool $dryRun;
    public bool $verbose;

    // Home dir / uidNumber strategy
    public string $homeFmt;  // sprintf('/home/%02d-%03d-%s', cmp, user, uid)
    public int $uidBase;
    public int $uidStride;
    public int $defaultGid;

    public static function load(): self {
        $c = new self();
        // LDAP
        $c->ldapUri = getenv('LDAP_URI') ?: 'ldap://127.0.0.1';
        $c->baseDn  = getenv('BASE_DN')  ?: 'dc=e-smile,dc=ne,dc=jp';
        $c->peopleOu= getenv('PEOPLE_OU') ?: ('ou=Users,' . $c->baseDn);
        $c->bindDn  = getenv('BIND_DN')  ?: ('cn=Admin,' . $c->baseDn);
        $c->bindPw  = getenv('BIND_PW')  ?: '';
        $c->domSidPrefix = getenv('DOM_SID_PREFIX') ?: '';
        if ($c->bindPw === '' || $c->domSidPrefix === '') {
            throw new RuntimeException("BIND_PW と DOM_SID_PREFIX は必須です。");
        }

        // DB（env/.pgpass 対応）
        $explicitDsn = getenv('PG_DSN') ?: '';
        $pgHost = getenv('PGHOST')     ?: '127.0.0.1';
        $pgPort = getenv('PGPORT')     ?: '5432';
        $pgDb   = getenv('PGDATABASE') ?: 'accounting';
        $c->pgUser = getenv('PGUSER')  ?: 'postgres';
        $pgPass = getenv('PGPASSWORD');
        $c->pgPass = ($pgPass === false || $pgPass === '') ? null : $pgPass;
        $c->pgDsn  = $explicitDsn !== '' ? $explicitDsn : "pgsql:host={$pgHost};port={$pgPort};dbname={$pgDb}";
        if ($c->pgUser === '') throw new RuntimeException("PGUSER が未設定です。");

        // Tables
        $c->tnasTable = getenv('TNAS_TABLE') ?: 'public.passwd_tnas';
        $c->jinfoTable= getenv('JINFO_TABLE') ?: 'public."情報個人"';

        // Behavior
        $c->dryRun  = (getenv('DRY_RUN') ?: '0') === '1';
        $c->verbose= (getenv('VERBOSE') ?: '1') === '1';

        // Home dir / uidNumber
        $c->homeFmt    = getenv('HOME_DIR_FORMAT') ?: '/home/%02d-%03d-%s';
        $c->uidBase    = (int)(getenv('UID_BASE') ?: '20000');
        $c->uidStride  = (int)(getenv('UID_STRIDE') ?: '1000');
        $c->defaultGid = (int)(getenv('DEFAULT_GID') ?: '100');
        return $c;
    }
}

final class Log {
    public static bool $verbose = true;
    public static function info(string $m): void { if (self::$verbose) fwrite(STDOUT, "[*] $m\n"); }
    public static function warn(string $m): void { fwrite(STDERR, "[WARN] $m\n"); }
    public static function err (string $m): void { fwrite(STDERR, "[ERROR] $m\n"); }
}

/** ===== kakasi: かな → ローマ字（英小文字、空白と'除去）===== */
function kanaToRomaji(string $kana): string {
    $kanaArg = escapeshellarg($kana);
    $cmd = "echo $kanaArg | iconv -f UTF-8 -t EUC-JP | kakasi -Ja -Ha -Ka -Ea -s -r | iconv -f EUC-JP -t UTF-8";
    $romaji = shell_exec($cmd) ?? '';
    $romaji = strtolower(str_replace([" ", "'"], '', trim($romaji)));
    return $romaji;
}
/** UID 生成: 姓かな + （ミドルネームがあれば-ミドル） + -名かな → kakasi → last-first 形式 */
function makeUidFromKana(string $seiKana, string $meiKana, string $middle = ''): string {
    $last  = kanaToRomaji($seiKana !== '' ? $seiKana : 'sei');
    $first = kanaToRomaji($meiKana !== '' ? $meiKana : 'mei');
    $mid   = $middle !== '' ? kanaToRomaji($middle) : '';
    $uid   = $mid !== '' ? "{$last}-{$mid}-{$first}" : "{$last}-{$first}";
    // 連続ハイフンや非英数安全化
    $uid = preg_replace('/[^a-z0-9\-]/', '-', $uid) ?? $uid;
    $uid = preg_replace('/-+/', '-', $uid) ?? $uid;
    return trim($uid, '-') ?: 'user';
}

// LDAP
final class Ldap {
    private $conn;
    private Cfg $cfg;
    public function __construct(Cfg $cfg) {
        $this->cfg = $cfg;
        $this->conn = ldap_connect($cfg->ldapUri);
        if (!$this->conn) throw new RuntimeException("LDAP接続失敗: {$cfg->ldapUri}");
        ldap_set_option($this->conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        if (!@ldap_bind($this->conn, $cfg->bindDn, $cfg->bindPw)) {
            throw new RuntimeException("LDAP bind失敗: {$cfg->bindDn}");
        }
    }
    public function __destruct() { if ($this->conn) @ldap_unbind($this->conn); }
    public function dn(string $uid): string { return "uid={$uid}," . $this->cfg->peopleOu; }
    public function exists(string $uid): bool {
        $sr = @ldap_search($this->conn, $this->cfg->peopleOu, "(uid={$uid})", ['dn']);
        if (!$sr) return false;
        $e = ldap_get_entries($this->conn, $sr);
        return ($e['count'] ?? 0) > 0;
    }
    public function fetchUidGid(string $uid): array {
        $sr = @ldap_search($this->conn, $this->cfg->peopleOu, "(uid={$uid})", ['uidNumber','gidNumber']);
        if (!$sr) return [null, null];
        $e = ldap_get_entries($this->conn, $sr);
        if (($e['count'] ?? 0) < 1) return [null, null];
        $u = $e[0];
        $uidNum = isset($u['uidnumber'][0]) ? (int)$u['uidnumber'][0] : null;
        $gidNum = isset($u['gidnumber'][0]) ? (int)$u['gidnumber'][0] : null;
        return [$uidNum, $gidNum];
    }
    public function add(array $entry): void {
        if ($this->cfg->dryRun) { Log::info("DRY-RUN: ldap_add ".json_encode($entry, JSON_UNESCAPED_UNICODE)); return; }
        $dn = $entry['dn']; unset($entry['dn']);
        if (!@ldap_add($this->conn, $dn, $entry)) throw new RuntimeException("ldap_add失敗: " . ldap_error($this->conn));
    }
    public function replace(string $dn, array $attrs): void {
        if ($this->cfg->dryRun) { Log::info("DRY-RUN: ldap_modify_replace($dn) ".json_encode($attrs, JSON_UNESCAPED_UNICODE)); return; }
        if (!@ldap_modify($this->conn, $dn, $attrs)) throw new RuntimeException("ldap_modify失敗: " . ldap_error($this->conn));
    }
}

function usage(): void {
    fwrite(STDOUT, "Usage: php ldap_id_pass_from_postgres_set.php --cmp-user CC-XXX [--lock]\n");
}

$cmpUser = null; $lockOnly = true; // 既定ロック
for ($i=1; $i<$argc; $i++) {
    $a = $argv[$i];
    if ($a === '--cmp-user' && $i+1 < $argc) $cmpUser = $argv[++$i];
    elseif ($a === '--lock') $lockOnly = true;
    elseif ($a === '-h' || $a === '--help') { usage(); exit(0); }
}

try {
    $cfg = Cfg::load(); Log::$verbose = $cfg->verbose;
    if ($cmpUser === null || !preg_match('/^(\d+)-(\d+)$/', $cmpUser, $m)) {
        throw new RuntimeException("--cmp-user は CC-XXX 形式で指定してください。例: 12-168");
    }
    $cmpId  = (int)$m[1];
    $userId = (int)$m[2];

    // DB
    $pdo = new PDO($cfg->pgDsn, $cfg->pgUser, $cfg->pgPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // JOIN: passwd_tnas + 情報個人
    $sql = <<<SQL
SELECT
  t.cmp_id, t.user_id, t.level_id, t.login_id, t.passwd_id, t.samba_id,
  j."姓" AS sei, j."名" AS mei, COALESCE(j."ミドルネーム",'') AS middle,
  COALESCE(j."姓かな",'') AS sei_k, COALESCE(j."名かな",'') AS mei_k,
  COALESCE(j."表示名",'') AS disp,
  COALESCE(j."電子メールアドレス",'') AS email
FROM {$cfg->tnasTable} t
JOIN {$cfg->jinfoTable} j
  ON j.cmp_id = t.cmp_id AND j.user_id = t.user_id
WHERE t.cmp_id = :cmp AND t.user_id = :usr
LIMIT 1
SQL;
    $st = $pdo->prepare($sql);
    $st->execute([':cmp'=>$cmpId, ':usr'=>$userId]);
    $row = $st->fetch();
    if (!$row) throw new RuntimeException("対象ユーザーが見つかりません: {$cmpUser}");

    $seiK   = (string)$row['sei_k'];
    $meiK   = (string)$row['mei_k'];
    $middle = (string)$row['middle'];  // ミドルはそのまま（かな列が無い想定）

    // --- 1) kakasiでUIDを毎回生成 ---
    $uid = makeUidFromKana($seiK, $meiK, $middle);
    Log::info("生成UID: {$uid}");

    // --- 2) samba_id を毎回上書き ---
    if ($cfg->dryRun) {
        Log::info("DRY-RUN: UPDATE {$cfg->tnasTable} SET samba_id='{$uid}' WHERE (cmp_id,user_id)=({$cmpId},{$userId})");
    } else {
        $up = $pdo->prepare("UPDATE {$cfg->tnasTable} SET samba_id = :uid WHERE cmp_id=:c AND user_id=:u");
        $up->execute([':uid'=>$uid, ':c'=>$cmpId, ':u'=>$userId]);
    }

    // --- 3) 表示名/メールなど ---
    $disp  = (string)$row['disp'];
    if ($disp === '') $disp = $row['sei'] . ' ' . $row['mei'];
    $email = (string)$row['email'];
    $cn    = $disp;
    $sn    = $row['sei'] !== '' ? (string)$row['sei'] : $uid;

    // --- 4) HOME: /home/CC-XXX-uid ---
    $home = sprintf($cfg->homeFmt, $cmpId, $userId, $uid);

    // --- 5) uidNumber / gidNumber ---
    $ldap = new Ldap($cfg);
    $dn   = $ldap->dn($uid);
    $exists = $ldap->exists($uid);
    [$uidNumber, $gidNumber] = $exists ? $ldap->fetchUidGid($uid) : [null, null];
    if ($uidNumber === null) $uidNumber = $cfg->uidBase + $cmpId * $cfg->uidStride + $userId;
    if ($gidNumber === null) $gidNumber = $cfg->defaultGid;

    // --- 6) LDAP 反映（既定はロックPW） ---
    $attrs = [
        'uid'           => $uid,
        'cn'            => $cn,
        'sn'            => $sn,
        'displayName'   => $cn,
        'uidNumber'     => (string)$uidNumber,
        'gidNumber'     => (string)$gidNumber,
        'homeDirectory' => $home,
        'loginShell'    => '/bin/bash',
        'sambaSID'      => $cfg->domSidPrefix . '-' . $uidNumber,
        'userPassword'  => '{CRYPT}!',
    ];
    if ($email !== '') $attrs['mail'] = $email;

    if ($exists) {
        Log::info("LDAP: 既存 → 属性置換 dn={$dn}");
        $ldap->replace($dn, $attrs);
    } else {
        Log::info("LDAP: 新規追加 dn={$dn}");
        $entry = $attrs + [
            'dn'          => $dn,
            'objectClass' => ['top','inetOrgPerson','posixAccount','shadowAccount','sambaSamAccount'],
        ];
        $ldap->add($entry);
    }

    Log::info(($cfg->dryRun ? "DRY-RUN: " : "") . "完了: cmp-user={$cmpUser} uid={$uid} home={$home}");
    exit(0);

} catch (Throwable $e) {
    Log::err($e->getMessage());
    exit(1);
}


