<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立私訊清除稽核表（staff_dm_wipe_log）
 *
 * ⚠⚠ **這張表是這個功能的安全前提，不是附屬品。**
 *
 * 一個能清掉自己通知紀錄的按鈕，正是內部人員要掩蓋「我沒處理那張單」時
 * 會用的東西。沒有稽核的話，這個功能是在幫人消滅證據。
 *
 * ⚠⚠ **這張表絕對不能被清除功能自己清掉**，也不要給它 TTL 排程 ——
 * 可以被清掉的稽核等於沒有稽核。
 *
 * ⚠ 操作者與對象都留**名稱快照**：帳號被刪掉之後（離職），
 * 「誰清了誰」還要看得出來，只靠外鍵的話會變成兩個 null。
 *
 * 詳見 .claude/knowledge/features/telegram-dm-wipe.md
 */
class CreateStaffDmWipeLogTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('staff_dm_wipe_log')) {
            return;
        }

        Schema::create('staff_dm_wipe_log', function (Blueprint $table) {
            $table->id();

            // nullable 是 nullOnDelete 的前提；真正可靠的是下面的名稱快照
            $table->foreignId('operator_user_id')->nullable()->constrained('user')->nullOnDelete()->comment('操作者（自動清除時為 null）');
            $table->string('operator_name', 100)->comment('操作者名稱快照（自動清除時寫「系統」）');

            $table->foreignId('target_user_id')->nullable()->constrained('user')->nullOnDelete()->comment('被清除的同仁');
            $table->string('target_name', 100)->comment('被清除同仁的名稱快照');

            $table->tinyInteger('source')->comment('1=後台手動, 2=排程自動');

            // ⚠ 「清除」含同仁自己早就刪掉的：Telegram 對刪不掉的訊息是靜默跳過，分不出來（也沒必要分）
            $table->unsignedInteger('cleared_count')->default(0)->comment('已清除幾則');
            $table->unsignedInteger('expired_count')->default(0)->comment('超過 48 小時、永久刪不掉幾則');
            $table->unsignedInteger('failed_count')->default(0)->comment('這次送出失敗幾則（維持未處理，下一輪再試）');

            $table->timestamp('created_at')->useCurrent()->comment('操作時間');

            $table->index('created_at');
            $table->index(['target_user_id', 'created_at']);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('staff_dm_wipe_log')) {
            return;
        }

        Schema::dropIfExists('staff_dm_wipe_log');
    }
}
