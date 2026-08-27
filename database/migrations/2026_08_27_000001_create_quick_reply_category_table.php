<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateQuickReplyCategoryTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('quick_reply_category')) {
            Schema::create('quick_reply_category', function (Blueprint $table) {
                $table->id();
                $table->string('label', 100)->comment('類別名稱');
                $table->unsignedInteger('sort')->default(0)->comment('排序，小的在前');
                $table->tinyInteger('status')->default(1)->comment('1=啟用, 0=停用');
                $table->timestamps();

                $table->index(['status', 'sort']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('quick_reply_category');
    }
}
