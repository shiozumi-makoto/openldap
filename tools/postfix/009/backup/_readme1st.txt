SSH_USER=root HOST=ovs-009 SRC_DIR="$(pwd)" EXT= bash ./deploy_postfix_confs.sh



# 5) 明示的に再起動（reloadではなく stop→start が安全）
systemctl stop postfix
systemctl start postfix
systemctl status postfix --no-pager

# 6) 待受確認
ss -ltnp | egrep '(:25 )|(:587 )|(:465 )'

scp main.cf.009       root@ovs-009:/etc/postfix/main.cf
scp master.cf.009     root@ovs-009:/etc/postfix/master.cf
scp ldap-alias.cf.009 root@ovs-009:/etc/postfix/ldap-alias.cf
scp ldap-users.cf.009 root@ovs-009:/etc/postfix/ldap-users.cf
scp virtual-regexp.009 root@ovs-009:/etc/postfix/virtual-regexp

for f in main.cf master.cf ldap-alias.cf ldap-users.cf virtual-regexp; do
  scp "${f}.009" root@ovs-009:/etc/postfix/"${f}"
done


# ---------------------------------------------------------------
EXT=009 EXT_DIR=009 HOST=ovs-009 bash deploy_postfix_confs.sh



SUBJ="[TEST] OVS-010 -> OVS-009 $(date +%F-%T)"
MID="<$(date +%s).ovs010@esmile-holdings.com>"
MSG="遅れましたが、いつものお礼！
ブログの購読、感想、個別コメント、
誠にありがとうございます。
まだ、みなさんからの日報を、
確認できていませんが、この後で、
コツコツ見て行きますね～
とうことで引き続き、毎度の情シスひとりからのお知らせ！"

swaks -s ovs-009 -p 25 \
  --helo ovs-009.esmile-holdings.com \
  --from shiozumi-makoto@esmile-holdings.com \
  --to shiozumi.makoto@gmail.com \
  --h-From "shiozumi-makoto@esmile-holdings.com" \
  --h-Subject "$SUBJ" \
  --h-Date "$(date -R)" \
  --h-Message-Id "$MID" \
  --body "$MSG" \
  --tls-optional


  --body "こんにちは。\nこちらは社内メールサーバの送信テストです。\n正常に届きましたらご確認ください。" \


shiozumi.makoto@gmail.com
shiozumi.makoto@gmail.com

SUBJ="[TEST] OVS-025 -> OVS-024 $(date +%F-%T)"
MID="<$(date +%s).ovs025@esmile-holdings.com>"

MSG="遅れましたが、いつものお礼！
ブログの購読、感想、個別コメント、
誠にありがとうございます。
まだ、みなさんからの日報を、
確認できていませんが、この後で、
コツコツ見て行きますね～
とうことで引き続き、毎度の情シスひとりからのお知らせ！"

swaks -s ovs-024 -p 25 \
  --helo ovs-024.esmile-holdings.com \
  --from shiozumi-makoto@esmile-holdings.com \
  --to shiozumi.makoto@gmail.com \
  --h-From "shiozumi-makoto@esmile-holdings.com" \
  --h-Subject "$SUBJ" \
  --h-Date "$(date -R)" \
  --h-Message-Id "$MID" \
  --body "$MSG" \
  --tls-optional



sendmail_path = "/usr/sbin/sendmail -t -i -fgyomu@esmile-holdings.com"

php -r "mail('shiozumi.makoto@gmail.com', 'DKIM test from gyomu', 'via PHP sendmail test');"

echo "DKIM test from ovs-010" | mail -s "DKIM test ovs-010" shiozumi.makoto@gmail.com
echo "DKIM test from ovs-009" | mail -s "DKIM test ovs-009" shiozumi.makoto@gmail.com





Delivered-To: shiozumi.makoto@gmail.com
Received: by 2002:a05:6802:6249:b0:5f8:f095:9c91 with SMTP id s9csp195752ocb;
        Fri, 24 Oct 2025 22:01:28 -0700 (PDT)
X-Google-Smtp-Source: AGHT+IEu/yDCv2s0qT+HP2clNo95PNxuROcHiYJ3ERV30skXNgnFGhL55VKPxBZBwldP7Dv7CPDX
X-Received: by 2002:a05:6a20:7286:b0:334:a72c:806e with SMTP id adf61e73a8af0-33dec82170cmr5869646637.43.1761368488110;
        Fri, 24 Oct 2025 22:01:28 -0700 (PDT)
