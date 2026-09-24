<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Based on codeman857/v3board @ a150523ea59a4b4c3294c39f7486a511405f5333 (MIT).
// https://github.com/codeman857/v3board
class CheckinService
{
    const BYTES_PER_MB = 1048576;
    const BYTES_PER_GB = 1073741824;
    const REASON_MESSAGES = [
        'disabled' => '签到功能未开启',
        'banned' => '账号已被禁用，无法签到',
        'no_plan' => '请先购买有效订阅后再签到',
        'expired' => '订阅已到期，请续费后再签到',
    ];

    /// 仅查询布尔签到状态，不根据缓存虚构历史奖励。
    public function status(int $userId): array
    {
        $user = User::find($userId);
        if (!$user) throw new HttpException(403, '用户不存在或登录已过期');
        $now = $this->now();
        $settings = $this->settings();
        $reason = $this->eligibilityReason($user, $settings, $now);
        return [
            'enabled' => $settings['enabled'],
            'lucky_enabled' => $settings['lucky_enabled'],
            'eligible' => $reason === null,
            'reason' => $reason,
            'checked_in' => $settings['enabled'] && $this->cache()->get($this->dailyKey($user->id, $now)) === true,
            'remaining_traffic' => $this->remainingTraffic($user),
            'next_checkin_at' => $now->startOfDay()->addDay()->getTimestamp(),
            'timezone' => $now->getTimezone()->getName(),
        ];
    }

    /// 已认证 API 与 Telegram 共用校验、日额度和事务。
    public function claimForApp(int $userId, $type = 1, $input = null): array
    {
        return $this->claim('id', $userId, $type, $input);
    }

    public function claimForTelegram(int $telegramId, $type = 1, $input = null): array
    {
        return $this->claim('telegram_id', $telegramId, $type, $input);
    }

    public function standardCheckin(User $user): array
    {
        return $this->claimForApp((int) $user->id);
    }

    public function luckyCheckinFromString(User $user, string $input): array
    {
        return $this->claimForApp((int) $user->id, 2, $input);
    }

    protected function claim(string $identityColumn, int $identity, $type, $input): array
    {
        $settings = $this->settings();
        if (!$settings['enabled']) return $this->failure(self::REASON_MESSAGES['disabled']);
        if (!in_array($type, [1, '1', 2, '2'], true)) return $this->failure('不支持的签到类型');
        $type = (int) $type;
        if ($type === 2 && !$settings['lucky_enabled']) return $this->failure('运气签到功能未开启');
        $inputBytes = $type === 2 ? $this->parseLuckyInput($input) : null;
        if (is_array($inputBytes)) return $inputBytes;
        $cache = $this->cache();
        $reservedKey = null;
        $writeCompleted = false;
        try {
            // 用户行锁涵盖余额读取、资格检查、缓存预约及增量入账。
            // 不自动重试此事务，避免回滚后的缓存预约被误认为已成功。
            return DB::transaction(function () use ($identityColumn, $identity, $type, $inputBytes, $settings, $cache, &$reservedKey, &$writeCompleted) {
                $user = User::where($identityColumn, $identity)->lockForUpdate()->first();
                if (!$user) return $this->failure($identityColumn === 'telegram_id'
                    ? '没有查询到您的用户信息，请先绑定账号' : '用户不存在或登录已过期');
                $now = $this->now();
                $reason = $this->eligibilityReason($user, $settings, $now);
                if ($reason !== null) return $this->failure(self::REASON_MESSAGES[$reason]);
                $key = $this->dailyKey($user->id, $now);
                if ($cache->get($key) === true) return $this->failure('您今天已经签到过，请勿重复签到');
                if ($type === 2 && $inputBytes > $this->remainingTraffic($user)) {
                    return $this->failure('输入流量不能超过当前剩余流量 (' . Helper::trafficConvert($this->remainingTraffic($user)) . ')');
                }
                $traffic = $type === 1
                    ? $this->randomTraffic(10 * self::BYTES_PER_MB, self::BYTES_PER_GB)
                    : $this->randomTraffic(-$inputBytes, $inputBytes);
                $nextDownload = (int) $user->d - $traffic;
                if (!is_int($nextDownload)) throw new HttpException(503, '流量数值超出上限，请联系客服');
                // Validate the resulting balance before reserving the day or changing d.
                $this->remainingTraffic($user, $nextDownload);

                // 缓存仍只存 true，与参考实现兼容；add 必须成功才允许发奖。
                // 预约先于数据库修改，缓存不可用时绝不静默发放流量。
                if (!$cache->add($key, true, $now->startOfDay()->addDay())) {
                    if ($cache->get($key) === true) return $this->failure('您今天已经签到过，请勿重复签到');
                    throw new HttpException(503, '签到缓存暂时不可用，请稍后重试');
                }
                $reservedKey = $key;
                // 零也是成功；无需依赖数据库对无变化 UPDATE 的受影响行数。
                if ($traffic !== 0 && User::where('id', $user->id)->decrement('d', $traffic) !== 1) {
                    throw new \RuntimeException('签到流量保存失败');
                }
                $amount = Helper::trafficConvert(abs($traffic));
                $message = $type === 1
                    ? '签到成功！获得 +' . $amount . ' 流量 (10MB-1GB随机)'
                    : '运气签到成功！可能获得-' . Helper::trafficConvert($inputBytes) . '到+' . Helper::trafficConvert($inputBytes)
                        . '流量，本次' . ($traffic >= 0 ? '获得 +' : '扣除 ') . $amount . '流量';
                $writeCompleted = true;
                return ['data' => true, 'message' => $message, 'traffic' => $traffic];
            });
        } catch (\Throwable $e) {
            // Retain the reservation if commit may already have succeeded.
            if ($reservedKey !== null && !$writeCompleted && DB::transactionLevel() === 0) {
                // 此预约属于已回滚事务。删除失败时保留关闭状态，并向调用方报告故障。
                if (!$cache->forget($reservedKey)) throw new HttpException(503, '签到失败且缓存暂时不可用，请稍后重试', $e);
            }
            throw $e;
        }
    }

