<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 公告範本加「公告編號」（需求方 2026-10-13）
 *
 * 人工輸入（例如 2026-001、AD-10），用來在後台對照是哪一則公告。
 *
 * ⚠ **不會發給客人** —— 跟標題一樣只在後台辨識用。
 *
 * ⚠ 欄位 `nullable` 但表單必填：既有的公告沒有編號，加 NOT NULL 會讓
 * migration 直接失敗（或被迫塞一個假值進去）。DB 寬、表單嚴，
 * 既有資料維持空白等人去補。
 *
 * ⚠ 加 `unique`：編號的用途就是唯一識別，重複的編號等於沒有編號。
 * MySQL 的 unique 索引允許多個 NULL，所以既有那幾筆空白不會互相衝突。
 */
class AddCodeToBroadcastTemplateTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('broadcast_template') || Schema::hasColumn('broadcast_template', 'code')) {
            return;
        }

        Schema::table('broadcast_template', function (Blueprint $table) {
            $table->string('code', 32)->nullable()->after('id')
                ->comment('公告編號，人工輸入，只在後台辨識用');

            $table->unique('code', 'broadcast_template_code_unique');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('broadcast_template') || !Schema::hasColumn('broadcast_template', 'code')) {
            return;
        }

        Schema::table('broadcast_template', function (Blueprint $table) {
            // 索引要先拿掉，不然 MySQL 不讓你刪掉它依賴的欄位
            $table->dropUnique('broadcast_template_code_unique');
            $table->dropColumn('code');
        });
    }
}
