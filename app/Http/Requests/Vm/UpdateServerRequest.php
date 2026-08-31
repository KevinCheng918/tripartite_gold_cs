<?php

namespace App\Http\Requests\Vm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新虛擬機驗證
 *
 * IP 欄位只限制長度不驗格式：內外網 IP 可能填網段、主機名或含 port 的寫法。
 */
class UpdateServerRequest extends FormRequest
{
    /**
     * 帳單日上限
     *
     * 新增與編輯共用同一個值 —— 兩邊若不一致（先前新增可到 31、編輯只到 28），
     * 帳單日 29 以後的主機一按編輯就會驗證失敗。
     *
     * @var int
     */
    protected const BILLING_DAY_MAX = 31;

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
            'station_id'   => 'sometimes|integer|exists:station,id',
            'hostname'     => 'sometimes|string|max:100',
            'internal_ip'  => 'nullable|string|max:45',
            'external_ip'  => 'nullable|string|max:45',
            'model_type'   => 'nullable|string|max:100',
            'spec'         => 'sometimes|string|max:255',
            'monthly_fee'  => 'sometimes|numeric|min:0',
            'vpn_fee'      => 'nullable|numeric|min:0',
            'google_fee'   => 'nullable|numeric|min:0',
            'billing_day'  => 'sometimes|integer|min:1|max:' . self::BILLING_DAY_MAX,
            'status'       => 'sometimes|integer|in:0,1',
            'note'         => 'nullable|string',
        ];
    }

    public function messages()
    {
        return [
            'station_id.exists'  => trans('vm.msg.station_not_found'),
            'hostname.max'       => trans('vm.msg.max_string', ['field' => trans('vm.field_hostname'), 'value' => '100']),
            'internal_ip.max'    => trans('vm.msg.max_string', ['field' => trans('vm.field_internal_ip'), 'value' => '45']),
            'external_ip.max'    => trans('vm.msg.max_string', ['field' => trans('vm.field_external_ip'), 'value' => '45']),
            'model_type.max'     => trans('vm.msg.max_string', ['field' => trans('vm.field_model_type'), 'value' => '100']),
            'spec.max'           => trans('vm.msg.max_string', ['field' => trans('vm.field_spec'), 'value' => '255']),
            'monthly_fee.numeric' => trans('vm.msg.numeric_required', ['field' => trans('vm.field_monthly_fee')]),
            'vpn_fee.numeric'    => trans('vm.msg.numeric_required', ['field' => trans('vm.field_vpn_fee')]),
            'google_fee.numeric' => trans('vm.msg.numeric_required', ['field' => trans('vm.field_google_fee')]),
            'billing_day.integer' => trans('vm.msg.billing_day_range', ['max' => self::BILLING_DAY_MAX]),
            'billing_day.min'    => trans('vm.msg.billing_day_range', ['max' => self::BILLING_DAY_MAX]),
            'billing_day.max'    => trans('vm.msg.billing_day_range', ['max' => self::BILLING_DAY_MAX]),
        ];
    }
}
