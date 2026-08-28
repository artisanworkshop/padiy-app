<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * POST /api/applications（プラグインの申込ウィザードからの受付口）のテスト。
 *
 * Api\ApplicationController は storage_path('box_data') 直書きで加盟店リスト CSV に追記するため、
 * 実ファイルを setUp で退避し tearDown で復元する。
 */
class ApiApplicationCreateTest extends TestCase
{
    use RefreshDatabase;

    private string $csvPath;
    private bool $csvExisted = false;
    private ?string $csvBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->csvPath = storage_path('box_data') . '/woocommerce_merchant_list.csv';
        $this->csvExisted = file_exists($this->csvPath) && !is_dir($this->csvPath);
        if ($this->csvExisted) {
            $this->csvBackup = file_get_contents($this->csvPath);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->csvPath)) {
            rmdir($this->csvPath);
        }
        if ($this->csvExisted) {
            file_put_contents($this->csvPath, $this->csvBackup);
        } elseif (file_exists($this->csvPath)) {
            unlink($this->csvPath);
        }
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'site_name' => 'テストショップ',
            'site_url' => 'https://example-shop.test',
            'trade_name' => 'テスト商店',
            'site_hash' => hash('sha256', 'test-site-hash'),
            'email' => 'merchant@example-shop.test',
            'phone' => '0312345678',
            'ceo' => '山田 太郎',
            'ceo_kana' => 'ヤマダ タロウ',
            'ceo_birthday' => '1980-01-01',
            'gmv_flag' => 1,
            'average_flag' => 1,
            'state' => 'a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6',
            'survey01' => 'yes',
            'survey08' => 'yes',
            'survey09' => 'no',
        ], $overrides);
    }

    public function test_plugin_version_is_sanitized_and_stored(): void
    {
        $response = $this->postJson('/api/applications', $this->payload([
            'plugin_version' => "2.9.16-beta.1 <b>x</b>\n" . str_repeat('9', 30),
        ]));

        $response->assertOk();
        $response->assertJsonPath('site_id', DB::table('sites')->where('site_url', 'https://example-shop.test')->value('id'));
        $this->assertMatchesRegularExpression('/^WC[0-9]{8}1$/', $response->json('application_id'));

        // 許可文字（英数字 . - +）以外を除去し 20 文字に切り詰める
        $stored = DB::table('applications')->where('application_id', $response->json('application_id'))->value('plugin_version');
        $this->assertSame(20, strlen($stored));
        $this->assertStringStartsWith('2.9.16-beta.1bxb9', $stored);
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z.\-+]+$/', $stored);
    }

    public function test_application_from_old_plugin_stores_null_plugin_version(): void
    {
        $withoutVersion = $this->postJson('/api/applications', $this->payload());
        $withoutVersion->assertOk();
        $this->assertNull(DB::table('applications')->where('application_id', $withoutVersion->json('application_id'))->value('plugin_version'));

        $arrayVersion = $this->postJson('/api/applications', $this->payload(['plugin_version' => ['2.9.16']]));
        $arrayVersion->assertOk();
        $this->assertNull(DB::table('applications')->where('application_id', $arrayVersion->json('application_id'))->value('plugin_version'));
    }

    public function test_application_id_increments_from_latest_application(): void
    {
        $site_id = DB::table('sites')->insertGetId([
            'site_name' => '既存ショップ',
            'site_url' => 'https://existing-shop.test',
            'trade_name' => '既存商店',
            'site_hash' => hash('sha256', 'existing'),
            'created_at' => Carbon::now()->subDay(),
            'updated_at' => Carbon::now()->subDay(),
        ]);
        DB::table('applications')->insert([
            'application_id' => 'WC000005711',
            'site_id' => $site_id,
            'email' => 'existing@existing-shop.test',
            'phone' => '0300000000',
            'ceo' => '既存 太郎',
            'ceo_kana' => 'キゾン タロウ',
            'ceo_birthday' => '1970-01-01',
            'gmv_flag' => 0,
            'average_flag' => 0,
            'created_at' => Carbon::now()->subDay(),
            'updated_at' => Carbon::now()->subDay(),
        ]);

        $response = $this->postJson('/api/applications', $this->payload());

        $response->assertOk();
        $this->assertSame('WC000005721', $response->json('application_id'));
    }

    public function test_merchant_list_csv_failure_still_returns_200_so_plugin_keeps_state_token(): void
    {
        if ($this->csvExisted) {
            $this->markTestSkipped('実際の加盟店リスト CSV が存在する環境では追記失敗を再現しない。');
        }
        // CSV のパスにディレクトリを置くと fopen が失敗し appendMerchantListCsv() が例外を投げる
        mkdir($this->csvPath);

        $response = $this->postJson('/api/applications', $this->payload());

        // 5xx を返すとプラグインが state トークンを破棄して後の審査結果送信が 403 になるため、200 が契約
        $response->assertOk();
        $this->assertDatabaseHas('applications', [
            'application_id' => $response->json('application_id'),
            'state' => 'a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6',
        ]);
    }
}
