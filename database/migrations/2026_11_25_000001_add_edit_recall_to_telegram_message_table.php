<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 我方送出的訊息可以改掉／撤回
 *
 * 需求方 2026-11-25：「我客服打出去的話，透過 Telegram 機器人送出去，
 * 這個我能改對話內容嗎」
 *
 * ⚠ `edited_at` **已經存在**（現在只用在「客人改了自己的訊息」），
 * 我方這一側直接沿用同一欄 —— 兩種都是「這一則被改過」，
 * 分兩欄只會讓前端判斷要寫兩次。
 *
 * 詳見 .claude/knowledge/features/outbound-edit-recall.md
 */
class AddEditRecallToTelegramMessageTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('telegram_message')) {
            return;
        }

        Schema::table('telegram_message', function (Blueprint $table) {
            if (!Schema::hasColumn('telegram_message', 'edited_by')) {
                /*
                 * 誰改的。
                 *
                 * ⚠⚠ **需求方選了「不留原文」＋「別人也能改」** ——
                 * 兩個合起來等於「別人可以改掉你跟客人說過的話，而且不留痕跡」。
                 * 而客服系統裡「我到底跟客人講了什麼」正是事後最常被追的事。
                 *
                 * 所以原文照決定不留，但**至少記下是誰改的** ——
                 * 這跟「不留原文」不衝突，是另一件事，而且只值一個欄位。
                 *
                 * ⚠ 用 `nullOnDelete`，所以欄位必須 nullable
                 * （這個專案 2026-11-? 踩過一次：nullOnDelete 配不可為空的欄位）。
                 */
                $table->unsignedBigInteger('edited_by')
                    ->nullable()
                    ->after('edited_at')
                    ->comment('誰改了這一則（我方訊息才有）');

                $table->foreign('edited_by')->references('id')->on('user')->nullOnDelete();
            }

            if (!Schema::hasColumn('telegram_message', 'recalled_at')) {
                /*
                 * 撤回的時間。
                 *
                 * ⚠⚠ **撤回不刪這一列。** 客人那邊看不到了，但
                 * 「我們曾經送過這句話」仍然是事實 —— 刪掉的話，
                 * 事後連「為什麼客人會那樣以為」都查不出來。
                 *
                 * 前端看到這一欄有值就把那一則灰掉並標「已撤回」，
                 * 內容仍然看得到。
                 */
                $table->dateTime('recalled_at')
                    ->nullable()
                    ->after('edited_by')
                    ->comment('撤回時間；有值＝客人那邊已經看不到了');

                $table->unsignedBigInteger('recalled_by')
                    ->nullable()
                    ->after('recalled_at')
                    ->comment('誰撤回的');

                $table->foreign('recalled_by')->references('id')->on('user')->nullOnDelete();
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('telegram_message')) {
            return;
        }

        Schema::table('telegram_message', function (Blueprint $table) {
            foreach (['edited_by', 'recalled_by'] as $fk) {
                if (Schema::hasColumn('telegram_message', $fk)) {
                    $table->dropForeign([$fk]);
                }
            }

            foreach (['edited_by', 'recalled_at', 'recalled_by'] as $column) {
                if (Schema::hasColumn('telegram_message', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
