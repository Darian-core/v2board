<?php

namespace App\Console\Commands;

use App\Services\CheckinService;
use Illuminate\Console\Command;

class ClearCheckinCache extends Command
{
    protected $signature = 'checkin:clear-cache';
    protected $description = '手动清除所有用户当天签到状态（允许再次签到）';

    public function handle(CheckinService $checkinService)
    {
        try {
            $checkinService->clearAllCheckinCache();
            $this->info('当天签到状态已清除');
            return 0;
        } catch (\Throwable $e) {
            $this->error('签到缓存清理失败：' . $e->getMessage());
            return 1;
        }
    }
}
