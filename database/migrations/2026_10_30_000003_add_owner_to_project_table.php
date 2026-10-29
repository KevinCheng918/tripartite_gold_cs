<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 專案加上負責人
 *
 * 需求方 2026-10-30：「專案要有負責人，要有權限能夠修改、新增負責人，
 * 負責人只會有一位。」
 *
 * ⚠ **用一個欄位而不是關聯表**：一個欄位天生就只放得下一個人 ——
 * 「只有一位」由結構保證，不靠程式檢查。關聯表則要另外寫「存檔前先刪掉舊的」
 * 或加 unique 索引，而那一步漏掉就會出現兩個負責人，且不會報錯。
 *
 * ⚠ **跟 `created_by` 是兩回事**：那個記的是「這筆資料是誰開的」，開完就不再變。
 */
class AddOwnerToProjectTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('project', 'owner_id')) {
            return;
        }

        Schema::table('project', function (Blueprint $table) {
            /*
             * ⚠ **一定要 nullable**，兩個理由：
             *
             * 1. FK 設 `nullOnDelete` 而欄位不是 nullable 的話，負責人帳號
             *    被刪除的那一刻 MySQL 會直接報錯 —— 刪不掉帳號，而且錯誤訊息
             *    指向一個跟帳號管理毫無關係的外鍵。
             * 2. 「未指派」是合法狀態：新專案開出來可能還沒決定負責人，
             *    沒有這個狀態的話，開專案的人會被迫先隨便填一個。
             */
            $table->unsignedBigInteger('owner_id')->nullable()->after('description')
                ->comment('專案負責人（只會有一位）；null = 未指派');

            /*
             * ⚠ `nullOnDelete` 只在真的 `DELETE` 時觸發，而這個專案的帳號是
             * **軟刪除** —— 所以離職的負責人 `owner_id` 會繼續指著他。
             * 那是刻意的（需求方裁示：保留並在畫面標紅字提醒換人），
             * 但也因此 `Project::owner()` 必須 `withTrashed()`，
             * 否則關聯撈出來是 null，畫面會顯示成「未指派」。
             */
            $table->foreign('owner_id')->references('id')->on('user')->nullOnDelete();

            // 「某個人負責哪些專案」要查得動（停用帳號時要列出他負責的專案）
            $table->index('owner_id', 'project_owner_idx');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('project', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropIndex('project_owner_idx');
            $table->dropColumn('owner_id');
        });
    }
}
