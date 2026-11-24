<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 撤銷請假：申請與審核的紀錄
 *
 * 需求方 2026-11-24：「我想要做一個功能是撤銷請假，然後也要經過同意，
 * 審核撤假也是吃權限，撤假的話要保留請假、撤假紀錄。」
 *
 * ⚠⚠ **為什麼要另開一張表，而不是在 `leave_request` 上加幾個欄位？**
 *
 * 需求方要「保留請假、撤假紀錄」。只改原單的狀態的話，「他撤過假」會變成
 * 一個狀態值，而**誰申請撤的、為什麼、誰審的、審核時那個勾選框勾了沒**
 * 全都沒有地方放 —— 而那幾件正是之後回頭查帳時唯一想知道的事。
 *
 * ⚠ 原假單除了 `status` 之外**一個字都不改**：那是他當初請了什麼的紀錄。
 *
 * 詳見 .claude/knowledge/features/leave-cancel.md
 */
class CreateLeaveCancelRequestTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('leave_cancel_request')) {
            return;
        }

        Schema::create('leave_cancel_request', function (Blueprint $table) {
            $table->id();

            /*
             * 要撤哪一張假單。
             *
             * ⚠ **不設 unique**：同一張單可能被申請撤銷、被拒絕、
             * 然後再申請一次 —— 每一次都要留下紀錄。
             * 「同時只能有一筆待審核」由 Service 擋，不靠索引。
             */
            $table->unsignedBigInteger('leave_request_id')->comment('要撤銷的假單');
            $table->unsignedBigInteger('requested_by')->comment('申請撤銷的人（只會是假單本人）');
            $table->text('reason')->nullable()->comment('為什麼要撤');

            $table->tinyInteger('status')->default(0)->comment('0=待審核 1=已通過 2=已拒絕');
            $table->unsignedBigInteger('reviewed_by')->nullable()->comment('誰審的');
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            /*
             * ⚠⚠ **TEMP-CANCEL-KEEP-QUOTA**
             *
             * 審核時那個勾選框的結果：true ＝ 把額度還回去（預設）。
             *
             * false 時那張**已撤銷**的假單**仍然計入已用特休** ——
             * 這是整個功能裡唯一一處「撤銷了卻還算數」的例外，
             * 額度計算那一側也要對應處理。
             *
             * ⚠ 需求方 2026-11-24：「等我正式站手動撤銷完，就可以把這個
             * 勾選拿掉了。」拿掉的是**讓人選的那一步**，
             * **這一欄不要刪** —— 既有那幾筆仍然要照原本的行為計算。
             *
             * ⚠ 預設 true：不填就是「還額度」，那是絕大多數情況。
             */
            $table->boolean('restore_quota')->default(true)
                ->comment('TEMP-CANCEL-KEEP-QUOTA：false=撤銷後仍計入已用特休');

            $table->timestamps();

            // 查「這張單有沒有待審核的撤假」「我的撤假清單」都靠它
            $table->index(['leave_request_id', 'status'], 'leave_cancel_request_leave_status_index');
            $table->index('requested_by', 'leave_cancel_request_requested_by_index');

            /*
             * ⚠ 假單被刪掉時撤假紀錄跟著走 —— 它脫離了那張假單就沒有意義
             * （而 `requested_by` 用 `nullOnDelete` 的話欄位得 nullable，
             * 那會讓「誰申請的」變成查不到，所以這裡用 restrict：
             * 有撤假紀錄的帳號不該被硬刪）。
             */
            $table->foreign('leave_request_id')->references('id')->on('leave_request')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('user')->restrictOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('user')->nullOnDelete();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('leave_cancel_request');
    }
}
