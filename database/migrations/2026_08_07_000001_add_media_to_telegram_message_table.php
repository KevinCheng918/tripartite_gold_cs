<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * telegram_message 加入媒體欄位（圖片支援）
 */
class AddMediaToTelegramMessageTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('telegram_message')) {
            return;
        }

        if (Schema::hasColumn('telegram_message', 'media_type')) {
            return;
        }

        Schema::table('telegram_message', function (Blueprint $table) {
            $table->string('media_type', 20)->nullable()->after('content')->comment('媒體類型：photo, sticker 等');
            $table->string('media_url', 500)->nullable()->after('media_type')->comment('媒體檔案 URL');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('telegram_message')) {
            return;
        }

        Schema::table('telegram_message', function (Blueprint $table) {
            if (Schema::hasColumn('telegram_message', 'media_type')) {
                $table->dropColumn('media_type');
            }
            if (Schema::hasColumn('telegram_message', 'media_url')) {
                $table->dropColumn('media_url');
            }
        });
    }
}
