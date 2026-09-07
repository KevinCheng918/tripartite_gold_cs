<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shared_folder 加入 parent_id，支援子資料夾
 *
 * 既有資料夾的 parent_id 維持 null，即最上層。
 */
class AddParentIdToSharedFolderTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('shared_folder', function (Blueprint $table) {
            if (Schema::hasColumn('shared_folder', 'parent_id')) {
                return;
            }

            // nullable：最上層資料夾沒有父層。
            // 用 nullOnDelete 而非 cascade —— MySQL 自我參照的 FK 串接刪除行為不可靠，
            // 遞迴刪除改由 SharedFileService::deleteFolder() 處理，
            // 這裡只是萬一有人直接動 DB 時不讓子資料夾留下無效的 parent_id
            $table->unsignedBigInteger('parent_id')->nullable()->after('name')
                ->comment('上層資料夾，null 表示最上層');

            $table->foreign('parent_id')->references('id')->on('shared_folder')->nullOnDelete();
            $table->index(['type', 'parent_id'], 'idx_shared_folder_type_parent');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('shared_folder', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex('idx_shared_folder_type_parent');
            $table->dropColumn('parent_id');
        });
    }
}
