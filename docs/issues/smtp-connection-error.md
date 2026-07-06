# SMTP接続エラー 調査報告・対応計画

**発生日時**: 2026-05-08 07:11:21  
**検知方法**: production.log  
**優先度**: 高（パスワードリセット機能が使用不可）

---

## エラー内容

```
Connection could not be established with host "ssl://smtp.heteml.jp:465":
stream_socket_client(): Unable to connect to ssl://smtp.heteml.jp:465 (Connection timed out)
```

**発生箇所**: `app/Http/Controllers/Auth/PasswordResetLinkController.php:36`  
**トリガー**: ユーザーがパスワードリセットを申請した際に SMTP 接続を試みてタイムアウト

---

## 原因

本番サーバー（Kusanagi）から `smtp.heteml.jp:465`（SSL/SMTPS）へのアウトバウンド接続が確立できていない。

考えられる原因（可能性の高い順）：

1. **サーバーのファイアウォールがポート465の送信をブロック**  
   VPS・クラウド環境ではメールポートをデフォルトで塞いでいることが多い

2. **heteml.jp側がサーバーのIPをブロック**  
   スパム対策でIPレンジを制限している場合がある

3. **heteml.jp SMTPサーバーの一時的な障害**

また、`config/mail.php` の `'timeout' => null` により PHP デフォルトのソケットタイムアウト（約60秒）まで待ち続けるため、ユーザーへのレスポンスも最大60秒ブロックされる。

---

## 影響範囲

| 機能 | 影響 |
|------|------|
| 新規ユーザー登録 | **影響なし**（`MustVerifyEmail` 未実装のためメール送信しない） |
| パスワードリセット申請 | **機能不全**（SMTP タイムアウト後に500エラー） |

---

## 対応タスク

### 優先度: 高

- [ ] **疎通確認**  
  本番サーバーにSSH接続し、ポートの疎通を確認する

  ```bash
  # ポート465（SSL）
  timeout 10 bash -c 'echo > /dev/tcp/smtp.heteml.jp/465' && echo "OK" || echo "BLOCKED"

  # ポート587（STARTTLS）
  timeout 10 bash -c 'echo > /dev/tcp/smtp.heteml.jp/587' && echo "OK" || echo "BLOCKED"
  ```

- [ ] **タイムアウト値を設定する**（即時適用可能）  
  `config/mail.php` の `'timeout' => null` を修正

  ```php
  'smtp' => [
      // ...
      'timeout' => 10, // null → 10秒
  ],
  ```

### 優先度: 中

- [ ] **ネットワーク設定の修正**  
  疎通確認の結果に応じて以下いずれかを実施：
  - サーバーのセキュリティグループ／ファイアウォールでポート465の送信を許可する
  - ポート587（STARTTLS）に切り替える（`.env` の `MAIL_PORT=587`, `MAIL_ENCRYPTION=tls`）

- [ ] **メール送信をキューで非同期化**  
  SMTP エラー発生時にユーザーへのレスポンスがブロックされないよう、パスワードリセット通知を `ShouldQueue` 実装にする

### 優先度: 低（長期対応）

- [ ] **外部メール配信サービスへの移行検討**  
  heteml.jp SMTP への依存をなくし、信頼性の高いサービスへ移行する

  | サービス | 月間無料枠 | 備考 |
  |----------|------------|------|
  | Amazon SES | 62,000通（EC2経由） | AWS 利用中であれば最安 |
  | Mailgun | 1,000通/月 | 設定が簡単 |
  | SendGrid | 100通/日 | 無料枠あり |

---

## 補足：phpMyAdmin について

本番環境への phpMyAdmin 導入状況は未確認。  
`docker-compose.yml` に定義はあるが、これはローカル開発用（Laravel Sail）。

- [ ] **本番環境の確認**  
  SSH 接続または以下の URL にアクセスして存在確認する
  - `https://[ドメイン]/phpmyadmin/`
  - `https://[ドメイン]/phpMyAdmin/`

- [ ] **存在する場合はアクセス制限の確認**  
  外部公開されている場合は IP 制限または Basic 認証の設定を推奨

---

## 関連ファイル

- `app/Http/Controllers/Auth/PasswordResetLinkController.php`
- `app/Models/User.php`（`MustVerifyEmail` コメントアウト中）
- `config/mail.php`
- `.env`（`MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`）
