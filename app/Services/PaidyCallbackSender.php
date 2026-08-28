<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * 加盟店サイト（Japanized for WooCommerce）の受信エンドポイントへ審査結果と API キーを送信する。
 *
 * CSV 取込（CsvImportController）と申込一覧の「再送信」（ApplicationController::resend）で共用する。
 *
 * 認証は 2 系統:
 *  - state トークン（申込時にプラグインが発行。2.9.13–2.9.14 は 2 日で失効、2.9.15 は 90 日）
 *  - HMAC-SHA256 署名（`<timestamp>.<raw body>` を site_hash で署名。プラグイン 2.9.16+ が検証）
 * 両方を常に送り、受信側はどちらか一方が通れば受理する。
 */
class PaidyCallbackSender
{
    public const RECEIVE_PATH = 'wp-json/paidy-receiver/v1/receive/';

    /**
     * @param string      $applicationId 申込番号（WC000000571 など）
     * @param string      $paidyStatus   approved / rejected / canceled
     * @param array       $keys          public_live_key, secret_live_key, public_test_key, secret_test_key（平文）
     * @param object      $site          site_url, site_hash を持つオブジェクト
     * @param string|null $state         申込時の state トークン（無ければ null）
     * @param string|null $pluginVersion 申込時のプラグインバージョン（エラー文の補足に使う）
     * @return array{success: bool, status: int|null, site_url: string, error: string|null}
     */
    public function send(
        string $applicationId,
        string $paidyStatus,
        array $keys,
        object $site,
        ?string $state = null,
        ?string $pluginVersion = null
    ): array {
        $siteUrl = rtrim($site->site_url, '/') . '/';

        // site_hash から AES-256-CBC の鍵と IV を導出（受信側と同じ導出式）
        $method = 'AES-256-CBC';
        $aesKey = substr(hash('sha256', $site->site_hash), 0, 32);
        $aesIv = substr(hash('sha256', $site->site_hash . 'iv'), 0, 16);

        $payload = [
            'application_id' => $applicationId,
            'paidy_status' => $paidyStatus,
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
        foreach (['public_live_key', 'secret_live_key', 'public_test_key', 'secret_test_key'] as $field) {
            $payload[$field] = base64_encode(
                openssl_encrypt((string) ($keys[$field] ?? ''), $method, $aesKey, OPENSSL_RAW_DATA, $aesIv)
            );
        }
        // state トークンは従来通り送る（プラグイン 2.9.15 以前はこれだけで検証する）。
        if (!empty($state)) {
            $payload['state'] = $state;
        }

        // 本文を site_hash で HMAC-SHA256 署名する。署名対象の文字列と送信本文は完全に一致させること。
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $site->site_hash);

        try {
            $response = Http::withHeaders([
                'X-Paidy-Receiver-Timestamp' => $timestamp,
                'X-Paidy-Receiver-Signature' => $signature,
            ])->withBody($body, 'application/json')
              ->post($siteUrl . self::RECEIVE_PATH);
        } catch (\Throwable $e) {
            Log::warning('Paidy callback send failed: ' . $applicationId . ' ' . $e->getMessage());
            return [
                'success' => false,
                'status' => null,
                'site_url' => $siteUrl,
                'error' => '加盟店サイトへ接続できませんでした: ' . $e->getMessage(),
            ];
        }

        if ($response->successful()) {
            return ['success' => true, 'status' => $response->status(), 'site_url' => $siteUrl, 'error' => null];
        }

        return [
            'success' => false,
            'status' => $response->status(),
            'site_url' => $siteUrl,
            'error' => $this->translateError($response->json('code'), $response->status(), $response->body(), $pluginVersion),
        ];
    }

    private function translateError(mixed $code, int $status, string $body, ?string $pluginVersion): string
    {
        // 受信側が想定外の型（数値・配列）の code を返しても TypeError にせず、汎用メッセージに落とす。
        if (!is_string($code)) {
            $code = null;
        }
        if ($code === 'paidy_invalid_state') {
            return '加盟店サイト側のstateトークンが期限切れまたは消滅しており、プラグインが署名検証に未対応（2.9.16 未満）です。'
                . '加盟店にプラグイン最新版への更新を案内し、更新後にこの申込を再送信してください（再申込は不要）。'
                . '更新できない場合は手動キー設定を案内してください。'
                . '（申込時のプラグイン: ' . ($pluginVersion ?: '不明（2.9.15 以前）') . '）';
        }
        if ($code === 'paidy_not_configured') {
            return '加盟店サイトのPaidy受信設定（site_hash）が未設定です。';
        }
        return 'HTTPステータス: ' . $status . ' - ' . $body;
    }
}
