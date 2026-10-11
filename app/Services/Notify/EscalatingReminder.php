<?php

namespace App\Services\Notify;

use App\Repositories\UserRepository;
use Illuminate\Support\Arr;

/**
 * 會升級的提醒：算「這一次是第幾次、該 tag 誰、是不是最後一則」
 *
 * 求助單超時提醒（[[remind-escalation]]）與待追蹤事項共用這一份規則：
 *
 * | 第幾次 | tag 誰 |
 * |---|---|
 * | 1 ～ escalateAt-1 | 當班人員 |
 * | escalateAt 以後 | 當班人員 **＋** 主管與老闆 |
 * | 超過上限 | 收尾那則，tag 當班＋主管老闆，之後不再提醒 |
 *
 * ⚠ **只做計算與名單，不送訊息也不寫 DB。** 文案、要不要引用回覆、提醒紀錄
 * 怎麼落地，兩邊差很多（求助單要引用原求助訊息、待追蹤事項要掛按鈕），
 * 硬塞進來會變成一支到處都是 if 的服務。
 *
 * ⚠ **第 escalateAt 次以後是「當班人員＋主管老闆」，不是「換成主管」**——
 * 2026-10-06 之前是後者，當班的人會以為事情已經不關他了。
 */
class EscalatingReminder
{
    private $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * 取這一輪要用的名單
     *
     * ⚠ **一輪只呼叫一次**。排程每分鐘跑，而同時卡住十筆是常態 ——
     * 逐筆去查排班與主管名單就是十倍查詢，而一分鐘內這兩份名單都不會變。
     *
     * @param array<int> $onDutyIds 當班人員的 user.id
     * @return array{on_duty: \Illuminate\Support\Collection, escalated: \Illuminate\Support\Collection}
     */
    public function targets(array $onDutyIds)
    {
        $onDuty = collect($this->userRepository->getMentionableByIds($onDutyIds));

        return [
            'on_duty' => $onDuty,
            // id 去重：主管自己也在值班時，不要被 tag 兩次
            'escalated' => $onDuty
                ->concat($this->userRepository->getManagersForMention())
                ->unique('id')
                ->values(),
        ];
    }

    /**
     * 這一次要怎麼提醒
     *
     * @param int   $remindCount 已經提醒過幾次
     * @param array $targets     `targets()` 的回傳
     * @param array $options     max（上限次數）、escalate_at（第幾次開始叫主管）
     * @return array{seq:int, stage:int, users:\Illuminate\Support\Collection, is_final:bool, next_count:int}
     */
    public function plan($remindCount, array $targets, array $options)
    {
        $stages = (array) config('constants.AUTO_REPLY.REMIND.STAGE');
        $max = (int) Arr::get($options, 'max');
        $escalateAt = (int) Arr::get($options, 'escalate_at');

        $seq = (int) $remindCount + 1;
        $isFinal = $seq > $max;

        // 收尾那則一定要 tag 到主管老闆 —— 系統要放手了，總得有人知道
        $stage = $isFinal
            ? (int) Arr::get($stages, 'FINAL')
            : ($seq >= $escalateAt
                ? (int) Arr::get($stages, 'MANAGER')
                : (int) Arr::get($stages, 'ON_DUTY'));

        return [
            'seq'      => $seq,
            'stage'    => $stage,
            'users'    => $this->pick($targets, $stage),
            'is_final' => $isFinal,
            /*
             * 收尾那則把次數推到 max + 1 —— 兩邊的查詢都用
             * `remind_count <= $max` 收單，推過去之後就不會再被撈出來。
             */
            'next_count' => $isFinal ? $max + 1 : $seq,
        ];
    }

    /**
     * 把名單組成 Telegram 的 @ 標註
     *
     * @param \Illuminate\Support\Collection|array $users
     * @param string                               $noMention 沒人可以 tag 時那句話
     * @return string
     */
    public function mentions($users, $noMention)
    {
        if (blank($users)) {
            /*
             * ⚠ **沒人被 tag 時那句說明比訊息本身重要**：代表這件事現在
             * 沒有人負責（深夜沒排班就會這樣），靜靜發出去只會沒人認領。
             */
            return (string) $noMention;
        }

        $mentions = [];

        foreach ($users as $user) {
            $mentions[] = '@' . ltrim($user->telegram_username, '@');
        }

        return implode(' ', $mentions);
    }

    /**
     * 這一階段要用哪一組名單
     *
     * @param array $targets
     * @param int   $stage
     * @return \Illuminate\Support\Collection
     */
    private function pick(array $targets, $stage)
    {
        $onDutyStage = (int) config('constants.AUTO_REPLY.REMIND.STAGE.ON_DUTY');
        $key = (int) $stage === $onDutyStage ? 'on_duty' : 'escalated';

        return collect(Arr::get($targets, $key, []));
    }
}
