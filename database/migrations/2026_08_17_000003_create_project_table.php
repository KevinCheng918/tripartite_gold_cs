<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProjectTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('project')) {
            Schema::create('project', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->text('description')->nullable();
                $table->tinyInteger('status')->default(1)->comment('1=啟用, 0=停用');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->foreign('created_by')->references('id')->on('user')->nullOnDelete();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('project');
    }
}
