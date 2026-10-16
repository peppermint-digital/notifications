<?php

namespace Peppermint\Notifications;

use Illuminate\Support\ServiceProvider;
use Peppermint\Notifications\Types\TypeRegistry;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/notifications.php', 'notifications');

        $this->app->singleton(TypeRegistry::class, fn (): TypeRegistry => new TypeRegistry(
            konfigurierteTypen: (array) config('notifications.types', []),
            rueckfall: (array) config('notifications.unknown_type', []),
            streng: (bool) config('notifications.strict_types', false),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/notifications.php' => config_path('notifications.php'),
        ], 'notifications-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'notifications-migrations');

        // Die Vorgabe ist `false`, und das ist Absicht.
        //
        // Jedes der vier Produkte hat seine Tabelle seit Monaten; AI Brain
        // allein ueber 17.000 Zeilen. Ein Paket, das beim Einbinden eine
        // `notifications`-Tabelle anlegen will, scheitert dort entweder laut
        // (Tabelle existiert) oder — schlimmer — legt eine zweite an.
        //
        // Wer adoptiert, ruestet seine fehlenden Spalten mit einer eigenen,
        // additiven Migration nach.
        if (config('notifications.run_migrations', false)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }
}
