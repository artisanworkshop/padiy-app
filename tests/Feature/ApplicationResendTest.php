<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApplicationResendTest extends TestCase
{
    use RefreshDatabase;

    private const SITE_HASH = 'aB3$dE6&gH9(kL2-';
    private const RECEIVE_URL = 'https://example-shop.test/wp-json/paidy-receiver/v1/receive/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function seedApplication(?string $paidyStatus, ?string $state = null): int
    {
        $site_id = DB::table('sites')->insertGetId([
            'site_name' => 'テストショップ',
            'site_url' => 'https://example-shop.test',
            'trade_name' => 'テスト商店',
            'site_hash' => self::SITE_HASH,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return DB::table('applications')->insertGetId([
            'application_id' => 'WC000000571',
            'site_id' => $site_id,
            'state' => $state,
            'plugin_version' => '2.9.14',
            'email' => 'merchant@example-shop.test',
            'phone' => '0312345678',
            'ceo' => '山田 太郎',
            'ceo_kana' => 'ヤマダ タロウ',
            'ceo_birthday' => '1980-01-01',
            'gmv_flag' => 0,
            'average_flag' => 0,
            'paidy_status' => $paidyStatus,
            'public_live_key' => $paidyStatus === 'approved' ? 'pk_live_xxx' : null,
            'secret_live_key' => $paidyStatus === 'approved' ? 'sk_live_xxx' : null,
            'public_test_key' => $paidyStatus === 'approved' ? 'pk_test_xxx' : null,
            'secret_test_key' => $paidyStatus === 'approved' ? 'sk_test_xxx' : null,
            'set_status' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    public function test_resend_sends_signed_callback_from_stored_keys_and_marks_success(): void
    {
        Http::fake([self::RECEIVE_URL => Http::response(['success' => true], 200)]);
        $id = $this->seedApplication('approved');

        $response = $this->actingAs(User::factory()->create())
            ->post(route('application.resend', $id));

        $response->assertRedirect(route('application.index'));
        $response->assertSessionHas('status');

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);
            $timestamp = $request->header('X-Paidy-Receiver-Timestamp')[0] ?? '';
            $signature = $request->header('X-Paidy-Receiver-Signature')[0] ?? '';
            $expected = hash_hmac('sha256', $timestamp . '.' . $request->body(), self::SITE_HASH);

            // 復号して DB 保存済みのキーが送られていることを確認する
            $aesKey = substr(hash('sha256', self::SITE_HASH), 0, 32);
            $aesIv = substr(hash('sha256', self::SITE_HASH . 'iv'), 0, 16);
            $decrypted = openssl_decrypt(base64_decode($payload['public_live_key']), 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA, $aesIv);

            return $request->url() === self::RECEIVE_URL
                && $payload['application_id'] === 'WC000000571'
                && $payload['paidy_status'] === 'approved'
                && !array_key_exists('state', $payload)
                && $decrypted === 'pk_live_xxx'
                && hash_equals($expected, $signature);
        });

        $this->assertDatabaseHas('applications', ['id' => $id, 'set_status' => 1]);
    }

    public function test_resend_reports_invalid_state_with_plugin_version_and_keeps_set_status_zero(): void
    {
        Http::fake([
            self::RECEIVE_URL => Http::response(['code' => 'paidy_invalid_state', 'data' => ['status' => 403]], 403),
        ]);
        $id = $this->seedApplication('approved', 'a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6');

        $response = $this->actingAs(User::factory()->create())
            ->post(route('application.resend', $id));

        $response->assertRedirect(route('application.index'));
        $response->assertSessionHas('error', function ($message) {
            return str_contains($message, 'stateトークンが期限切れまたは消滅')
                && str_contains($message, '2.9.14');
        });

        $this->assertDatabaseHas('applications', ['id' => $id, 'set_status' => 0]);
    }

    public function test_resend_is_refused_before_review_result_is_registered(): void
    {
        Http::fake();
        $id = $this->seedApplication(null);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('application.resend', $id));

        $response->assertRedirect(route('application.index'));
        $response->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_non_string_error_code_from_merchant_site_does_not_crash(): void
    {
        // 受信側が想定外の型の code を返しても TypeError で 500 にせず、汎用エラー文で失敗扱いにする
        Http::fake([
            self::RECEIVE_URL => Http::response(['code' => 403, 'message' => 'Forbidden'], 403),
        ]);
        $id = $this->seedApplication('approved');

        $response = $this->actingAs(User::factory()->create())
            ->post(route('application.resend', $id));

        $response->assertRedirect(route('application.index'));
        $response->assertSessionHas('error', function ($message) {
            return str_contains($message, 'HTTPステータス: 403');
        });
        $this->assertDatabaseHas('applications', ['id' => $id, 'set_status' => 0]);
    }

    public function test_resend_requires_authentication(): void
    {
        $id = $this->seedApplication('approved');

        $this->post(route('application.resend', $id))->assertRedirect(route('login'));
    }
}
