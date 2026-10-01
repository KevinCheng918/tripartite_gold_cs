<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立「這個對話特別放行哪些同事」表（telegram_group_staff_allow）
 *
 * 內部員工預設在所有對話都不自動回覆（見 StaffIgnoreService）。這張表記的是
 * **反向的例外**：客服在某一個對話把某個同事「特別打開」，那個對話就會照常
 * 自動回覆他。
 *
 * **有列 = 特別打開，沒列 = 預設忽略。** 用存在與否表達狀態，不再多一個 bool ——
 * 多一個 bool 就會有「有列但 false」這種跟「沒列」意思相同的狀態，白白多一種
 * 要處理的情況。
 *
 * 為什麼不沿用 telegram_group_member.ignored：
 *
 *   1. 那個欄位的語意是「客服手動把這個人加進忽略名單」，而這裡要記的是放行。
 *      混在同一個 bool 上，「沒有紀錄」對一般人是不忽略、對同事卻是忽略
 *   2. 名冊以 Telegram 身分為鍵，而且**人要發言過才有列** —— 客服想預先打開
 *      一個還沒在這個對話講過話的同事時，根本沒有列可以標
 *
 * 所以這張表以**後台帳號 id** 為鍵，不依賴名冊。
 */
class CreateTelegramGroupStaffAllowTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('telegram_group_staff_allow')) {
            return;
        }

        Schema::create('telegram_group_staff_allow', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_group_id')->constrained('telegram_group')->cascadeOnDelete()
                ->comment('哪個對話');

            /*
             * cascadeOnDelete：帳號沒了，這筆例外也該消失 ——
             * 留著會變成一筆指向不存在的人的放行紀錄，而 user_id 又可能被
             * 新帳號重用，那就等於默默放行了另一個人。
             */
            $table->foreignId('user_id')->constrained('user')->cascadeOnDelete()
                ->comment('哪個同事（後台帳號）');

            // 誰打開的要留著讓人看 —— 這是「為什麼這個對話會自動回覆他」的唯一線索
            $table->foreignId('allowed_by')->nullable()->constrained('user')->nullOnDelete()
                ->comment('誰打開的');
            $table->timestamp('allowed_at')->nullable()->comment('什麼時候打開的');

            $table->timestamps();

            $table->unique(['telegram_group_id', 'user_id'], 'tg_staff_allow_group_user_unique');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('telegram_group_staff_allow')) {
            return;
        }

        Schema::dropIfExists('telegram_group_staff_allow');
    }
}
