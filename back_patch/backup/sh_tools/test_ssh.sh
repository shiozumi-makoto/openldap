# ホスト鍵を事前に登録
#for ip in 192.168.61.9 192.168.61.11 192.168.61.24 192.168.61.26

for ip in 192.168.61.2
do
  ssh-keyscan -H $ip >> ~/.ssh/known_hosts
done

# SSH接続確認
#for ip in 192.168.61.9 192.168.61.10 192.168.61.11 192.168.61.24 192.168.61.25 192.168.61.26
#for ip in 192.168.61.11 192.168.61.24 192.168.61.25 192.168.61.26

for ip in 192.168.61.2
do
  echo "== Testing SSH connection to $ip =="
  ssh -o ConnectTimeout=5 -o BatchMode=yes root@$ip 'echo "  OK: Connected to $ip"' || echo "  ? Failed to connect to $ip"
done


rsync -avz -e ssh /usr/local/etc/openldap/tools/ root@192.168.61.2:/usr/local/etc/openldap/tools/





