<?php

/**
 * Telegram Bot 設定
 *
 * bot_token：Telegram Bot API Token
 * webhook_secret：Webhook 驗證密鑰（X-Telegram-Bot-Api-Secret-Token）
 */

return [
    'bot_token'      => env('TELEGRAM_BOT_TOKEN'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'api_base'       => 'https://api.telegram.org/bot',
];
