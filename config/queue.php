<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue API supports an assortment of back-ends via a single
    | API, giving you convenient access to each back-end using the same
    | syntax for every one. Here you may define a default connection.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'sync'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    /*
    |--------------------------------------------------------------------------
    | ⚠⚠ retry_after 必須大於所有 Job 的 timeout
    |--------------------------------------------------------------------------
    |
    | `retry_after` 是「一個 Job 跑超過這麼久，佇列就認定它卡死，**把它再派
    | 一份出去**」。所以它比任何一支 Job 的 `timeout` 短的話，那支 Job
    | 只要跑得夠久就會**同時有兩份在跑** —— 而 `$tries = 1` 擋不住這個，
    | 那一條擋的是「失敗後重試」，不是「佇列認定卡死而重派」。
    |
    | 線上的後果是具體的：
    |   · `AutoReplyJob`     兩份都會發訊息 ⇒ **客人收到兩份答案**
    |   · `FollowupDetectJob` 兩份都會開單 ⇒ 重複的待追蹤事項
    |   · `HandoverReviewJob` 兩份都叫模型 ⇒ 撞 `(handover_id, seq)` 的 unique
    |
    | 原本是 **90**，而 `AutoReplyJob` 與 `FollowupDetectJob` 的 timeout
    | 都是 **120** —— 平常跑不到 90 秒所以沒出事，但模型一慢就會踩到
    | （2026-11-20 發現、2026-11-24 才改）。
    |
    | 目前最長的 timeout 是 120，設 **300** 留足餘裕；之後新增 Job 時，
    | **timeout 不可以超過這個數字**。
    |
    | ⚠ 改完要 `php artisan optimize`，而且**一定要重啟 queue worker** ——
    | worker 是常駐程序，不重啟的話它手上還是舊的設定。
    |
    */
    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            // ⚠ 要大於所有 Job 的 timeout，理由見上面那段
            'retry_after' => 300,
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => 'localhost',
            'queue' => 'default',
            // ⚠ 要大於所有 Job 的 timeout，理由見上面那段
            'retry_after' => 300,
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'default'),
            // ⚠ 要大於所有 Job 的 timeout，理由見上面那段
            'retry_after' => 300,
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database / table you wish.
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];
