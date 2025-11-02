<?php
/**
 * ldap_groupmap_smb_add.php  (RID 2000-series, add/modify with SID fallback)
 * - posixGroup を走査し、固定表の RID に groupmap を揃える
 * - 既存: modify（まず sid=、失敗したら delete+add にフォールバック）
 * - 未登録: add
 * - DRY_RUN=1 で確認のみ
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

/* ===== Settings ===== */
$ldap_host        = "ldap://127.0.0.1";
$ldap_base_groups = "ou=Groups,dc=e-smile,dc=ne,dc=jp";
$ldap_user        = "cn=admin,dc=e-smile,dc=ne,dc=jp";
$ldap_pass        = "es0356525566";

$MASTER_SID = "S-1-5-21-3566765955-3362818161-2431109675";

$fixedRidMap = [
  "esmile-dev"  => 2001,
  "nicori-dev"  => 2002,
  "kindaka-dev" => 2003,
  "boj-dev"     => 2004,
  "e_game-dev"  => 2005,
  "solt-dev"    => 2006,
  "social-dev"  => 2007,
  "users"       => 2008,
];

$policyForOthers = "skip"; // or "use_gid"
$DRY_RUN = getenv('DRY_RUN') ? true : false;

/* ===== Helpers ===== */
function sh($cmd, &$out=null, &$rc=null) {
  $full = "LC_ALL=C ".$cmd." 2>&1";
  $out = []; exec($full, $out, $rc);
  return $rc === 0;
}
function join_lines($arr){ return preg_replace('/\s+/', ' ', implode(' ', (array)$arr)); }
function array_ci_get($key,$arr){ $k=strtolower($key); foreach($arr as $kk=>$v){ if(strtolower($kk)===$k) return $v; } return null; }

/* ===== 1) LocalSID check ===== */
$out=$rc=null;
if(!sh("net getlocalsid",$out,$rc)){ fwrite(STDERR,"[ERR] net getlocalsid failed\n"); exit(1); }
$joined=join_lines($out);
if(!preg_match('/(S-\d+(?:-\d+){5})/',$joined,$m)){ fwrite(STDERR,"[ERR] cannot parse localsid: $joined\n"); exit(1); }
$localSid=$m[1];
if($localSid!==$MASTER_SID){ fwrite(STDERR,"[ERR] LocalSID mismatch: $localSid != $MASTER_SID\n"); exit(1); }

/* ===== 2) LDAP ===== */
$ldap=ldap_connect($ldap_host);
if(!$ldap){ fwrite(STDERR,"[ERR] LDAP connect failed\n"); exit(1); }
ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION,3);
ldap_set_option($ldap, LDAP_OPT_REFERRALS,0);
if(!@ldap_bind($ldap,$ldap_user,$ldap_pass)){ fwrite(STDERR,"[ERR] LDAP bind failed\n"); exit(1); }
$attrs=["cn","gidNumber"];
$search=ldap_search($ldap,$ldap_base_groups,"(objectClass=posixGroup)",$attrs);
if(!$search){ fwrite(STDERR,"[ERR] LDAP search failed\n"); exit(1); }
$entries=ldap_get_entries($ldap,$search);
if($entries["count"]==0){ echo "posixGroup not found.\n"; exit(0); }

/* ===== Header ===== */
printf("%-22s %-8s %-12s %-12s %-8s %-8s\n","GROUP","gid","currentRID","wantRID","action","result");

/* ===== main ===== */
for($i=0;$i<$entries["count"];$i++){
  $cn  = $entries[$i]["cn"][0] ?? null;
  $gid = isset($entries[$i]["gidnumber"][0]) ? (int)$entries[$i]["gidnumber"][0] : null;
  if(!$cn){ continue; }

  $unixgroup = $cn;
  $wantRid = array_ci_get($cn,$fixedRidMap);
  if(!$wantRid){
    if($policyForOthers==="use_gid"){ $wantRid = max(2000,(int)$gid); }
    else {
      printf("%-22s %-8s %-12s %-12s %-8s %-8s\n",$cn,($gid??"-"),"-","-","skip","NOMAP");
      continue;
    }
  }

  // read current mapping
  $curRid = null; $out=$rc=null;
  if(sh("net groupmap list ntgroup=".escapeshellarg($cn),$out,$rc) && !empty($out)){
    $line = join_lines($out);
    if(preg_match('/-(\d+)\)\s*->/',$line,$m2) || preg_match('/-(\d+)\)/',$line,$m2)){
      $curRid = (int)$m2[1];
    }
  }

  if($curRid===null){
    $action="add";
    $cmd = sprintf(
      'net groupmap add ntgroup=%s unixgroup=%s type=domain rid=%d -d 3',
      escapeshellarg($cn), escapeshellarg($unixgroup), $wantRid
    );
  } elseif($curRid!==$wantRid){
    $action="modify";
    // prefer sid= modification
    $cmd = sprintf(
      'net groupmap modify ntgroup=%s sid=%s-%d -d 3',
      escapeshellarg($cn), $MASTER_SID, $wantRid
    );
  } else {
    printf("%-22s %-8d %-12d %-12d %-8s %-8s\n",$cn,$gid,$curRid,$wantRid,"noop","OK");
    continue;
  }

  if($DRY_RUN){
    printf("%-22s %-8d %-12s %-12d %-8s %-8s\n",$cn,$gid,($curRid??"-"),$wantRid,$action,"DRYRUN");
    continue;
  }

  $out=$rc=null; sh($cmd,$out,$rc); $res = ($rc===0)?"OK":"FAIL";

  // Fallback: if modify failed, do delete+add
  if($res==="FAIL" && $action==="modify"){
    $delCmd = sprintf('net groupmap delete ntgroup=%s', escapeshellarg($cn));
    $addCmd = sprintf('net groupmap add ntgroup=%s unixgroup=%s type=domain rid=%d -d 3',
                      escapeshellarg($cn), escapeshellarg($unixgroup), $wantRid);
    $out2=$rc2=null; sh($delCmd,$out2,$rc2); // ignore rc2
    $out3=$rc3=null; sh($addCmd,$out3,$rc3);
    $res = ($rc3===0)?"OK":"FAIL";
    $out = array_merge($out,["--- FALLBACK delete+add ---"],$out2,$out3);
  }

  printf("%-22s %-8d %-12s %-12d %-8s %-8s\n",$cn,$gid,($curRid??"-"),$wantRid,$action,$res);

  file_put_contents('/var/logs_share/ldap_groupmap_smb_add.log',
    date('c')." CMD: $cmd\n".implode("\n",$out)."\nRC=$rc\n\n", FILE_APPEND);
}

ldap_unbind($ldap);
echo ($DRY_RUN?"[DRY-RUN] ":"")."完了\n";
