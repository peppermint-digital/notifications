<?php

namespace Peppermint\Notifications\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Peppermint\Notifications\Feed\NotificationFeed;
use Peppermint\Notifications\Feed\SourceLabels;
use Peppermint\Notifications\Mcp\Concerns\FindetHandelndePerson;

/**
 * Die eigenen Meldungen herausgeben — damit andere sie LESEN statt kopieren.
 *
 * ## Warum es dieses Werkzeug gibt
 *
 * Die erste Fassung der Spiegelung schob jede Meldung als Zeile in jedes
 * Produkt. Das widerspricht dem Prinzip, nach dem die uebrigen geteilten
 * Systeme gebaut sind: AI Brain ist die Datenquelle, und niemand haelt
 * Kopien. Der Kalender macht es vor — `FremdeKalenderQuellen` fragt beim
 * Anzeigen und schreibt nichts.
 *
 * Dieses Werkzeug ist die Anbieter-Seite davon. Es liefert, was in DIESEM
 * System fuer die handelnde Person steht; zusammengefuehrt wird anderswo.
 *
 * ## Fail-closed
 *
 * Ohne handelnde Person kommt eine leere Liste und kein Fehler. Das ist
 * nicht Bequemlichkeit: Eine Benachrichtigung gehoert immer genau einem
 * Menschen, und ein Aufruf ohne Person koennte nur „alle" meinen. Das waere
 * hier ein Datenleck ueber Systemgrenzen hinweg.
 */
class ListNotificationsTool extends Tool
{
    use FindetHandelndePerson;

    protected string $description = 'List the notifications of the acting person in this system. Read-only; the caller merges them with its own.';

    public function handle(Request $request, NotificationFeed $feed): Response
    {
        $person = $this->handelnde();

        if ($person === null) {
            // Ohne Person keine Meldungen. Siehe Klassenkommentar: Die
            // Alternative waere, „alle" zu liefern.
            return Response::json(['items' => [], 'unread_count' => 0, 'source' => SourceLabels::local()]);
        }

        $antwort = $feed->for($person);

        $grenze = min(max((int) ($request->get('limit') ?? 100), 1), 500);

        return Response::json([
            // Das Kuerzel des eigenen Systems liegt bei, damit der Aufrufer
            // die Zeilen zuordnen kann, ohne es aus der Antwort zu raten.
            'source' => SourceLabels::local(),
            'unread_count' => $antwort['unread_count'] ?? 0,
            'items' => array_slice($antwort['items'] ?? [], 0, $grenze),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('How many at most. Default 100, maximum 500.'),
        ];
    }
}
