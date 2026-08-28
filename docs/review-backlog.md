# レビューバックログ（今回対応しない指摘）
| 追記日 | ID | 重大度 | 場所 | 内容 | 起票状況 |
|---|---|---|---|---|---|
| 2026-08-28 | R1-X1 | High | routes/auth.php:15-18 | `/register` が開放されており、任意のユーザーが管理画面（申込一覧・再送信・CSV 取込）にアクセス可能。前段保護の有無を確認し、無ければ登録ルートを閉じる | 未起票（要確認） |
| 2026-08-28 | R1-X2 | High | app/Http/Controllers/CsvImportController.php:22-24 | ファイルバリデーションがコメントアウトのまま。未選択送信で 500 | docs/issues/csv-import-null-error.md |
| 2026-08-28 | R1-X3 | Medium | app/Http/Controllers/CsvImportController.php:169 | 行ループの `catch (\Exception)` を `\Throwable` に広げ、1行の `\Error` で取込全体が 500 にならないようにする | 未起票 |
| 2026-08-28 | R1-L1 | Low | PHP 差分 7ファイル | Pint スタイル指摘（array_syntax, concat_space 等）。一括整形するかは要判断 | 未起票 |
| 2026-08-28 | R1-L2 | Low | app/Http/Controllers/ApplicationController.php:29 | `DB::table('sites')` ではなく `$application->site` リレーションを使う | 未起票 |
| 2026-08-28 | R1-L3 | Low | app/Services/PaidyCallbackSender.php:68 | `Http::timeout(15)` 程度を明示（既定 30 秒 × CSV 行数の待ちを短縮） | 未起票 |
| 2026-08-28 | R1-L4 | Low | app/Services/PaidyCallbackSender.php:97-102 | `paidy_invalid_state` の案内文に 2.9.16+ での署名不一致（site_hash 相違・時刻ずれ）の可能性を併記 | 未起票 |
| 2026-08-28 | R2-L1 | Low | app/Http/Controllers/Api/ApplicationController.php:216 | 加盟店リスト CSV のパスを `storage_path()` 直書きから `Storage::disk('local')`/config 経由にし、テストで差し替え可能にする（ApiApplicationCreateTest の実ファイル退避を不要にする） | 未起票 |
