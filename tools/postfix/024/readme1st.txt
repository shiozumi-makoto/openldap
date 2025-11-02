
	mail2.esmile-soltribe.jp	221.241.134.211

	mail.esmile-soltribe.jp
	mail2.esmile-soltribe.jp


TXT	esmile-soltribe.jp（初期レコード）			600	"v=spf1 ip4:221.241.134.212 include:_spf.onamae.ne.jp ~all"
A	mail.esmile-soltribe.jp（初期レコード）		600	221.241.134.212
A	ml-cp.esmile-soltribe.jp（初期レコード）	600	157.120.209.14（不要）


dig +nocmd esmile-soltribe.jp MX +noall +answer;
dig +nocmd mail.esmile-soltribe.jp A +noall +answer;
dig +nocmd esmile-soltribe.jp TXT +noall +answer;



# users マップ
postmap -v -q shiozumi-makoto               ldap:/etc/postfix/ldap-users.cf
postmap -v -q shiozumi-makoto@esmile-holdings.com  ldap:/etc/postfix/ldap-users.cf
postmap -v -q alias@esmile-holdings.com     ldap:/etc/postfix/ldap-alias.cf


postmap -q shiozumi-makoto               ldap:/etc/postfix/ldap-users.cf
postmap -q shiozumi-makoto@esmile-holdings.com  ldap:/etc/postfix/ldap-users.cf
postmap -q alias@esmile-holdings.com     ldap:/etc/postfix/ldap-alias.cf




ldapsearch -LLL -H ldaps://ovs-024.e-smile.local:636 -x -D "uid=shiozumi-makoto,ou=Users,dc=e-smile,dc=ne,dc=jp" -w 'Makoto87426598' -b "ou=Users,dc=e-smile,dc=ne,dc=jp" "(uid=shiozumi-makoto)"


shiozumi-makoto@esmile-holdings.com



echo "テスト本文：送信確認" | mail -r shiozumi-makoto@esmile-holdings.com -s "送信テスト from ovs-024" shiozumi@esmile-hd.jp


tail -n 10 /var/log/maillog

Oct 24 15:53:29 ovs-024 postfix/pickup[461106]: 56FC69BD8D: uid=0 from=<shiozumi-makoto@esmile-holdings.com>
Oct 24 15:53:29 ovs-024 postfix/cleanup[461108]: 56FC69BD8D: message-id=<68fb2269.GCE6KCG+o9OjYrt+%shiozumi-makoto@esmile-holdings.com>
Oct 24 15:53:29 ovs-024 postfix/qmgr[461107]: 56FC69BD8D: from=<shiozumi-makoto@esmile-holdings.com>, size=542, nrcpt=1 (queue active)
Oct 24 15:53:30 ovs-024 postfix/smtp[461114]: Untrusted TLS connection established to gmail-smtp-in.l.google.com[108.177.97.26]:25: TLSv1.3 with cipher TLS_AES_256_GCM_SHA384 (256/256 bits) key-exchange X25519 server-signature ECDSA (P-256) server-digest SHA256
Oct 24 15:53:31 ovs-024 postfix/smtp[461114]: 56FC69BD8D: to=<shiozumi.makoto@gmail.com>, relay=gmail-smtp-in.l.google.com[108.177.97.26]:25, delay=1.7, delays=0.01/0/0.94/0.77, dsn=2.0.0, status=sent (250 2.0.0 OK  1761288810 41be03b00d2f7-b6cf4b95f0asi2402216a12.166 - gsmtp)
Oct 24 15:53:31 ovs-024 postfix/qmgr[461107]: 56FC69BD8D: removed



echo "テスト本文：送信確認" | mail -r shiozumi-makoto@esmile-holdings.com -s "送信テスト from ovs-024" shiozumi.makoto@gmail.com
echo "テスト本文：送信確認" | mail -r shiozumi-makoto@esmile-holdings.com -s "送信テスト from ovs-024 test 2" shiozumi@esmile-hd.jp
echo "テスト本文：送信確認" | mail -r shiozumi-makoto@esmile-holdings.com -s "送信テスト from ovs-024" shiozumi@esmile-hd.jp


echo "テスト本文：送信確認" | mail -r shiozumi-makoto@esmile-holdings.com -s "送信テスト from ovs-009" shiozumi.makoto@gmail.com


