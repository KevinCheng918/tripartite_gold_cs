<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 移除 `vm_server.status`（啟用／停用）
 *
 * 需求方確認（2026-10-13）：**開機才收錢，不開機就不用收**。
 * 一台機器要不要算錢、要不要催款，只由 `power_status` 決定 ——
 * 多一個「啟用」等於同一件事有兩個開關，而且兩個可以互相矛盾
 * （停用卻開機、啟用卻關機），看的人分不出哪個才算數。
 *
 * ⚠ **程式已經完全不讀這一欄**（查詢、篩選、顯示、表單、驗證都移除了）。
 * 這支只是把欄位本身清掉。
 *
 * ⚠ **建議等程式跑過一輪確認沒問題再跑這支。** 欄位留著不影響任何功能，
 * 但 drop 之後要回滾程式就得先把欄位加回來 —— 順序顛倒會卡住。
 * `down()` 會把欄位補回來（預設 1＝全部視為啟用），但**原本的值救不回來**。
 */
class DropStatusFromVmServerTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('vm_server', 'status')) {
            return;
        }

        Schema::table('vm_server', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('vm_server', 'status')) {
            return;
        }

        Schema::table('vm_server', function (Blueprint $table) {
            // 回滾只能回到「全部啟用」—— 原本誰被停用過已經查不到了
            $table->tinyInteger('status')->default(1)->after('powered_off_at')
                ->comment('已停用的概念，保留僅為相容舊版程式');
        });
    }
}
