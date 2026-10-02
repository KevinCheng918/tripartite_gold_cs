<?php

namespace App\Http\Requests\Consumable;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * 消耗品流水（領用／使用）驗證
 *
 * 新增與修改共用這一支。
 *
 * ⚠ **這裡只驗格式，不驗餘額** —— 「手上夠不夠扣」要查 DB 而且會因為
 * 其他人同時登記而變動，那是 ConsumableService 的事（它也負責在修改時
 * 把自己那一筆排除掉再算）。Request 驗完不代表存得進去。
 */
class StoreRecordRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $types = array_values(config('constants.CONSUMABLE.TYPE'));

        return [
            'user_id'            => 'required|integer|exists:user,id',
            'consumable_item_id' => 'required|integer|exists:consumable_item,id',
            'type'               => ['required', 'integer', Rule::in($types)],

            /*
             * 一律正整數，方向由 type 決定 —— 不接受 0（登記 0 張沒有意義）
             * 也不接受負數（那會變成另一個方向的紀錄，繞過 type 的語意）。
             */
            'quantity' => 'required|integer|min:1|max:' . config('constants.CONSUMABLE.QUANTITY_MAX'),

            // 自己填的日期，可以補登過去的；但不給填未來
            'happened_at' => 'required|date|before_or_equal:today',

            // 用途只有「使用」要填 —— 領用是公司發的，沒有用途可言
            'purpose' => [
                'nullable',
                'string',
                'max:' . config('constants.CONSUMABLE.PURPOSE_MAX'),
                Rule::requiredIf(function () {
                    return (int) $this->input('type') === (int) config('constants.CONSUMABLE.TYPE.USE');
                }),
            ],

            'note' => 'nullable|string|max:' . config('constants.CONSUMABLE.NOTE_MAX'),
        ];
    }

    public function messages()
    {
        return [
            'user_id.required'            => trans('staff_manage.msg.consumable_user_required'),
            'user_id.exists'              => trans('staff_manage.msg.consumable_user_required'),
            'consumable_item_id.required' => trans('staff_manage.msg.consumable_item_required'),
            'consumable_item_id.exists'   => trans('staff_manage.msg.consumable_item_not_found'),
            'type.required'               => trans('staff_manage.msg.consumable_type_required'),
            'type.in'                     => trans('staff_manage.msg.consumable_type_required'),
            'quantity.required'           => trans('staff_manage.msg.consumable_quantity_required'),
            'quantity.min'                => trans('staff_manage.msg.consumable_quantity_min'),
            'quantity.max'                => trans('staff_manage.msg.consumable_quantity_max', [
                'value' => config('constants.CONSUMABLE.QUANTITY_MAX'),
            ]),
            'happened_at.required'        => trans('staff_manage.msg.consumable_date_required'),
            'happened_at.before_or_equal' => trans('staff_manage.msg.consumable_date_future'),
            'purpose.required'            => trans('staff_manage.msg.consumable_purpose_required'),
            'purpose.max'                 => trans('staff_manage.msg.consumable_purpose_max'),
            'note.max'                    => trans('staff_manage.msg.consumable_note_max'),
        ];
    }

    /**
     * 驗證過的資料（Controller 只用這個）
     *
     * @return array
     */
    public function params()
    {
        $params = $this->validated();

        // 領用沒有用途，就算前端送了也不要存 —— 免得畫面上出現「領用：補卡」
        if ((int) Arr::get($params, 'type') === (int) config('constants.CONSUMABLE.TYPE.ISSUE')) {
            $params['purpose'] = null;
        }

        return $params;
    }
}
