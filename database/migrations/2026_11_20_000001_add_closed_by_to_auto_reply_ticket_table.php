<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 求助單加上「是誰把它結掉的」（closed_by）
 *
 * 需求方 2026-11-20：「忽略的要紀錄是誰去按忽略有幾張。」
 *
 * ⚠ **`answered_by` 答不了這個問題**：那一欄記的是「誰引用回覆作答」，
 * 而按忽略的人根本沒有作答 —— 那一欄是空的。後台標「客服已自行處理」
 * 也一樣（操作者在後台，不在 Telegram）。
 *
 * 三種人工結案都會寫這一欄，對應 `constants.AUTO_REPLY.CLOSE_ACTION`：
 *
 * | close_action | 誰 |
 * |---|---|
 * | 6 同仁按忽略 | 按按鈕的那個 Telegram 帳號 |
 * | 7 後台標已處理 | 後台登入的帳號 |
 * | 8 客服在對話窗回掉 | 在對話窗送出回覆的那個帳號 |
 *
 * ⚠ 存的是**顯示名稱而不是 user_id**：按按鈕的人是 Telegram 身分，
 * 不一定對得到後台帳號（同仁沒填 `telegram_username` 就查不到）——
 * 記一個「報表上印得出來的名字」比記一個常常是 null 的外鍵有用。
 *
 * ⚠ 舊單一律 null，不回填 —— 誰按的這件事從來沒被記下來過，猜不出來。
 */
class AddClosedByToAutoReplyTicketTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('auto_reply_ticket') || Schema::hasColumn('auto_reply_ticket', 'closed_by')) {
            return;
        }

        Schema::table('auto_reply_ticket', function (Blueprint $table) {
            $table->string('closed_by', 100)
                ->nullable()
                ->after('close_action')
                ->comment('誰把這張單結掉的（顯示名稱）；舊單為 null');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('auto_reply_ticket') || !Schema::hasColumn('auto_reply_ticket', 'closed_by')) {
            return;
        }

        Schema::table('auto_reply_ticket', function (Blueprint $table) {
            $table->dropColumn('closed_by');
        });
    }
}
