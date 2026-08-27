<?php

namespace Database\Seeders;

use App\Models\QuickReplyCategory;
use App\Models\QuickReplyItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 快速回覆題庫初始資料
 *
 * 從 config/quick_reply.php 匯入初始題庫，之後改由後台維護。
 * 可重複執行：已存在同名類別時只補缺少的問答，不會重複塞。
 */
class QuickReplySeeder extends Seeder
{
    public function run()
    {
        $source = config('quick_reply', []);
        if (!$source) {
            $this->command->warn('config/quick_reply.php 沒有資料，略過');

            return;
        }

        DB::transaction(function () use ($source) {
            $categorySort = 0;

            foreach ($source as $category) {
                $categorySort++;

                $model = QuickReplyCategory::query()->firstOrCreate(
                    ['label' => $category['label']],
                    [
                        'sort'   => $categorySort,
                        'status' => config('constants.QUICK_REPLY.STATUS.ACTIVE'),
                    ]
                );

                $itemSort = 0;
                foreach ($category['items'] as $item) {
                    $itemSort++;

                    QuickReplyItem::query()->firstOrCreate(
                        [
                            'category_id' => $model->id,
                            'label'       => $item['label'],
                        ],
                        [
                            'answer' => $item['answer'],
                            'sort'   => $itemSort,
                            'status' => config('constants.QUICK_REPLY.STATUS.ACTIVE'),
                        ]
                    );
                }
            }
        });

        $this->command->info('快速回覆題庫匯入完成：'
            . QuickReplyCategory::query()->count() . ' 類 / '
            . QuickReplyItem::query()->count() . ' 題');
    }
}
