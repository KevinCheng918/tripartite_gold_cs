<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立「誰讀到哪」表（telegram_group_read）
 *
 * 每個客服在每個對話各有一列，記他最後一次點進去的時間。未讀＝這個時間之後
 * 客人又發的訊息。
 *
 * **為什麼不是在 `telegram_message` 上加 `read_at`**：需求方要的是
 * 「每個客服各自的未讀」（2026-10-11），而訊息表一列是一則訊息，
 * 加個欄位只能表達「有人讀過」，表達不了「誰讀過」。
 *
 * **為什麼不沿用 `telegram_message.replied`**：那個欄位的原意是「這則客人的
 * 訊息已經被回覆了」（送出訊息時標），卻被「點進對話就標已讀」借去用，
 * 於是「客服看了但沒回」會被記成已回覆。這次把兩件事分開，`replied` 回歸原意。
 *
 * **存時間戳而不是逐則標記已讀**：一列就夠，不必為每則訊息 × 每個客服各存一列。
 * 代價是沒辦法表達「跳著讀」，但客服是從頭往下看的，沒有這種需求。
 */
class CreateTelegramGroupReadTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('telegram_group_read')) {
            return;
        }

        Schema::create('telegram_group_read', function (Blueprint $table) {
            $table->id();

            /*
             * cascadeOnDelete：帳號或對話沒了，這筆「讀到哪」就沒有意義。
             * 留著還有風險 —— id 可能被重用，那就變成另一個人的已讀位置。
             */
            $table->foreignId('user_id')->constrained('user')->cascadeOnDelete()
                ->comment('哪個客服');
            $table->foreignId('telegram_group_id')->constrained('telegram_group')->cascadeOnDelete()
                ->comment('哪個對話');

            $table->dateTime('last_read_at')->comment('最後一次點進這個對話的時間');

            $table->timestamps();

            // 一個人在一個對話只會有一列，用它來 updateOrCreate
            $table->unique(['user_id', 'telegram_group_id'], 'tg_group_read_user_group_unique');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('telegram_group_read')) {
            return;
        }

        Schema::dropIfExists('telegram_group_read');
    }
}
