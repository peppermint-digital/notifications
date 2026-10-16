<?php

namespace Peppermint\Notifications;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\Events\NotificationCreated;
use Peppermint\Notifications\Feed\NotificationActions;
use Peppermint\Notifications\Feed\NotificationFeed;
use Peppermint\Notifications\Mirroring\BrainMirror;
use Peppermint\Notifications\Mirroring\MirrorReceiver;
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

        // Zustandslos, deshalb geteilt. Wer sie ersetzen will — etwa um das
        // Spiegeln an AI Brain anders zu loesen —, bindet hier eine eigene
        // Ableitung.
        $this->app->singleton(Notifier::class);
        $this->app->singleton(BrainMirror::class);
        $this->app->singleton(MirrorReceiver::class);
        $this->app->singleton(NotificationFeed::class);
        $this->app->singleton(NotificationActions::class);
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

        $this->spiegelungVerdrahten();
    }

    /**
     * Die Spiegelung ueber AI Brain — nur im `brain`-Betrieb.
     *
     * Ein Produkt auf `local` haengt den Horcher gar nicht erst ein. Das ist
     * nicht nur sparsam, sondern die Zusicherung, die im README steht: Wer
     * das Paket allein benutzt, hat keine Verbindung nach draussen, auch
     * keine, die nur gerade nichts tut.
     */
    private function spiegelungVerdrahten(): void
    {
        if (config('notifications.hub', 'local') !== 'brain') {
            return;
        }

        // Die SENDENDE Seite: jede neue Meldung an den Hub melden.
        //
        // Bewusst nur `NotificationCreated` und NICHT `NotificationRolled`:
        // Eine fortgeschriebene Sammelmeldung ist dieselbe Sache noch einmal,
        // und sie drueben als zweite Zeile anzulegen waere genau die Flut,
        // gegen die das Rollen gebaut ist.
        Event::listen(NotificationCreated::class, [BrainMirror::class, 'handle']);
    }
}
