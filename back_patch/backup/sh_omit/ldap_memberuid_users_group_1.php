#!/usr/bin/env php
<?php
ini_set('display_errors','1');
error_reporting(E_ALL);

$ALLOWED = ['ovs-010','ovs-012'];
$host = gethostname() ?: php_uname('n');
$short = strtolower(preg_replace('/\..*$/','',$host));
if (!in_array($short,$ALLOWED,true)) {
  fwrite(STDERR,"[ERROR] allowed only on ovs-010 / ovs-012 (current: {$host})\n");
  exit(1);
}

$opt = getopt('',[
  'ldap-url::','base-dn::','bind-dn::','bind-pass::',
  'group-dn::','group-cn::','groups-base-dn::',
  'filter::','log::','confirm'
]);

$LDAP_URL  = $opt['ldap-url']  ?? 'ldap://127.0.0.1';
$BASE_DN   = $opt['base-dn']   ?? 'ou=Users,dc=e-smile,dc=ne,dc=jp';
$BIND_DN   = $opt['bind-dn']   ?? '';
$BIND_PASS = $opt['bind-pass'] ?? '';
$FILTER    = $opt['filter']    ?? '(uid=*)';
$LOGFILE   = $opt['log']       ?? null;
$CONFIRM   = isset($opt['confirm']);

$GROUP_DN = $opt['group-dn'] ?? null;
if (!$GROUP_DN) {
  $GROUP_CN   = $opt['group-cn']      ?? 'users';
  $GROUPS_BASE= $opt['groups-base-dn']?? 'ou=Groups,dc=e-smile,dc=ne,dc=jp';
  $GROUP_DN   = "cn={$GROUP_CN},{$GROUPS_BASE}";
}

function logx($f,$m){ echo $m.PHP_EOL; if($f) @file_put_contents($f,'['.date('Y-m-d H:i:s')."] $m\n",FILE_APPEND); }
function ldap_conn_bind($url,$dn,$pw){
  $c=@ldap_connect($url);
  if(!$c) return [null,'connect failed'];
  ldap_set_option($c, LDAP_OPT_PROTOCOL_VERSION,3);
  ldap_set_option($c, LDAP_OPT_REFERRALS,0);
  if($dn!==''){ if(!@ldap_bind($c,$dn,$pw)) { $e=ldap_error($c); @ldap_unbind($c); return [null,"bind failed: $e"]; } }
  else { if(!@ldap_bind($c)) { $e=ldap_error($c); @ldap_unbind($c); return [null,"anonymous bind failed: $e"]; } }
  return [$c,null];
}
if(!function_exists('ldap_escape')){
  function ldap_escape($s,$i='',$f=0){ $find=['*','(',')','\\',"\x00"]; $repl=array_map(fn($c)=>'\\'.str_pad(dechex(ord($c)),2,'0',STR_PAD_LEFT),$find); return str_replace($find,$repl,$s); }
}

logx($LOGFILE,"=== users_group START ===");
[$conn,$err]=ldap_conn_bind($LDAP_URL,$BIND_DN,$BIND_PASS);
if(!$conn){ logx($LOGFILE,"[ERROR] LDAP: $err"); exit(1); }

$sr=@ldap_read($conn,$GROUP_DN,'(objectClass=posixGroup)',['memberUid','cn']);
if(!$sr){ logx($LOGFILE,"[ERROR] group read failed: ".ldap_error($conn)); ldap_unbind($conn); exit(1); }
$ge=@ldap_get_entries($conn,$sr);
if(($ge['count']??0)<1){ logx($LOGFILE,"[ERROR] group not found: {$GROUP_DN}"); ldap_unbind($conn); exit(1); }
$cn=$ge[0]['cn'][0]??'(unknown)';
$memberSet=[];
if(isset($ge[0]['memberuid'])) { for($i=0;$i<$ge[0]['memberuid']['count'];$i++){ $memberSet[$ge[0]['memberuid'][$i]]=true; } }

$sr=@ldap_search($conn,$BASE_DN,$FILTER,['uid']);
if(!$sr){ logx($LOGFILE,"[ERROR] search users failed: ".ldap_error($conn)); ldap_unbind($conn); exit(1); }
$es=@ldap_get_entries($conn,$sr);
$addList=[]; $keep=0;
for($i=0;$i<($es['count']??0);$i++){
  $uid=$es[$i]['uid'][0]??null; if(!$uid) continue;
  if(isset($memberSet[$uid])) { $keep++; continue; }
  $addList[]=$uid;
}

$ok=0; $errc=0;
foreach($addList as $uid){
  $mods=['memberUid'=>[$uid]];
  if(!$CONFIRM){ logx($LOGFILE,"[DRY][ADD] {$uid} -> {$GROUP_DN}"); continue; }
  if(!@ldap_mod_add($conn,$GROUP_DN,$mods)){
    $le=ldap_error($conn);
    if(!@ldap_mod_replace($conn,$GROUP_DN,$mods)){
      $errc++; logx($LOGFILE,"[ERR ] memberUid add failed uid={$uid}: {$le} / ".ldap_error($conn));
    } else { $ok++; logx($LOGFILE,"[UPD ] memberUid ensure uid={$uid}"); }
  } else { $ok++; logx($LOGFILE,"[ADD ] memberUid uid={$uid}"); }
}

logx($LOGFILE,"SUMMARY: group={$cn} keep={$keep} addTargets=".count($addList)." ok={$ok} err={$errc}");
ldap_unbind($conn);
logx($LOGFILE,"=== users_group DONE ===");
