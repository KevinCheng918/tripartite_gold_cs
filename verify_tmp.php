<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach (["admin/notification/index", "admin/setting/index", "layouts/app"] as $v) {
    try {
        app("blade.compiler")->compileString(file_get_contents("resources/views/{$v}.blade.php"));
        echo "blade OK: {$v}\n";
    } catch (\Throwable $e) {
        echo "blade 失敗 {$v}：" . $e->getMessage() . "\n";
    }
}

echo "\n分頁：" . implode(" | ", [
    trans("notification.tab_group"), trans("notification.tab_remind"),
    trans("notification.tab_shift"), trans("notification.tab_report"),
]) . "\n";
echo "AI 引擎：選單=" . trans("setting.nav_label") . "、權限群組=" . trans("permission.group.setting") . "\n";
echo "   " . trans("permission.setting.view") . " / " . trans("permission.setting.manage") . "\n";
echo "通知設定：選單=" . trans("notification.nav_label") . "、權限群組=" . trans("permission.group.notification") . "\n";
echo "   " . trans("permission.notification.view") . " / " . trans("permission.notification.manage") . "\n";

foreach (["setting", "notification", "permission"] as $f) {
    $k = [];
    foreach (["tw", "cn", "en"] as $l) {
        $k[$l] = array_keys(Illuminate\Support\Arr::dot(require "resources/lang/{$l}/{$f}.php"));
    }
    $diff = count(array_diff($k["tw"], $k["cn"])) + count(array_diff($k["cn"], $k["tw"]))
          + count(array_diff($k["tw"], $k["en"])) + count(array_diff($k["en"], $k["tw"]));
    echo "{$f} 三份 key 差異：{$diff}\n";
}

$n = app(App\Services\NotificationSettingService::class)->forPage();
echo "\n通知設定頁資料 OK：" . implode(", ", array_keys($n)) . "\n";
