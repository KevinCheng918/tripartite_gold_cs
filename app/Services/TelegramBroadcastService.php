<?php

namespace App\Services;

use App\Models\TelegramBroadcast;
use App\Repositories\StationRepository;
use App\Repositories\TelegramBroadcastRepository;
use App\Repositories\TelegramRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Telegram 群發公告 Service
 *
 * 處理群發公告的建立、發送、紀錄。
 */
class TelegramBroadcastService
{
    private $broadcastRepository;
    private $telegramRepository;
    private $stationRepository;
    private $botService;
    private $imageUploadService;

    public function __construct(
        TelegramBroadcastRepository $broadcastRepository,
        TelegramRepository $telegramRepository,
        StationRepository $stationRepository,
        TelegramBotService $botService,
        ImageUploadService $imageUploadService
    ) {
        $this->broadcastRepository = $broadcastRepository;
        $this->telegramRepository = $telegramRepository;
        $this->stationRepository = $stationRepository;
        $this->botService = $botService;
        $this->imageUploadService = $imageUploadService;
    }

    /**
     * 取得公告歷史紀錄（分頁）
     *
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    /**
     * 取得可發送公告的站台列表（啟用且有 Telegram 群組）
     *
     * @return array
     */
    public function getTargetStations()
    {
        return $this->stationRepository->getActiveWithTelegram()->map(function ($s) {
            return [
                'id'        => $s->id,
                'name'      => $s->name,
                'title'     => $s->name . ($s->domain ? " ({$s->domain})" : ''),
                'system_id' => $s->system_id,
                'system'    => $s->system ? $s->system->name : null,
            ];
        })->values()->all();
    }

    public function list($perPage = 20)
    {
        return $this->broadcastRepository->paginate($perPage);
    }

    /**
     * 上傳公告圖片並回傳可對外存取的網址
     *
     * 預約公告要到排程時間才送出，網址必須先落地，屆時才拿得到圖。
     *
     * @param \Illuminate\Http\UploadedFile[] $files
     * @return string[]
     */
    public function uploadImages($files)
    {
        $paths = $this->imageUploadService->uploadMultiple($files, 'broadcast');

        return collect($paths)->map(function ($path) {
            return asset("storage/{$path}");
        })->all();
    }

    /**
     * 上傳公告附件
     *
     * 存的是**相對路徑而非網址**：sendDocument 要的是本地絕對路徑，
     * 只留網址的話送出時還得反推路徑。原始檔名也一併留著，
     * 否則客戶收到的會是「時間戳_uniqid_原檔名」。
     *
     * @param \Illuminate\Http\UploadedFile[] $files
     * @return array [{path, name}]
     */
    public function uploadFiles($files)
    {
        $result = [];

        foreach ($files as $file) {
            $result[] = [
                'path' => $this->imageUploadService->uploadKeepName($file, 'broadcast'),
                'name' => $file->getClientOriginalName(),
            ];
        }

        return $result;
    }

    /**
     * 建立預約公告
     *
     * 只寫紀錄不發送，實際送出由 telegram:send-scheduled 排程負責。
     * 目標站台在**發送當下**才解析，預約期間站台被停用或解綁群組就不會誤送。
     *
     * @param array  $params      含 content, target_type, group_ids, image_urls, scheduled_at
     * @param int    $senderId
     * @return TelegramBroadcast
     */
    public function schedule($params, $senderId)
    {
        $targetType = (int) $params['target_type'];
        $stationIds = $targetType === TelegramBroadcast::TARGET_SELECTED
            ? ($params['group_ids'] ?? [])
            : null;

        return $this->broadcastRepository->create([
            'content'          => $params['content'],
            'target_type'      => $targetType,
            'target_group_ids' => $stationIds,
            'image_urls'       => $params['image_urls'] ?? null,
            'file_urls'        => $params['file_urls'] ?? null,
            'status'           => TelegramBroadcast::STATUS_PENDING,
            'scheduled_at'     => $params['scheduled_at'],
            'total_count'      => 0,
            'success_count'    => 0,
            'fail_count'       => 0,
            'sender_id'        => $senderId,
            'sent_at'          => null,
        ]);
    }

