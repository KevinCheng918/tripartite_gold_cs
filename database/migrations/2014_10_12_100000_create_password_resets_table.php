<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立密碼重設表（password_resets）
 */
class CreatePasswordResetsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('password_resets')) {
            return;
        }

        Schema::create('password_resets', function (Blueprint $table) {
            $table->string('account')->index()->comment('帳號');
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('password_resets')) {
            return;
        }

        Schema::dropIfExists('password_resets');
    }
}
