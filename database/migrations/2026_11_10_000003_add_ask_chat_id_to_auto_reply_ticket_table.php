<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 求助單補上 ask_chat_id（2026-11-10 線上事故）
 *
 * ⚠⚠ 事故：同仁引用回覆 TR 群組的求助卡作答，系統把答案送到**另一個客人**
 * 的群組。原因是 `ask_message_id` 被當成全域唯一鍵在用，但 Telegram 的
 * message_id **只在單一 chat 內唯一** —— 內部支援群組換過 chat（開啟話題會
 * 讓群組升級成 supergroup 並換發新的 chat_id）之後，新舊兩批單的
 * ask_message_id 會從小數字開始重疊，而 `findByAskMessageId()` 當時是
 * 無排序的 `first()`，撈到哪一張是未定義行為。
 *
 * 補上這一欄之後，對應鍵變成 **(ask_chat_id, ask_message_id)**，
 * 跨 chat 的碰撞就不可能再發生。
 *
 * 詳見 .claude/knowledge/bugfix/2026-11-10-ticket-answer-wrong-group.md
 */
class AddAskChatIdToAutoReplyTicketTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('auto_reply_ticket') || Schema::hasColumn('auto_reply_ticket', 'ask_chat_id')) {
            return;
        }

        Schema::table('auto_reply_ticket', function (Blueprint $table) {
            // 型別比照 `telegram_group.chat_id`（chat_id 是負數大整數）
            $table->bigInteger('ask_chat_id')
                ->nullable()
                ->after('ask_message_id')
                ->comment('求助訊息發在哪個內部支援群組的 chat_id，與 ask_message_id 合起來才唯一');
        });

        $this->backfill();
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('auto_reply_ticket') || !Schema::hasColumn('auto_reply_ticket', 'ask_chat_id')) {
            return;
        }

        Schema::table('auto_reply_ticket', function (Blueprint $table) {
            $table->dropColumn('ask_chat_id');
        });
    }

    /**
     * 回填既有資料
     *
     * ⚠⚠ **刻意只回填「ask_message_id 不重複」的那些單。**
     *
     * 重複的那幾筆正是事故的來源 —— 它們分屬不同的 chat，而我們**無從判斷
     * 哪一筆屬於哪一個**（舊 chat_id 已經沒有任何地方留著）。猜一個填下去
     * 等於把錯誤對應固化成資料，比留空更糟。
     *
     * 留空的後果是「引用那幾張舊卡作答會沒有反應」——
     * 而 `handleSupportMessage()` 對這種情況會回一句說明並記 log，
     * 不是靜默失敗。⚠ **沒反應遠好過回錯客人。**
     *
     * 不重複的那些則是安全的：一個 ask_message_id 只對到一張單，
     * 回填成目前的支援群組不會改變任何既有行為。
     *
     * @return void
     */
    private function backfill()
    {
        $chatId = DB::table('app_setting')
            ->where('key', 'auto_reply.support_chat_id')
            ->value('value');

        // 短路：還沒設定過支援群組，沒有東西可回填（新安裝的環境）
        if (blank($chatId)) {
            return;
        }

        $duplicated = DB::table('auto_reply_ticket')
            ->select('ask_message_id')
            ->whereNotNull('ask_message_id')
            ->groupBy('ask_message_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ask_message_id')
            ->all();

        $filled = DB::table('auto_reply_ticket')
            ->whereNotNull('ask_message_id')
            ->when(filled($duplicated), function ($query) use ($duplicated) {
                return $query->whereNotIn('ask_message_id', $duplicated);
            })
            ->update(['ask_chat_id' => (int) $chatId]);

        /*
         * ⚠ 這一行要留下來：重複的筆數就是「這次事故波及的規模」，
         * 而 migration 跑完之後就再也算不出來了（欄位補上、行為也變了）。
         */
        Log::info('auto_reply_ticket 回填 ask_chat_id', [
            'support_chat_id'    => $chatId,
            'filled'             => $filled,
            'duplicated_groups'  => count($duplicated),
            'left_null'          => DB::table('auto_reply_ticket')->whereNull('ask_chat_id')->count(),
            'duplicated_ids'     => array_slice($duplicated, 0, 50),
        ]);
    }
}
