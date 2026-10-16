<?php

namespace Peppermint\Notifications\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Peppermint\Notifications\Delivery\Notifier;
use Peppermint\Notifications\Models\Notification;

/**
 * Eine Meldung ist neu entstanden.
 *
 * Hier haengt sich an, wer pushen oder an AI Brain spiegeln will. Der
 * {@see Notifier} selbst tut beides nicht —
 * sonst muesste er wissen, ob gerade das Brain die Schluessel haelt oder das
 * Produkt, und das ist die Frage, die ihn nichts angeht.
 */
class NotificationCreated
{
    use Dispatchable;

    public function __construct(public readonly Notification $notification) {}
}
