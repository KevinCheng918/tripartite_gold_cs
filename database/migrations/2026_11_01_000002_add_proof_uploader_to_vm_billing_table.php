<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 繳款證明記錄「誰在什麼時候上傳」
 *
 * 需求方 2026-11-01：「虛擬機的上傳繳款證明要紀錄是誰在什麼時候上傳的，
 * 在虛擬機管理的帳務紀錄這個頁面要看得到。」
 *
 * ⚠ **這是推翻 2026-10-27 的決定。** 當時裁示「不加這個欄位」，所以
 * `VmController::ajaxUploadProof()` 把上傳者只傳給通知服務
 * （人名只出現在 Telegram 訊息裡，帳單資料本身不記）。
 * 現在要記進資料，那段註解也一起更新了。
 *
 * ⚠ **在這之前「上傳時間」是用 `updated_at` 近似的**
 * （見 `VmRepository::getPendingBillingsForReminder()` 的註解：
 * 上傳證明會更新 `updated_at`，而審核前通常不會有別的更新）。
 * 現在有了真正的欄位，但**舊資料沒有補**（理由見下）。
 */
class AddProofUploaderToVmBillingTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('vm_billing') || Schema::hasColumn('vm_billing', 'proof_uploaded_by')) {
            return;
        }

        Schema::table('vm_billing', function (Blueprint $table) {
            /*
             * ⚠ **一定要 nullable**：FK 設 `nullOnDelete` 而欄位不是 nullable
             * 的話，上傳者的帳號被刪除的那一刻 MySQL 會直接報錯 ——
             * 刪不掉帳號，而且錯誤訊息指向一個跟帳號管理毫無關係的外鍵。
             *
             * ⚠ 而且「沒有上傳者」本來就是合法狀態：還沒上傳證明的帳單、
             * 以及這個欄位加上來之前就上傳好的那些。
             */
            $table->unsignedBigInteger('proof_uploaded_by')->nullable()->after('proof_image')
                ->comment('繳款證明是誰上傳的');

            $table->timestamp('proof_uploaded_at')->nullable()->after('proof_uploaded_by')
                ->comment('繳款證明的上傳時間');

            $table->foreign('proof_uploaded_by')->references('id')->on('user')->nullOnDelete();
        });
    }

    /*
     * ⚠ **刻意不回填舊資料。**
     *
     * 「誰上傳的」舊資料裡根本沒有，補不出來。
     * 「什麼時候上傳」可以用 `updated_at` 近似，但那是**近似值** ——
     * 把它寫進一個讀起來像事實的欄位，日後沒有人分得出哪一筆是真的。
     *
     * 所以舊帳單在畫面上顯示「—」。要看那些的近似時間，
     * 催審核通知那邊仍然會 fallback 到 `updated_at`。
     */

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('vm_billing') || !Schema::hasColumn('vm_billing', 'proof_uploaded_by')) {
            return;
        }

        Schema::table('vm_billing', function (Blueprint $table) {
            $table->dropForeign(['proof_uploaded_by']);
            $table->dropColumn(['proof_uploaded_by', 'proof_uploaded_at']);
        });
    }
}
