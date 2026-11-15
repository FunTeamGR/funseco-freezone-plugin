<?php
namespace Funseco\Freezone;

use Funseco\Core\SharedKernel\PublishesPluginConfig;
use Funseco\Core\SharedKernel\Settings\Setting;
use Funseco\Core\SharedKernel\Settings\SettingType;
use Funseco\Core\SharedKernel\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Funseco\Core\SharedKernel\Events\EverySecondEvent;
use Funseco\Core\SharedKernel\Events\PlayerChatLine;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Core\SharedKernel\Events\PlayerDisconnected;

class FreezoneServiceProvider extends ServiceProvider
{
    use PublishesPluginConfig;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/freezone.php', 'freezone');
        $this->publishPluginConfig(__DIR__.'/..');

        // Every value is read at use time (listeners are always registered), so all apply live. Skipped: class_hash, manialive_revision (impersonation constants).
        $this->app->make(SettingsRegistry::class)->declare('freezone', 'freezone', [
            Setting::bool('enabled', 'FreeZone enabled'),
            Setting::url('ws_url', 'Web service URL'),
            Setting::string('ws_user', 'Web service user (empty = server login)'),
            Setting::string('ws_password', 'Web service password', secret: true),
            Setting::bool('testmode', 'Test mode (no web calls)'),
            Setting::bool('ban_sync', 'Ban sync'),
            Setting::bool('advert', 'Free-account advert'),
            Setting::bool('slang_report', 'Slang report'),
            Setting::bool('stats', 'Hourly stats POST'),
            Setting::int('advert_minutes', 'Advert interval', min: 1, unit: 'min'),
            Setting::int('slang_threshold', 'Slang hits before report', min: 1),
            Setting::int('slang_window', 'Slang window', min: 1, unit: 's'),
            Setting::int('refresh_hours', 'Slang list refresh', min: 1, unit: 'h'),
            Setting::int('stats_minutes', 'Stats interval', min: 1, unit: 'min'),
            Setting::int('notice_minutes', 'Bad API password notice interval', min: 1, unit: 'min'),
        ]);
        $this->app->singleton(Slang::class);
        $this->app->singleton(WebService::class);
        $this->app->singleton(Freezone::class);
    }

    public function boot(): void
    {
        Event::listen(PlayerConnected::class, fn (PlayerConnected $e) => app(Freezone::class)->connected($e->player));
        Event::listen(PlayerDisconnected::class, fn (PlayerDisconnected $e) => app(Freezone::class)->disconnected($e->player));
        Event::listen(PlayerChatLine::class, fn (PlayerChatLine $e) => app(Freezone::class)->chatted($e->player, $e->text));
        Event::listen(EverySecondEvent::class, fn () => app(Freezone::class)->tick());
    }
}
