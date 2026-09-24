<?php

/*
| 信頼するリバースプロキシ(docs/infrastructure.md)。
|
| 本番は ブラウザ → CloudFront → ALB → ECS(Nginx → PHP-FPM) の構成で、X-Forwarded-For は
| 「(クライアントが送ってきた値), 閲覧者のIP, CloudFrontのIP」の順に積まれる。
| ALB(VPC内のIP)とCloudFront(オリジン向けIPレンジ)の2段だけを信頼し、その手前の値をクライアントIPとして扱う。
| すべてのIPを信頼すると、クライアントが偽のX-Forwarded-Forを送ることでIPを詐称でき、
| ログイン試行回数の制限(IPごと)も回避できてしまうため、信頼する範囲を明示的に絞る。
|
| Laravel標準のTrustProxiesミドルウェアが、この値(カンマ区切りのIP/CIDR)を読む。
| 値はTerraformがVPCのCIDRとCloudFrontのマネージドプレフィックスリストから生成し、ECSタスクの環境変数で渡す。
| 未設定(ローカル開発)の場合は、どのプロキシも信頼しない。
*/

return [
    'proxies' => env('TRUSTED_PROXIES'),
];
