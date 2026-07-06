<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_STATE = 'a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6';
    private const SITE_URL = 'https://example-shop.test/';
    private const RECEIVE_URL = 'https://example-shop.test/wp-json/paidy-receiver/v1/receive/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake();
    }

    private function seedApplication(?string $state): void
    {
        $site_id = DB::table('sites')->insertGetId([
            'site_name' => 'テストショップ',
            'site_url' => self::SITE_URL,
            'trade_name' => 'テスト商店',
            'site_hash' => hash('sha256', 'test-site-hash'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DB::table('applications')->insert([
            'application_id' => 'WC000000561',
            'site_id' => $site_id,
            'state' => $state,
            'email' => 'merchant@example-shop.test',
            'phone' => '0312345678',
            'ceo' => '山田 太郎',
            'ceo_kana' => 'ヤマダ タロウ',
            'ceo_birthday' => '1980-01-01',
            'gmv_flag' => 0,
            'average_flag' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function importCsv()
    {
        $csv = implode("\n", [
            'application_id,status,public_live_key,secret_live_key,public_test_key,secret_test_key',
            'WC000000561,approved,pk_live_xxx,sk_live_xxx,pk_test_xxx,sk_test_xxx',
        ]);
        $file = UploadedFile::fake()->createWithContent('paidy_result.csv', $csv);

        return $this->actingAs(User::factory()->create())
            ->post('import-csv', ['file' => $file]);
    }

    public function test_application_without_state_is_skipped_with_manual_action_message(): void
    {
        Http::fake();
        $this->seedApplication(null);

        $response = $this->importCsv();

        $response->assertOk();
        Http::assertNothingSent();

        $api_error_list = $response->viewData('api_error_list');
        $this->assertCount(1, $api_error_list);
        $this->assertSame('WC000000561', $api_error_list[0]['application_id']);
        $this->assertStringContainsString('stateトークン未保存', $api_error_list[0]['error']);

        $this->assertDatabaseHas('applications', [
            'application_id' => 'WC000000561',
            'set_status' => 0,
        ]);
    }

    public function test_invalid_state_response_is_translated_to_admin_message(): void
    {
        Http::fake([
            self::RECEIVE_URL => Http::response([
                'code' => 'paidy_invalid_state',
                'message' => 'Invalid or missing state token for Paidy onboarding.',
                'data' => ['status' => 403],
            ], 403),
        ]);
        $this->seedApplication(self::VALID_STATE);

        $response = $this->importCsv();

        $response->assertOk();

        $api_error_list = $response->viewData('api_error_list');
        $this->assertCount(1, $api_error_list);
        $this->assertStringContainsString('stateトークンが期限切れまたは消滅', $api_error_list[0]['error']);

        $this->assertDatabaseHas('applications', [
            'application_id' => 'WC000000561',
            'set_status' => 0,
        ]);
    }

    public function test_successful_import_sends_state_and_marks_set_status(): void
    {
        Http::fake([
            self::RECEIVE_URL => Http::response(['success' => true], 200),
        ]);
        $this->seedApplication(self::VALID_STATE);

        $response = $this->importCsv();

        $response->assertOk();

        Http::assertSent(function ($request) {
            return $request->url() === self::RECEIVE_URL
                && $request['application_id'] === 'WC000000561'
                && $request['state'] === self::VALID_STATE;
        });

        $api_success_list = $response->viewData('api_success_list');
        $this->assertCount(1, $api_success_list);

        $this->assertDatabaseHas('applications', [
            'application_id' => 'WC000000561',
            'set_status' => 1,
        ]);
    }
}
