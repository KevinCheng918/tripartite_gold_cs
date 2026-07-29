<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 班別定義 Model
 *
 * 系統預設三班制（早班/午班/晚班），Admin 可調整各班別起訖時間。
 *
 * @property int    $id
 * @property string $name         班別代碼（morning / afternoon / night）
 * @property string $display_name 顯示名稱
 * @property string $start_time   班別開始時間
 * @property string $end_time     班別結束時間
 * @property bool   $is_active    是否啟用
 * @property int    $sort         排序權重
 */
class Shift extends Model
{
    use HasFactory;

    /** @var string 早班 */
    public const NAME_MORNING = 'morning';

    /** @var string 午班 */
    public const NAME_AFTERNOON = 'afternoon';

    /** @var string 晚班 */
    public const NAME_NIGHT = 'night';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * 此班別下的所有排班紀錄
     *
     * @return HasMany
     */
    public function assignments()
    {
        return $this->hasMany(ShiftAssignment::class);
    }
}
