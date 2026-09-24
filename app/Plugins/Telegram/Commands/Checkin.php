<?php

namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Services\CheckinService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Checkin extends Telegram
{
    public $command = '/sign1';
    public $description = '普通签到，随机获得10MB-1GB流量';

    public function handle($message, $match = [])
    {
        if (!$message->is_private) return;
        try {
            $result = app(CheckinService::class)->claimForTelegram((int) $message->chat_id);
            $text = ($result['data'] ? '✅ ' : '❌ ') . $result['message'];
        } catch (HttpException $e) {
            $text = $e->getMessage();
        } catch (\Throwable $e) {
            report($e);
            $text = '签到服务暂时不可用，请稍后重试。';
        }
        $this->telegramService->sendMessage($message->chat_id, $text);
    }
}
