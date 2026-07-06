# CSV インポート null エラー 調査報告・対応計画

**発生日時**: 2026-06-03 14:48:47  
**検知方法**: production.log  
**発生ユーザー**: userId: 2  
**優先度**: 中（特定操作で500エラー、データ破損はなし）

---

## エラー内容

```
Call to a member function getClientOriginalExtension() on null
at /home/kusanagi/paidy_artws/padiy-app/app/Http/Controllers/CsvImportController.php:31
```

**発生箇所**:
- `CsvImportController::import()` (行23) → `CsvImportController::processFile()` (行31)

---

## 原因

`CsvImportController.php:19` のバリデーションがコメントアウトされており、**ファイルを選択せずにフォームを送信してもバリデーションを通過**してしまう。

```php
// 現在のコード（問題あり）
$request->validate([
//    'file' => 'required|file|mimes:csv,xlsx,xls|max:2048', // コメントアウト中
]);
```

その結果、`processFile()` 内の `$request->file('file')` が `null` を返し、
31行目の `$file->getClientOriginalExtension()` で null に対するメソッド呼び出しエラーが発生する。

---

## 影響範囲

| 操作 | 影響 |
|------|------|
| ファイルを選択してインポート | **影響なし**（正常動作） |
| ファイルを選択せずに送信 | **500エラー**（サーバーエラー画面が表示される） |

データの破損・不正書き込みは発生しない（エラー発生がファイル処理より前のため）。

---

## 対応タスク

### 優先度: 高

- [ ] **バリデーションのコメントアウトを解除する**  
  `app/Http/Controllers/CsvImportController.php:19`

  ```php
  // 修正後
  $request->validate([
      'file' => 'required|file|mimes:csv,xlsx,xls|max:2048',
  ]);
  ```

  > **注意**: コメントアウトした経緯を確認すること。xlsx/xls で mimes チェックが通らなかった場合は、
  > 下記の `mimetypes` を使った代替案を検討する。

  **代替案（xlsx/xls を確実に受け付けたい場合）**：

  ```php
  $request->validate([
      'file' => 'required|file|mimetypes:text/csv,text/plain,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet|max:2048',
  ]);
  ```

### 優先度: 中

- [ ] **`processFile()` 内に null チェックを追加する（防御的実装）**  
  バリデーションとは別に、`processFile()` 内でも `$file` が null の場合に適切なエラーを返す

  ```php
  private function processFile(Request $request)
  {
      $file = $request->file('file');
      if (!$file) {
          return view('import-csv.show', compact('result_msg', 'error_msg', 'api_success_list', 'api_error_list'))
              ->withErrors(['file' => 'ファイルが選択されていません。']);
      }
      // ...
  }
  ```

---

## 関連ファイル

- `app/Http/Controllers/CsvImportController.php`（バリデーション: 行18-20、null参照: 行28-31）