[root@ovs-024 tools]# tail -n 10 /var/log/maillog
Oct 24 16:30:02 ovs-024 postfix/qmgr[465002]: 8F29D9BD9C: from=<root@ovs-025.esmile-holdings.com>, size=2706, nrcpt=1 (queue active)
Oct 24 16:30:04 ovs-024 postfix/smtp[465101]: Anonymous TLS connection established to mx7.gmoserver.jp[210.172.183.45]:25: TLSv1.2 with cipher ADH-AES256-GCM-SHA384 (256/256 bits)
Oct 24 16:30:04 ovs-024 postfix/smtp[465101]: 8F29D9BD9C: to=<shiozumi@esmile-sys.com>, relay=mx7.gmoserver.jp[210.172.183.45]:25, delay=1.6, delays=0.03/0.01/1.5/0.03, dsn=2.0.0, status=sent (250 2.0.0 Ok: queued as 1C6CDAD250)
Oct 24 16:30:04 ovs-024 postfix/qmgr[465002]: 8F29D9BD9C: removed
Oct 24 16:30:41 ovs-024 postfix/pickup[465001]: 07BA69BD9C: uid=0 from=<shiozumi-makoto@esmile-holdings.com>
Oct 24 16:30:41 ovs-024 postfix/cleanup[465099]: 07BA69BD9C: message-id=<68fb2b21.jcWSDlklyvrWRNFm%shiozumi-makoto@esmile-holdings.com>
Oct 24 16:30:41 ovs-024 postfix/qmgr[465002]: 07BA69BD9C: from=<shiozumi-makoto@esmile-holdings.com>, size=538, nrcpt=1 (queue active)
Oct 24 16:30:41 ovs-024 postfix/smtp[465101]: Untrusted TLS connection established to mail18.onamae.ne.jp[150.95.219.145]:25: TLSv1.2 with cipher ECDHE-RSA-AES128-GCM-SHA256 (128/128 bits)
Oct 24 16:30:42 ovs-024 postfix/smtp[465101]: 07BA69BD9C: to=<shiozumi@esmile-hd.jp>, relay=mail18.onamae.ne.jp[150.95.219.145]:25, delay=1.3, delays=0.01/0/0.09/1.2, dsn=2.0.0, status=sent (250 2.0.0 Ok: queued as 4012E21552EA2)
Oct 24 16:30:42 ovs-024 postfix/qmgr[465002]: 07BA69BD9C: removed



echo "テストメール（署名確認用0n）NEXT" | mail -r shiozumi-makoto@esmile-holdings.com -s "NEXT : DKIM / SPF / DMARC test from ovs-024" shiozumi.makoto@gmail.com

ARC-Authentication-Results: i=1; mx.google.com;
       dkim=pass header.i=@esmile-holdings.com header.s=default header.b=H3ewIb+C;
       spf=pass (google.com: domain of shiozumi-makoto@esmile-holdings.com designates 221.241.134.212 as permitted sender) smtp.mailfrom=shiozumi-makoto@esmile-holdings.com;
       dmarc=pass (p=NONE sp=NONE dis=NONE) header.from=esmile-holdings.com


echo "テストメール（署名確認用0n）NEXT ovs-009 " | mail -r shiozumi-makoto@esmile-holdings.com -s "NEXT : DKIM / SPF / DMARC test from ovs-009" shiozumi.makoto@gmail.com

ARC-Authentication-Results: i=1; mx.google.com;
       spf=pass (google.com: domain of shiozumi-makoto@esmile-holdings.com designates 221.241.134.211 as permitted sender) smtp.mailfrom=shiozumi-makoto@esmile-holdings.com;
       dmarc=pass (p=NONE sp=NONE dis=NONE) header.from=esmile-holdings.com

echo "SMTP inbound OK test" | mail -r shiozumi-makoto@esmile-holdings.com -s "Inbound test" shiozumi-makoto@esmile-holdings.com



宛先に shiozumi-makoto@esmile-holdings.com を指定して
タイトル「Inbound test」、本文「SMTP inbound OK test」として送信。



/etc/dovecot/conf.d/10-master.conf
/etc/opendkim.conf
/etc/systemd/system/opendkim.service.d/override.conf


