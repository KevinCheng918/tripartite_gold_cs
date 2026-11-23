<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * 踢出登入
 *
 * ⚠ **擋掉「踢自己」**：那會讓按下按鈕的人自己被登出，而他多半以為
 * 自己踢的是別人。帳號列表本來就不列自己（`exclude_id`），
 * 但 API 是公開端點，不能只靠前端不給按。
 */
class KickRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'user_id' => [
                'required',
                'integer',
                'exists:user,id',
                /*
                 * ⚠⚠ **不能用 `different:`** —— 那一條比的是「跟**另一個欄位**
                 * 的值不同」，`different:5` 會去找名為 `5` 的欄位，找不到就
                 * **直接通過**。第一版這樣寫，結果踢自己完全沒被擋
                 * （實測時真的把自己踢下線了）。
                 *
                 * `not_in:` 比的才是值。
                 */
                'not_in:' . Auth::id(),
            ],
        ];
    }

    public function messages()
    {
        return [
            'user_id.required' => trans('account.msg.not_found'),
            'user_id.exists'   => trans('account.msg.not_found'),
            'user_id.not_in'   => trans('account.msg.kick_self'),
        ];
    }
}
