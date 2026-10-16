<?php

namespace Peppermint\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Peppermint\Notifications\Push\PushKeys;

/**
 * Mit welchem Schluessel soll dieser Browser abonnieren?
 *
 * Eine Antwort, zwei Schreibweisen: `public_key` und `publicKey`. Die vier
 * Produkte haben ihre eigenen Endpunkte mit je eigener Schreibweise
 * gewachsen, und ein Frontend, das die falsche liest, bekommt `undefined`
 * und bietet stumm kein Abo an. Zwei Schluessel im JSON kosten nichts; ein
 * stiller Ausfall kostet eine Fehlersuche.
 */
class PushKeyController
{
    public function __invoke(PushKeys $schluessel): JsonResponse
    {
        $oeffentlich = $schluessel->publicKey();

        return response()->json([
            'public_key' => $oeffentlich,
            'publicKey' => $oeffentlich,
        ]);
    }
}
