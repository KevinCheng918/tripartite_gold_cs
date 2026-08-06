<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立系統表（system）
 *
 * 記錄可選擇的系統（如 tripartite_gold 等），
 * 站台建立時需選擇所屬系統。
 */
class CreateSystemTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('system')) {
            return;
        }

        Schema::create('system', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique()->comment('系統名稱');
            $table->tinyInteger('status')->default(1)->comment('1=啟用, 0=停用');
            $table->timestamps();
        });

        // station 表加 system_id 欄位
        if (Schema::hasTable('station') && !Schema::hasColumn('station', 'system_id')) {
            Schema::table('station', function (Blueprint $table) {
                $table->foreignId('system_id')->nullable()->after('id')->constrained('system')->nullOnDelete()->comment('所屬系統');
            });
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('station') && Schema::hasColumn('station', 'system_id')) {
            Schema::table('station', function (Blueprint $table) {
                $table->dropConstrainedForeignId('system_id');
            });
        }

        if (!Schema::hasTable('system')) {
            return;
        }

        Schema::dropIfExists('system');
    }
}
