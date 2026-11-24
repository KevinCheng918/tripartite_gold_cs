<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 交接漏項：把「催補那一則」的訊息 id 也記下來
 *
 * ⚠⚠ **線上實際出事了**（需求方 2026-11-24 回報截圖）：
 * 同仁引用催補訊息回了「完成」，系統完全沒反應，一路催到**第 23 次**。
 *
 * 原因是 `handleTopicReply()` 只認得三種引用 —— 題目（`ask_message_id`）、
 * 開場、總結。而**催補訊息是每次新發一則，它的 id 沒有被存在任何地方**。
 *
 * ⚠ 最難堪的是：那則催補訊息自己寫著「麻煩引用這一則把內容補上來」——
 * **我們叫他引用一則我們認不得的訊息**，然後繼續催他。
 *
 * 詳見 .claude/knowledge/features/handover-gap-review.md
 */
class AddRemindMessageIdToShiftHandoverGapTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('shift_handover_gap')) {
            return;
        }

        Schema::table('shift_handover_gap', function (Blueprint $table) {
            if (Schema::hasColumn('shift_handover_gap', 'remind_message_id')) {
                return;
            }

            /*
             * 最後一次催補那一則的訊息 id。
             *
             * ⚠ **只留最後一次**：催了 23 次就有 23 則，全部留著得另開一張表。
             * 而人會引用的幾乎一定是**最新那一則**（它在話題的最下面）。
             * 舊的那幾則引用不到 —— 已知限制，寫在知識庫裡。
             *
             * ⚠ 要索引：每一則帶引用的訊息都會拿 `reply_to_message.message_id`
             * 來反查這一欄。
             */
            $table->unsignedBigInteger('remind_message_id')
                ->nullable()
                ->after('last_reminded_at')
                ->comment('最後一次催補的訊息 id（引用它也要認得）');

            $table->index('remind_message_id', 'shift_handover_gap_remind_message_id_index');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('shift_handover_gap')) {
            return;
        }

        Schema::table('shift_handover_gap', function (Blueprint $table) {
            if (Schema::hasColumn('shift_handover_gap', 'remind_message_id')) {
                $table->dropIndex('shift_handover_gap_remind_message_id_index');
                $table->dropColumn('remind_message_id');
            }
        });
    }
}
