<?php
/*
  2025-10-05  FDW集約→重複付番→各社+accounting反映 + 出力整形（平仮名20幅/アカウント表示）
  - invalid <= 1, cmp_id 1..12, (user_id>=100 or =1)
  - kakasi 自動検出（環境変数KAKASI/KAKASI_PATH, PATH, よくある場所）
  - 出力: cmp_id, user_id, 平仮名=（全角20幅）/ account=（姓-名+ミドル）
*/

ini_set('display_errors','On');
error_reporting(E_ALL);

/* ================== 設定 ================== */
$host   = 'localhost';
$port   = 5432;
$dbname = 'accounting';   // 接続DB（スキーマは public を使用）
$user   = 'postgres';     // .pgpass / PGPASSWORD で認証

$db_sql_table = [
  1=>"esmile_user",  2=>"rgb_user",     3=>"kindaka_user",
  4=>"moepara_user", 5=>"denno_user",   6=>"est_user",
  7=>"earth_user",   8=>"systez_user",  9=>"e_game_user",
  10=>"strive_user", 11=>"pfjobs_user", 12=>"social_user",
];

/* ================== kakasi 検出/変換 ================== */
function find_kakasi_path(): ?string {
  static $cached = null;
  if ($cached !== null) return $cached;

  $candidates = array_filter([
    getenv('KAKASI'),
    getenv('KAKASI_PATH'),
  ]);

  foreach (['command -v kakasi 2>/dev/null', 'which kakasi 2>/dev/null'] as $cmd) {
    $p = trim((string)@shell_exec($cmd));
    if ($p !== '') $candidates[] = $p;
  }

  $candidates = array_merge($candidates, [
    '/usr/local/bin/kakasi',
    '/usr/bin/kakasi',
    '/opt/homebrew/bin/kakasi',
    '/opt/local/bin/kakasi',
  ]);

  foreach ($candidates as $path) {
    if ($path && @is_executable($path)) {
      $cached = $path;
      return $cached;
    }
  }
  return $cached = null;
}

function kana_to_romaji(string $s): string {
  $kakasi = find_kakasi_path();
  if ($kakasi) {
    $descriptorspec = [
      0 => ["pipe", "r"],
      1 => ["pipe", "w"],
      2 => ["pipe", "w"],
    ];
    $cmd = escapeshellcmd($kakasi) . " -iutf-8 -outf8 -Ha -Ka -Ja -Ea -rhepburn -s";
    $proc = @proc_open($cmd, $descriptorspec, $pipes);
    if (is_resource($proc)) {
      fwrite($pipes[0], $s);
      fclose($pipes[0]);
      $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
      // $err = stream_get_contents($pipes[2]); fclose($pipes[2]); // 必要ならログ
      proc_close($proc);
      $out = strtolower($out);
      $out = preg_replace('/\s+/', '', $out);
      $out = preg_replace('/[^a-z0-9\-]/', '', $out);
      $out = preg_replace('/-+/', '-', $out);
      $out = trim($out, '-');
      if ($out !== '') return $out;
    }
  }
  return $s;
}

/* 平仮名の列を全角スペースで右パディング（幅=全角換算） */
function jp_pad(string $s, int $width = 20): string {
  if (mb_strwidth($s, 'UTF-8') > $width) {
    $s = mb_strimwidth($s, 0, $width, '', 'UTF-8');
  }
  $pad = $width - mb_strwidth($s, 'UTF-8');
  return $s . str_repeat('　', max(0, $pad));
}

/* ================== SQL組み立て ================== */
$fdwSql = [];

/* 拡張 */
$fdwSql[] = 'CREATE EXTENSION IF NOT EXISTS postgres_fdw;';

/* accounting 側 public."情報個人"（UPSERT先） */
$fdwSql[] = <<<SQL
CREATE TABLE IF NOT EXISTS public."情報個人" (
  cmp_id integer NOT NULL,
  user_id integer NOT NULL,
  "姓" character varying(20),
  "名" character varying(20),
  "姓かな" character varying(20),
  "名かな" character varying(20),
  "ミドルネーム" character varying(10),
  invalid smallint DEFAULT 0,
  "入社日" timestamp without time zone,
  CONSTRAINT "情報個人_pkey" PRIMARY KEY (cmp_id, user_id)
);
SQL;

