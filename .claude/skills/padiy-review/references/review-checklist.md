# padiy-app レビューチェックリスト

`padiy-review` スキルの各ラウンドで参照する、このリポジトリ固有の観点集。
重大度の最終判定は SKILL.md の定義に従う（ここは「何を見るか」の一覧）。

## 1. 外部契約（壊すと High、秘密が漏れれば Critical）

### 1-a. 申込受付 API: `POST /api/applications`（`app/Http/Controllers/Api/ApplicationController.php`）

- 呼び出し元は Japanized for WooCommerce プラグインの申込ウィザード。**認証なし**は設計上の前提
- 受け取るフィールド: `site_name`, `site_url`, `trade_name`, `site_hash`, `email`, `phone`, `ceo`,
  `ceo_kana`, `ceo_birthday`, `gmv_flag`, `average_flag`, `state`, `plugin_version`, `survey01`〜`survey09`
- `state` は 32 文字英数字（`/^[A-Za-z0-9]{32}$/`）。**形式不正でも拒否しない**（旧プラグイン互換）
- 申込番号は `'WC' . sprintf('%08d', n) . '1'` 形式（例 `WC000005711`）。CSV 取込側は `substr($row[0],0,2) === 'WC'` で判定
- **DB 登録後に 5xx を返してはならない**。プラグインは 5xx を「申込未達」とみなして state トークンを削除し、
  後の審査結果送信が 403 `paidy_invalid_state` になる（孤児トークン）。CSV 追記失敗は捕捉してログのみ
- レスポンスは `response()->json($application)`。プラグインが参照するキーを削除・改名しない
- `Site` は `site_url` で既存検索 → 無ければ作成。`site_hash` は後続の暗号化・署名の鍵になる

### 1-b. 加盟店サイトへの審査結果送信（`app/Services/PaidyCallbackSender.php`）

- 送信先: `rtrim(site_url,'/') . '/' . 'wp-json/paidy-receiver/v1/receive/'`
- 認証は 2 系統を**常に両方**送る:
  - `state`（プラグイン 2.9.13〜2.9.14 は 2 日で失効、2.9.15 は 90 日）
  - HMAC-SHA256: 署名対象 `"<timestamp>.<raw body>"`、鍵 `site_hash`、
    ヘッダー `X-Paidy-Receiver-Timestamp` / `X-Paidy-Receiver-Signature`（プラグイン 2.9.16+ が検証）
  - **署名した文字列と送信本文は完全一致**が必要。`json_encode` のフラグや `withBody` を変えるときは要注意
- ペイロード: `application_id`, `paidy_status`(`approved|rejected|canceled`), `updated_at`,
  `public_live_key`, `secret_live_key`, `public_test_key`, `secret_test_key`（各 AES 暗号化 → base64）, `state`(任意)
- 暗号化: `AES-256-CBC`, key = `substr(hash('sha256', site_hash), 0, 32)`,
  iv = `substr(hash('sha256', site_hash . 'iv'), 0, 16)`, **`OPENSSL_RAW_DATA` 必須**（受信側と同じ導出式）
- 受信側のエラーコード `paidy_invalid_state` / `paidy_not_configured` は `translateError()` で日本語化。新コードを足すならここ
- 変更が受信側（プラグインの `includes/gateways/paidy/class-wc-paidy-apply-receiver.php`）にも
  必要なら、レビュー結果に**必ず明記**する

### 1-c. Paidy 向け加盟店リスト CSV（`storage/box_data/woocommerce_merchant_list.csv`）

- `Api\ApplicationController::appendMerchantListCsv()` が 1 申込 1 行追記。初回のみ BOM + ヘッダー行
- **列順・列数（54 列）は Paidy 側の取込仕様**。列の追加・削除・並べ替えは High
- `Console/Kernel::schedule()` が 6 時間ごとに `Storage::disk('local')` → `Storage::disk('sftp')` へ put。
  Box へは `routes/web.php` の `/box` ルートで `uploadRevision`
- ファイル名・保存先ディスクの変更はスケジュールと Box 転送の両方に波及する

### 1-d. Paidy からの審査結果 CSV 取込（`app/Http/Controllers/CsvImportController.php`）

- 入力列: `[0]=application_id (WC…)`, `[1]=paidy_status`, `[2]=public_live_key`, `[3]=secret_live_key`,
  `[4]=public_test_key`, `[5]=secret_test_key`。`approved` のときはキー 4 つ必須
- 1 行ごとに `applications` を更新し、`PaidyCallbackSender` で加盟店へ送信、`set_status` に成否を記録
- `state` が空/不正な行は送信をスキップしてエラー表示（`docs/issues/csv-import-null-error.md` の経緯を参照）
- ファイルバリデーション（`required|file|mimes:...|max:`）が無効化されていないか
- 1 行の失敗でループ全体が止まらないか、成功/失敗一覧（`api_success_list` / `api_error_list`）に反映されるか

## 2. Laravel セキュリティ観点