[root@ovs-024 esmile-holdings.com]# cat /etc/opendkim.conf
Syslog                  yes
UMask                   002
Mode                    sv
Canonicalization        relaxed/simple
SubDomains              no
KeyTable                /etc/opendkim/KeyTable
SigningTable            refile:/etc/opendkim/SigningTable
ExternalIgnoreList      /etc/opendkim/TrustedHosts
InternalHosts           /etc/opendkim/TrustedHosts
Socket                  local:/var/spool/postfix/opendkim/opendkim.sock
PidFile                 /var/run/opendkim/opendkim.pid
UserID                  opendkim:postfix

[root@ovs-024 esmile-holdings.com]# cat /etc/systemd/system/opendkim.service.d/override.conf
[Service]
User=opendkim
Group=postfix

[root@ovs-024 esmile-holdings.com]#  ls -l /var/spool/postfix/opendkim/opendkim.sock
srwxrwxr-x 1 opendkim postfix 0 10月 24 17:24 /var/spool/postfix/opendkim/opendkim.sock
[root@ovs-024 esmile-holdings.com]#  ss -x | grep opendkim


















# RTX1210 Rev.14.01.42 (Fri Jul  5 11:17:45 2024)
# MAC Address : ac:44:f2:6f:7d:d8, ac:44:f2:6f:7d:d9, ac:44:f2:6f:7d:da
# Memory 256Mbytes, 3LAN, 1BRI
# main:  RTX1210 ver=00 serial=S4H196102 MAC-Address=ac:44:f2:6f:7d:d8 MAC-Address=ac:44:f2:6f:7d:d9 MAC-Address=ac:44:f2:6f:7d:da TAM=11
# Reporting Date: Oct 24 19:57:53 2025
login password *
administrator password *
login user shiozumi *
login user wol *
user attribute shiozumi administrator=on connection=serial,telnet,remote,ssh,sftp,http login-timer=3600 multi-session=on host=any
console character en.ascii
console columns 200
console lines 70
login timer 1500
ip route default gateway 221.241.134.209
ip filter directed-broadcast filter 107001
ip keepalive 1 icmp-echo 60 5 221.241.134.209
vlan port mapping lan1.1 vlan1
vlan port mapping lan1.2 vlan2
vlan port mapping lan1.3 vlan3
vlan port mapping lan1.4 vlan8
vlan port mapping lan1.5 vlan1
vlan port mapping lan1.6 vlan2
vlan port mapping lan1.7 vlan3
vlan port mapping lan1.8 vlan1
lan type lan1 port-based-option=divide-network
ip vlan1 address 192.168.61.1/24
ip vlan1 rip send off
ip vlan1 rip receive on version 2
ip vlan1 rip filter in 101 102
ip vlan1 rip trust gateway 192.168.61.250 192.168.61.251 192.168.61.252 192.168.61.247
ip vlan1 secure filter in 109997 109998 106101 106102 100000 100001 100002 100003 100004 100005 100006 100007 100099
ip vlan2 address 192.168.62.1/24
ip vlan2 rip send off
ip vlan2 rip receive off
ip vlan2 secure filter in 109997 109998 106201 106202 100000 100001 100002 100003 100004 100005 100006 100007 100099
ip vlan3 address 192.168.63.1/24
ip vlan3 rip send off
ip vlan3 rip receive off
ip vlan3 secure filter in 109997 109998 106301 106302 100000 100001 100002 100003 100004 100005 100006 100007 100099
ip vlan8 address 192.168.100.1/24
ip vlan8 rip send off
ip vlan8 rip receive off
ip vlan8 secure filter in 100099
description lan2 PRV/DHCP/195:e-game
ip lan2 address 221.241.134.210/28
ip lan2 rip send off
ip lan2 rip receive off
ip lan2 secure filter in 101120 101000 101001 101002 101003 101020 101021 101022 101023 101024 101025 101030 101040 101041 101042 101043 101044 101100 101101 101102 101103 101104 101105 101106 101107 101109 101110 101111 101112 101108
ip lan2 secure filter out 101130 101010 101011 101012 101013 101020 101021 101022 101023 101024 101025 101026 101027 101099 dynamic 101080 101081 101082 101083 101084 101098 101099
ip lan2 intrusion detection in on
ip lan2 intrusion detection in ip on reject=on
ip lan2 intrusion detection in ip-option on reject=on
ip lan2 intrusion detection in fragment on reject=on
ip lan2 intrusion detection in icmp on reject=on
ip lan2 intrusion detection in udp on reject=on
ip lan2 intrusion detection in tcp on reject=on
ip lan2 intrusion detection in default off
ip lan2 intrusion detection out on
ip lan2 intrusion detection out ftp on reject=on
ip lan2 intrusion detection out winny on reject=on
ip lan2 intrusion detection out share on reject=on
ip lan2 intrusion detection out default off
ip lan2 nat descriptor 210 211 212 213 214 216 222
ip lan3 address 192.168.10.1/24
ip lan3 secure filter in 101131 300100 300101 100000 100001 100002 100003 100004 100005 100006 100007 100099
lan link-aggregation static 1 lan1:1 lan1:5
lan link-aggregation static 2 lan1:2 lan1:6
lan link-aggregation static 3 lan1:3 lan1:7
ip filter 101 reject 122.0.0.0/8 *
ip filter 102 pass * * * *
ip filter 100000 reject * * udp,tcp 135 *
ip filter 100001 reject * * udp,tcp * 135
ip filter 100002 reject * * udp,tcp netbios_ns-netbios_dgm *
ip filter 100003 reject * * udp,tcp * netbios_ns-netbios_dgm
ip filter 100004 reject * * udp,tcp netbios_ssn *
ip filter 100005 reject * * udp,tcp * netbios_ssn
ip filter 100006 reject * * udp,tcp 445 *
ip filter 100007 reject * * udp,tcp * 445
ip filter 100099 pass * * * * *
ip filter 101000 reject 10.0.0.0/8 * * * *
ip filter 101001 reject 172.16.0.0/12 * * * *
ip filter 101002 reject 192.168.0.0/16 * * * *
ip filter 101003 reject 192.168.100.0/24,192.168.10.0/24,192.168.63.0/24,192.168.62.0/24,192.168.61.0/24 * * * *
ip filter 101010 reject * 10.0.0.0/8 * * *
ip filter 101011 reject * 172.16.0.0/12 * * *
ip filter 101012 reject * 192.168.0.0/16 * * *
ip filter 101013 reject * 192.168.100.0/24,192.168.10.0/24,192.168.63.0/24,192.168.62.0/24,192.168.61.0/24 * * *
ip filter 101020 reject * * udp,tcp 135 *
ip filter 101021 reject * * udp,tcp * 135
ip filter 101022 reject * * udp,tcp netbios_ns-netbios_ssn *
ip filter 101023 reject * * udp,tcp * netbios_ns-netbios_ssn
ip filter 101024 reject * * udp,tcp 445 *
ip filter 101025 reject * * udp,tcp * 445
ip filter 101026 restrict * * tcpfin * www,21,nntp
ip filter 101027 restrict * * tcprst * www,21,nntp
ip filter 101030 pass * 172.20.0.0/16,172.19.0.0/16,172.18.0.0/16,172.17.0.0/16,172.16.0.0/16,192.168.61.0/24,192.168.62.0/24,192.168.63.0/24,192.168.100.0/24,192.168.10.0/24 icmp * *
ip filter 101033 pass * 192.168.100.0/24 tcp ftpdata *
ip filter 101034 pass * 192.168.100.0/24 tcp,udp * domain
ip filter 101035 pass * 192.168.100.0/24 udp domain *
ip filter 101036 pass * 192.168.100.0/24 udp * ntp
ip filter 101037 pass * 192.168.100.0/24 udp ntp *
ip filter 101040 pass * 192.168.100.1 tcp * 1701
ip filter 101041 pass * 192.168.100.1 gre
ip filter 101042 pass * 192.168.100.1 udp * 500
ip filter 101043 pass * 192.168.100.1 udp * 4500
ip filter 101044 pass * 192.168.100.1 esp
ip filter 101099 pass * * * * *
ip filter 101100 pass 221.241.134.219,221.241.134.215 192.168.100.1 tcp * www
ip filter 101101 pass * 192.168.10.9 tcp * https,8089,www,smtp
ip filter 101102 pass * 192.168.10.10 tcp * https,8090,www,ldap
ip filter 101103 pass * 192.168.10.11 tcp * https,8091,www
ip filter 101104 pass * 192.168.10.24 tcp * https,8092,www,smtp
ip filter 101105 pass * 192.168.10.25 tcp * https,8093,www,ldap
ip filter 101106 pass * 192.168.10.26 tcp * https,8094,www
ip filter 101107 pass * 192.168.61.220 tcp * 5000,5001
ip filter 101108 pass * 192.168.10.23 tcp * www,https
ip filter 101109 pass * 192.168.10.24 tcp * smtp
ip filter 101110 pass * 192.168.62.13 tcp * ftpdata,21,9333,55536-56559
ip filter 101111 pass * 192.168.62.14 tcp * ftpdata,21,9333,60000-60099
ip filter 101112 pass * 192.168.63.6 tcp * 9333
ip filter 101120 pass-log 122.134.177.127 192.168.10.10 tcp * https,www
ip filter 101130 pass-log * 122.134.177.127 tcp * *
ip filter 101131 pass-log 192.168.10.10 122.134.177.127 tcp https,www *
ip filter 106101 pass-log 192.168.61.0/24,172.20.0.0/16,172.19.0.0/16,172.18.0.0/16,172.17.0.0/16,172.16.0.0/16 192.168.63.6,192.168.63.7,192.168.63.8,192.168.63.9,192.168.62.6,192.168.62.10,192.168.62.11,192.168.62.12,192.168.62.13,192.168.62.14 udp,tcp * netbios_ns-netbios_dgm,netbios_ssn,445
ip filter 106102 pass-log 192.168.61.220,192.168.61.6,192.168.61.7 192.168.61.0/24,192.168.62.0/24,192.168.63.0/24,172.20.0.0/16,172.19.0.0/16,172.18.0.0/16,172.17.0.0/16,172.16.0.0/16 udp,tcp netbios_ns-netbios_dgm,netbios_ssn,445 *
ip filter 106201 pass-log 192.168.62.0/24 192.168.61.220,192.168.61.6,192.168.61.7.168.63.6,192.168.63.7 udp,tcp * netbios_ns-netbios_dgm,netbios_ssn,445
ip filter 106202 pass-log 192.168.62.6,192.168.62.10,192.168.62.11,192.168.62.12,192.168.62.13,192.168.62.14,192.168.62.8 192.168.61.0/24,192.168.63.0/24,172.20.0.0/16,172.19.0.0/16,172.18.0.0/16,172.17.0.0/16,172.16.0.0/16 udp,tcp netbios_ns-netbios_dgm,netbios_ssn,445 *
ip filter 106301 pass-log 192.168.63.0/24 192.168.61.220,192.168.61.6,192.168.61.7,192.168.62.6,192.168.62.10,192.168.62.11,192.168.62.12,192.168.62.13,192.168.62.14,192,192.168.62.8 udp,tcp * netbios_ns-netbios_dgm,netbios_ssn,445
ip filter 106302 pass-log 192.168.63.6,192.168.63.7,192.168.63.8,192.168.63.9 192.168.61.0/24,192.168.62.0/24,172.20.0.0/16,172.19.0.0/16,172.18.0.0/16,172.17.0.0/16,172.16.0.0/16 udp,tcp netbios_ns-netbios_dgm,netbios_ssn,445 *
ip filter 107001 pass-log 192.168.100.0/24,192.168.61.0/24,192.168.62.0/24,192.168.63.0/24 192.168.100.255,192.168.61.255,192.168.62.255,192.168.63.255 udp * discard
ip filter 109998 reject 192.168.61.240-192.168.61.242,192.168.62.2-192.168.62.4,192.168.63.2-192.168.63.4 192.168.61.0/24,192.168.62.0/24,192.168.63.0/24,192.168.10.0/24,192.168.100.0/24 udp,tcp * 21,22,telnet,www,sunrpc,https,netbios_ns-netbios_dgm,netbios_ssn,445,548,873
ip filter 109999 reject * * * * *
ip filter 300100 pass 192.168.10.0/24 192.168.61.0/24,192.168.62.0/24,192.168.63.0/24 * * *
ip filter 300101 pass 192.168.61.2 192.168.62.0/24,192.168.63.0/24 udp,tcp netbios_ns-netbios_dgm,netbios_ssn,445 *
ip filter 500000 restrict * * * * *
ip filter dynamic 101080 * * ftp syslog=off
ip filter dynamic 101081 * * domain syslog=off
ip filter dynamic 101082 * * www syslog=off
ip filter dynamic 101083 * * smtp syslog=off
ip filter dynamic 101084 * * pop3 syslog=off
ip filter dynamic 101098 * * tcp
ip filter dynamic 101099 * * udp syslog=off


