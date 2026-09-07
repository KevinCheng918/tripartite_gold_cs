<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * telegram_message 加入 media_name（附件的原始檔名）
 *
 * 存檔時檔名會被加上時間戳前綴、且非 ASCII 字元被換成底線，
 * 從 media_url 反推救不回原始檔名（中文會變成一串 _），
 * 因此必須另外存一欄。既有資料為 null，前端會退回用網址推。
 */
class AddMediaNameToTelegramMessageTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('telegram_message', function (Blueprint $table) {
            if (Schema::hasColumn('telegram_message', 'media_name')) {
                return;
            }

            $table->string('media_name', 255)->nullable()->after('media_url')
                ->comment('附件原始檔名，供下載時還原');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('telegram_message', function (Blueprint $table) {
            $table->dropColumn('media_name');
        });
    }
}
