<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立消耗品品項表（consumable_item）
 *
 * 內勤領用的消耗品種類（卡片、SIM 卡…）。做成一張可維護的表而不是寫死的
 * 常數 —— 之後要加新的消耗品不用改程式。
 */
class CreateConsumableItemTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('consumable_item')) {
            return;
        }

        Schema::create('consumable_item', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('品項名稱，如：卡片');

            // 純顯示用（「剩餘 7 張」），不參與任何計算
            $table->string('unit', 10)->nullable()->comment('單位，如：張');

            /*
             * 停用的品項不能再登記新紀錄，但**舊紀錄照常顯示與計算** ——
             * 所以是狀態而不是刪除。真的要刪會被外鍵擋住（見流水表）。
             */
            $table->tinyInteger('status')->default(1)->comment('1=啟用, 0=停用');

            $table->integer('sort_order')->default(0)->comment('排序');
            $table->string('note', 255)->nullable()->comment('備註');
            $table->timestamps();

            $table->index(['status', 'sort_order'], 'consumable_item_status_sort_index');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('consumable_item')) {
            return;
        }

        Schema::dropIfExists('consumable_item');
    }
}
