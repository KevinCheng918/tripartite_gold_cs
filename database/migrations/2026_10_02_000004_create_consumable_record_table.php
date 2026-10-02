<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立消耗品流水表（consumable_record）
 *
 * 內勤的消耗品進出：領用（進）與使用（出）。
 *
 * **一張表就夠，不拆成領用／使用兩張** —— 兩者只差一個方向，拆開的話
 * 「列出某人某品項的所有異動」要查兩次再合併排序，餘額也要兩次 SUM
 * 各寫一份篩選條件。
 *
 * ⚠ **沒有餘額欄位。** 剩餘一律由
 * `SUM(領用) - SUM(使用)` 算出來 —— 存一份餘額就有兩份真相，
 * 補登或修改歷史紀錄時一定會不同步。
 */
class CreateConsumableRecordTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('consumable_record')) {
            return;
        }

        Schema::create('consumable_record', function (Blueprint $table) {
            $table->id();

            // 帳號沒了，他的消耗品餘額就沒有意義，流水跟著走
            $table->foreignId('user_id')->constrained('user')->cascadeOnDelete()
                ->comment('哪個內勤');

            /*
             * restrictOnDelete：有紀錄的品項不准刪 —— 刪了之後那些流水會變成
             * 「不知道是什麼東西的 10 張」。要停用請改 status。
             */
            $table->foreignId('consumable_item_id')->constrained('consumable_item')->restrictOnDelete()
                ->comment('哪個品項');

            $table->tinyInteger('type')->comment('1=領用（進）, 2=使用（出）');

            /*
             * 一律存正數，方向交給 type —— 存負數會讓「這個人用了幾張」
             * 這種統計必須先判斷正負，很容易算錯。
             */
            $table->integer('quantity')->comment('數量，永遠是正數');

            /*
             * 自己填的日期，不是 created_at —— 要能補登「上週領的那 10 張」。
             * 餘額是總量相減、跟先後順序無關，所以補登不會算錯。
             */
            $table->date('happened_at')->comment('領用／使用的日期');

            $table->string('purpose', 255)->nullable()->comment('用途，使用時必填、領用時留空');
            $table->string('note', 255)->nullable()->comment('備註');

            // 分得出是本人登記還是管理者代登記；帳號被刪時顯示「已離職人員」
            $table->foreignId('created_by')->nullable()->constrained('user')->nullOnDelete()
                ->comment('誰登記的');

            $table->timestamps();

            // 餘額查詢是「某人某品項的所有紀錄」，這是最主要的存取路徑
            $table->index(['user_id', 'consumable_item_id'], 'consumable_record_user_item_index');
            $table->index('happened_at', 'consumable_record_happened_index');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('consumable_record')) {
            return;
        }

        Schema::dropIfExists('consumable_record');
    }
}
