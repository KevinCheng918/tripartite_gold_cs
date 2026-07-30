<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立個人存取權杖表（personal_access_tokens）
 */
class CreatePersonalAccessTokensTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::dropIfExists('personal_access_tokens');
    }
}
