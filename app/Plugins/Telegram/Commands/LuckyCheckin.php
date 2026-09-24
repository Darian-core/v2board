<?php

namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Services\CheckinService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LuckyCheckin extends Telegram
{
    public $command = '/sign2';
    public $description = '运气签到，输入1-1000MB或GB，随机增加或扣除流量';

    public function handle($message, $match = [])
    {
        if (!$message->is_private) return;
        try {
            $result = app(CheckinService::class)->claimForTelegram((int) $message->chat_id, 2,
                count($message->args ?? []) === 1 ? $message->args[0] : null);
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
