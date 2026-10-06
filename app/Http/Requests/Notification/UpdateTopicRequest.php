<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * 更新話題分流設定
 */
class UpdateTopicRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // 全部刪掉 = 所有通知都發到群組主區
            'topics'             => 'nullable|array|max:' . (int) config('constants.SUPPORT_TOPIC.MAX_TOPICS'),
            'topics.*.name'      => 'nullable|string|max:' . (int) config('constants.SUPPORT_TOPIC.NAME_MAX'),
            /*
             * 話題 id 是正整數。
             *
             * ⚠ 不驗「這個話題真的存在」—— Bot API 沒有「列出群組所有話題」的方法，
             * 唯一的驗證方式是真的發一則過去。那是「測試發送」按鈕的工作。
             */
            'topics.*.thread_id' => 'required|integer|min:1',
            'topics.*.types'     => 'nullable|array',
            'topics.*.types.*'   => 'string|in:' . implode(',', array_keys((array) config('constants.SUPPORT_TOPIC.TYPES'))),
        ];
    }

    public function messages()
    {
        return [
            'topics.max'                 => trans('notification.msg.topic_too_many'),
            'topics.*.thread_id.required' => trans('notification.msg.thread_id_required'),
            'topics.*.thread_id.integer' => trans('notification.msg.thread_id_invalid'),
            'topics.*.thread_id.min'     => trans('notification.msg.thread_id_invalid'),
            'topics.*.name.max'          => trans('notification.msg.topic_name_too_long'),
            'topics.*.types.*.in'        => trans('notification.msg.notice_type_invalid'),
        ];
    }

    /**
     * 額外驗證
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $topics = (array) $this->input('topics', []);

            $this->checkDuplicateThreadId($validator, $topics);
            $this->checkSingleTopicTypes($validator, $topics);
        });
    }

    /**
     * 同一個話題 id 不能出現兩次
     *
     * 重複的話同一則通知會發兩遍到同一個話題，而且設定頁會看起來像有兩筆設定。
     *
     * @param \Illuminate\Validation\Validator $validator
     * @param array                            $topics
     * @return void
     */
    private function checkDuplicateThreadId($validator, array $topics)
    {
        $seen = [];

        foreach ($topics as $topic) {
            $threadId = (int) Arr::get($topic, 'thread_id', 0);

            if ($threadId < 1) {
                continue;
            }

            if (in_array($threadId, $seen, true)) {
                $validator->errors()->add('topics', trans('notification.msg.thread_id_duplicate', ['id' => $threadId]));

                return;
            }

            $seen[] = $threadId;
        }
    }

    /**
     * 「要等客服引用回覆」的通知只能勾一個話題
     *
     * ⚠ 這不是潔癖，是會壞掉：求助單靠 `ask_message_id` 對回客服的引用回覆、
     * 匯率靠 `ask_message_id` 對回決定的那句。發到兩個話題會有兩個 message_id，
     * 系統只記得到一個 —— **另一個話題裡的回覆就變成「回了也沒反應」**，
     * 而且完全不會報錯。
     *
     * @param \Illuminate\Validation\Validator $validator
     * @param array                            $topics
     * @return void
     */
    private function checkSingleTopicTypes($validator, array $topics)
    {
        $counts = [];

        foreach ($topics as $topic) {
            foreach ((array) Arr::get($topic, 'types', []) as $type) {
                $counts[$type] = (int) Arr::get($counts, $type, 0) + 1;
            }
        }

        foreach ((array) config('constants.SUPPORT_TOPIC.TYPES') as $key => $type) {
            if (!Arr::get($type, 'needs_reply', false)) {
                continue;
            }

            if ((int) Arr::get($counts, $key, 0) > 1) {
                $validator->errors()->add('topics', trans('notification.msg.type_single_only', [
                    'type' => (string) Arr::get($type, 'label', $key),
                ]));
            }
        }
    }
}
