<?php

namespace App\Http\Requests\Consumable;

use Illuminate\Foundation\Http\FormRequest;
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
            'user_id' => 'required|integer|exists:user,id',

            // 品項直接打名稱，沒有品項表 —— 不限定只能選既有的那些
            'item_name' => 'required|string|max:50',

            'type' => ['required', 'integer', Rule::in($types)],

            /*
             * 一律正整數，方向由 type 決定 —— 不接受 0（登記 0 張沒有意義）
             * 也不接受負數（那會變成另一個方向的紀錄，繞過 type 的語意）。
             */
            'quantity' => 'required|integer|min:1|max:' . config('constants.CONSUMABLE.QUANTITY_MAX'),

            // 自己填的日期，可以補登過去的；但不給填未來
            'happened_at' => 'required|date|before_or_equal:today',

            // 只有一個備註欄，選填（原本還分「用途」，需求方確認用不到）
            'note' => 'nullable|string|max:' . config('constants.CONSUMABLE.NOTE_MAX'),
        ];
    }

    public function messages()
    {
        return [
            'user_id.required'   => trans('staff_manage.msg.consumable_user_required'),
            'user_id.exists'     => trans('staff_manage.msg.consumable_user_required'),
            'item_name.required' => trans('staff_manage.msg.consumable_item_required'),
            'item_name.max'      => trans('staff_manage.msg.consumable_name_max'),
            'type.required'      => trans('staff_manage.msg.consumable_type_required'),
            'type.in'            => trans('staff_manage.msg.consumable_type_required'),
            'quantity.required'  => trans('staff_manage.msg.consumable_quantity_required'),
            'quantity.min'       => trans('staff_manage.msg.consumable_quantity_min'),
            'quantity.max'       => trans('staff_manage.msg.consumable_quantity_max', [
                'value' => config('constants.CONSUMABLE.QUANTITY_MAX'),
            ]),
            'happened_at.required'        => trans('staff_manage.msg.consumable_date_required'),
            'happened_at.before_or_equal' => trans('staff_manage.msg.consumable_date_future'),
            'note.max'                    => trans('staff_manage.msg.consumable_note_max'),
        ];
    }
}
