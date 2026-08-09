<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Web Push 推播服務
 *
 * 負責發送瀏覽器推播通知給客服人員。
 */
class WebPushService
{
    private $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * 發送推播通知給指定使用者
     *
     * @param User   $user
     * @param string $title 通知標題
     * @param string $body  通知內文
     * @param string $url   點擊後開啟的 URL
     * @return void
     */
    public function sendToUser(User $user, $title, $body, $url = '/')
    {
        if (!filled($user->push_endpoint)) {
            return;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject'    => config('services.vapid.subject'),
                    'publicKey'  => config('services.vapid.public_key'),
                    'privateKey' => config('services.vapid.private_key'),
                ],
            ]);

            $subscription = Subscription::create([
                'endpoint' => $user->push_endpoint,
                'keys'     => [
                    'p256dh' => $user->push_p256dh_key,
                    'auth'   => $user->push_auth_token,
                ],
            ]);

            $payload = json_encode([
                'title' => $title,
                'body'  => $body,
                'url'   => $url,
            ]);

            $webPush->queueNotification($subscription, $payload);

            foreach ($webPush->flush() as $report) {
                if ($report->isSubscriptionExpired()) {
                    $this->userRepository->clearPushSubscription($user);
                }
            }
        } catch (\Exception $e) {
            Log::error("Web Push 發送失敗: {$e->getMessage()}", [
                'user_id' => $user->id,
            ]);
        }
    }

    /**
     * 發送推播通知給所有已訂閱的使用者（排除指定 ID）
     *
     * @param string   $title     通知標題
     * @param string   $body      通知內文
     * @param string   $url       點擊後開啟的 URL
     * @param int|null $excludeId 排除的使用者 ID（如發送者自己）
     * @return void
     */
    public function sendToAll($title, $body, $url = '/', $excludeId = null)
    {
        $users = $this->userRepository->getSubscribedUsers($excludeId);

        foreach ($users as $user) {
            $this->sendToUser($user, $title, $body, $url);
        }
    }
}
