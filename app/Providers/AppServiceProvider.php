<?php

namespace App\Providers;

use App\Contracts\AutoReplyMatcher;
use App\Services\AutoReply\ClaudeCodeMatcher;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // 自動回覆的比對器。抽成介面是為了保留換實作的空間
        // （日後改走 API、或在前面加一層本地預篩省額度）
        $this->app->bind(AutoReplyMatcher::class, ClaudeCodeMatcher::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Paginator::useBootstrap();
    }
}
