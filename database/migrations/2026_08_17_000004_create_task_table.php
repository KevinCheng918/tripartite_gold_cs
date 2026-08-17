<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTaskTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('task')) {
            Schema::create('task', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->string('title', 200);
                $table->text('description')->nullable();
                $table->tinyInteger('status')->default(1)->comment('1=待處理, 2=進行中, 3=審核中, 4=已解決');
                $table->tinyInteger('priority')->default(2)->comment('1=低, 2=中, 3=高, 4=緊急');
                $table->unsignedBigInteger('assignee_id')->nullable();
                $table->unsignedBigInteger('creator_id');
                $table->date('due_date')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->foreign('project_id')->references('id')->on('project')->cascadeOnDelete();
                $table->foreign('assignee_id')->references('id')->on('user')->nullOnDelete();
                $table->foreign('creator_id')->references('id')->on('user')->nullOnDelete();
                $table->index(['status', 'sort_order']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('task');
    }
}
