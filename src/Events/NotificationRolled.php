<?php

namespace Peppermint\Notifications\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Peppermint\Notifications\Models\Notification;

/**
 * Eine bestehende Meldung wurde fortgeschrieben — dieselbe Sache ist noch
 * einmal passiert.
 *
 * Bewusst ein ANDERES Ereignis als {@see NotificationCreated}: Wer pusht, soll
 * hier normalerweise NICHT pushen. Der Browser hat die Meldung schon gezeigt,
 * und ein zweiter Ton fuer dieselbe Sache ist genau die Flut, gegen die das
 * Rollen gebaut ist.
 *
 * Wer es trotzdem braucht — etwa eine Live-Anzeige, die den Zaehler
 * mitfuehrt —, hoert hier mit.
 */
class NotificationRolled
{
    use Dispatchable;

    public function __construct(
        public readonly Notification $notification,
        /** Die Gesamtzahl der zusammengefassten Vorgaenge. */
        public readonly int $count,
    ) {}
}
