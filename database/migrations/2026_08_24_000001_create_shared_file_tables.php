<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSharedFileTables extends Migration
{
    public function up()
    {
        Schema::create('shared_folder', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 10)->default('shared')->comment('shared=共用, personal=個人');
            $table->unsignedBigInteger('user_id')->nullable()->comment('個人文件區擁有者');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('user')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('user')->nullOnDelete();
        });

        Schema::create('shared_file', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('folder_id');
            $table->string('original_name', 255);
            $table->string('file_path', 500);
            $table->unsignedBigInteger('file_size')->default(0)->comment('bytes');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('folder_id')->references('id')->on('shared_folder')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('user')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('shared_file');
        Schema::dropIfExists('shared_folder');
    }
}
