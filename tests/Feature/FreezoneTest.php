<?php
namespace Funseco\Freezone\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use React\Http\Message\Response;
use React\Http\Message\ResponseException;
use Funseco\Core\Testing\FakesServer;
use Funseco\Freezone\Tests\TestCase;
use Funseco\Core\Models\Player;
use Funseco\Core\Servers\TmForever\Values\PlayerInfo;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\Http\AsyncHttpClient;
use Funseco\Core\SharedKernel\Events\EverySecondEvent;
use Funseco\Core\SharedKernel\Events\PlayerChatLine;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Freezone\Freezone;
use Funseco\Freezone\Slang;
use Funseco\Freezone\WebService;
use function React\Promise\reject;
use function React\Promise\resolve;

class FreezoneTest extends TestCase
{
    use FakesServer;
    use RefreshDatabase;

    /** @var array<int, array{0:string,1:string,2:?string,3:array}> verb, url, body, headers */
    private array $http = [];
    /** url fragment => body (string) or Throwable */
    private array $answers = [];
    private Player $free;
    private Player $united;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        CurrentPlayers::clear();
        Carbon::setTestNow('2026-01-01 12:00:00');
        config(['freezone.enabled' => true, 'freezone.ws_password' => 'pw', 'freezone.ws_user' => 'srv',
            'freezone.ban_sync' => true, 'freezone.advert' => true, 'freezone.slang_report' => true, 'freezone.stats' => true,
            'adminops.masteradmins' => ['boss']]);

        $client = Mockery::mock(AsyncHttpClient::class);
        foreach (['get', 'put', 'post'] as $verb) {
            $client->shouldReceive($verb)->andReturnUsing(function (...$args) use ($verb) {
                $url = $args[0];
                $body = $verb === 'get' ? null : $args[1];
                $headers = $verb === 'get' ? ($args[1] ?? []) : ($args[2] ?? []);
                if (!str_contains($url, "ws.trackmania.com")) {
                    return resolve(new Response(200, [], "null"));
                }
                $this->http[] = [$verb, $url, $body, $headers];
                foreach ($this->answers as $fragment => $answer) {
                    if (str_contains($url, $fragment)) {
                        return $answer instanceof \Throwable ? reject($answer) : resolve(new Response(200, [], $answer));
                    }
                }

                return resolve(new Response(200, [], 'null'));
            });
        }
        $this->app->instance(AsyncHttpClient::class, $client);
        foreach ([WebService::class, Freezone::class, Slang::class] as $class) {
            $this->app->forgetInstance($class);
        }

