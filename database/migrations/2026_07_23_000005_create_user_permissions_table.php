<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立帳號權限表（user_permissions）
 *
 * 權限直接綁定帳號，不經過角色。
 * 管理者（level=0）自動 bypass 所有權限檢查，
 * 客服（level=1）依此表的 keyword 判斷。
 */
class CreateUserPermissionsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('user_permissions')) {
            return;
        }

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user')->cascadeOnDelete()->comment('帳號 ID');
            $table->string('permission_keyword', 100)->comment('權限 keyword');
            $table->timestamps();

            $table->unique(['user_id', 'permission_keyword'], 'user_permissions_unique');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('user_permissions')) {
            return;
        }

        Schema::dropIfExists('user_permissions');
    }
}
