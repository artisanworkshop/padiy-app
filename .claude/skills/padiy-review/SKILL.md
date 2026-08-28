---
name: padiy-review
description: >
  padiy-app（Paidy 加盟店申込の中継 Laravel アプリ）専用の収束型コードレビューループ。
  このリポジトリで「レビューして」「再レビュー」「レビューを回して」「マージ前チェック」
  「review-loop」「padiy-review」と言われたら、汎用の review-loop ではなく必ずこのスキルを使う。
  「新規 Critical/High ゼロ」を APPROVE 条件とし、最大3ラウンドで必ず終了する。
  Laravel 10 / Paidy・Japanized for WooCommerce 連携の契約に基づく重大度判定、
  SQLite でのテスト実行、コミットせずワーキングツリーに残す運用（stash スナップショット）を組み込み済み。
---

# padiy-review — padiy-app 専用の収束型レビューループ

「指摘ゼロ」を目指さない。**新規 Critical/High がゼロになったら APPROVE で終了**する
レビューループを、最大3ラウンドで実行する。汎用 `review-loop` をこのリポジトリ向けに
特化したもので、フローは同じ・判定基準と手順だけがこのアプリ向けになっている。

## このリポジトリの前提（レビュー前に必ず把握する）

| 項目 | 内容 |
|---|---|
| スタック | Laravel 10 / PHP ^8.1 / Blade + Alpine.js + Tailwind (Vite) / MySQL(本番) |
| 役割 | WooCommerce プラグイン (Japanized for WooCommerce) からの Paidy 申込を受け、CSV を Paidy へ SFTP/Box 転送し、審査結果と API キーを加盟店サイトへ暗号化して送り返す中継サーバー |
| コーディング規約 | **Laravel Pint（PSR-12 系）**。WordPress Coding Standards は適用しない |
| 差分の基点 | `origin/main`（= artisanworkshop/padiy-app）。作業は `fix/*` 等のブランチか、まれに `main` 直接 |
| コミット | **ユーザーの明示的な指示があるまで commit / push しない**（グローバル CLAUDE.md）。修正はワーキングツリーに残す |
| テスト | `DB_CONNECTION=sqlite DB_DATABASE=":memory:" php artisan test`（MySQL 不要）。既知の既存失敗あり（後述） |
| 外部契約 | プラグイン受信 API・Paidy CSV 列順・申込番号形式など。詳細は `references/review-checklist.md` |

レビュー観点・重大度の具体例・外部契約の一覧は **`references/review-checklist.md` を先に読む**こと。

## 全体フロー

```
R1: フルレビュー → Critical/High/Medium を修正（ワーキングツリー上）
R2: R1修正差分のみ再レビュー（検証モード） → Critical/High のみ修正
R3: (R2でCritical/Highが出た場合のみ) 修正 → 最終判定
その後: ユーザー指示でコミット → PR (artisanworkshop/main 宛) → Copilot を独立最終ゲート
```

- Low・差分範囲外の指摘は**修正せず** `docs/review-backlog.md` へ追記する
- `docs/review-baseline.md` に記載済みの許容項目は**指摘しない**
- R3 終了時点で Critical/High が残る場合のみ、ユーザーに判断を委ねる（無限ループ禁止）

## レビュー対象範囲の決定

コミットせずに進める前提なので、**「コミット済み差分 + ワーキングツリーの未コミット差分」を常に1つの対象**として扱う。

1. `git branch --show-current` でブランチ名を取得
2. 基点を決める:
   - フィーチャーブランチ: `BASE=$(git merge-base origin/main HEAD)`
   - `main` で `origin/main` より進んでいる: `BASE=origin/main`
   - `main` で `origin/main` と一致し、未コミット変更のみ: `BASE=HEAD`
   - 上記いずれにも差分が無い: レビュー対象が無い。範囲（例: `HEAD~3`）をユーザーに確認する