/* FDW: SERVER / USER MAPPING / FOREIGN TABLE（互換版） */
foreach ($db_sql_table as $cmp => $db) {
  $srv = "srv_cmp{$cmp}";
  $ft  = "ft_user_{$cmp}";

  $fdwSql[] = <<<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_foreign_server WHERE srvname = '{$srv}') THEN
    EXECUTE 'CREATE SERVER {$srv}
             FOREIGN DATA WRAPPER postgres_fdw
             OPTIONS (host ''localhost'', dbname ''{$db}'', port ''{$port}'')';
  END IF;
END
\$\$;
SQL;

  $fdwSql[] = <<<SQL
DO \$\$
BEGIN
  BEGIN
    EXECUTE 'CREATE USER MAPPING FOR CURRENT_USER SERVER {$srv}
             OPTIONS (user ''{$user}'')';
  EXCEPTION
    WHEN duplicate_object THEN
      NULL;
  END;
END
\$\$;
SQL;

  $fdwSql[] = <<<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.foreign_tables
    WHERE foreign_table_schema = 'public'
      AND foreign_table_name   = '{$ft}'
  ) THEN
    EXECUTE 'CREATE FOREIGN TABLE public."{$ft}" (
               cmp_id integer NOT NULL,
               user_id integer NOT NULL,
               "姓" character varying(20),
               "名" character varying(20),
               "姓かな" character varying(20),
               "名かな" character varying(20),
               "ミドルネーム" character varying(10),
               invalid smallint,
               "入社日" timestamp without time zone
             ) SERVER {$srv}
               OPTIONS (schema_name ''public'', table_name ''情報個人'')';
  END IF;
END
\$\$;
SQL;
}

/* 集約TEMP */
$union = [];
foreach ($db_sql_table as $cmp => $db) {
  $ft = "ft_user_{$cmp}";
  $union[] = 'SELECT cmp_id, user_id, "姓", "名", "姓かな", "名かな", COALESCE("ミドルネーム", \'\') AS "ミドルネーム", invalid, "入社日" FROM public."'.$ft.'"';
}
$fdwSql[] = "DROP TABLE IF EXISTS tmp_people;";
$fdwSql[] = "CREATE TEMP TABLE tmp_people AS \n".implode("\nUNION ALL\n", $union).";";

/* 重複判定＆付番 → tmp_middle */
$fdwSql[] = <<<SQL
DROP TABLE IF EXISTS tmp_middle;
CREATE TEMP TABLE tmp_middle AS
WITH base AS (
  SELECT *
  FROM tmp_people
  WHERE cmp_id BETWEEN 1 AND 12
    AND invalid <= 1
    AND (user_id >= 100 OR user_id = 1)
    AND COALESCE("姓かな",'') <> ''
    AND COALESCE("名かな",'') <> ''
),
dups AS (
  SELECT "姓かな","名かな"
  FROM base
  GROUP BY "姓かな","名かな"
  HAVING COUNT(*) >= 2
),
numbered AS (
  SELECT
    b.cmp_id, b.user_id, b."姓かな", b."名かな",
    ROW_NUMBER() OVER (
      PARTITION BY b."姓かな", b."名かな"
      ORDER BY b.cmp_id, b.user_id
    ) AS rn
  FROM base b
  JOIN dups d
    ON b."姓かな"=d."姓かな" AND b."名かな"=d."名かな"
)
SELECT
  cmp_id,
  user_id,
  CASE WHEN rn=1 THEN '' ELSE rn::text END AS new_middle
FROM numbered;
SQL;

/* 各社に反映 */
foreach ($db_sql_table as $cmp => $db) {
  $ft = "ft_user_{$cmp}";
  $fdwSql[] = <<<SQL
UPDATE public."{$ft}" t
SET "ミドルネーム" = m.new_middle
FROM tmp_middle m
WHERE t.cmp_id = m.cmp_id
  AND t.user_id = m.user_id
  AND t.cmp_id = {$cmp};
SQL;
}

/* accounting(public) に反映（UPSERT→上書き） */
$fdwSql[] = <<<SQL
INSERT INTO public."情報個人" AS a
  (cmp_id, user_id, "姓", "名", "姓かな", "名かな", "ミドルネーム", invalid, "入社日")
SELECT
  p.cmp_id, p.user_id, p."姓", p."名", p."姓かな", p."名かな",
  COALESCE(m.new_middle, p."ミドルネーム") AS "ミドルネーム",
  p.invalid, p."入社日"
FROM tmp_people p
LEFT JOIN tmp_middle m
  ON p.cmp_id=m.cmp_id AND p.user_id=m.user_id
