<?php

namespace App\Http\Requests\Station;

use App\Models\CreditTopup;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 申請補點／扣點驗證
 *
 * ⚠ 這一支之所以從 Controller 搬出來，是因為「其他」類型的備註必填
 * 需要一句**中文**錯誤訊息。寫在 Controller 的 `validate()` 裡只能吃
 * Laravel 的預設文案（"The note field is required when credit type is
 * other."），前端擋下來時講中文、後端擋下來時講英文，同一條規則兩種臉。
 */
class StoreTopupRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            'station_id'    => 'required|integer|exists:station,id',
            'action_type'   => 'required|integer|in:1,2',
            // other = 不呼叫主系統 API，只記一筆帳（見 CreditTopup::CREDIT_TYPE_OTHER）
            'credit_type'   => 'required|string|in:' . implode(',', [
                CreditTopup::CREDIT_TYPE_CREDIT,
                CreditTopup::CREDIT_TYPE_SHOP,
                CreditTopup::CREDIT_TYPE_OTHER,
            ]),
            // input_type = 2 為直接輸入點數，此時不需要 USDT 與匯率
            'input_type'    => 'required|integer|in:1,2',
            'usdt_amount'   => 'required_if:input_type,1|nullable|numeric|min:0.0001',
            'exchange_rate' => 'required_if:input_type,1|nullable|numeric|min:0.0001',
            'credit_amount' => 'required|numeric|min:0.01',
            /*
             * ⚠ 「其他」的備註**必填**（需求方 2026-10-14）。這一類不打
             * 主系統 API，紀錄上也就沒有任何外部回應可以對照 ——
             * 不寫備註的話，事後完全沒人知道這筆是什麼。
             */
            'note'          => 'required_if:credit_type,' . CreditTopup::CREDIT_TYPE_OTHER . '|nullable|string|max:500',
            'images'        => 'nullable|array',
            'images.*'      => 'image|max:5120',
        ];
    }

    /**
     * @return array
     */
    public function messages()
    {
        return [
            'note.required_if' => trans('station.topup_other_note_required'),
        ];
    }
}
