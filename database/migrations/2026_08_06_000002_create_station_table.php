<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立站台表（station）
 *
 * 每個站台代表一個租用主系統的客人（商戶），
 * 記錄站台基本資訊、API 設定，站台詳細設定從 API 同步後存入 settings JSON。
 */
class CreateStationTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('station')) {
            return;
        }

        Schema::create('station', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('站台名稱');
            $table->string('domain', 255)->nullable()->comment('站台域名');

            // API 設定
            $table->string('api_url', 255)->nullable()->comment('主系統 API 網址');
            $table->string('api_key', 64)->nullable()->comment('主系統 API Key');

            // 從 API 同步的資料
            $table->decimal('credits', 20, 2)->default(0)->comment('點數餘額（從 API 同步）');
            $table->json('settings')->nullable()->comment('站台設定（費率、存款類型開關等，從 API 同步）');

            // Telegram 群組
            $table->foreignId('telegram_group_id')->nullable()->constrained('telegram_group')->nullOnDelete()->comment('對應的 Telegram 群組 ID');

            // 狀態與備註
            $table->tinyInteger('status')->default(1)->comment('1=啟用, 2=凍結, 0=停用');
            $table->text('note')->nullable()->comment('備註');
            $table->timestamp('synced_at')->nullable()->comment('最後同步時間');
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('station')) {
            return;
        }

        Schema::dropIfExists('station');
    }
}
