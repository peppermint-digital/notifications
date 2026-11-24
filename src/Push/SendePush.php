<?php

namespace Peppermint\Notifications\Push;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Peppermint\Notifications\Models\Notification;

/**
 * Eine Meldung an alle Geraete ihrer Person pushen.
 *
 * In der Warteschlange, nicht im Ereignis: Ein Push-Dienst antwortet in
 * Sekunden oder gar nicht, und das darf nicht den Vorgang aufhalten, der die
 * Meldung ausgeloest hat.
 *
 * Abgelaufene Abos (404/410) werden geloescht — sonst wuerde an jedes davon
 * bei jeder Meldung erneut zugestellt. Andere Fehlschlaege bleiben stehen: Ein
 * Push-Dienst, der gerade nicht antwortet, sagt nichts ueber das Abo.
 */
class SendePush implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int|string $notificationId) {}

    public function handle(PushZusteller $zusteller): void
    {
        $meldung = Notification::query()->find($this->notificationId);

        if ($meldung === null || ! $zusteller->eingerichtet()) {
            return;
        }

        $abos = PushSubscription::query()->fuer($meldung->field('user_id'))->get();

        if ($abos->isEmpty()) {
            return;
        }

        $tot = [];
        $zugestellt = [];

        foreach ($zusteller->senden($abos, PushNutzlast::aus($meldung)) as $ergebnis) {
            if ($ergebnis['erfolg']) {
                $zugestellt[] = $ergebnis['abo']->getKey();
            } elseif ($ergebnis['abgelaufen']) {
                $tot[] = $ergebnis['abo']->getKey();
            } else {
                Log::warning('Push an ein Geraet nicht zugestellt.', [
                    'abo' => $ergebnis['abo']->getKey(),
                    'grund' => $ergebnis['grund'],
                ]);
            }
        }

        if ($zugestellt !== []) {
            PushSubscription::query()->whereKey($zugestellt)->update(['last_used_at' => now()]);
        }

        if ($tot !== []) {
            PushSubscription::query()->whereKey($tot)->delete();
        }
    }
}