    /// 两个入口使用同一十进制解析；拒绝科学计数、无限值和超大输入。
    protected function parseLuckyInput($input)
    {
        if (!is_string($input) || !preg_match('/\A(\d{1,4})(?:\.(\d{1,10}))?(MB|GB)\z/i', $input, $matches)) {
            return $this->failure('参数格式错误，请使用数值+MB或GB，例如100.5GB（最多10位小数）');
        }
        $whole = (int) $matches[1];
        $fraction = $matches[2] ?? '';
        if ($whole < 1 || $whole > 1000 || ($whole === 1000 && (int) $fraction !== 0)) {
            return $this->failure('数值必须在 1-1000 之间');
        }
        $multiplier = strtoupper($matches[3]) === 'GB' ? self::BYTES_PER_GB : self::BYTES_PER_MB;
        $fractionBytes = 0;
        if ($fraction !== '') {
            // 先约去 2 的公因子，使中间乘积不溢出且无需浮点舍入。
            $factor = 2 ** strlen($fraction);
            $scale = 10 ** strlen($fraction);
            $fractionBytes = intdiv((int) $fraction * intdiv($multiplier, $factor), intdiv($scale, $factor));
        }
        return $whole * $multiplier + $fractionBytes;
    }

    protected function randomTraffic(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    protected function remainingTraffic(User $user, ?int $download = null): int
    {
        $remaining = (int) $user->transfer_enable - (int) $user->u - ($download ?? (int) $user->d);
        if (!is_int($remaining)) throw new HttpException(503, '流量数值超出上限，请联系客服');
        return max(0, $remaining);
    }

    protected function eligibilityReason(User $user, array $settings, CarbonImmutable $now): ?string
    {
        if (!$settings['enabled']) return 'disabled';
        if ($user->banned) return 'banned';
        if (!$user->plan_id || !Plan::where('id', $user->plan_id)->exists()) return 'no_plan';
        if ($user->expired_at !== null && (int) $user->expired_at <= $now->getTimestamp()) return 'expired';
        return null;
    }

    protected function dailyKey(int $userId, CarbonImmutable $now): string
    {
        return CacheKey::get('USER_CHECKIN_STATUS', $userId . '_' . $now->format('Ymd'));
    }

    protected function cache()
    {
        $cache = Cache::store();
        $store = $cache->getStore();
        if ($store instanceof NullStore || ($store instanceof ArrayStore && !app()->environment('testing'))) {
            throw new HttpException(503, '签到需要可跨请求共享的缓存');
        }
        return $cache;
    }

    /// 手动重置当天签到资格；旧日期缓存由 TTL 自动过期，不清理其他业务缓存。
    public function clearAllCheckinCache(): void
    {
        $cache = $this->cache();
        User::select('id')->chunkById(500, function ($users) use ($cache) {
            foreach ($users as $item) {
                DB::transaction(function () use ($item, $cache) {
                    $user = User::where('id', $item->id)->lockForUpdate()->first();
                    if (!$user) return;
                    $key = $this->dailyKey($user->id, $this->now());
                    if ($cache->get($key) === true && !$cache->forget($key)) {
                        throw new \RuntimeException('签到缓存清理失败');
                    }
                });
            }
        });
    }

    protected function failure(string $message): array
    {
        return ['data' => false, 'message' => $message];
    }

    protected function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone', 'Asia/Shanghai'));
    }

    protected function settings(): array
    {
        $enabled = in_array(config('v2board.checkin_enable', 0), [1, '1', true], true);
        return [
            'enabled' => $enabled,
            'lucky_enabled' => $enabled && in_array(config('v2board.lucky_checkin_enable', 0), [1, '1', true], true),
        ];
    }
}
