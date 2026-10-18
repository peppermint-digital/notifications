<?php

namespace Peppermint\Notifications;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\Events\NotificationCreated;
use Peppermint\Notifications\Feed\NotificationActions;
use Peppermint\Notifications\Feed\BridgeRemoteSource;
use Peppermint\Notifications\Feed\FremdeMeldungen;
use Peppermint\Notifications\Feed\GesamtFeed;
use Peppermint\Notifications\Feed\NotificationFeed;
use Peppermint\Notifications\Feed\RemoteSource;
use Peppermint\Notifications\Mirroring\BrainMirror;
use Peppermint\Notifications\Mirroring\MirrorReceiver;
use Peppermint\Notifications\Preferences\PreferenceResolver;
use Peppermint\Notifications\Push\PushForwarder;
use Peppermint\Notifications\Bridge\NativeBridge;
use Peppermint\Notifications\Console\SammelMailCommand;
use Peppermint\Notifications\Mail\EmailDelivery;
use Peppermint\Notifications\Push\PushKeys;
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
        // Woher die fremden Meldungen kommen, entscheidet das aufnehmende
        // System: Ein Produkt fragt ueber die Bruecke, AI Brain ueber seinen
        // eigenen Gateway. Wer es anders braucht, bindet eine eigene
        // Umsetzung von RemoteSource — deshalb steht hier `bind` und keine
        // feste Klasse im Konstruktor.
        $this->app->bindIf(RemoteSource::class, BridgeRemoteSource::class);
        $this->app->singleton(FremdeMeldungen::class);
        $this->app->singleton(GesamtFeed::class);
        $this->app->singleton(NotificationActions::class);

        // Der Zwischenspeicher im Resolver lebt fuer die Dauer eines Aufrufs
        // und ist deshalb geteilt: Eine Zustellung an zwanzig Menschen soll
        // nicht zwanzig Mal dieselbe Tabelle lesen.
        $this->app->singleton(PreferenceResolver::class);
        $this->app->singleton(PushKeys::class);
        $this->app->singleton(PushForwarder::class);
        $this->app->singleton(EmailDelivery::class);
        $this->app->singleton(NativeBridge::class);
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

        // Die Einstellungen dagegen IMMER: Diese Tabelle gibt es nirgends,
        // also gibt es auch keinen Bestand, auf den Ruecksicht zu nehmen
        // waere. Der Riegel oben schuetzt die gewachsene Meldungstabelle —
        // hier schuetzte er nichts und verhinderte nur, dass die Maske
        // ueberhaupt etwas zu speichern haette.
        $this->loadMigrationsFrom(__DIR__.'/../database/preferences-migrations');

        $this->spiegelungVerdrahten();
        $this->mailVerdrahten();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'notifications');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/notifications'),
        ], 'notifications-views');

        if ($this->app->runningInConsole()) {
            $this->commands([SammelMailCommand::class]);
        }
    }

    /**
     * Die Einzelmail an das Entstehen einer Meldung haengen.
     *
     * Bewusst nur `NotificationCreated` und NICHT `NotificationRolled`: Eine
     * fortgeschriebene Sammelmeldung ist dieselbe Sache noch einmal. Eine
     * zweite Mail dafuer waere genau die Flut, gegen die das Rollen gebaut
     * ist — und sie traefe ausgerechnet die lautesten Arten.
     */
    private function mailVerdrahten(): void
    {
        Event::listen(NotificationCreated::class, function (NotificationCreated $ereignis): void {
            try {
                app(EmailDelivery::class)->sofort($ereignis->notification);
            } catch (\Throwable $fehler) {
                // Fail-open: Eine Mail, die nicht hinausgeht, darf den
                // Vorgang nicht abbrechen, der die Meldung ausgeloest hat.
                Log::warning('Sofort-Mail zu einer Benachrichtigung fehlgeschlagen.', [
                    'error' => $fehler->getMessage(),
                ]);
            }
        });
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

        $this->empfangVerdrahten();
    }

    /**
     * Die EMPFANGENDE Seite: was der Hub zurueckspiegelt, lokal anlegen.
     *
     * Die Bruecke feuert `AiBrainEventReceived` fuer jedes signierte
     * Ereignis, das AI Brain an dieses Produkt schickt. Davon interessiert
     * hier genau eine Art.
     *
     * ## Warum das lange gefehlt hat
     *
     * Beim Bau der Spiegelung war die sendende Seite offensichtlich und die
     * empfangende nicht: Das AI Brain empfaengt ueber seinen eigenen
     * Endpunkt (es ist der Hub), und solange man nur dorthin schickt,
     * funktioniert alles. Dass ein PRODUKT auch empfaengt, faellt erst auf,
     * wenn ein zweites abonniert — und dann steht da nichts.
     *
     * Aufgefallen beim Erklaeren des Aufbaus, nicht beim Bauen.
     */
    private function empfangVerdrahten(): void
    {
        $ereignis = 'Peppermint\\AiBrainBridge\\Events\\AiBrainEventReceived';

        if (! class_exists($ereignis)) {
            // Ohne Bruecke gibt es nichts zu empfangen. Das meldet bereits
            // der BrainMirror beim ersten Sendeversuch — eine zweite Warnung
            // an derselben Ursache waere nur Rauschen.
            return;
        }

        Event::listen($ereignis, function (object $e): void {
            if (($e->event->type ?? null) !== BrainMirror::EVENT_TYPE) {
                return;
            }

            try {
                app(MirrorReceiver::class)->empfangen((array) ($e->event->payload ?? []));
            } catch (\Throwable $fehler) {
                // Fail-open: Eine Meldung, die hier nicht ankommt, darf den
                // Empfang der uebrigen Ereignisse nicht stoeren.
                Log::warning('Gespiegelte Benachrichtigung konnte nicht angelegt werden.', [
                    'error' => $fehler->getMessage(),
                ]);
            }
        });
    }
}
