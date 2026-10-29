<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 系統規章
 *
 * 需求方 2026-10-30：「做在專案管理這邊，每個專案不限定只有一個規章，
 * 可以有多個，那要有規章所屬專案、規章標題、規章內容，我在小幫手這邊
 * 要可以取得規章。」
 */
class CreateProjectRegulationTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('project_regulation')) {
            return;
        }

        Schema::create('project_regulation', function (Blueprint $table) {
            $table->id();

            // 一個專案多筆 —— 所以是關聯不是在 project 上加欄位
            $table->foreignId('project_id')->constrained('project')->cascadeOnDelete()
                ->comment('所屬專案');

            $table->string('title', 100)->comment('規章標題，例如「三方規章」');

            /*
             * ⚠ **要 longtext 不是 text**。
             *
             * `text` 的上限是 65,535 **位元組**，中文一個字佔 3 個 ——
             * 實際只放得下約 2 萬字。規章一不小心就超過，而超過時
             * MySQL 非 strict mode 會**安靜截斷**：存檔看起來成功，
             * 後面少了一段卻沒有人會發現。
             */
            $table->longText('content')->comment('規章內容（純文字）');

            $table->tinyInteger('status')->default(1)->comment('1=啟用, 0=停用');
            $table->smallInteger('sort')->default(0)->comment('同一個專案底下的排序');

            /*
             * ⚠ 只記「誰最後改的、什麼時候」，不做版本比對（需求方 2026-10-30）。
             * 規章是會被拿來當依據的東西，至少要答得出「這版是誰改的」。
             */
            $table->foreignId('created_by')->nullable()->constrained('user')->nullOnDelete()
                ->comment('建立者');
            $table->foreignId('updated_by')->nullable()->constrained('user')->nullOnDelete()
                ->comment('最後修改者');

            $table->timestamps();

            // 後台一定是「某個專案底下的規章，照 sort 排」
            $table->index(['project_id', 'sort'], 'project_regulation_project_sort_idx');

            /*
             * ⚠ 標題要給索引：小幫手是**用標題找**的（「給我三方規章」），
             * 而那是每次指令都會下的查詢。
             */
            $table->index('title', 'project_regulation_title_idx');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('project_regulation');
    }
}
