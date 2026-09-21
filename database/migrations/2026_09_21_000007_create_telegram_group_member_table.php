<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立 Telegram 群組成員名冊表（telegram_group_member）
 *
 * 一張表同時是「這個對話裡誰發言過」的名冊，和「誰不要自動回覆」的名單 ——
 * 忽略只是這個人身上的一個狀態（ignored），拆兩張表會多出「名單有、名冊沒有」的對應問題。
 *
 * 刻意不改 telegram_message：那張表資料量大，加欄位要 ALTER 會鎖表。
 * 而且「誰發言過」本來就該是一份名冊，不該每次去大表做 DISTINCT。
 */
class CreateTelegramGroupMemberTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('telegram_group_member')) {
            return;
        }

        Schema::create('telegram_group_member', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_group_id')->constrained('telegram_group')->cascadeOnDelete()->comment('所屬對話');

            // 兩個識別鍵，至少要有一個：
            // 發言過的人一定有 telegram_user_id；手動輸入的只有 username
            $table->unsignedBigInteger('telegram_user_id')->nullable()->comment('Telegram 使用者 ID，發言過才有');
            $table->string('username', 32)->nullable()->comment('Telegram username，存小寫且不含 @；對方可能沒設');

            $table->string('display_name', 255)->nullable()->comment('最近一次看到的顯示名稱，只給 UI 看不參與比對');
            $table->timestamp('last_seen_at')->nullable()->comment('最後發言時間；null 代表是手動加入的');

            $table->boolean('ignored')->default(false)->comment('是否不自動回覆');
            $table->foreignId('ignored_by')->nullable()->constrained('user')->nullOnDelete()->comment('誰設的');
            $table->timestamp('ignored_at')->nullable()->comment('什麼時候設的');

            $table->timestamps();

            // MySQL 的 unique 允許多筆 NULL，所以手動加入的多個成員
            // （telegram_user_id 皆為 null）不會互相衝突
            $table->unique(['telegram_group_id', 'telegram_user_id'], 'tg_member_group_user_unique');
            $table->unique(['telegram_group_id', 'username'], 'tg_member_group_username_unique');
            $table->index(['telegram_group_id', 'ignored'], 'tg_member_group_ignored_index');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('telegram_group_member')) {
            return;
        }

        Schema::dropIfExists('telegram_group_member');
    }
}
