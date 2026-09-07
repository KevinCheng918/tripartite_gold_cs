<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * telegram_broadcast 加入預約傳送欄位
 *
 * image_urls 必須落庫：預約的公告在建立當下不會送出，
 * 圖片網址只存在 request 裡的話，到了排程時間就找不到圖了。
 */
class AddScheduleToTelegramBroadcastTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('telegram_broadcast', function (Blueprint $table) {
            if (!Schema::hasColumn('telegram_broadcast', 'status')) {
                $table->tinyInteger('status')->default(1)->after('target_group_ids')
                    ->comment('1=已發送, 2=待發送, 3=已取消');
            }

            if (!Schema::hasColumn('telegram_broadcast', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable()->after('status')
                    ->comment('預約發送時間，null 表示立即發送');
            }

            if (!Schema::hasColumn('telegram_broadcast', 'image_urls')) {
                $table->json('image_urls')->nullable()->after('scheduled_at')
                    ->comment('附帶圖片網址陣列');
            }
        });

        // 排程每分鐘掃一次待發送，用複合索引避免全表掃描
        Schema::table('telegram_broadcast', function (Blueprint $table) {
            $table->index(['status', 'scheduled_at'], 'idx_broadcast_status_scheduled');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('telegram_broadcast', function (Blueprint $table) {
            $table->dropIndex('idx_broadcast_status_scheduled');
            $table->dropColumn(['status', 'scheduled_at', 'image_urls']);
        });
    }
}