3. **未追跡ファイルを先に取り込む**。`git diff` も `git stash create` も未追跡ファイルを含まないため、
   新規の Controller / Service / migration / テストがレビューとスナップショットから漏れる。
   ラウンド開始時（および修正後のスナップショット前）に必ず:
   ```bash
   git ls-files --others --exclude-standard     # 未追跡ファイルの一覧を R<n>.md に記録
   git add -A                                    # 内容は変えずインデックスに載せる（コミットはしない）
   ```
   `git add -N`（intent-to-add）は `git diff` には効くが `git stash create` が
   「Entry not uptodate. Cannot merge」で失敗するので使わない。
   `git add -A` でレビュー対象外のゴミ（一時ファイル等）まで載る場合は、その旨をユーザーに伝えて除外する
4. R1 の対象差分は `git diff $BASE`（ステージ済み + 未ステージ + 手順3で取り込んだ新規ファイル）
5. **ラウンド境界のスナップショット**: 各ラウンドの「レビュー対象」と「修正後」の状態を
   `git stash create` で作る（ブランチもワーキングツリーも変更しない）:
   ```bash
   git add -A && SNAP=$(git stash create "padiy-review R1 target") ; echo ${SNAP:-$(git rev-parse HEAD)}
   ```
   空文字が返る（= ワーキングツリーがクリーン）場合は `HEAD` を使う。
   得られた sha を R<n>.md に記録し、次ラウンドの差分起点にする（`git diff <前ラウンド修正後SNAP>`。
   この diff には前ラウンド以降に追加した新規ファイルも含まれる）。
   stash create のオブジェクトは同一セッション内で参照できれば十分。消えていた場合は
   R<n>.md に記録した**修正ファイル一覧**を対象に `git diff $BASE -- <files>` で代替する
6. ユーザーが途中でコミットを指示した場合は、以降の起点をそのコミット sha に置き換えてよい

## ラウンドの自動判定

レビュー結果は `docs/reviews/<dir>/R<n>.md` に保存する。`<dir>` はブランチ名の `/` を `-` に
置換したもの（例: `fix/signed-callback-resend` → `fix-signed-callback-resend`）。
`main` 直接作業の場合は `main-<YYYYMMDD>`。

1. `docs/reviews/<dir>/` 内の既存 `R*.md` を確認し、**最新ラウンドの判定行**（`- 判定:`）で決める
   - `R*.md` が無ければ **R1**
   - 最新が `R1.md`: 判定が APPROVE かつ「修正ファイル」が無い（修正ゼロ）なら**完了**。
     それ以外（修正を行った、または CHANGES REQUESTED）なら **R2**
   - 最新が `R2.md`: 判定が APPROVE なら**完了**。新規 Critical/High が出て修正した場合のみ **R3**
   - 最新が `R3.md`: **完了**（判定にかかわらず。未収束なら R3.md にその旨が記録されている）
   - 完了済みの場合は再実行せず、ユーザーに
     「ループは完了しています（最終判定: <R<n> の判定>）。新規ループを始めるなら reset を指示してください」と伝える
2. ユーザーが「reset」「最初から」と言った場合は `R*.md` を `archive/` へ移動して R1 から開始
3. 各ラウンド開始時に `git rev-parse HEAD` とスナップショット sha を R<n>.md の冒頭に明記する

## 重大度定義（全ラウンド共通）

| 重大度 | 定義（padiy-app 向け） |
|---|---|
| **Critical** | セキュリティ脆弱性（SQLi: `DB::raw`/`whereRaw` への未バインド入力、XSS: `{!! !!}` への未エスケープ出力、CSRF: `VerifyCsrfToken::$except` の追加、認証: `auth` ミドルウェア無しの新規 web ルート、mass assignment: `$request->all()` を `$guarded=[]` モデルへ）、**秘密情報の漏洩**（`secret_live_key` / `site_hash` / 暗号鍵をログ・レスポンス・CSV へ平文出力）、**暗号・署名の破壊**（AES 鍵/IV 導出式・`OPENSSL_RAW_DATA`・HMAC 署名対象文字列の変更）、データ破壊（applications/sites の破壊的マイグレーション、加盟店リスト CSV の上書き・消失） |
| **High** | **外部契約の破壊**（プラグイン受信 API のペイロード/ヘッダー/パス、state トークン形式、Paidy CSV の列順、申込番号形式、旧プラグイン互換）、明確なバグ（null 参照、未定義変数、例外未捕捉で 500）、新規入力のバリデーション欠落、`POST /api/applications` で DB 登録後に 5xx を返す変更（孤児 state トークンを生む）、マイグレーションとモデル/フォームの不整合、スケジュール・SFTP・Box 転送の動作破壊、既存テストの失敗 |
| **Medium** | N+1、外部 HTTP 呼び出しの timeout 未設定、変更した Controller/Service にテストが無い、個人情報（email/phone/生年月日）の不要なログ出力、ハードコードされた設定値（`.env`/`config` へ逃がすべきもの）、エラーハンドリング不足（CSV 取込で1行の失敗が全体を止める等） |
| **Low** | Pint スタイル違反、命名、コメント、日本語メッセージの表記ゆれ、リファクタ提案、未使用 `use` |