ARC-Seal: i=1; a=rsa-sha256; t=1761368488; cv=none;
        d=google.com; s=arc-20240605;
        b=LbCiGvgadVeQ4ogt20SBP060iD9yoFrG7aSqDNmM5qtKLOFpnz7pg+ovbHpQ+0DDFo
         MasmICaSho6E9zDLmxQEU08Ooh0xzaETDBmFVsDPGkQfLMXXHQtxxO1O9JgKx1h3Rkii
         dwXGijWskUWVu92oK8MpCjsB7JbXCbptQ45yL8AfybmV9WhpMH5G986dcqq3KHU5mVw8
         CowDwdScn3At4RC2bveVn0HSPhRRa9QxMI6XkjalOIS+WXofNxcXEze2V9Z8jRJWDqFz
         UpfII3QSG22fWIY9jcmj67HelwJE4EGMGs4TdDje0zjiFGi5XX1eZEVMdT0IkEAMw7KF
         W7dg==
ARC-Message-Signature: i=1; a=rsa-sha256; c=relaxed/relaxed; d=google.com; s=arc-20240605;
        h=message-id:subject:from:to:date:dkim-signature;
        bh=XjZlJrDT2HSm7kvh8rqM4/Mvw4vAI5lqb20AKy31BD4=;
        fh=8lKKw4+1W8vGWzKXH1zNiIPY6Nbnxq3lhKk4T533Qls=;
        b=U4s1XtAsaPPfmvJ2blSC15l+OShn+2Sc/qsc0yZrXLfgx6YRh07WPQSPfeAsQMVaFB
         krXr0HrCbjkPFT/ZMlWGUQ1Jt8GC+C3ytd2YK469wQkDvNHL+aUCsbn4gmVra8pnAa/y
         piAE5leepCcRYIQ3h0z6EDs9UV3beS1VJX8njopeQvZTj7WFqO+l57lc4uu5dY6OAV2n
         Hz4Kr6VUUS2RUzLKVicN87TKhjoZ0xaGSdKuOMbZ1W7LQr35aDbIsh9xpUeb/Hu2PY86
         QT0n4uUVVGL6e8ZuZKdy74MoCjd2DUPBLJ+2hV+XFweqCp5qW5kuBpLud/0UvW7ClkEl
         vUQQ==;
        dara=google.com
ARC-Authentication-Results: i=1; mx.google.com;
       dkim=pass header.i=@esmile-holdings.com header.s=s2025 header.b=NdPAdva3;
       spf=pass (google.com: domain of shiozumi-makoto@esmile-holdings.com designates 221.241.134.211 as permitted sender) smtp.mailfrom=shiozumi-makoto@esmile-holdings.com;
       dmarc=pass (p=NONE sp=NONE dis=NONE) header.from=esmile-holdings.com
Return-Path: <shiozumi-makoto@esmile-holdings.com>
Received: from ovs-009.esmile-holdings.com ([221.241.134.211])
        by mx.google.com with ESMTPS id 41be03b00d2f7-b71f7d847e6si425634a12.1520.2025.10.24.22.01.27
        for <shiozumi.makoto@gmail.com>
        (version=TLS1_2 cipher=ECDHE-ECDSA-AES128-GCM-SHA256 bits=128/128);
        Fri, 24 Oct 2025 22:01:28 -0700 (PDT)
Received-SPF: pass (google.com: domain of shiozumi-makoto@esmile-holdings.com designates 221.241.134.211 as permitted sender) client-ip=221.241.134.211;
Authentication-Results: mx.google.com;
       dkim=pass header.i=@esmile-holdings.com header.s=s2025 header.b=NdPAdva3;
       spf=pass (google.com: domain of shiozumi-makoto@esmile-holdings.com designates 221.241.134.211 as permitted sender) smtp.mailfrom=shiozumi-makoto@esmile-holdings.com;
       dmarc=pass (p=NONE sp=NONE dis=NONE) header.from=esmile-holdings.com