> esmile-holdings.com
サーバー:  UnKnown
Address:  2400:4050:5f21:cf02::1

権限のない回答:
esmile-holdings.com     MX preference = 20, mail exchanger = mail2.esmile-holdings.com
esmile-holdings.com     MX preference = 10, mail exchanger = mail.esmile-holdings.com

mail.esmile-holdings.com        internet address = 221.241.134.211
mail2.esmile-holdings.com       internet address = 221.241.134.212

権限のない回答:
名前:    mail2.esmile-holdings.com
Address:  221.241.134.212
Aliases:  ovs-024.esmile-holdings.com

権限のない回答:
名前:    mail.esmile-holdings.com
Address:  221.241.134.211
Aliases:  ovs-009.esmile-holdings.com



nat descriptor type 211 masquerade
nat descriptor address outer 211 221.241.134.211
nat descriptor address inner 211 192.168.61.220 192.168.10.11 192.168.61.10 192.168.10.9 192.168.10.10
nat descriptor masquerade incoming 211 reject 
nat descriptor masquerade static 211 1 192.168.10.9 tcp 8009=https,8089,82=www,smtp

nat descriptor type 212 masquerade
nat descriptor address outer 212 221.241.134.212
nat descriptor address inner 212 192.168.10.26 192.168.10.25 192.168.10.24
nat descriptor masquerade incoming 212 reject 
nat descriptor masquerade static 212 11 192.168.10.24 tcp 8012=https,8092,85=www,smtp


