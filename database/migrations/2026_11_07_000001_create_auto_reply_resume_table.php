<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立自動回覆關閉紀錄表（auto_reply_resume）
 *
 * 客服取消勾選自動回覆改人工，處理完忘記勾回來 —— 那個群組從此一直是人工。
 * 這張表讓排程把它勾回來。
 *
 * ⚠ **為什麼不直接掃 `telegram_group WHERE auto_reply = 0`**：那張結果裡混了
 * 兩種語意相反的群組，而欄位本身分不出來 ——
 *
 *   - 平常開著、客服剛剛關掉去人工處理 → 要恢復
 *   - 從來沒開過、刻意一直人工        → **絕對不能**恢復（訊息發給客人收不回來）
 *
 * 改成記「關閉事件」：關的那一刻寫一筆，排程只看這張表。從沒開過自動回覆的
 * 群組永遠不會有紀錄，所以自動被排除 —— 不需要任何人維護排除名單，
 * 新群組加進來也不用有人記得去勾。
 *
 * ⚠ **永久關閉也要寫一筆**（`mode` = 永久），不是不寫。理由是稽核：
 * 之後要回答「這個群組是什麼時候、誰決定不自動回覆的」只能靠這張表。
 */
class CreateAutoReplyResumeTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('auto_reply_resume')) {
            return;
        }

        Schema::create('auto_reply_resume', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_group_id')->constrained('telegram_group')->cascadeOnDelete()->comment('哪個對話');

            /*
             * 關掉的人。
             *
             * nullable + nullOnDelete：帳號被刪時這筆仍要留著（排程還要靠它
             * 恢復），所以不能 cascade。nullOnDelete 的欄位必須 nullable。
             *
             * 帳號沒了之後還要看得出是誰關的，所以另存 `operator_name` 快照 ——
             * 只靠關聯的話，通知訊息會變成「不知道是誰關的」。
             */
            $table->foreignId('user_id')->nullable()->constrained('user')->nullOnDelete()->comment('關掉的人');
            $table->string('operator_name', 100)->nullable()->comment('關掉的人（名稱快照，帳號刪掉後仍可顯示）');

            /*
             * 關閉時間。它是**靜置基準的一半**：
             * 實際起算點是 max(off_at, telegram_group.last_message_at)。
             *
             * 只看 last_message_at 不行 —— 客服常是在客人安靜一陣子之後才關的，
             * 那時 last_message_at 比 off_at 還早，一關掉就立刻達標、
             * 下一分鐘就被恢復，人工處理還沒開始就結束了。
             */
            $table->dateTime('off_at')->comment('關閉時間（靜置基準之一）');

            /*
             * 這一筆要靜置幾分鐘才恢復 —— **由關閉的人在 modal 當場填**
             * （需求方 2026-11-07），不是全域一個值：他才知道這一通要處理多久。
             *
             * 範圍 5～1440 由 `config/rules.php` 驗證。永久關閉時為 null。
             */
            $table->unsignedSmallInteger('quiet_minutes')->nullable()->comment('靜置幾分鐘後恢復；永久關閉時為 null');

            $table->tinyInteger('mode')->default(1)->comment('1=暫時轉人工, 2=永久關閉');
            $table->tinyInteger('status')->default(1)->comment('1=待恢復, 2=已自動恢復, 3=已手動恢復, 4=永久關閉, 5=作廢');
            $table->dateTime('resumed_at')->nullable()->comment('恢復（或結案）時間');
            $table->timestamps();

            /*
             * 排程每分鐘查「待恢復的有哪些」，所以 `status` 擺在前面；
             * 第二個欄位讓「這個群組目前那一筆」也走同一個索引
             * （勾回來時要把它結案、聊天視窗要顯示倒數）。
             */
            $table->index(['status', 'telegram_group_id'], 'auto_reply_resume_status_group_index');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('auto_reply_resume');
    }
}
