<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * 全域設定 Model
 *
 * key-value 形式的系統層級設定，由後台「全域設定」頁維護。
 * 敏感值（Claude token、API key）以 Crypt::encrypt 加密存放，
 * 沿用 User 密碼的既有慣例 —— 是可還原的加密而非雜湊，因為用的時候需要明文。
 *
 * @property int         $id
 * @property string      $key        設定鍵
 * @property string|null $value      設定值（is_secret 時為加密內容）
 * @property bool        $is_secret  是否為敏感值
 * @property int|null    $updated_by 最後修改者
 */
class AppSetting extends Model
{
    protected $table = 'app_setting';
    protected $guarded = ['id'];

    protected $casts = [
        'is_secret'  => 'boolean',
        'updated_by' => 'integer',
    ];

    /**
     * 最後修改者
     *
     * @return BelongsTo
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->select(['id', 'account', 'nickname']);
    }

    /**
     * 取得可直接使用的值：敏感欄位自動解密
     *
     * 解密失敗（APP_ENCRYPT_KEY 換過、資料被改壞）時回 null 而不是拋例外 ——
     * 一把壞掉的 token 應該讓自動回覆降級成人工，不該讓整個請求 500。
     *
     * @return string|null
     */
    public function getPlainValueAttribute()
    {
        if (!filled($this->value)) {
            return null;
        }

        if (!$this->is_secret) {
            return $this->value;
        }

        try {
            return \Crypt::decrypt($this->value);
        } catch (\Exception $e) {
            // 只記 key，不記內容 —— 這裡放的是 token 與 API key
            Log::error('全域設定解密失敗', ['key' => $this->key, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * 遮罩後的值，供前端顯示
     *
     * 敏感值的明文永遠不會離開伺服器，前端只看得到頭尾。
     *
     * @return string|null
     */
    public function getMaskedValueAttribute()
    {
        $plain = $this->plain_value;

        if (!filled($plain)) {
            return null;
        }

        $length = mb_strlen($plain);

        // 太短的值沒有安全的遮罩方式，整串蓋掉
        if ($length <= 12) {
            return str_repeat('*', $length);
        }

        return mb_substr($plain, 0, 8) . str_repeat('*', 8) . mb_substr($plain, -4);
    }
}
