<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 任務加上「參與人」
 *
 * 需求方 2026-10-30：「任務版要新增一個欄位是參與人，在總覽時不用看到，
 * 但點進去卡片詳細內容時要能夠看到跟編輯。」
 *
 * ⚠ **跟 `assignee_ids`（指派人員）是兩回事**，差別一定要分得開，
 * 不然兩個欄位會被隨意互換使用：
 *
 *   - 指派人員 ＝ **負責把這件事做完的人**（這張卡片結案與否由他負責）
 *   - 參與人   ＝ **一起處理或需要知道的人**，但不負責結案
 *
 * ⚠ **用 JSON 欄位而不是關聯表**，跟 `assignee_ids` 一致 —— 這個系統的任務
 * 參與者不需要「加入時間」「角色」之類的額外欄位，開表只會多一層 join。
 */
class AddParticipantIdsToTaskTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('task') || Schema::hasColumn('task', 'participant_ids')) {
            return;
        }

        Schema::table('task', function (Blueprint $table) {
            /*
             * ⚠ **一定要 nullable**：既有的任務全部沒有參與人，
             * 預設就該是空的。不給 nullable 的話這支 migration 在有資料的
             * 表上會直接失敗。
             *
             * ⚠ 擺在 `assignee_ids` 後面 —— 兩個人員欄位相鄰，
             * 日後用 `DESCRIBE task` 看結構時也看得出它們是一組的。
             */
            $table->json('participant_ids')->nullable()->after('assignee_ids')
                ->comment('參與人 ID 陣列（一起處理或需要知道，但不負責結案）');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('task') || !Schema::hasColumn('task', 'participant_ids')) {
            return;
        }

        Schema::table('task', function (Blueprint $table) {
            $table->dropColumn('participant_ids');
        });
    }
}