        $this->free = Player::factory()->create(['login' => 'free1', 'nickname' => 'Free']);
        $this->united = Player::factory()->create(['login' => 'uni1', 'nickname' => 'Uni']);
        CurrentPlayers::add($this->free);
        CurrentPlayers::add($this->united);
        state()->forever('funseco:player_united:uni1', true);
        ServerInfo::set(['server_name' => 'Srv', 'max_players' => 32, 'hidden' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function join(Player $p): void
    {
        Event::dispatch(new PlayerConnected($p, new PlayerInfo(['Login' => $p->login])));
    }

    private function urls(string $verb): array
    {
        return array_values(array_map(fn ($h) => $h[1], array_filter($this->http, fn ($h) => $h[0] === $verb)));
    }

    public function test_off_by_default_does_nothing(): void
    {
        config(['freezone.enabled' => false]);
        $this->join($this->free);
        Event::dispatch(new EverySecondEvent());

        $this->assertSame([], $this->http);
        $this->assertSame([], $this->sent('ChatSendServerMessageToLanguage'));
    }

    public function test_shipped_defaults_are_all_off(): void
    {
        $defaults = require dirname(__DIR__, 2).'/config/freezone.php';
        foreach (['enabled', 'ban_sync', 'advert', 'slang_report', 'stats', 'testmode'] as $key) {
            $this->assertFalse($defaults[$key], $key);
        }
    }

    public function test_banned_player_is_kicked_with_basic_auth(): void
    {
        $this->answers['/freezone/ban/status/free1/'] = '2';
        $this->join($this->free);

        $this->assertSame([['free1', '']], $this->sent('Kick'));
        $this->assertSame('http://ws.trackmania.com/freezone/ban/status/free1/index.json', $this->urls('get')[0]);
        $this->assertSame('Basic '.base64_encode('srv:pw'), $this->http[0][3]['Authorization']);
    }

    public function test_united_player_is_ban_checked_too_and_unbanned_is_left_alone(): void
    {
        $this->answers['/freezone/ban/status/uni1/'] = '2';
        $this->join($this->united);
        $this->assertSame([['uni1', '']], $this->sent('Kick'));

        $this->answers['/freezone/ban/status/free1/'] = '0';
        $this->join($this->free);
        $this->assertCount(1, $this->sent('Kick'));
    }

    public function test_local_banned_list_kicks_without_web_call_until_the_refresh(): void
    {
        $this->answers['/freezone/ban/status/free1/'] = '2';
        $this->join($this->free);
        $this->join($this->free);   // from the local list
        $this->assertCount(2, $this->sent('Kick'));
        $this->assertCount(1, array_filter($this->urls('get'), fn ($u) => str_contains($u, 'ban/status')));

        Event::dispatch(new EverySecondEvent());   // first tick refreshes: list cleared
        $this->answers['/freezone/ban/status/free1/'] = '0';
        $this->join($this->free);
        $this->assertCount(2, $this->sent('Kick'));
    }

    public function test_ban_check_fails_open(): void
    {
        $this->answers['/freezone/ban/status/'] = new \RuntimeException('down');
        Log::shouldReceive('warning')->once();
        $this->join($this->free);

        $this->assertSame([], $this->sent('Kick'));
    }

    public function test_wrong_password_tells_master_admins_once(): void
    {
        $boss = Player::factory()->create(['login' => 'boss', 'nickname' => 'Boss']);
        CurrentPlayers::add($boss);
        $this->answers['/freezone/ban/status/'] = new ResponseException(new Response(401));
        Log::shouldReceive('warning');
        $this->join($this->free);
        $this->join($this->united);

        $this->assertSame([[Freezone::BAD_PASSWORD, 'boss']], array_values(array_filter($this->sent('ChatSendServerMessageToLogin'), fn ($a) => str_contains($a[0], 'FreeZone'))));
        $this->assertSame('$<$0f0$oFreeZone:$> Bad API Password, please check your configuration file and the manialink $hfreezone:servers$h', Freezone::BAD_PASSWORD);
    }

    public function test_no_web_call_without_password_or_in_testmode(): void
    {
        config(['freezone.ws_password' => '']);
        $this->join($this->free);
        Event::dispatch(new EverySecondEvent());
        $this->assertSame([], $this->http);

        config(['freezone.ws_password' => 'pw', 'freezone.testmode' => true]);
        $this->join($this->free);
        $this->assertSame([], $this->http);
    }

    public function test_advert_goes_to_free_players_only_every_13_minutes(): void
    {
        config(['freezone.ws_password' => '']);
        Event::dispatch(new EverySecondEvent());

        $sent = $this->sent('ChatSendServerMessageToLanguage');
        $this->assertCount(1, $sent);
        $this->assertSame('free1', $sent[0][1]);
        $this->assertSame('en', end($sent[0][0])['Lang']);
        $this->assertSame('To enjoy unlimited access to all online games, you must upgrade to a TrackMania United Forever account. Click here $hfreezone$h for more information',
            end($sent[0][0])['Text']);

        Carbon::setTestNow(now()->addMinutes(12));
        Event::dispatch(new EverySecondEvent());
        $this->assertCount(1, $this->sent('ChatSendServerMessageToLanguage'));
        Carbon::setTestNow(now()->addMinutes(1));
        Event::dispatch(new EverySecondEvent());
        $this->assertCount(2, $this->sent('ChatSendServerMessageToLanguage'));
    }

    public function test_advert_is_skipped_when_everybody_is_united(): void
    {
        state()->forever('funseco:player_united:free1', true);
        Event::dispatch(new EverySecondEvent());
        $this->assertSame([], $this->sent('ChatSendServerMessageToLanguage'));
    }

    public function test_never_forces_spectator_or_player_mode(): void
    {
        $this->join($this->free);
        Event::dispatch(new EverySecondEvent());
        $this->assertSame([], $this->sent('ForceSpectator'));
    }

    public function test_slang_list_loads_and_report_is_sent_hourly(): void
    {
        $this->answers['/freezone/slang/'] = json_encode(['badword', 'Foo']);
        Event::dispatch(new EverySecondEvent());   // loads the list, hourly job runs (nothing yet)
        $this->assertSame(2, app(Slang::class)->wordCount());

        for ($i = 0; $i < 11; $i++) {
            Event::dispatch(new PlayerChatLine($this->free, $i % 2 ? 'BADWORD' : 'you foo'));
        }
        Event::dispatch(new PlayerChatLine($this->free, 'badwords here'));   // not a whole word

        Carbon::setTestNow(now()->addMinutes(60));
        Event::dispatch(new EverySecondEvent());

        $puts = array_values(array_filter($this->http, fn ($h) => $h[0] === 'put'));
        $this->assertCount(1, $puts);
        $this->assertStringEndsWith('/freezone/abuses/reports/srv/', $puts[0][1]);
        $this->assertSame([['language' => 'en', 'matches' => [['foo', 6], ['badword', 5]]]], json_decode($puts[0][2], true));
        $this->assertSame([], app(Slang::class)->report(), 'watched list cleared');
    }

    public function test_slang_below_threshold_or_slow_is_not_reported(): void
    {
        $slang = app(Slang::class);
        $slang->setWords(['x']);
        for ($i = 0; $i < 9; $i++) {
            $slang->check('a', 'en', 'x', 1000 + $i);
        }
        $this->assertSame([], $slang->report());
        for ($i = 0; $i < 10; $i++) {
            $slang->check('b', 'en', 'x', 1000 + $i * 1000);   // spread over 9000 s
        }
        $this->assertSame([], $slang->report());
        $slang->check('a', 'en', 'x', 1100);   // 10th hit within the hour
        $this->assertCount(1, $slang->report());
    }

    public function test_stats_post_body(): void
    {
        ServerInfo::set(['server_name' => 'My Srv', 'max_players' => 32, 'hidden' => 0]);
        $this->responses['GetVersion'] = ['Name' => 'TmForever', 'Version' => '2.11.26', 'Build' => '2011-02-21'];
        $this->answers['/manialive/version/check/'] = json_encode(['version' => ['revision' => '5xx']]);
        Event::dispatch(new EverySecondEvent());

        $posts = array_values(array_filter($this->http, fn ($h) => $h[0] === 'post'));
        $this->assertCount(1, $posts);
        $this->assertStringEndsWith('/freezone/live/', $posts[0][1]);
        $this->assertSame([
            'serverLogin' => 'srv', 'serverName' => 'My Srv', 'serverVersion' => 'TmForever,2.11.26,2011-02-21',
            'manialiveVersion' => '5xx', 'maxPlayers' => 32, 'visibility' => 0,
            'classHash' => '6f116833b419fe7cb9c912fdaefb774845f60e79',
        ], json_decode($posts[0][2], true));
    }

    public function test_stats_and_report_wait_for_server_info(): void
    {
        state()->forget('serverInfo');
        Event::dispatch(new EverySecondEvent());
        $this->assertSame([], array_filter($this->http, fn ($h) => $h[0] !== 'get' || !str_contains($h[1], '/slang/')));

        ServerInfo::set(['server_name' => 'Srv', 'max_players' => 1, 'hidden' => 0]);
        Event::dispatch(new EverySecondEvent());
        $this->assertNotEmpty($this->urls('post'));
    }

    public function test_enabled_with_every_feature_off_sends_nothing(): void
    {
        config(['freezone.ban_sync' => false, 'freezone.advert' => false, 'freezone.slang_report' => false, 'freezone.stats' => false]);
        $this->join($this->free);
        Event::dispatch(new PlayerChatLine($this->free, 'hello'));
        Event::dispatch(new EverySecondEvent());

        $this->assertSame([], $this->http);
        $this->assertSame([], $this->sent('ChatSendServerMessageToLanguage'));
        $this->assertSame([], $this->sent('Kick'));
        $this->assertSame([], $this->sent('GetVersion'));
    }

    public function test_exception_while_building_stats_does_not_escape(): void
    {
        config(['freezone.ban_sync' => false, 'freezone.advert' => false, 'freezone.slang_report' => false]);
        ServerInfo::set(['server_name' => new \stdClass, 'max_players' => 1, 'hidden' => 0]);   // (string) cast throws
        Log::shouldReceive('warning')->atLeast()->once();

        Event::dispatch(new EverySecondEvent());
        $this->assertSame([], $this->urls('post'));
    }

    public function test_failure_report_that_throws_does_not_escape(): void
    {
        $bad = Mockery::mock(Player::factory()->create(['login' => 'bad'])->makeVisible([]))->makePartial();
        $bad->shouldReceive('effectiveRole')->andThrow(new \RuntimeException('boom'));
        CurrentPlayers::add($bad);
        $this->answers['/freezone/ban/status/'] = new ResponseException(new Response(401));
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->join($this->free);
        $this->assertSame([], $this->sent('Kick'));
    }
}
