<?php
namespace Funseco\Freezone;

use Illuminate\Support\Facades\Log;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\Funseco\PlayerAccounts;
use Funseco\Core\SharedKernel\Enums\Role;

/**
 * FreeZone, asmo variant. Free-account players are never forced to spectate or to play (no cap,
 * no lock, no button); United players are exempt from everything except the ban check.
 * Left: ban sync (kick), free-account advert, slang telemetry, hourly stats. All fail open.
 */
class Freezone
{
    public const ADVERT = [
        ['Lang' => 'de', 'Text' => 'Für einen unbegrenzten Zugang zu allen Onlinepartien musst Du auf einen Trackmania United Forever Account upgraden. Klicke hier $hfreezone$h für mehr Informationen.'],
        ['Lang' => 'fr', 'Text' => 'Pour profiter d\'un accès illimité à tous les serveurs en ligne, vous devez convertir votre compte à TrackMania United Forever. Clicker ici $hfreezone$h pour plus d\'informations'],
        // last = fallback
        ['Lang' => 'en', 'Text' => 'To enjoy unlimited access to all online games, you must upgrade to a TrackMania United Forever account. Click here $hfreezone$h for more information'],
    ];

    public const BAD_PASSWORD = '$<$0f0$oFreeZone:$> Bad API Password, please check your configuration file and the manialink $hfreezone:servers$h';

    /** @var array<string, true> */
    private array $banned = [];
    /** @var array<string, string> */
    private array $languages = [];
    private ?int $refreshedAt = null;
    private ?int $statsAt = null;
    private ?int $advertAt = null;
    private int $noticeAt = 0;
    private ?string $manialiveVersion = null;

