<?php

namespace Database\Seeders;

use App\Models\Shift;
use Illuminate\Database\Seeder;

/**
 * 初始班別 Seeder
 *
 * 建立預設的三班制（早班/午班/晚班），
 * 若班別已存在則跳過不重複建立。
 */
class ShiftSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run()
    {
        $shifts = [
            [
                'name' => Shift::NAME_MORNING,
                'display_name' => '早班',
                'start_time' => '08:00',
                'end_time' => '16:00',
                'sort' => 1,
            ],
            [
                'name' => Shift::NAME_AFTERNOON,
                'display_name' => '午班',
                'start_time' => '16:00',
                'end_time' => '00:00',
                'sort' => 2,
            ],
            [
                'name' => Shift::NAME_NIGHT,
                'display_name' => '晚班',
                'start_time' => '00:00',
                'end_time' => '08:00',
                'sort' => 3,
            ],
        ];

        foreach ($shifts as $shift) {
            Shift::query()->firstOrCreate(
                ['name' => $shift['name']],
                $shift
            );
        }

        $this->command->info('班別初始化完成（早班/午班/晚班）。');
    }
}
