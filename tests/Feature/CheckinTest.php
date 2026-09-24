<?php

namespace Tests\Feature;

use App\Console\Commands\ClearCheckinCache;
use App\Console\Commands\TrafficUpdate;
use App\Http\Controllers\V1\Admin\ConfigController;
use App\Http\Controllers\V1\User\CheckinController;
use App\Http\Middleware\User as UserMiddleware;
use App\Http\Requests\Admin\ConfigSave;
use App\Http\Routes\V1\UserRoute;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Plugins\Telegram\Commands\Checkin as CheckinCommand;
use App\Plugins\Telegram\Commands\LuckyCheckin as LuckyCommand;
use App\Services\CheckinService;
use App\Services\OrderService;
use App\Services\TelegramService;
use App\Utils\CacheKey;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Redis;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CheckinTest extends TestCase
{
    private $app;
    private $db;
    private $service;
    private $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('env', 'testing');
        $this->app->instance('files', new Filesystem());
        $this->app->instance('config', new Repository([
            'app' => ['env' => 'testing', 'timezone' => 'Asia/Shanghai'],
            'v2board' => ['checkin_enable' => 1, 'lucky_checkin_enable' => 1],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
        ]));
        $this->app->instance('cache', new CacheManager($this->app));
        $this->db = new Capsule($this->app);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db->setEventDispatcher(new Dispatcher($this->app));
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        $this->app->instance('db', $this->db->getDatabaseManager());
        $this->app->instance('db.schema', $this->db->schema());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->db->schema()->create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('plan_id')->nullable();
            $table->bigInteger('expired_at')->nullable();
            $table->boolean('banned')->default(false);
            $table->bigInteger('transfer_enable')->default(0);
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->bigInteger('t')->default(0);
            $table->bigInteger('telegram_id')->nullable();
            $table->bigInteger('created_at')->nullable();
            $table->bigInteger('updated_at')->nullable();
        });
        $this->db->schema()->create('v2_plan', function (Blueprint $table) {
            $table->increments('id');
            $table->bigInteger('created_at')->nullable();
            $table->bigInteger('updated_at')->nullable();
        });
        $this->db->schema()->create('v2_order', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('plan_id');
            $table->string('period');
            $table->integer('status')->default(3);
            $table->integer('total_amount')->default(0);
            $table->integer('balance_amount')->default(0);
            $table->integer('surplus_amount')->default(0);
            $table->integer('refund_amount')->default(0);
            $table->bigInteger('created_at')->nullable();
            $table->bigInteger('updated_at')->nullable();
        });
        Plan::create([]);
        $this->setNow('2026-09-17 15:00:00', 'Asia/Shanghai');
        $this->now = CarbonImmutable::now()->getTimestamp();
        $this->service = new CheckinService();
        $response = $this->createMock(ResponseFactory::class);
        $response->method('make')->willReturnCallback(function ($content, $status = 200, $headers = []) {
            return new Response($content, $status, $headers);
        });
        $this->app->instance(ResponseFactory::class, $response);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        Model::clearBootedModels();
        $this->db->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        $this->app->flush();
        parent::tearDown();
    }

    private function setNow(string $time, string $timezone): void
    {
        $now = CarbonImmutable::parse($time, $timezone);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    private function user(array $attributes = []): User
    {
        return User::create(array_merge([
            'plan_id' => 1, 'expired_at' => $this->now + 86400 * 2, 'banned' => 0,
            'transfer_enable' => 5 * CheckinService::BYTES_PER_GB,
            'u' => 123, 'd' => 456, 'telegram_id' => 987654321,
        ], $attributes));
    }

    private function deterministic(int $traffic): CheckinService
    {
        return new class($traffic) extends CheckinService {
            public $traffic;
            public $range;
            public function __construct(int $traffic) { $this->traffic = $traffic; }
            protected function randomTraffic(int $min, int $max): int
            {
                $this->range = [$min, $max];
                if ($this->traffic < $min || $this->traffic > $max) throw new \LogicException('Test outcome outside random range');
                return $this->traffic;
            }
        };
    }

    private function key(User $user, string $day = '20260917'): string
    {
        return CacheKey::get('USER_CHECKIN_STATUS', $user->id . '_' . $day);
    }

    public function test_status_is_read_only_typed_and_has_no_reward_history(): void
    {
        $user = $this->user();
        $before = $user->fresh()->getAttributes();
        $this->assertSame([
            'enabled' => true, 'lucky_enabled' => true, 'eligible' => true, 'reason' => null,
            'checked_in' => false, 'remaining_traffic' => 5 * CheckinService::BYTES_PER_GB - 579,
            'next_checkin_at' => CarbonImmutable::parse('2026-09-18 00:00:00', 'Asia/Shanghai')->getTimestamp(),
            'timezone' => 'Asia/Shanghai',
        ], $this->service->status($user->id));
        Cache::put($this->key($user), true, 60);
        $this->assertTrue($this->service->status($user->id)['checked_in']);
        $this->assertArrayNotHasKey('reward_bytes', $this->service->status($user->id));
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertFalse($this->db->schema()->hasTable('v2_checkin'));
    }

    public function test_default_off_and_lucky_off_do_not_consume_daily_eligibility(): void
    {
        $user = $this->user();
        $this->app['config']->set('v2board', []);
        $status = $this->service->status($user->id);
        $this->assertFalse($status['enabled']);
        $this->assertFalse($status['lucky_enabled']);
        $this->assertSame('disabled', $status['reason']);
        $this->assertFalse($this->service->claimForApp($user->id)['data']);
        $this->app['config']->set('v2board.checkin_enable', 1);
        $this->assertFalse($this->service->claimForApp($user->id, 2, '1GB')['data']);
        $this->assertFalse(Cache::has($this->key($user)));
        $this->assertTrue($this->service->claimForApp($user->id)['data']);
    }

    /** @dataProvider invalidUsers */
    public function test_both_modes_and_channels_reject_ineligible_accounts(array $attributes, string $reason): void
    {
        if (isset($attributes['expired_at_offset'])) {
            $attributes['expired_at'] = $this->now + $attributes['expired_at_offset'];
            unset($attributes['expired_at_offset']);
        }
        $user = $this->user($attributes);
        $before = $user->fresh()->getAttributes();
        $this->assertSame($reason, $this->service->status($user->id)['reason']);
        foreach ([1, 2] as $type) {
            foreach (['claimForApp' => $user->id, 'claimForTelegram' => $user->telegram_id] as $method => $identity) {
                $result = $this->service->$method($identity, $type, '1MB');
                $this->assertSame(['data' => false, 'message' => CheckinService::REASON_MESSAGES[$reason]], $result);
            }
        }
        $this->assertFalse(Cache::has($this->key($user)));
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public static function invalidUsers(): array
    {
        return [
            [['plan_id' => null], 'no_plan'], [['plan_id' => 999], 'no_plan'],
            [['expired_at_offset' => -1], 'expired'], [['expired_at_offset' => 0], 'expired'], [['banned' => 1], 'banned'],
        ];
    }

    /** @dataProvider eligibleUsers */
    public function test_normal_claim_accepts_valid_null_expiry_and_exhausted_accounts(array $attributes): void
    {
        $user = $this->user($attributes);
        $result = $this->service->claimForApp($user->id);
        $this->assertTrue($result['data']);
        $this->assertIsInt($result['traffic']);
        $this->assertGreaterThanOrEqual(10 * CheckinService::BYTES_PER_MB, $result['traffic']);
        $this->assertLessThanOrEqual(CheckinService::BYTES_PER_GB, $result['traffic']);
        $fresh = $user->fresh();
        $this->assertSame($user->d - $result['traffic'], $fresh->d);
        foreach (['u', 'transfer_enable', 'expired_at', 'plan_id'] as $field) $this->assertSame($user->$field, $fresh->$field);
        $this->assertSame(true, Cache::get($this->key($user)));
    }

    public static function eligibleUsers(): array
    {
        return [[[]], [['expired_at' => null]], [['transfer_enable' => 579]], [['transfer_enable' => 1]]];
    }

    public function test_normal_range_is_inclusive_bytes_independent_of_old_config(): void
    {
        $this->app['config']->set('v2board.checkin_min_gb', 'invalid');
        $this->app['config']->set('v2board.checkin_max_gb', 99999);
        foreach ([10 * CheckinService::BYTES_PER_MB, 10 * CheckinService::BYTES_PER_MB + 1, CheckinService::BYTES_PER_GB] as $amount) {
            $service = $this->deterministic($amount);
            $this->assertSame($amount, $service->claimForApp($this->user()->id)['traffic']);
            $this->assertSame([10 * CheckinService::BYTES_PER_MB, CheckinService::BYTES_PER_GB], $service->range);
        }
    }

    /** @dataProvider luckyOutcomes */
    public function test_lucky_signed_outcomes_and_zero_are_successful(int $amount): void
    {
        $user = $this->user();
        $service = $this->deterministic($amount);
        $result = $service->claimForApp($user->id, '2', '1.5gB');
        $limit = 3 * CheckinService::BYTES_PER_GB / 2;
        $this->assertSame([-(int) $limit, (int) $limit], $service->range);
        $this->assertTrue($result['data']);
        $this->assertSame($amount, $result['traffic']);
        $this->assertSame($user->d - $amount, $user->fresh()->d);
        $this->assertTrue($service->status($user->id)['checked_in']);
        $this->assertFalse($service->claimForTelegram($user->telegram_id)['data']);
        if ($amount < 0) $this->assertStringContainsString('扣除', $result['message']);
    }

    public static function luckyOutcomes(): array
    {
        return [[-1610612736], [-1], [0], [1], [1610612736]];
    }

    public function test_lucky_decimal_bytes_and_remaining_bound_are_exact(): void
    {
        $service = $this->deterministic(0);
        $user = $this->user(['u' => 0, 'd' => 0, 'transfer_enable' => 1000 * CheckinService::BYTES_PER_GB]);
        $this->assertTrue($service->claimForTelegram($user->telegram_id, 2, '100.5GB')['data']);
        $this->assertSame([-107911053312, 107911053312], $service->range);
        Cache::forget($this->key($user));
        $this->assertTrue($service->claimForApp($user->id, 2, '1.0000000009MB')['data']);
        $this->assertSame([-CheckinService::BYTES_PER_MB, CheckinService::BYTES_PER_MB], $service->range);
        Cache::forget($this->key($user));
        $this->assertTrue($service->claimForApp($user->id, 2, '1000GB')['data']);
        $this->assertSame([-1000 * CheckinService::BYTES_PER_GB, 1000 * CheckinService::BYTES_PER_GB], $service->range);
        $user = $this->user(['u' => 0, 'd' => 0, 'transfer_enable' => CheckinService::BYTES_PER_MB]);
        $this->assertFalse($service->claimForApp($user->id, 2, '1.000001MB')['data']);
        $this->assertFalse(Cache::has($this->key($user)));
        $this->assertSame(-CheckinService::BYTES_PER_MB,
            $this->deterministic(-CheckinService::BYTES_PER_MB)->claimForApp($user->id, 2, '1MB')['traffic']);
        $this->assertSame(0, $service->status($user->id)['remaining_traffic']);
    }

    /** @dataProvider invalidLuckyInputs */
    public function test_malformed_nonfinite_overflow_or_out_of_range_input_has_no_effect($input): void
    {
        $user = $this->user();
        foreach (['claimForApp' => $user->id, 'claimForTelegram' => $user->telegram_id] as $method => $identity) {
            $this->assertFalse($this->service->$method($identity, 2, $input)['data']);
        }
        $this->assertFalse(Cache::has($this->key($user)));
        $this->assertSame($user->d, $user->fresh()->d);
    }

    public static function invalidLuckyInputs(): array
    {
        return [[null], [[]], [1], [''], ['GB'], ['.5GB'], ['0GB'], ['0.99MB'], ['-1GB'], ['+1GB'], ['1e2GB'],
            ['NANMB'], ['INFGB'], ['1e999GB'], ['1001MB'], ['1000.0000000001GB'], ['999999999999999999999GB'],
            ['1TB'], ['1.0.1GB'], ["1GB\n"], [' 1GB'], ['1 GB'], ['1.00000000001MB'], ['5GB']];
    }

    public function test_invalid_types_have_no_effect_and_default_api_type_is_normal(): void
    {
        $user = $this->user();
        foreach ([null, 0, 3, true, false, 1.0, '1.0', '01', []] as $type) {
            $this->assertFalse($this->service->claimForApp($user->id, $type)['data']);
        }
        $this->assertFalse(Cache::has($this->key($user)));
        $this->assertTrue($this->service->claimForApp($user->id)['data']);
    }

    public function test_midnight_expiry_and_timezone_share_one_marker(): void
    {
        $user = $this->user();
        $this->setNow('2026-09-17 15:59:59', 'UTC');
        $first = $this->service->claimForApp($user->id);
        $this->assertSame(true, Cache::get($this->key($user)));
        $this->assertFalse($this->service->claimForTelegram($user->telegram_id, 2, '1MB')['data']);
        $this->setNow('2026-09-17 16:00:00', 'UTC');
        $this->assertFalse($this->service->status($user->id)['checked_in']);
        // Laravel 8 ArrayStore expires with >; the dated key changes at midnight.
        $this->setNow('2026-09-17 16:00:01', 'UTC');
        $this->assertNull(Cache::get($this->key($user)));
        $second = $this->service->claimForTelegram($user->telegram_id);
        $this->assertTrue($second['data']);
        $this->assertSame(true, Cache::get($this->key($user, '20260918')));
        $this->assertSame($user->d - $first['traffic'] - $second['traffic'], $user->fresh()->d);
    }

    public function test_negative_net_usage_reports_remaining_above_plan_without_clamping_reward(): void
    {
        $user = $this->user(['u' => 0, 'd' => 0]);
        $reward = $this->deterministic(CheckinService::BYTES_PER_GB)->claimForApp($user->id)['traffic'];
        $this->assertSame($user->transfer_enable + $reward, $this->service->status($user->id)['remaining_traffic']);
    }

    public function test_cache_write_failure_fails_closed_before_database_update(): void
    {
        $user = $this->user();
        $store = new class extends ArrayStore {
            public function add($key, $value, $seconds) { return false; }
        };
        $repository = new CacheRepository($store);
        Cache::swap(new class($repository) {
            private $repository;
            public function __construct($repository) { $this->repository = $repository; }
            public function store() { return $this->repository; }
        });
        try {
            $this->service->claimForApp($user->id);
            $this->fail('Cache failure must prevent credit');
        } catch (HttpException $e) { $this->assertSame(503, $e->getStatusCode()); }
        $this->assertSame($user->d, $user->fresh()->d);
        $this->assertNull($repository->get($this->key($user)));
    }

    public function test_failed_database_write_rolls_back_credit_and_releases_reservation(): void
    {
        $user = $this->user();
        $injected = false;
        $this->db->getConnection()->listen(function ($query) use (&$injected) {
            if (!$injected && strpos($query->sql, 'update "v2_user"') === 0) {
                $injected = true;
                throw new \RuntimeException('Injected database failure after UPDATE');
            }
        });
        try {
            $this->service->claimForApp($user->id);
            $this->fail('Database failure should propagate');
        } catch (\RuntimeException $e) { $this->assertSame('Injected database failure after UPDATE', $e->getMessage()); }
        $this->assertSame($user->d, $user->fresh()->d);
        $this->assertFalse(Cache::has($this->key($user)));
        $this->assertTrue($this->service->claimForApp($user->id)['data']);
    }

    public function test_commit_phase_failure_retains_marker_when_credit_may_have_committed(): void
    {
        $user = $this->user();
        $reward = 10 * CheckinService::BYTES_PER_MB;
        $fired = false;
        $this->db->getConnection()->getEventDispatcher()->listen(
            \Illuminate\Database\Events\TransactionCommitted::class,
            function () use (&$fired) {
                if (!$fired) { $fired = true; throw new \RuntimeException('Injected lost commit acknowledgement'); }
            }
        );
        try {
            $this->deterministic($reward)->claimForApp($user->id);
            $this->fail('Commit failure should propagate');
        } catch (\RuntimeException $e) { $this->assertSame('Injected lost commit acknowledgement', $e->getMessage()); }
        $this->assertSame($user->d - $reward, $user->fresh()->d);
        $this->assertSame(true, Cache::get($this->key($user)));
        $this->assertFalse($this->service->claimForTelegram($user->telegram_id)['data']);
    }

    public function test_nonshared_production_cache_and_integer_overflow_are_rejected(): void
    {
        $user = $this->user(['d' => PHP_INT_MIN]);
        try {
            $this->service->claimForApp($user->id);
            $this->fail('Signed overflow must prevent credit');
        } catch (HttpException $e) { $this->assertSame(503, $e->getStatusCode()); }
        $this->assertFalse(Cache::has($this->key($user)));
        $this->assertSame(PHP_INT_MIN, $user->fresh()->d);
        $this->app->instance('env', 'production');
        try {
            $this->service->claimForApp($user->id);
            $this->fail('Production array cache must be rejected');
        } catch (HttpException $e) { $this->assertSame(503, $e->getStatusCode()); }
    }

    /** @dataProvider overflowingRemainingBalances */
    public function test_claim_rejects_remaining_balance_overflow_without_consuming_day(int $type, int $headroom, int $traffic): void
    {
        $user = $this->user(['transfer_enable' => PHP_INT_MAX - $headroom, 'u' => 0, 'd' => 0]);
        $before = $user->fresh()->getAttributes();
        $this->assertSame(PHP_INT_MAX - $headroom, $this->service->status($user->id)['remaining_traffic']);
        try {
            $this->deterministic($traffic)->claimForApp($user->id, $type, '1MB');
            $this->fail('Resulting remaining balance must fit in a signed integer');
        } catch (HttpException $e) { $this->assertSame(503, $e->getStatusCode()); }
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertFalse(Cache::has($this->key($user)));
        $this->assertSame(PHP_INT_MAX - $headroom, $this->service->status($user->id)['remaining_traffic']);
    }

    public static function overflowingRemainingBalances(): array
    {
        return [
            'normal at maximum' => [1, 0, 10 * CheckinService::BYTES_PER_MB],
            'normal one byte beyond maximum' => [1, 10 * CheckinService::BYTES_PER_MB - 1, 10 * CheckinService::BYTES_PER_MB],
            'lucky positive at maximum' => [2, 0, 1],
            'lucky positive one byte beyond maximum' => [2, CheckinService::BYTES_PER_MB - 1, CheckinService::BYTES_PER_MB],
        ];
    }

    /** @dataProvider safeRemainingBalances */
    public function test_boundary_balances_accept_exact_maximum_zero_and_negative_lucky_results(int $type, int $headroom, int $traffic): void
    {
        $user = $this->user(['transfer_enable' => PHP_INT_MAX - $headroom, 'u' => 0, 'd' => 0]);
        $result = $this->deterministic($traffic)->claimForApp($user->id, $type, '1MB');
        $this->assertTrue($result['data']);
        $this->assertSame($traffic, $result['traffic']);
        $this->assertSame(-$traffic, $user->fresh()->d);
        $this->assertTrue(Cache::has($this->key($user)));
        $this->assertSame(PHP_INT_MAX - $headroom + $traffic, $this->service->status($user->id)['remaining_traffic']);
    }

    public static function safeRemainingBalances(): array
    {
        return [
            'normal reaches exact maximum' => [1, 10 * CheckinService::BYTES_PER_MB, 10 * CheckinService::BYTES_PER_MB],
            'lucky positive reaches exact maximum' => [2, CheckinService::BYTES_PER_MB, CheckinService::BYTES_PER_MB],
            'lucky zero at maximum' => [2, 0, 0],
            'lucky negative at maximum' => [2, 0, -CheckinService::BYTES_PER_MB],
        ];
    }

    public function test_manual_cache_clear_only_resets_checkin_and_never_reverts_credits(): void
    {
        $user = $this->user();
        $this->service->claimForApp($user->id);
        $credited = $user->fresh()->d;
        Cache::put('unrelated-session', 'keep', 300);
        $this->service->clearAllCheckinCache();
        $this->assertFalse($this->service->status($user->id)['checked_in']);
        $this->assertSame('keep', Cache::get('unrelated-session'));
        $this->assertSame($credited, $user->fresh()->d);
        $this->assertTrue($this->service->claimForApp($user->id)['data']);
        $this->assertSame('checkin:clear-cache', (new ClearCheckinCache())->getName());
    }

    public function test_api_uses_authenticated_identity_and_reference_boolean_contract(): void
    {
        $user = $this->user();
        $victim = $this->user();
        $request = Request::create('/checkin', 'POST', [
            'user' => ['id' => $victim->id], 'user_id' => $victim->id, 'source' => 'telegram', 'traffic' => PHP_INT_MAX,
        ]);
        Cache::put('test-session', ['id' => $user->id], 60);
        $request->headers->set('authorization', 'test-session');
        $controller = new CheckinController();
        $claim = function ($request) use ($controller) { return $controller->claim($request, $this->service); };
        $first = json_decode((new UserMiddleware())->handle($request, $claim)->getContent(), true);
        $second = json_decode((new UserMiddleware())->handle($request, $claim)->getContent(), true);
        $this->assertTrue($first['data']);
        $this->assertSame(['data', 'message', 'traffic'], array_keys($first));
        $this->assertFalse($second['data']);
        $this->assertArrayNotHasKey('traffic', $second);
        $this->assertSame($user->d - $first['traffic'], $user->fresh()->d);
        $this->assertSame($victim->d, $victim->fresh()->d);
        $status = json_decode($controller->status($request, $this->service)->getContent(), true);
        $this->assertTrue($status['data']['checked_in']);
        $router = new Router(new Dispatcher($this->app), $this->app);
        $router->group(['namespace' => 'App\\Http\\Controllers'], function ($router) { (new UserRoute())->map($router); });
        foreach (['user/checkin', 'user/checkin/status'] as $uri) {
            $routes = array_filter($router->getRoutes()->getRoutes(), function ($route) use ($uri) { return $route->uri() === $uri; });
            $this->assertCount(1, $routes);
            $this->assertContains('user', reset($routes)->gatherMiddleware());
        }
    }

    public function test_unauthenticated_claim_is_rejected_before_controller(): void
    {
        $user = $this->user();
        try {
            (new UserMiddleware())->handle(Request::create('/checkin', 'POST', ['user' => ['id' => $user->id]]),
                function () { $this->fail('Unauthenticated request reached controller'); });
            $this->fail('Unauthenticated request was accepted');
        } catch (HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->assertFalse(Cache::has($this->key($user)));
    }

    public function test_bot_commands_only_allow_bound_private_users_and_share_both_modes(): void
    {
        $user = $this->user();
        $this->app->instance(CheckinService::class, $this->deterministic(0));
        $normal = new CheckinCommand();
        $lucky = new LuckyCommand();
        $transport = new class {
            public $messages = [];
            public function sendMessage($chat, $text, $parseMode = '') { $this->messages[] = [$chat, $text, $parseMode]; }
        };
        $normal->telegramService = $lucky->telegramService = $transport;
        $message = (object) ['is_private' => false, 'chat_id' => $user->telegram_id, 'args' => ['1.5GB']];
        $normal->handle($message);
        $lucky->handle($message);
        $this->assertCount(0, $transport->messages);
        $message->is_private = true;
        $message->chat_id = 1234;
        $normal->handle($message);
        $lucky->handle($message);
        $this->assertStringContainsString('绑定账号', $transport->messages[0][1]);
        $this->assertStringContainsString('绑定账号', $transport->messages[1][1]);
        $message->chat_id = $user->telegram_id;
        $lucky->handle($message);
        $this->assertStringContainsString('运气签到成功', $transport->messages[2][1]);
        $normal->handle($message);
        $this->assertStringContainsString('已经签到', $transport->messages[3][1]);
        $this->assertFalse($this->service->claimForApp($user->id)['data']);
        foreach ($transport->messages as $sent) $this->assertSame('', $sent[2]);
        $this->assertSame($user->d, $user->fresh()->d);
        $commands = (new TelegramService())->discoverCommands(dirname(__DIR__, 2) . '/app/Plugins/Telegram/Commands');
        foreach (['sign1', 'sign2', 'traffic'] as $name) $this->assertContains($name, array_column($commands, 'command'));
        $this->assertNotContains('checkin', array_column($commands, 'command'));
    }

    public function test_admin_site_toggles_have_no_schema_or_reward_range_dependency(): void
    {
        $request = ConfigSave::create('/config/save', 'POST');
        $factory = new Factory(new Translator(new ArrayLoader(), 'zh-CN'), $this->app);
        foreach (['checkin_enable', 'lucky_checkin_enable'] as $field) {
            foreach ([0, 1, '0', '1'] as $value) $this->assertTrue($factory->make([$field => $value], $request->rules())->passes());
            foreach ([2, -1, 'true', null, []] as $value) $this->assertFalse($factory->make([$field => $value], $request->rules())->passes());
        }
        $this->assertArrayNotHasKey('checkin_min_gb', $request->rules());
        $this->assertArrayNotHasKey('checkin_max_gb', $request->rules());
        $this->app['config']->set('v2board', []);
        $body = json_decode((new ConfigController())->fetch(Request::create('/config/fetch', 'GET'))->getContent(), true)['data'];
        $this->assertSame(0, $body['site']['checkin_enable']);
        $this->assertSame(0, $body['site']['lucky_checkin_enable']);
        $this->assertArrayNotHasKey('checkin', $body);
    }

    public function test_traffic_flush_preserves_credit_claimed_after_user_snapshot_and_upload_only_users(): void
    {
        $user = $this->user();
        $uploadOnly = $this->user();
        Redis::swap(new class($user->id, $uploadOnly->id) {
            private $hashes;
            public function __construct($userId, $uploadOnlyId)
            {
                $this->hashes = ['v2board_upload_traffic' => [$userId => '13', $uploadOnlyId => '23'],
                    'v2board_download_traffic' => [$userId => '17', 99999 => '1']];
            }
            public function exists($key) { return false; }
            public function hgetall($key) { return $this->hashes[$key]; }
            public function del($key) { unset($this->hashes[$key]); }
        });
        $reward = null;
        $this->db->getConnection()->listen(function ($query) use ($user, &$reward) {
            if ($reward === null && strpos($query->sql, 'select') === 0 && strpos($query->sql, 'from "v2_user" where "id" in') !== false) {
                $reward = $this->service->claimForApp($user->id)['traffic'];
            }
        });
        (new TrafficUpdate())->handle();
        $this->assertIsInt($reward);
        $this->assertSame($user->d - $reward + 17, $user->fresh()->d);
        $this->assertSame($user->u + 13, $user->fresh()->u);
        $this->assertSame($uploadOnly->d, $uploadOnly->fresh()->d);
        $this->assertSame($uploadOnly->u + 23, $uploadOnly->fresh()->u);
    }

    public function test_one_time_and_period_proration_never_monetize_more_than_paid_value(): void
    {
        foreach (['onetime_price', 'month_price', 'year_price'] as $period) {
            $user = $this->user(['u' => 0, 'd' => -10 * CheckinService::BYTES_PER_GB,
                'expired_at' => $period === 'onetime_price' ? null : time() + 86400 * 400]);
            Order::create(['user_id' => $user->id, 'plan_id' => 1, 'period' => $period,
                'total_amount' => 10000, 'balance_amount' => 5000, 'created_at' => time() - 86400]);
            $newOrder = new Order(['user_id' => $user->id, 'plan_id' => 2, 'period' => 'month_price', 'total_amount' => 50000]);
            (new OrderService($newOrder))->setOrderType($user);
            $this->assertSame(3, $newOrder->type);
            $this->assertGreaterThanOrEqual(0, $newOrder->surplus_amount);
            $this->assertLessThanOrEqual(15000, $newOrder->surplus_amount);
            if ($period === 'onetime_price') $this->assertEquals(15000, $newOrder->surplus_amount);
        }
    }

    public function test_overlapping_processes_share_atomic_day_reservation(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Concurrency test requires pcntl and Unix socket pairs');
        }
        $user = $this->user();
        $path = tempnam(sys_get_temp_dir(), 'checkin-race-');
        $cachePath = $path . '-cache';
        $this->app['config']->set('cache.default', 'file');
        $this->app['config']->set('cache.stores.file', ['driver' => 'file', 'path' => $cachePath]);
        $manager = $this->db->getDatabaseManager();
        $this->db->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => ''], 'race');
        $connection = $manager->connection('race');
        $connection->statement('PRAGMA journal_mode=WAL');
        foreach ($this->db->getConnection()->select("SELECT sql FROM sqlite_master WHERE type='table' AND name LIKE 'v2_%'") as $schema) {
            $connection->statement($schema->sql);
        }
        foreach (['v2_user', 'v2_plan'] as $table) {
            foreach ($this->db->getConnection()->table($table)->get() as $row) $connection->table($table)->insert((array) $row);
        }
        $manager->setDefaultConnection('race');
        $workers = [];
        try {
            foreach (['app', 'telegram'] as $source) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                $pid = pcntl_fork();
                if ($pid === -1) throw new \RuntimeException('Unable to fork check-in test worker');
                if ($pid === 0) {
                    fclose($pair[0]);
                    stream_set_timeout($pair[1], 5);
                    $manager->purge('race');
                    $reads = 0;
                    $manager->connection()->listen(function ($query) use ($pair, &$reads) {
                        if (strpos($query->sql, 'from "v2_user"') === false || ++$reads !== 1) return;
                        fwrite($pair[1], "ready\n");
                        if (fgets($pair[1]) !== "go\n") throw new \RuntimeException('Concurrency barrier timed out');
                    });
                    try {
                        $result = $source === 'app' ? $this->service->claimForApp($user->id)
                            : $this->service->claimForTelegram($user->telegram_id, 2, '1MB');
                        fwrite($pair[1], json_encode(['result' => $result]) . "\n");
                    } catch (\Throwable $e) { fwrite($pair[1], json_encode(['error' => $e->getMessage()]) . "\n"); }
                    fclose($pair[1]);
                    exit(0);
                }
                fclose($pair[1]);
                stream_set_timeout($pair[0], 5);
                $workers[$source] = ['socket' => $pair[0], 'pid' => $pid];
            }
            foreach ($workers as $worker) $this->assertSame("ready\n", fgets($worker['socket']));
            fwrite($workers['app']['socket'], "go\n");
            $app = json_decode(fgets($workers['app']['socket']), true);
            fwrite($workers['telegram']['socket'], "go\n");
            $telegram = json_decode(fgets($workers['telegram']['socket']), true);
            $this->assertArrayNotHasKey('error', $app);
            $this->assertArrayNotHasKey('error', $telegram);
            $this->assertTrue($app['result']['data']);
            $this->assertFalse($telegram['result']['data']);
            $this->assertSame($user->d - $app['result']['traffic'], User::find($user->id)->d);
        } finally {
            foreach ($workers as $worker) { fclose($worker['socket']); pcntl_waitpid($worker['pid'], $status); }
            $manager->purge('race');
            $manager->setDefaultConnection('default');
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) if (is_file($file)) unlink($file);
            (new Filesystem())->deleteDirectory($cachePath);
        }
    }
}