判定に迷ったら低い方に倒す（重大度インフレを防ぐ）。
**既存コードに元からある問題**（例: `BasicAuthMiddleware` のハードコード認証情報）は差分範囲外なら
指摘欄ではなく「対象外指摘」→ backlog へ。

## R1: フルレビュー

対象: 上記で決めた `git diff $BASE`。

手順:
1. `docs/review-baseline.md` を読む（無ければ後述のテンプレートで作成）
2. `references/review-checklist.md` の観点で差分をレビューし、`docs/reviews/<dir>/R1.md` に出力
3. 自動チェックを実行し、結果を R1.md の「自動チェック」欄に記録する:
   ```bash
   # 構文
   git diff --name-only $BASE -- '*.php' | xargs -I{} php -l {}
   # スタイル（違反は Low。--test なので書き換えない）
   git diff --name-only $BASE -- '*.php' | xargs vendor/bin/pint --test
   # テスト
   DB_CONNECTION=sqlite DB_DATABASE=":memory:" php artisan test
   ```
   既知の既存失敗（差分と無関係なら指摘しない）:
   - 「screen can be rendered」系 — `public/build/manifest.json` 未ビルド。自作テストは `setUp` で `$this->withoutVite()`
   - PHP 8.4 + 旧 vendor の「Implicitly marking parameter as nullable」deprecation ノイズ
4. 差分範囲**外**の既存コードへの指摘は「対象外指摘」セクションに分離する
5. Critical/High が1件も無ければ冒頭に **APPROVE** と明記する

出力フォーマット:

```markdown
# R1 レビュー結果
- 対象: <BASE sha>...<HEAD sha> + working tree（対象SNAP: <sha>）
- 判定: APPROVE / CHANGES REQUESTED
- 自動チェック: php -l OK / pint N件 / test X passed, Y failed（既知: Z）

## 指摘
### [R1-1][Critical][app/Services/PaidyCallbackSender.php:42]
内容の説明（1〜3行）
**修正方針:** 1行で

## 対象外指摘（差分範囲外）
- [R1-X1][Medium][...] ...

## Low（backlogへ）
- [R1-L1][Low][...] ...
```

6. レビュー後、**Critical/High/Medium をすべて修正**する（ワーキングツリー上。コミットしない）。
   各修正には R1.md の該当指摘に「→ 修正済み: <ファイル:行>」を追記する
7. 修正後にテストを再実行し、新規失敗が無いことを確認する
8. Low と対象外指摘は `docs/review-backlog.md` に追記する（修正しない）
9. R1.md 末尾に以下を追記する:
   ```
   修正後SNAP: <git stash create の sha>
   修正ファイル: app/..., app/...
   ```

## R2: 検証再レビュー

対象: `git diff <R1 の対象SNAP>`（= R1 の修正で変更された差分のみ）。

これは**自由探索ではなく検証タスク**である:

1. R1.md を読み、Critical/High/Medium の各指摘IDについて **解消 / 未解消** を1件ずつ判定する
2. R1 の修正によって**新たに混入した**問題のみ、新規指摘として [R2-連番] で報告する
   （特に: 修正で外部契約を壊していないか、テストが通るか）