Received: from ovs-009.esmile-holdings.com (ovs-010.e-smile.local [192.168.61.10]) (using TLSv1.2 with cipher ECDHE-RSA-AES256-GCM-SHA384 (256/256 bits)) (No client certificate requested) by ovs-009.esmile-holdings.com (Postfix) with ESMTPS id CA6EB101A2E68 for <shiozumi.makoto@gmail.com>; Sat, 25 Oct 2025 14:01:26 +0900 (JST)
DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/simple; d=esmile-holdings.com; s=s2025; t=1761368486; bh=XjZlJrDT2HSm7kvh8rqM4/Mvw4vAI5lqb20AKy31BD4=; h=Date:To:From:Subject; b=NdPAdva31XGMeyTkyaqw2yisy9l2NhCvl9QwSweO8zCCQo7/RbjeQDWZw2FAq6cAT
	 V52uZ88KYo4Wl03n+f871EWpqX1+kyRfYTgkQIDymB73y7MVJoNHTsXvXl1iANSkkl
	 22QCvfhHS49cDDqIBpFBNF8KgEgpA13Fk/y1TQfLKvn/2JKm/Z1YQ5C2mR3j5NDS7q
	 z/wj5DLh4lHR5HUJUC8JfGYt5bK67QeiCD94B+dkRktogi2RZklLKF9+LW7up1Twah
	 BMUI9DhtmaZGKohxh3HM+qVCCyTnNHrQU+eU+Sep94h5X8ZPYLl4Hl8rRm+y9xqkMy
	 SdU5XDaNHwCYA==
Date: Sat, 25 Oct 2025 14:01:26 +0900
To: shiozumi.makoto@gmail.com
From: shiozumi-makoto@esmile-holdings.com
Subject: [TEST] OVS-010 -> OVS-009 2025-10-25-14:01:26
Message-Id: <1761368486.ovs010@esmile-holdings.com>
X-Mailer: swaks v20170101.0 jetmore.org/john/code/swaks/

遅れましたが、いつものお礼！
ブログの購読、感想、個別コメント、
誠にありがとうございます。
まだ、みなさんからの日報を、
確認できていませんが、この後で、
コツコツ見て行きますね～
とうことで引き続き、毎度の情シスひとりからのお知らせ！



Delivered-To: shiozumi.makoto@gmail.com
Received: by 2002:a05:6802:6249:b0:5f8:f095:9c91 with SMTP id s9csp201304ocb;
        Fri, 24 Oct 2025 22:20:34 -0700 (PDT)
X-Forwarded-Encrypted: i=2; AJvYcCVhSBDjFNMZbT1gVqsmLOmsicRUwThTeVitw0gKr8ih9J7dTmkSIrBVf7vnZKwq/ZOiPhaFvCVHdqo8n8K/TWI=@gmail.com
X-Google-Smtp-Source: AGHT+IElhNi1YyKhNJB9+mX7jazVUQWrClZkXAOFD0eR2spRprE19JrhtlAU4ss2OUbZd8Tsb9e0
X-Received: by 2002:a17:902:f60f:b0:290:c07f:e8ee with SMTP id d9443c01a7336-2948ba3e130mr56379475ad.43.1761369634789;
        Fri, 24 Oct 2025 22:20:34 -0700 (PDT)
ARC-Seal: i=1; a=rsa-sha256; t=1761369634; cv=none;
        d=google.com; s=arc-20240605;
        b=E+pLwGGIs0OgVzVC0aUdjJNzydfRYATiDSeo9HGIA9HRI+W0kWNmvbBD9jIikeQskL
         ETYJJ7YQd6ZQYrUGkDz3oERpSlUOCC01eKz8DukrAXewLL81M0TaYquEoraNNSATHdPZ
         aCyRgLy6t5Af2ycc+SD6vJWN4rRW3/y1I1iTa6eSRcAx+X0G16uwYM9KC0IosaF1HbVc
         sX81jLMmwafftIN/5lO4Qo+BKpYM9dsVQohd4FZN5e7EgabpNpBXxMOu0BBK0c2+gzUi
         EjeHlRYmhRYXmYPtm4pvOSZ5FfeSIRG7pAX0y8KwmBIxy6eebzmbTf8d/QKhi1PBLLh5
         ttEA==
ARC-Message-Signature: i=1; a=rsa-sha256; c=relaxed/relaxed; d=google.com; s=arc-20240605;
        h=date:message-id:mime-version:from:subject:to:dkim-signature;
        bh=t1xLu3NQFQeJsPuB1GdihW6l1D1YnVBmsSIeQHULZmc=;
        fh=C+3t29KQgO1EFPQTKS5nb7KxpyIbLpVEoIObr/FEr/E=;
        b=KrlFgLVXA+Pegj+HylwBJ/HMt/VF93XNXpfRwocbqwf4MX2nc/LQiMOjJh36epHD0i
         spyPdBHYjr8AYrItxZZdetcC5nkRW64wy1gEf/0+R7fxFHIkOB4tUBJasYZWX26wOnyJ
         AnN3/mDeBTElzVl2Jx9Z31XQNyoHr9kBJF0Emcwg0J8+sQ4gaPPmwNMxaZx2DBpjeCyA
         I3kwF7Pdkw466VYn0wvpKSNs9B3bYVH27BVCqXgbQjv4kVPP6/WF9onbcba5wj66jYUl
         rN1F+94BPKkXd7BxyG8of8VpWFXSD97k5cfsGXJ2RdaKeOiOwYSEgB46lxy1yH5sPh+u
         fd0g==;
        dara=google.com
