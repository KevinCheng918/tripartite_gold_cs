<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * telegram_broadcast 加入附件欄位
 *
 * 與 image_urls 分開存：圖片走 sendPhoto 只需要網址，
 * 檔案走 sendDocument 需要「本地路徑 + 原始檔名」，兩者結構不同。
 */
class AddFileUrlsToTelegramBroadcastTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('telegram_broadcast', function (Blueprint $table) {
            if (Schema::hasColumn('telegram_broadcast', 'file_urls')) {
                return;
            }

            $table->json('file_urls')->nullable()->after('image_urls')
                ->comment('附件陣列 [{path, name}]，path 為 storage/app/public 下的相對路徑');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('telegram_broadcast', function (Blueprint $table) {
            $table->dropColumn('file_urls');
        });
    }
}
