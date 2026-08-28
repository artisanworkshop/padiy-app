<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 申込時のプラグインバージョンを記録する。
     * state トークンの扱いがバージョンで異なる（≤2.9.12: 未送信 / 2.9.13–2.9.14: 2日 transient /
     * 2.9.15: 90日 option / 2.9.16+: 署名検証あり）ため、CSV 取込失敗時の切り分けに使う。
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('plugin_version', 20)->nullable()->default(null)->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('plugin_version');
        });
    }
};