ARC-Authentication-Results: i=1; mx.google.com;
       dkim=pass header.i=@esmile-holdings.com header.s=s2025 header.b=RZo7PDm9;
       spf=pass (google.com: domain of gyomu@esmile-holdings.com designates 221.241.134.211 as permitted sender) smtp.mailfrom=gyomu@esmile-holdings.com;
       dmarc=pass (p=NONE sp=NONE dis=NONE) header.from=esmile-holdings.com
Return-Path: <gyomu@esmile-holdings.com>
Received: from ovs-009.esmile-holdings.com ([221.241.134.211])
        by mx.google.com with ESMTPS id d9443c01a7336-2949aadb7f9si5067895ad.230.2025.10.24.22.20.34
        for <shiozumi.makoto@gmail.com>
        (version=TLS1_2 cipher=ECDHE-ECDSA-AES128-GCM-SHA256 bits=128/128);
        Fri, 24 Oct 2025 22:20:34 -0700 (PDT)
Received-SPF: pass (google.com: domain of gyomu@esmile-holdings.com designates 221.241.134.211 as permitted sender) client-ip=221.241.134.211;
Authentication-Results: mx.google.com;
       dkim=pass header.i=@esmile-holdings.com header.s=s2025 header.b=RZo7PDm9;
       spf=pass (google.com: domain of gyomu@esmile-holdings.com designates 221.241.134.211 as permitted sender) smtp.mailfrom=gyomu@esmile-holdings.com;
       dmarc=pass (p=NONE sp=NONE dis=NONE) header.from=esmile-holdings.com
Received: from ovs-010.esmile-holdings.com (ovs-010.e-smile.local [192.168.61.10]) by ovs-009.esmile-holdings.com (Postfix) with ESMTP id 6E43B101A17BD; Sat, 25 Oct 2025 14:20:33 +0900 (JST)
DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/simple; d=esmile-holdings.com; s=s2025; t=1761369633; bh=t1xLu3NQFQeJsPuB1GdihW6l1D1YnVBmsSIeQHULZmc=; h=To:Subject:From:Date; b=RZo7PDm9kWaKTs9PXGpVA3v76+E8LKudHYe4AXKGhnC/W51JxbiqQ6VrSs7SFqr1/
	 C7YZmYQ8t3p+4LflwHSEQCKzNyvzU8fyFmhWkmE4YMTm15YmtxZHCyDlJAynuOCbWL
	 6JQpJ76ockM5erEgd1BY7IJwWp5W3J6sEj+0OF7sK5CnQ7m3tjyYHBfwqEv5uBtBWX
	 GIpmVeAbDmh76ldZ1z2TGXoTtus92LK8qLGtcCMdGEOVUrnTdwQxF+zorjC/1oOgg8
	 xUZPstBpfCzXAJSzD2GuBUjcf4IHrmKnGx/zYCQV9N+W3o0MfApuR0H/N3ogFoAyeM
	 FGAd3pDE3MwMQ==
Received: by ovs-010.esmile-holdings.com (Postfix, from userid 48) id 62BC8C07FEAF; Sat, 25 Oct 2025 14:20:33 +0900 (JST)
To: shiozumi@e-smile.ne.jp, shiozumi.makoto@gmail.com, makoto.shiozumi@docomo.ne.jp
Subject: パスワードのお知らせ。ver1.3b
From: gyomu@esmile-holdings.com
MIME-Version: 1.0
Content-type: text/html; charset=UTF-8
X-Mailer: PHP/7.4.33
Message-Id: <20251025052033.62BC8C07FEAF@ovs-010.esmile-holdings.com>
Date: Sat, 25 Oct 2025 14:20:33 +0900 (JST)

現在のパスワードとＩＤを再発行いたいました。
<br><br>ログインＩＤ -> shiozumi@e-smile.ne.jp
<br>パスワード -> makoto87424749<br><br>■本メールにお心あたりがない場合は、至急、下記までご連絡ください。
<br>イースマイルＩＴ総務宛 03-5652-5566 ／ soumu@e-smile.ne.jp
<br><br>■申し訳ございませんが、本メールへの返信はお受けしておりません。
<br>