WHERE p.cmp_id BETWEEN 1 AND 12
ON CONFLICT (cmp_id, user_id) DO UPDATE SET
  "姓" = EXCLUDED."姓",
  "名" = EXCLUDED."名",
  "姓かな" = EXCLUDED."姓かな",
  "名かな" = EXCLUDED."名かな",
  "ミドルネーム" = EXCLUDED."ミドルネーム",
  invalid = EXCLUDED.invalid,
  "入社日" = EXCLUDED."入社日";

UPDATE public."情報個人" a
SET "ミドルネーム" = m.new_middle
FROM tmp_middle m
WHERE a.cmp_id = m.cmp_id
  AND a.user_id = m.user_id;
SQL;

/* 出力: cmp_id|user_id|姓かな|名かな|new_middle */
$fdwSql[] = <<<SQL
SELECT m.cmp_id, m.user_id, p."姓かな", p."名かな", m.new_middle
FROM tmp_middle m
JOIN tmp_people p
  ON p.cmp_id = m.cmp_id AND p.user_id = m.user_id
ORDER BY m.cmp_id, m.user_id;
SQL;

/* ================== 実行 ================== */
$sql = "BEGIN;\n".implode("\n", $fdwSql)."\nCOMMIT;";

$tmp = tempnam(sys_get_temp_dir(), 'fdw_sql_');
file_put_contents($tmp, $sql);
$cmd = sprintf(
  "psql -v ON_ERROR_STOP=1 -A -t -F '|' -h %s -p %d -d %s -U %s -f %s",
  escapeshellarg($host),
  $port,
  escapeshellarg($dbname),
  escapeshellarg($user),
  escapeshellarg($tmp)
);
echo "[RUN] {$cmd}\n\n";
exec($cmd, $out, $ret);
@unlink($tmp);

if ($ret !== 0) {
  echo "FDW集約～反映でエラー（ret={$ret})\n";
  if (!empty($out)) echo implode("\n", $out), "\n";
  exit(1);
}


/* 6全角固定の右パディング（全角スペース U+3000） */
function jp_padN(string $s, int $width): string {
  if (mb_strwidth($s, 'UTF-8') > $width) {
    $s = mb_strimwidth($s, 0, $width, '', 'UTF-8');
  }
  $pad = $width - mb_strwidth($s, 'UTF-8');
  return $s . str_repeat('　', max(0, $pad));
}

/* 任意の全角幅に右パディング */
function jp_pad_width(string $s, int $width): string {
  $w = mb_strwidth($s, 'UTF-8');
  if ($w > $width) {
    $s = mb_strimwidth($s, 0, $width, '', 'UTF-8');
    $w = $width;
  }
  return $s . str_repeat('　', max(0, $width - $w));
}

/* 全角“文字数”で6文字固定（超過は切り、足りなければ全角空白で右パディング） */
function jp_pad_chars(string $s, int $chars = 6): string {
  $len = mb_strlen($s, 'UTF-8');
  if ($len > $chars) {
    $s = mb_substr($s, 0, $chars, 'UTF-8');
  }
  $pad = $chars - mb_strlen($s, 'UTF-8');
  return $s . str_repeat('　', max(0, $pad));  // 全角スペース U+3000
}


echo "=== 付番結果（重複該当のみ） ===\n";

foreach ($out as $line) {
  $line = trim($line);
  if ($line === '' || strpos($line, '|') === false) continue;

  $fields = array_map('trim', explode('|', $line));
  if (count($fields) < 5) continue;

  [$cmp_id, $user_id, $sei_kana, $mei_kana, $new_middle] = $fields;

  // 姓・名とも“文字数で”6文字固定、区切りは全角スペース1つ
  $sei = jp_pad_chars($sei_kana, 6);
  $mei = jp_pad_chars($mei_kana, 6);
  $kana_block = $sei . '　' . $mei;

  // ローマ字アカウント
  $sei_romaji = kana_to_romaji($sei_kana);
  $mei_romaji = kana_to_romaji($mei_kana);
  $account    = $sei_romaji . '-' . $mei_romaji . ($new_middle !== '' ? $new_middle : '');

  printf(
    "cmp_id = %2d user_id = %4d  ひらがな =　%s / ldap samba [ account ] = 	%s\n",
    (int)$cmp_id, (int)$user_id, $kana_block, $account
  );
}

echo "\n=== 完了 ===\n";


