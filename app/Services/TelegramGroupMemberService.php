<?php

namespace App\Services;

use App\Models\TelegramGroupMember;
use App\Repositories\TelegramGroupMemberRepository;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Telegram 群組成員名冊與忽略名單
 *
 * 名冊會隨著每則收到的訊息自己長大；忽略名單只是名冊上的一個狀態。
 *
 * 忽略某人只擋「自動回覆」這一段 —— 訊息照常存、照常推播、照常算未讀，
 * 客服仍然看得到，只是系統不會替他回答。
 *
 * 比對時 Telegram 使用者 ID 與 username 兩個都比：
 * 從清單挑的人有 ID（最可靠），手動輸入的只有 username（好填但對方改掉就失效）。
 */
class TelegramGroupMemberService
{
    private $memberRepository;

    public function __construct(TelegramGroupMemberRepository $memberRepository)
    {
        $this->memberRepository = $memberRepository;
    }

    /**
     * 有人在這個對話發言 —— 更新名冊
     *
     * 一律寫、不做條件判斷：名冊要隨時備妥，不能等到有人打開自動回覆開關才開始累積，
     * 否則剛打開時清單是空的，還要等對方先講一句話才挑得到人。
     * 成本是單筆 unique index 命中，而且這張表永遠只有幾十列。
     *
     * @param int   $groupId
     * @param array $from Telegram 的 from 物件
     * @return void
     */
    public function touch($groupId, array $from)
    {
        $telegramUserId = (int) Arr::get($from, 'id', 0);

        if ($telegramUserId <= 0) {
            return;
        }

        $username = $this->normalizeUsername(Arr::get($from, 'username'));
        $displayName = $this->buildDisplayName($from);

        try {
            $member = $this->memberRepository->findByUserId($groupId, $telegramUserId);

            // 之前用 username 手動加進來的那筆，這次要把 ID 補上去 ——
            // 直接以 ID 新增的話，同一個人會在名單裡出現兩次
            if (!filled($member) && filled($username)) {
                $member = $this->memberRepository->findManualByUsername($groupId, $username);
            }

            $attributes = [
                'username'     => $username,
                'display_name' => $displayName,
                'last_seen_at' => Carbon::now(),
            ];

            if (filled($member)) {
                $this->memberRepository->update($member, array_merge($attributes, [
                    'telegram_user_id' => $telegramUserId,
                ]));

                // 補上 ID 這種情況會改到忽略名單的比對內容，快取要跟著失效
                $this->forgetCache($groupId);

                return;
            }

            $this->memberRepository->create(array_merge($attributes, [
                'telegram_group_id' => $groupId,
                'telegram_user_id'  => $telegramUserId,
            ]));
        } catch (\Exception $e) {
            // 名冊寫失敗不該影響收訊 —— 訊息本身已經存好了
            Log::warning('群組成員名冊更新失敗', [
                'group_id' => $groupId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * 這個人是不是被設為不自動回覆
     *
     * @param int   $groupId
     * @param array $from Telegram 的 from 物件
     * @return bool
     */
    public function isIgnored($groupId, array $from)
    {
        $ignored = $this->getIgnoredKeys($groupId);

        // 名單是空的就不必再比 —— 絕大多數對話都是這個情況
        if (!filled($ignored['ids']) && !filled($ignored['usernames'])) {
            return false;
        }

        $telegramUserId = (int) Arr::get($from, 'id', 0);

        if ($telegramUserId > 0 && in_array($telegramUserId, $ignored['ids'], true)) {
            return true;
        }

        $username = $this->normalizeUsername(Arr::get($from, 'username'));

        return filled($username) && in_array($username, $ignored['usernames'], true);
    }

    /**
     * 取得忽略名單面板資料
     *
     * @param int $groupId
     * @return array{ignored: \Illuminate\Database\Eloquent\Collection, recent: \Illuminate\Database\Eloquent\Collection}
     */
    public function getPanel($groupId)
    {
        $config = config('constants.TELEGRAM.IGNORE');
        $since = Carbon::now()->subDays((int) $config['RECENT_DAYS']);

        return [
            'ignored' => $this->memberRepository->getIgnoredWithCreator($groupId),
            'recent'  => $this->memberRepository->getRecentByGroup($groupId, $since, (int) $config['RECENT_LIMIT']),
        ];
    }

    /**
     * 把名冊上的某個人設為不自動回覆
     *
     * @param int $groupId
     * @param int $memberId
     * @param int $operatorId
     * @return TelegramGroupMember
     * @throws \RuntimeException 找不到這個人
     */
    public function ignore($groupId, $memberId, $operatorId)
    {
        $member = $this->memberRepository->findInGroup($groupId, $memberId);

        if (!filled($member)) {
            throw new \RuntimeException(trans('telegram_chat.msg.member_not_found'));
        }

        $this->memberRepository->update($member, [
            'ignored'    => true,
            'ignored_by' => $operatorId,
            'ignored_at' => Carbon::now(),
        ]);

        $this->forgetCache($groupId);

        return $member;
    }

    /**
     * 用 username 手動加入忽略名單
     *
     * 清單裡挑不到人的時候用 —— 上線前發過言的人名冊裡沒有，
     * 還沒發言的人也還不在名冊上。
     *
     * @param int         $groupId
     * @param string      $username 可含 @、大小寫不拘
     * @param string|null $note     備註，會顯示在名單上
     * @param int         $operatorId
     * @return TelegramGroupMember
     * @throws \RuntimeException username 不合法或已在名單中
     */
    public function ignoreByUsername($groupId, $username, $note, $operatorId)
    {
        $normalized = $this->normalizeUsername($username);

        if (!filled($normalized)) {
            throw new \RuntimeException(trans('telegram_chat.msg.username_invalid'));
        }

        $member = $this->memberRepository->findByUsername($groupId, $normalized);

        if (filled($member) && $member->ignored) {
            throw new \RuntimeException(trans('telegram_chat.msg.member_already_ignored'));
        }

        $attributes = [
            'ignored'    => true,
            'ignored_by' => $operatorId,
            'ignored_at' => Carbon::now(),
        ];

        // 這個 username 已經在名冊上（發過言），改狀態就好，不要多開一筆
        if (filled($member)) {
            $this->memberRepository->update($member, $attributes);
            $this->forgetCache($groupId);

            return $member;
        }

        $member = $this->memberRepository->create(array_merge($attributes, [
            'telegram_group_id' => $groupId,
            'username'          => $normalized,
            'display_name'      => filled($note) ? $note : "@{$normalized}",
        ]));

        $this->forgetCache($groupId);

        return $member;
    }

    /**
     * 恢復自動回覆
     *
     * 手動加入的那幾筆沒有發言紀錄，留著只會讓名冊長出一堆空殼，直接刪掉；
     * 發言過的人要留著，他還在名冊的「發言過的人」清單裡。
     *
     * @param int $groupId
     * @param int $memberId
     * @return void
     * @throws \RuntimeException 找不到這個人
     */
    public function restore($groupId, $memberId)
    {
        $member = $this->memberRepository->findInGroup($groupId, $memberId);

        if (!filled($member)) {
            throw new \RuntimeException(trans('telegram_chat.msg.member_not_found'));
        }

        if ($member->isManual()) {
            $this->memberRepository->delete($member);
            $this->forgetCache($groupId);

            return;
        }

        $this->memberRepository->update($member, [
            'ignored'    => false,
            'ignored_by' => null,
            'ignored_at' => null,
        ]);

        $this->forgetCache($groupId);
    }

    /**
     * 取得這個對話的忽略名單顯示名稱
     *
     * 訊息上的灰色標籤用的 —— 訊息本身沒有存發話者 ID（不動大表），
     * 只能拿顯示名稱去比。對方改名後標籤會不準，但那只是視覺提示；
     * 要不要自動回覆是收訊當下用 ID／username 判的，一定準。
     *
     * @param int $groupId
     * @return array
     */
    public function getIgnoredNames($groupId)
    {
        return $this->memberRepository->getIgnoredNames($groupId)
            ->pluck('display_name')
            ->values()
            ->all();
    }

    /**
     * username 正規化：去掉開頭的 @、轉小寫
     *
     * 存跟比對都走這一支，否則 `@Abc` 與 `abc` 會被當成兩個人。
     *
     * @param string|null $username
     * @return string|null
     */
    public function normalizeUsername($username)
    {
        if (!filled($username)) {
            return null;
        }

        $normalized = mb_strtolower(ltrim(trim($username), '@'));

        return filled($normalized) ? $normalized : null;
    }

    /**
     * 取得忽略名單的兩組比對鍵（快取）
     *
     * @param int $groupId
     * @return array{ids: array, usernames: array}
     */
    private function getIgnoredKeys($groupId)
    {
        $config = config('constants.TELEGRAM.IGNORE');
        $repository = $this->memberRepository;

        return Cache::remember(
            "{$config['CACHE_PREFIX']}{$groupId}",
            (int) $config['CACHE_SECONDS'],
            function () use ($repository, $groupId) {
                $members = $repository->getIgnoredByGroup($groupId);

                return [
                    'ids' => $members->pluck('telegram_user_id')
                        ->filter()
                        ->map(function ($id) {
                            return (int) $id;
                        })
                        ->values()
                        ->all(),
                    'usernames' => $members->pluck('username')
                        ->filter()
                        ->values()
                        ->all(),
                ];
            }
        );
    }

    /**
     * 清掉這個對話的忽略名單快取
     *
     * @param int $groupId
     * @return void
     */
    private function forgetCache($groupId)
    {
        Cache::forget(config('constants.TELEGRAM.IGNORE.CACHE_PREFIX') . $groupId);
    }

    /**
     * 組顯示名稱
     *
     * @param array $from Telegram 的 from 物件
     * @return string
     */
    private function buildDisplayName(array $from)
    {
        $firstName = Arr::get($from, 'first_name', '');
        $lastName = Arr::get($from, 'last_name', '');
        $name = trim("{$firstName} {$lastName}");

        if (filled($name)) {
            return $name;
        }

        $username = Arr::get($from, 'username');

        return filled($username) ? "@{$username}" : 'Unknown';
    }
}
