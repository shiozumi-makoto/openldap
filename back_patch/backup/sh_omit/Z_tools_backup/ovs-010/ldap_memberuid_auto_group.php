#!/usr/bin/env php
<?php
ini_set('display_errors','1');
error_reporting(E_ALL);

$ALLOWED=['ovs-010','ovs-012'];
$host=gethostname() ?: php_uname('n');
$short=strtolower(preg_replace('/\..*$/','',$host));
if(!in_array($short,$ALLOWED,true)){ fwrite(STDERR,"[ERROR] allowed only on ovs-010 / ovs-012 (current: {$host})\n"); exit(1); }

$opt=getopt('',[
  'ldap-url::','users-base-dn::','groups-base-dn::','bind-dn::','bind-pass::',
  'user-filter::','log::','confirm'
]);

$LDAP_URL  = $opt['ldap-url']      ?? 'ldap://127.0.0.1';
$USERS_DN  = $opt['users-base-dn'] ?? 'ou=Users,dc=e-smile,dc=ne,dc=jp';
$GROUPS_DN = $opt['groups-base-dn']?? 'ou=Groups,dc=e-smile,dc=ne,dc=jp';
$BIND_DN   = $opt['bind-dn']       ?? '';
$BIND_PASS = $opt['bind-pass']     ?? '';
$FILTER    = $opt['user-filter']   ?? '(&(objectClass=posixAccount)(uid=*))';
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

logx($LOGFILE,"=== auto_group START ===");

[$conn,$err]=ldap_conn_bind($LDAP_URL,$BIND_DN,$BIND_PASS);
if(!$conn){ logx($LOGFILE,"[ERROR] LDAP: $err"); exit(1); }

$sr=@ldap_search($conn,$GROUPS_DN,'(objectClass=posixGroup)',['gidNumber','cn','memberUid']);
if(!$sr){ logx($LOGFILE,"[ERROR] search groups failed: ".ldap_error($conn)); ldap_unbind($conn); exit(1); }
$gs=@ldap_get_entries($conn,$sr);
$gByGid=[];
for($i=0;$i<($gs['count']??0);$i++){
  $dn=$gs[$i]['dn']??null; if(!$dn) continue;
  $gid=isset($gs[$i]['gidnumber'][0]) ? (int)$gs[$i]['gidnumber'][0] : null; if($gid===null) continue;
  $cn =$gs[$i]['cn'][0]??'(unknown)';
  $memSet=[];
  if(isset($gs[$i]['memberuid'])){
    for($k=0;$k<$gs[$i]['memberuid']['count'];$k++){ $memSet[$gs[$i]['memberuid'][$k]]=true; }
  }
  $gByGid[$gid]=['dn'=>$dn,'cn'=>$cn,'members'=>$memSet];
}

$sr=@ldap_search($conn,$USERS_DN,$FILTER,['uid','gidNumber']);
if(!$sr){ logx($LOGFILE,"[ERROR] search users failed: ".ldap_error($conn)); ldap_unbind($conn); exit(1); }
$us=@ldap_get_entries($conn,$sr);

$add=0; $keep=0; $miss=0; $ok=0; $errc=0;
for($i=0;$i<($us['count']??0);$i++){
  $uid=$us[$i]['uid'][0]??null;
  $gid=isset($us[$i]['gidnumber'][0]) ? (int)$us[$i]['gidnumber'][0] : null;
  if(!$uid || $gid===null) continue;

  if(!isset($gByGid[$gid])){ $miss++; logx($LOGFILE,"[MISS] group for gid={$gid} not found (uid={$uid})"); continue; }
  $g=&$gByGid[$gid];

  if(isset($g['members'][$uid])){ $keep++; continue; }

  $mods=['memberUid'=>[$uid]];
  if(!$CONFIRM){ $add++; logx($LOGFILE,"[DRY][ADD] uid={$uid} -> {$g['dn']} (cn={$g['cn']})"); continue; }
  if(!@ldap_mod_add($conn,$g['dn'],$mods)){
    if(!@ldap_mod_replace($conn,$g['dn'],$mods)){
      $errc++; logx($LOGFILE,"[ERR ] memberUid add failed uid={$uid}: ".ldap_error($conn));
    } else { $ok++; $g['members'][$uid]=true; logx($LOGFILE,"[UPD ] memberUid ensure uid={$uid}"); }
  } else { $ok++; $g['members'][$uid]=true; logx($LOGFILE,"[ADD ] memberUid uid={$uid} -> cn={$g['cn']}"); }
}

logx($LOGFILE,"SUMMARY: keep={$keep} add(dry+ok)=".($add+$ok)." ok={$ok} err={$errc} missingGroup={$miss}");
ldap_unbind($conn);
logx($LOGFILE,"=== auto_group DONE ===");