    /**
     * 送出所有已到期的預約公告
     *
     * @return array 每筆的發送結果摘要
     */
    public function sendDue()
    {
        $due = $this->broadcastRepository->getDueScheduled(now());
        if ($due->isEmpty()) {
            return [];
        }

        $summary = [];
        foreach ($due as $broadcast) {
            try {
                $sent = $this->dispatch($broadcast);
                $summary[] = [
                    'id'      => $sent->id,
                    'total'   => $sent->total_count,
                    'success' => $sent->success_count,
                    'fail'    => $sent->fail_count,
                ];
            } catch (\Exception $e) {
                // 一筆失敗不能讓其餘的預約跟著卡住
                Log::error('預約公告發送失敗', [
                    'broadcast_id' => $broadcast->id,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * 取消預約公告
     *
     * @param TelegramBroadcast $broadcast
     * @return bool 已送出或已取消的回 false
     */
    public function cancelSchedule(TelegramBroadcast $broadcast)
    {
        if (!$broadcast->isCancelable()) {
            return false;
        }

        $this->broadcastRepository->update($broadcast, ['status' => TelegramBroadcast::STATUS_CANCELED]);

        return true;
    }

    /**
     * 發送群發公告（立即）
     *
     * 從站台列表取得目標（僅啟用且有 Telegram 群組的站台）。
     * group_ids 在這裡是 station.id，不是 telegram_group.id。
     *
     * @param array $params   含 content, target_type, group_ids(station ids, optional)
     * @param int   $senderId 發送者 user_id
     * @return TelegramBroadcast
     */
    public function send($params, $senderId)
    {
        $targetType = (int) $params['target_type'];

        $broadcast = $this->broadcastRepository->create([
            'content'          => $params['content'],
            'target_type'      => $targetType,
            'target_group_ids' => $targetType === TelegramBroadcast::TARGET_SELECTED ? ($params['group_ids'] ?? []) : null,
            'image_urls'       => $params['image_urls'] ?? null,
            'file_urls'        => $params['file_urls'] ?? null,
            'status'           => TelegramBroadcast::STATUS_SENT,
            'scheduled_at'     => null,
            'total_count'      => 0,
            'success_count'    => 0,
            'fail_count'       => 0,
            'sender_id'        => $senderId,
            'sent_at'          => now(),
        ]);

        return $this->dispatch($broadcast);
    }

    /**
     * 把附件逐一送到單一站台的群組
     *
     * @param \App\Models\Station $station
     * @param array               $files [{path, name}]
     * @param int                 $senderId
     * @param string              $senderName
     * @param int                 $broadcastId
     * @return bool 全部送成功才回 true
     */
    private function sendFilesTo($station, $files, $senderId, $senderName, $broadcastId)
    {
        if (empty($files)) {
            return true;
        }

        $chatId = $station->telegramGroup->chat_id;
        $allOk = true;

        foreach ($files as $file) {
            $diskPath = Storage::disk('public')->path($file['path']);

            if (!file_exists($diskPath)) {
                Log::error('群發公告附件不存在', [
                    'broadcast_id' => $broadcastId,
                    'path'         => $diskPath,
                ]);
                $allOk = false;
                continue;
            }

            $result = $this->botService->sendDocument($chatId, $diskPath, $file['name']);

            if (!$result || empty($result['ok'])) {
                Log::warning('群發公告附件發送失敗', [
                    'broadcast_id' => $broadcastId,
                    'chat_id'      => $chatId,
                    'filename'     => $file['name'],
                ]);
                $allOk = false;
                continue;
            }

            $this->telegramRepository->createMessage([
                'telegram_group_id'   => $station->telegramGroup->id,
                'direction'           => config('constants.TELEGRAM.DIRECTION.OUTBOUND'),
                'telegram_message_id' => $result['result']['message_id'] ?? null,
                'sender_name'         => $senderName,
                'sender_user_id'      => $senderId,
                'content'             => '',
                'media_type'          => 'document',
                'media_url'           => asset("storage/{$file['path']}"),
                'media_name'          => $file['name'],
                'replied'             => true,
            ]);
        }

        return $allOk;
    }

    /**
     * 實際把公告送到各站台群組並回寫結果
     *
     * 立即發送與預約發送共用這支，兩條路徑的行為才不會分岔。
     *
     * @param TelegramBroadcast $broadcast
     * @return TelegramBroadcast
     */
    private function dispatch(TelegramBroadcast $broadcast)
    {
        $content = $broadcast->content;
        $imageUrls = $broadcast->image_urls ?? [];
        $files = $broadcast->file_urls ?? [];
        $senderId = $broadcast->sender_id;

        // 從站台列表取得目標（僅啟用且有 Telegram 群組的）
        $allStations = $this->stationRepository->getActiveWithTelegram();

        $stations = (int) $broadcast->target_type === TelegramBroadcast::TARGET_ALL
            ? $allStations
            : $allStations->whereIn('id', $broadcast->target_group_ids ?? []);

        // 取得對應的 Telegram 群組（透過站台的 telegramGroup 關聯）
        $groups = $stations->map(function ($station) {
            return $station->telegramGroup;
        })->filter();

        // 查發送者暱稱
        $sender = $this->telegramRepository->findSender($senderId);
        $senderName = $sender ? $sender->nickname : '系統';

        // 逐一發送（根據站台系統切換 Bot Token）
        $success = 0;
        $fail = 0;
        $sendResults = [];

        foreach ($stations as $station) {
            if (!$station->telegramGroup) {
                $sendResults[] = ['station_id' => $station->id, 'name' => $station->name, 'success' => false];
                $fail++;
                continue;
            }

            // 切換到該站台系統的 Bot Token
            if ($station->system && filled($station->system->bot_token)) {
                $this->botService->setToken($station->system->bot_token);
            }

            $chatId = $station->telegramGroup->chat_id;

            if (count($imageUrls) > 1) {
                $result = $this->botService->sendMediaGroup($chatId, $imageUrls, $content);
            } elseif (count($imageUrls) === 1) {
                $result = $this->botService->sendPhoto($chatId, $imageUrls[0], $content);
            } else {
                $result = $this->botService->sendMessage($chatId, $content);
            }

            if (!$result || empty($result['ok'])) {
                $sendResults[] = ['station_id' => $station->id, 'name' => $station->name, 'success' => false];
                $fail++;
                Log::warning('群發公告發送失敗', ['chat_id' => $station->telegramGroup->chat_id, 'broadcast_id' => $broadcast->id]);
                continue;
            }

            // 存入 outbound 訊息到對話紀錄（多張取第一張）
            $firstImage = !empty($imageUrls) ? $imageUrls[0] : null;
            $msgId = null;
            if (isset($result['result'])) {
                // sendMediaGroup 回傳陣列，取第一個
                $msgResult = is_array($result['result']) && isset($result['result'][0])
                    ? $result['result'][0] : $result['result'];
                $msgId = $msgResult['message_id'] ?? null;
            }

            $this->telegramRepository->createMessage([
                'telegram_group_id'  => $station->telegramGroup->id,
                'direction'          => config('constants.TELEGRAM.DIRECTION.OUTBOUND'),
                'telegram_message_id' => $msgId,
                'sender_name'        => $senderName,
                'sender_user_id'     => $senderId,
                'content'            => $content ?: '',
                'media_type'         => filled($firstImage) ? 'photo' : null,
                'media_url'          => $firstImage,
                'replied'            => true,
            ]);

            // 附件在正文之後逐一送出。有任何一個沒送成功，
            // 這個站台就算失敗 —— 客服才知道要補送，而不是誤以為全都送到了
            $filesOk = $this->sendFilesTo($station, $files, $senderId, $senderName, $broadcast->id);

            $sendResults[] = ['station_id' => $station->id, 'name' => $station->name, 'success' => $filesOk];
            if ($filesOk) {
                $success++;
                continue;
            }

            $fail++;
        }

        // 更新結果。預約公告到這裡才轉成已發送並補上 sent_at
        $this->broadcastRepository->update($broadcast, [
            'total_count'   => $groups->count(),
            'success_count' => $success,
            'fail_count'    => $fail,
            'send_results'  => $sendResults,
            'status'        => TelegramBroadcast::STATUS_SENT,
            'sent_at'       => $broadcast->sent_at ?: now(),
        ]);

        return $broadcast->fresh();
    }
}