| 観点 | 見る場所 | 重大度の目安 |
|---|---|---|
| SQL インジェクション | `DB::raw`, `whereRaw`, `selectRaw`, `orderByRaw` に文字列連結（`kyslik/column-sortable` の sort 列は `$sortable` で制限されているか） | Critical |
| XSS | Blade の `{!! !!}`、`@json` に未検証データ、`resources/views/**` | Critical |
| CSRF | `VerifyCsrfToken::$except` の追加、`@csrf` 抜け | Critical |
| 認可 | `routes/web.php` の新規ルートが `auth` グループの外、`application/{application}/resend` 等の所有者チェック | Critical |
| mass assignment | `Application` は `$guarded=['id']`、`BoxToken` は `$guarded=[]`。`::create($request->all())` は禁止 | Critical |
| 秘密情報 | `secret_live_key` / `site_hash` / 暗号鍵 / SFTP・Box 認証情報を `Log::*`・レスポンス・Blade・CSV に出していないか。`Log::info` に申込データ全体を渡していないか | Critical |
| 個人情報 | `email` / `phone` / `ceo_birthday` を不要にログ出力 | Medium |
| ファイルアップロード | `mimes`/`max` 検証、保存パスにユーザー入力を使っていないか、`Storage::path()` のパストラバーサル | Critical〜High |
| 外部 URL への送信 | `site_url` は加盟店入力値。新たに任意 URL へ HTTP を発行する処理を足すなら scheme/host の検証 | High |
| ハードコード認証情報 | `BasicAuthMiddleware`（既存。差分範囲外なら backlog） | 差分内なら Critical |
| Sanctum | `auth:sanctum` ルートの追加時、トークン発行経路と `abilities` | High |

## 3. Laravel 正確性・品質観点

- **バリデーション**: 新しい入力は FormRequest（`app/Http/Requests/`）か `$request->validate()` を通す。
  `$request->foo` を検証なしで DB へ入れていないか
- **null 安全**: `$request->file()` / `->first()` / `->json('code')` の null を扱っているか（過去障害: `getClientOriginalExtension() on null`）
- **例外**: ユーザー操作（CSV 取込・再送信・パスワードリセット）で外部 I/O の例外が 500 にならず、画面にエラー表示されるか
- **外部 HTTP**: `Http::` に `timeout()` / `retry()` があるか（過去障害: SMTP 60 秒タイムアウト）。無ければ Medium
- **マイグレーション**: モデル/フォーム/CSV 列に追加した属性に対応する migration があるか。
  本番は `git pull` → `php artisan migrate` 運用なので、破壊的変更（列削除・型変更）は High
- **スケジュール/キュー**: `Console/Kernel` の変更は本番 cron（`php artisan schedule:run`）で動く。`QUEUE_CONNECTION=sync` 前提のコードか確認
- **N+1**: 申込一覧（`ApplicationController::index`）で `site` を `with()` しているか
- **設定**: 環境依存値（ホスト名、パス、認証情報）は `config/*.php` + `.env`。コードに直書きなら Medium
- **テスト**: `tests/Feature/` に変更した Controller/Service の Feature テストがあるか。
  外部 HTTP は `Http::fake()`、ストレージは `Storage::fake()`、画面テストは `$this->withoutVite()`
- **後方互換**: プラグインのバージョン差（2.9.13 / 2.9.15 / 2.9.16+）を壊していないか。
  `plugin_version` は診断用で、無い（旧バージョン）ケースを常に考慮する

## 4. スタイル（Low）

- `vendor/bin/pint --test <files>` の結果。修正はユーザーの承認後に `vendor/bin/pint <files>`
- 既存コードは `if($x){` 形式など Pint 非準拠の箇所が多い。**差分行以外の整形は指摘しない**
- 未使用 `use`、日本語メッセージの表記ゆれ（例: 「５万円」の全角数字）、typo（`$suevey_type`）は Low

## 5. baseline 候補（初回にユーザーへ確認する。勝手に許容扱いにしない）

差分範囲外で既に存在し、設計判断または暫定運用の可能性があるもの:

- `POST /api/applications` / `GET /api/applications` が認証なし（プラグインからの申込受付口）
- `routes/web.php` の `/box`, `/box/oauth` ルートが `auth` 無し・クロージャ実装
- `BasicAuthMiddleware` の認証情報ハードコード（`/dashboard` の追加保護）
- `Api\ApplicationController::create` がバリデーション無しで `$request->*` を保存
- `User` の `MustVerifyEmail` がコメントアウト（`docs/issues/smtp-connection-error.md`）
- `config/mail.php` の `timeout => null`
- `tests/Feature/*` の Vite manifest 依存による既存失敗

これらを baseline に入れるか backlog（Issue 化）にするかはユーザーの判断。
baseline に入れたものは以後のラウンドで指摘しない。

## 6. コマンド早見表

```bash
# 差分の基点
BASE=$(git merge-base origin/main HEAD)          # フィーチャーブランチ
git diff --stat $BASE                             # 対象ファイル一覧（working tree 含む）

# スナップショット（ブランチ・working tree を変更しない）
SNAP=$(git stash create "padiy-review R1 target"); echo ${SNAP:-$(git rev-parse HEAD)}
git diff <SNAP>                                   # スナップショット以降の差分

# 静的チェック
git diff --name-only $BASE -- '*.php' | xargs -I{} php -l {}
git diff --name-only $BASE -- '*.php' | xargs vendor/bin/pint --test

# テスト（MySQL 不要）
DB_CONNECTION=sqlite DB_DATABASE=":memory:" php artisan test
DB_CONNECTION=sqlite DB_DATABASE=":memory:" php artisan test --filter=CsvImportTest

# プラグイン側の受信コードを参照（ローカルは権限で読めない）
gh api repos/artisanworkshop/Japanized-for-WooCommerce/contents/includes/gateways/paidy/class-wc-paidy-apply-receiver.php \
  -q .content | base64 -d | less
```
