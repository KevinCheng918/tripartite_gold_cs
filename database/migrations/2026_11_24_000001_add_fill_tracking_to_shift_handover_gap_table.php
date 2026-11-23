<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 交接漏項：記下「他補了什麼」，以及催他補的進度
 *
 * 需求方 2026-11-23：「這邊有補在最後一句了，但是 AI 沒回了，是回覆錯地方嗎，
 * 還是什麼問題。」→「應該是都要回話，有區分話題了，人工沒回錯的話，
 * 系統不應該回錯才對。」
 *
 * ⚠⚠ **現在答完「有，我補上」之後整條線就斷了**：`shift_handover_gap`
 * 只記了「他說要補」，沒記他補了什麼，也不知道他到底補了沒 ——
 * **答了要補卻忘記補，沒有任何人會發現**，而這正是這個功能本身
 * 要解決的那種事。
 *
 * ⚠ 要認出「他補的那一句是在補哪一題」，就得知道盤點那幾則訊息的
 * message_id —— v4.00 送出去之後把回傳的 id 丟掉了，所以這裡補上。
 *
 * 詳見 .claude/knowledge/features/handover-gap-review.md
 */
class AddFillTrackingToShiftHandoverGapTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('shift_handover_gap')) {
            Schema::table('shift_handover_gap', function (Blueprint $table) {
                if (!Schema::hasColumn('shift_handover_gap', 'ask_message_id')) {
                    /*
                     * 這一題發在交接話題的那一則訊息。
                     *
                     * ⚠ **要有索引**：同仁引用某一題來補內容時，是拿
                     * `reply_to_message.message_id` 反查這一欄 ——
                     * 每一則引用都會查一次。
                     */
                    $table->unsignedBigInteger('ask_message_id')
                        ->nullable()
                        ->after('seq')
                        ->comment('這一題所在的 Telegram 訊息 id');

                    $table->index('ask_message_id', 'shift_handover_gap_ask_message_id_index');
                }

                if (!Schema::hasColumn('shift_handover_gap', 'filled_note')) {
                    /*
                     * 他實際補上的內容。
                     *
                     * ⚠ **跟 `status` 是兩件事**：`status = FILLED` 只代表
                     * 他**按了**「要補上」，這一欄才是他**真的補了**。
                     * 兩者分開才問得出「答應了但沒補」的那些。
                     */
                    $table->text('filled_note')
                        ->nullable()
                        ->after('ask_message_id')
                        ->comment('他在交接話題補上的內容');
                }

                if (!Schema::hasColumn('shift_handover_gap', 'filled_at')) {
                    $table->dateTime('filled_at')
                        ->nullable()
                        ->after('filled_note')
                        ->comment('補上的時間；null = 說要補但還沒補');
                }

                if (!Schema::hasColumn('shift_handover_gap', 'remind_count')) {
                    // 催了幾次。第 N 次起連主管一起 tag（同交接本身的做法）
                    $table->unsignedInteger('remind_count')
                        ->default(0)
                        ->after('filled_at')
                        ->comment('催他補的次數');
                }

                if (!Schema::hasColumn('shift_handover_gap', 'last_reminded_at')) {
                    $table->dateTime('last_reminded_at')
                        ->nullable()
                        ->after('remind_count')
                        ->comment('上次催的時間');
                }
            });
        }

        if (!Schema::hasTable('shift_handover')) {
            return;
        }

        Schema::table('shift_handover', function (Blueprint $table) {
            /*
             * 開場與總結那兩則。
             *
             * ⚠ **它們不屬於任何一題**，所以放在交接紀錄上而不是 gap 上。
             * 同仁最自然的動作是引用**總結**那一則來補（需求方的截圖就是
             * 這樣做的）—— 那時要能反查回這一輪盤點。
             */
            if (!Schema::hasColumn('shift_handover', 'review_opening_message_id')) {
                $table->unsignedBigInteger('review_opening_message_id')
                    ->nullable()
                    ->after('handed_message_id')
                    ->comment('漏項盤點的開場訊息 id');

                $table->index('review_opening_message_id', 'shift_handover_review_opening_index');
            }

            if (!Schema::hasColumn('shift_handover', 'review_summary_message_id')) {
                $table->unsignedBigInteger('review_summary_message_id')
                    ->nullable()
                    ->after('review_opening_message_id')
                    ->comment('漏項盤點的總結訊息 id');

                $table->index('review_summary_message_id', 'shift_handover_review_summary_index');
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('shift_handover_gap')) {
            Schema::table('shift_handover_gap', function (Blueprint $table) {
                if (Schema::hasColumn('shift_handover_gap', 'ask_message_id')) {
                    $table->dropIndex('shift_handover_gap_ask_message_id_index');
                }

                foreach (['ask_message_id', 'filled_note', 'filled_at', 'remind_count', 'last_reminded_at'] as $column) {
                    if (Schema::hasColumn('shift_handover_gap', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (!Schema::hasTable('shift_handover')) {
            return;
        }

        Schema::table('shift_handover', function (Blueprint $table) {
            if (Schema::hasColumn('shift_handover', 'review_opening_message_id')) {
                $table->dropIndex('shift_handover_review_opening_index');
            }

            if (Schema::hasColumn('shift_handover', 'review_summary_message_id')) {
                $table->dropIndex('shift_handover_review_summary_index');
            }

            foreach (['review_opening_message_id', 'review_summary_message_id'] as $column) {
                if (Schema::hasColumn('shift_handover', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