3. 禁止事項:
   - R1 で指摘済み・許容済み・backlog 送りにした項目を別の表現で再指摘しない
   - 差分起点より前から存在するコードへの新規指摘をしない（見つけたら backlog へ）
4. テストを再実行し結果を記録する
5. 新規 Critical/High がゼロなら冒頭に **APPROVE** → **ループ終了**
6. 新規 Critical/High があれば修正し、修正後SNAP を記録して R3 へ

出力は R1 と同フォーマットで `R2.md` に保存。冒頭に R1 指摘の解消判定表を付ける:

```markdown
## R1 指摘の解消判定
| ID | 重大度 | 判定 |
|---|---|---|
| R1-1 | Critical | 解消 |
| R1-2 | High | 未解消 → 下記 R2-1 参照 |
```

## R3: 最終ラウンド（R2 で Critical/High が出た場合のみ）

R2 と同じ検証モードで実行し、対象は R2 修正差分のみ。

- 新規 Critical/High ゼロ → **APPROVE、ループ終了**
- まだ Critical/High が残る場合 → **修正せずに停止**し、ユーザーに報告:
  「3ラウンドで収束しませんでした。設計レベルの問題の可能性があります。
  該当指摘: [一覧]。個別に対応方針を相談してください」

R3 を超えてループを続けてはならない。

## ループ終了後

APPROVE が出たら以下をユーザーに提示する:

1. 全ラウンドのサマリ（指摘数・修正数・backlog送り数・テスト結果）
2. **ワーキングツリーに残っている変更の一覧**（`git status --short`）と、
   コミットするかどうかの判断依頼。コミットメッセージ案（英語、対応した指摘IDを含める）を添える
3. 次のステップの提案:
   - `start-task` スキルで issue → コミット → push → PR（`artisanworkshop/main` 宛）
   - PR 上の Copilot レビューを独立最終ゲートとし、指摘は `fix-copilot-review` スキルで処理。
     その際「最終ゲートで出た指摘は Critical/High のみ修正、他は backlog」というルールを添える
   - プラグイン側（Japanized for WooCommerce）にも変更が必要な契約変更が含まれる場合は、
     その旨と該当ファイル（`includes/gateways/paidy/class-wc-paidy-apply-receiver.php` 等）を明記する

## docs/review-baseline.md（無ければ初回に作成）

```markdown
# レビューベースライン（許容済み指摘リスト）
レビュー時、ここに記載された項目は指摘しないこと。

## 形式
- [カテゴリ] 対象範囲 — 許容理由

## 許容項目
<!-- 例:
- [Auth] POST /api/applications が認証なし — WooCommerce プラグインからの申込受付口のため（site_hash で後続を検証）
- [Legacy] routes/web.php の box ルートが auth 無し — 手動運用の暫定ルート
-->
```

初回作成時は例をコメントとして残し、実際の許容項目は**ユーザーに確認して**追記する。
`references/review-checklist.md` の「baseline 候補」も確認の材料にする。

## docs/review-backlog.md（無ければ初回に作成）

```markdown
# レビューバックログ（今回対応しない指摘）
| 追記日 | ID | 重大度 | 場所 | 内容 | 起票状況 |
|---|---|---|---|---|---|
```

ラウンドごとに追記する。GitHub Issue 化するかはユーザーに確認する
（起票する場合は `gh issue create`。`docs/issues/` の調査報告形式に合わせてもよい）。

## 注意事項

- レビューと修正は同一セッションで行う（stash スナップショットとコンテキスト維持が収束の前提）。
  セッションを跨ぐ場合は `--resume` で再開してからこのスキルを使う
- 他セッションが同じワーキングツリーで作業している可能性がある。ラウンド開始時に
  `git status --short` を確認し、自分の修正ではない変更が増えていたらユーザーに確認する
- レビュー範囲の拡大（「ついでに全体も見て」等）を求められた場合は、
  本ループとは別タスクとして扱い、ループの差分スコープを崩さない
- プラグイン側リポジトリのローカルコピーは macOS 権限で読めない。契約の突き合わせが必要なら
  `gh api repos/artisanworkshop/Japanized-for-WooCommerce/contents/<path>` で参照する