nat descriptor masquerade static 212 11 192.168.10.24 tcp 25=smtp,587=submission,143=imap,993=imaps,110=pop3,995=pop3s


ip filter 101104 pass * 192.168.10.24 tcp * https,8092,www
ip filter 101109 pass * 192.168.10.24 tcp * smtp,submissionimap,imaps,pop3,pop3s



systemctl restart dovecot

[root@ovs-024 tools]# ss -lntup | egrep ':(143|993|110|995)\b'
tcp   LISTEN 0      100                              0.0.0.0:143        0.0.0.0:*    users:(("dovecot",pid=495899,fd=38))                                                                                                  tcp   LISTEN 0      100                              0.0.0.0:993        0.0.0.0:*    users:(("dovecot",pid=495899,fd=39))                                                                                                  tcp   LISTEN 0      100                              0.0.0.0:995        0.0.0.0:*    users:(("dovecot",pid=495899,fd=22))                                                                                                  tcp   LISTEN 0      100                              0.0.0.0:110        0.0.0.0:*    users:(("dovecot",pid=495899,fd=21))






SUBJ="[TEST] OVS-010 -> OVS-009 $(date +%F-%T)"
MID="<$(date +%s).ovs010@esmile-holdings.com>"

swaks -s ovs-009 -p 25 \
  --helo ovs-009.esmile-holdings.com \
  --from shiozumi-makoto@esmile-holdings.com \
  --to shiozumi.makoto@gmail.com \
  --h-From "shiozumi-makoto@esmile-holdings.com" \
  --h-Subject "$SUBJ" \
  --h-Date "$(date -R)" \
  --h-Message-Id "$MID" \
  --body "こんにちは。\nこちらは社内メールサーバの送信テストです。\n正常に届きましたらご確認ください。" \
  --tls-optional




SUBJ="[TEST] OVS-025 -> OVS-024 $(date +%F-%T)"
MID="<$(date +%s).ovs025@esmile-holdings.com>"

swaks -s ovs-024 -p 25 \
  --helo ovs-024.esmile-holdings.com \
  --from shiozumi-makoto@esmile-holdings.com \
  --to shiozumi.makoto@gmail.com \
  --h-From "shiozumi-makoto@esmile-holdings.com" \
  --h-Subject "$SUBJ" \
  --h-Date "$(date -R)" \
  --h-Message-Id "$MID" \
  --body "こんにちは。\nこちらは社内メールサーバの送信テストです。\n正常に届きましたらご確認ください。" \
  --tls-optional
