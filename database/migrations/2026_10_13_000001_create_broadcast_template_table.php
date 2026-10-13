<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立公告範本表（broadcast_template）
 *
 * 事先把常發的公告建檔（標題＋內容＋附件），發送時選一則帶進表單。
 *
 * ⚠ **帶進表單之後還能改**（需求方 2026-10-13）—— 這是「範本」不是「固定公版」。
 * 送出的是當下表單裡的東西，改了不會回寫這張表，也不影響已經發出去的。
 *
 * ⚠ `image_urls` / `file_urls` 刻意跟 `telegram_broadcast` **同形狀**
 * （`[{path, name}]` 的相對路徑）：發送時整個陣列搬過去就好，
 * `sendPhoto` / `sendDocument` 那條路完全不用動。
 *
 * ⚠ **刪範本不刪實體檔**。發送紀錄存的是複製過去的同一組路徑，
 * 刪檔會讓歷史紀錄裡的附件變成死連結。代價是刪掉的範本會留下孤兒檔案，
 * 之後補一支清理指令處理（需求方 2026-10-13 選擇「歷史紀錄不受影響」）。
 */
class CreateBroadcastTemplateTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('broadcast_template')) {
            return;
        }

        Schema::create('broadcast_template', function (Blueprint $table) {
            $table->id();

            // 標題只有範本有。發送紀錄沿用現況不存標題，免得兩邊各有一份會分岔
            $table->string('title', 100)->comment('範本標題，只在後台辨識用，不會發給客人');
            $table->text('content')->comment('公告內容');

            $table->json('image_urls')->nullable()->comment('圖片 [{path, name}]');
            $table->json('file_urls')->nullable()->comment('檔案 [{path, name}]');

            /*
             * nullOnDelete 而不是 cascade：建檔的人離職不該讓公告跟著消失 ——
             * 那些公告通常是部門在用的，不是個人財產。
             */
            $table->foreignId('created_by')->nullable()->constrained('user')->nullOnDelete()
                ->comment('建檔的人');

            $table->timestamps();

            // 列表預設照更新時間排，最近改過的在最前面
            $table->index('updated_at');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('broadcast_template')) {
            return;
        }

        Schema::dropIfExists('broadcast_template');
    }
}
