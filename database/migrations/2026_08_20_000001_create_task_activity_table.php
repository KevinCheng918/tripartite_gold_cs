<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTaskActivityTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('task_activity')) {
            Schema::create('task_activity', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('task_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('action', 50)->comment('動作：created, updated, moved, deleted');
                $table->json('changes')->nullable()->comment('變更內容 JSON');
                $table->timestamps();

                $table->foreign('task_id')->references('id')->on('task')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('user')->nullOnDelete();
                $table->index(['task_id', 'created_at']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('task_activity');
    }
}
