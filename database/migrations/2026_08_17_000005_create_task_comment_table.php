<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTaskCommentTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('task_comment')) {
            Schema::create('task_comment', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('task_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->text('content');
                $table->timestamps();

                $table->foreign('task_id')->references('id')->on('task')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('user')->nullOnDelete();
                $table->index('task_id');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('task_comment');
    }
}
