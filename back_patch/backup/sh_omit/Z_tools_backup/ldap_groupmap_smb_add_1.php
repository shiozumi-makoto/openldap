#!/usr/bin/env php
<?php
ini_set('display_errors','1');
error_reporting(E_ALL);

$ALLOWED=['ovs-010','ovs-012'];
$host=gethostname() ?: php_uname('n');
$short=strtolower(preg_replace('/\..*$/','',$host));
if(!in_array($short,$ALLOWED,true)){ fwrite(STDERR,"[ERROR] allowed only on ovs-010 / ovs-012 (current: {$host})\n"); exit(1); }

$HAVE_NET = (bool)shell_exec('command -v net 2>/dev/null');

$opt=getopt('',[
  'ldap-url::','groups-base-dn::','bind-dn::','bind-pass::',
  'filter::','log::','confirm'
]);

$LDAP_URL  = $opt['ldap-url']      ?? 'ldap://127.0.0.1';
$GROUPS_DN = $opt['groups-base-dn']?? 'ou=Groups,dc=e-smile,dc=ne,dc=jp';
$BIND_DN   = $opt['bind-dn']       ?? '';
$BIND_PASS = $opt['bind-pass']     ?? '';
$FILTER    = $opt['filter']        ?? '(objectClass=posixGroup)';
$LOGFILE   = $opt['log']           ?? null;
$CONFIRM   = isset($opt['confirm']);

function logx($f,$m){ echo $m.PHP_EOL; if($f) @file_put_contents($f,'['.date('Y-m-d H:i:s')."] $m\n",FILE_APPEND); }
function ldap_conn_bind($url,$dn,$pw){
  $c=@ldap_connect($url);
  if(!$c) return [null,'connect failed'];
  ldap_set_option($c, LDAP_OPT_PROTOCOL_VERSION,3);
  ldap_set_option($c, LDAP_OPT_REFERRALS,0);
  if($dn!==''){ if(!@ldap_bind($c,$dn,$pw)){ $e=ldap_error($c); @ldap_unbind($c); return [null,"bind failed: $e"]; } }
  else { if(!@ldap_bind($c)){ $e=ldap_error($c); @ldap_unbind($c); return [null,"anonymous bind failed: $e"]; } }
  return [$c,null];
}

logx($LOGFILE,"=== groupmap START ===");
logx($LOGFILE,"HOST={$host} GROUPS_DN={$GROUPS_DN} CONFIRM=".($CONFIRM?'YES':'NO')." HAVE_NET=".($HAVE_NET?'YES':'NO'));
if(!$HAVE_NET){ logx($LOGFILE,"[SKIP] 'net' command not found; skipping groupmap."); exit(0); }

[$conn,$err]=ldap_conn_bind($LDAP_URL,$BIND_DN,$BIND_PASS);
if(!$conn){ logx($LOGFILE,"[ERROR] LDAP: $err"); exit(1); }

$gmOutput = shell_exec('net groupmap list 2>/dev/null') ?? '';
$mappedSet = [];
foreach (explode("\n",$gmOutput) as $line){
  if (preg_match('/^\s*([^()]+)\s*\(.*\)\s*->\s*(.+)\s*$/',$line,$m)) {
    $mappedSet[trim($m[1])] = true;
    $mappedSet[trim($m[2])] = true;
  }
}

$sr=@ldap_search($conn,$GROUPS_DN,$FILTER,['cn']);
if(!$sr){ logx($LOGFILE,"[ERROR] search groups failed: ".ldap_error($conn)); ldap_unbind($conn); exit(1); }
$gs=@ldap_get_entries($conn,$sr);

$plan=[]; $skip=0; $ok=0; $errc=0;
for($i=0;$i<($gs['count']??0);$i++){
  $cn=$gs[$i]['cn'][0]??null; if(!$cn) continue;
  if(isset($mappedSet[$cn])) { $skip++; logx($LOGFILE,"[KEEP] mapped: {$cn}"); continue; }
  $plan[]=$cn;
}

foreach($plan as $cn){
  $cmd = sprintf('net groupmap add ntgroup=%s unixgroup=%s type=domain', escapeshellarg($cn), escapeshellarg($cn));
  if(!$CONFIRM){ logx($LOGFILE,"[DRY][MAP] $cmd"); continue; }
  exec($cmd.' 2>&1', $out, $rc);
  if($rc!==0){ $errc++; logx($LOGFILE,"[ERR ] map failed cn={$cn}: ".implode('; ',$out)); }
  else { $ok++; logx($LOGFILE,"[MAP ] cn={$cn} mapped"); }
}

logx($LOGFILE,"SUMMARY: keep(mapped)={$skip} plan=".count($plan)." ok={$ok} err={$errc}");
ldap_unbind($conn);
logx($LOGFILE,"=== groupmap DONE ===");
