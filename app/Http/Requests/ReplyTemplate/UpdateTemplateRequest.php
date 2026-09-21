<?php

namespace App\Http\Requests\ReplyTemplate;

use App\Services\ReplyTemplateService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新對客話術模板
 *
 * 模板是客人唯一看得到的東西，所以驗證比其他設定嚴格：
 * 不能留空（留空就送不出訊息），而且**必須保留該用的變數** ——
 * 少了 {答案} 的模板會讓客人收到一則沒有內容的客套話。
 */
class UpdateTemplateRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $rules = [];

        foreach (array_keys(ReplyTemplateService::FIELDS) as $field) {
            $rules[$field] = 'required|string|max:2000';
        }

        return $rules;
    }

    public function messages()
    {
        $messages = [];

        foreach (array_keys(ReplyTemplateService::FIELDS) as $field) {
            $messages["{$field}.required"] = trans('reply_template.msg.required');
            $messages["{$field}.max"] = trans('reply_template.msg.max', ['value' => '2000']);
        }

        return $messages;
    }

    /**
     * 檢查模板有沒有保留必要的變數
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            foreach (ReplyTemplateService::FIELDS as $field => $placeholder) {
                if (!filled($placeholder)) {
                    continue;
                }

                $value = (string) $this->input($field);

                if (filled($value) && mb_strpos($value, $placeholder) === false) {
                    $validator->errors()->add(
                        $field,
                        trans('reply_template.msg.placeholder_missing', ['value' => $placeholder])
                    );
                }
            }
        });
    }
}
