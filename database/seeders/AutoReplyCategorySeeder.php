<?php

namespace Database\Seeders;

use App\Models\QuickReplyCategory;
use Illuminate\Database\Seeder;

/**
 * 建立「待整理」題庫類別
 *
 * 支援群組回填題庫時要選類別，不確定歸哪一類就丟這裡，事後再整理。
 * sort 給一個很大的值，讓它永遠排在類別按鈕的最後面。
 *
 * @example php artisan db:seed --class=AutoReplyCategorySeeder
 */
class AutoReplyCategorySeeder extends Seeder
{
    /** @var int 排序值，刻意給大數字讓它排最後 */
    private const SORT = 9999;

    /**
     * @return void
     */
    public function run()
    {
        $label = config('constants.AUTO_REPLY.PENDING_CATEGORY');

        // 已經有就不動 —— 重跑 seeder 不該覆蓋使用者調整過的排序或狀態
        $exists = QuickReplyCategory::query()
            ->select(['id'])
            ->where('label', $label)
            ->exists();

        if ($exists) {
            $this->command->info("類別「{$label}」已存在，略過");

            return;
        }

        QuickReplyCategory::query()->create([
            'label'  => $label,
            'sort'   => self::SORT,
            'status' => config('constants.QUICK_REPLY.STATUS.ACTIVE'),
        ]);

        $this->command->info("已建立類別「{$label}」");
    }
}
