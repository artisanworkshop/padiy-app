# レビューベースライン（許容済み指摘リスト）
レビュー時、ここに記載された項目は指摘しないこと。

## 形式
- [カテゴリ] 対象範囲 — 許容理由

## 許容項目
<!-- 以下は候補。ユーザー確認のうえコメントを外して有効化する
- [Auth] POST /api/applications が認証なし — WooCommerce プラグインからの申込受付口のため（後続は site_hash で暗号化・署名）
- [Auth] routes/auth.php の /register 開放 — 本番は前段の Basic 認証で保護している場合のみ
- [Legacy] routes/web.php の /box, /box/oauth ルートが auth 無し・クロージャ実装 — 手動運用の暫定ルート
- [Legacy] BasicAuthMiddleware の認証情報ハードコード — /dashboard の追加保護
- [Test] tests/Feature/* の Vite manifest 未ビルドによる既存失敗 7件 — ローカルでは public/build を生成しない運用
- [Style] 既存コードの Pint 非準拠（if(...){ 形式等） — 差分行以外は整形しない
-->