    public function __construct(private WebService $web, private Slang $slang, private PlayerAccounts $accounts)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('freezone.enabled');
    }

    private function on(string $feature): bool
    {
        return $this->enabled() && (bool) config('freezone.'.$feature);
    }

    private function webOn(string $feature): bool
    {
        return $this->on($feature) && $this->web->usable();
    }

    // --- events -------------------------------------------------------------------------

    /** Every joining player, United or not: local banned list first, then the web ban check. */
    public function connected(Player $player): void
    {
        if ($this->webOn('slang_report')) {
            \Server::query()->GetDetailedPlayerInfo($player->login, function ($result) use ($player) {
                $lang = is_array($result[0] ?? null) ? (string) ($result[0]['Language'] ?? '') : '';
                $this->languages[$player->login] = $lang !== '' ? $lang : 'en';
            });
        }
        if (!$this->on('ban_sync')) {
            return;
        }
        if (isset($this->banned[$player->login])) {
            \Server::query()->Kick($player->login, '');

            return;
        }
        if (!$this->web->usable()) {
            return;
        }
        $this->web->get('/freezone/ban/status/'.rawurlencode($player->login).'/index.json')->then(function ($status) use ($player) {
            if ((int) $status === 2) {
                $this->banned[$player->login] = true;
                \Server::query()->Kick($player->login, '');
            }
        })->catch(fn (\Throwable $e) => $this->failed($e, 'ban status'));
    }

    public function disconnected(Player $player): void
    {
        unset($this->languages[$player->login]);
    }

    public function chatted(Player $player, string $text): void
    {
        if (!$this->webOn('slang_report') || $this->slang->wordCount() === 0) {
            return;
        }
        $this->slang->check($player->login, $this->languages[$player->login] ?? 'en', $text, now()->timestamp);
    }

    /** One-second tick: schedules the jobs, each first right away. */
    public function tick(): void
    {
        if (!$this->enabled()) {
            return;
        }
        $now = now()->timestamp;

        if ($this->webOn('ban_sync') || $this->webOn('slang_report')) {
            if ($this->refreshedAt === null || $now - $this->refreshedAt >= (int) config('freezone.refresh_hours') * 3600) {
                $this->refreshedAt = $now;
                $this->banned = [];
                $this->loadSlang();
            }
        }
        // not before the server info is known (stats body, ready controller)
        if (($this->webOn('stats') || $this->webOn('slang_report')) && ServerInfo::get()->isNotEmpty()) {
            if ($this->statsAt === null || $now - $this->statsAt >= (int) config('freezone.stats_minutes') * 60) {
                $this->statsAt = $now;
                $this->hourly($now);
            }
        }
        if ($this->on('advert')) {
            if ($this->advertAt === null || $now - $this->advertAt >= (int) config('freezone.advert_minutes') * 60) {
                $this->advertAt = $now;
                $this->advert();
            }
        }
    }

    // --- jobs ---------------------------------------------------------------------------

    /** Chat line to every free-account player, in their language (English fallback). */
    public function advert(): void
    {
        $free = CurrentPlayers::all()->filter(fn (Player $p) => !$this->accounts->isUnited($p))->pluck('login')->all();
        if ($free) {
            \Server::query()->ChatSendServerMessageToLanguage(self::ADVERT, implode(',', $free));
        }
    }

    private function loadSlang(): void
    {
        if (!$this->webOn('slang_report')) {
            return;
        }
        $this->web->get('/freezone/slang/')->then(function ($words) {
            if (is_array($words)) {
                $this->slang->setWords($words);
            }
        })->catch(fn (\Throwable $e) => $this->failed($e, 'slang list'));
    }

    private function hourly(int $now): void
    {
        if ($this->webOn('stats')) {
            $this->postStats();
        }
        if ($this->webOn('slang_report')) {
            $report = $this->slang->report();
            if ($report) {
                $this->web->put('/freezone/abuses/reports/'.rawurlencode($this->web->user()).'/', $report)
                    ->catch(fn (\Throwable $e) => $this->failed($e, 'slang report'));
            }
            $this->slang->clearWatched();   // after a send attempt, success or not
            $this->slang->prune($now);
        }
    }

    private function postStats(): void
    {
        $send = function () {
            try {
                \Server::query()->GetVersion(function ($result) {
                    try {
                        $v = is_array($result[0] ?? null) ? $result[0] : [];
                        $info = ServerInfo::get();
                        $this->web->post('/freezone/live/', [
                            'serverLogin'      => $this->web->user(),
                            'serverName'       => (string) ($info['server_name'] ?? ''),
                            'serverVersion'    => implode(',', [$v['Name'] ?? '', $v['Version'] ?? '', $v['Build'] ?? '']),
                            'manialiveVersion' => $this->manialiveVersion,
                            'maxPlayers'       => (int) ($info['max_players'] ?? 0),
                            'visibility'       => $info['hidden'] ?? 0,
                            'classHash'        => (string) config('freezone.class_hash'),
                        ])->catch(fn (\Throwable $e) => $this->failed($e, 'stats'));
                    } catch (\Throwable $e) {
                        $this->failed($e, 'stats');
                    }
                });
            } catch (\Throwable $e) {
                $this->failed($e, 'stats');
            }
        };
        if ($this->manialiveVersion !== null) {
            $send();

            return;
        }
        $this->web->get('/manialive/version/check/'.(int) config('freezone.manialive_revision').'/index.json')
            ->then(function ($answer) {
                $this->manialiveVersion = (string) ($answer['version']['revision'] ?? '');
            })->catch(fn (\Throwable $e) => $this->failed($e, 'version check'))
            ->then($send, $send);   // $send never throws
    }

    /** Fail open: log, and tell master admins about bad credentials (at most once per notice_minutes). */
    private function failed(\Throwable $e, string $what): void
    {
        try {
            if ($e instanceof WrongPassword) {
                $now = now()->timestamp;
                if ($now - $this->noticeAt >= (int) config('freezone.notice_minutes') * 60) {
                    $this->noticeAt = $now;
                    foreach (CurrentPlayers::all() as $p) {
                        if ($p->effectiveRole() === Role::MasterAdmin) {
                            \Server::query()->ChatSendServerMessageToLogin(self::BAD_PASSWORD, $p->login);
                        }
                    }
                }
            }
            Log::warning('freezone: '.$what.' failed: '.$e->getMessage());
        } catch (\Throwable) {
            // reporting a failure must never raise another
        }
    }
}
