<?php

namespace Peppermint\Notifications\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Peppermint\Notifications\Feed\SourceLabels;
use Peppermint\Notifications\Mcp\Concerns\FindetHandelndePerson;
use Peppermint\Notifications\Preferences\PreferenceResolver;
use Peppermint\Notifications\Types\TypeRegistry;

/**
 * Welche Arten gibt es hier, und was hat die Person dazu entschieden?
 *
 * ## Warum dieses Werkzeug gebraucht wird
 *
 * Die Pflegemaske soll nur noch an EINER Stelle stehen — im Brain (#520) —
 * und von dort aus auch die Arten der anderen Systeme stellen koennen. Dafuer
 * muss das Brain zwei Dinge erfahren, die es nicht erraten kann:
 *
 * 1. **Welche Arten dieses Produkt ueberhaupt kennt.** Sie stehen im
 *    {@see TypeRegistry} des Produkts — im
 *    Manager heisst eine `task_assigned`, anderswo `task.assigned`. Eine
 *    Maske, die die Arten raet, zeigt entweder zu viele oder zu wenige.
 * 2. **Was gilt, und ob es eine Entscheidung ist.** Der Unterschied zwischen
 *    „steht auf sofort" und „folgt der Vorgabe, die gerade sofort ist" ist
 *    der ganze Witz der Sache: Wer nichts entschieden hat, soll einer
 *    spaeteren Aenderung der Vorgabe weiter folgen.
 *
 * Beides liefert {@see PreferenceResolver::props()} bereits in genau der
 * Form, die die Maske im Frontend isst. Dieses Werkzeug ist deshalb duenn —
 * und das ist Absicht: Eine zweite Aufbereitung waere eine zweite Wahrheit.
 *
 * ## Es wird nichts kopiert
 *
 * Die Antwort ist eine Auskunft, kein Abgleich. Die Zeilen bleiben in der
 * Datenbank des Produkts, dem sie gehoeren. Eine Kopie im Brain haette ihren
 * eigenen Stand und ihr eigenes Altern — dieselbe Begruendung wie bei den
 * Meldungen selbst ({@see ListNotificationsTool}).
 */
class ListNotificationPreferencesTool extends Tool
{
    use FindetHandelndePerson;

    protected string $description = 'List the notification types this system knows and what the acting person has decided for each. Read-only.';

    public function handle(Request $request, PreferenceResolver $einstellungen): Response
    {
        $person = $this->handelnde();

        if ($person === null) {
            // Ohne Person gibt es nichts zu zeigen. Die Arten alleine
            // herauszugeben waere denkbar, aber der Aufrufer will die
            // Matrix — eine halbe Antwort sieht aus wie eine ganze.
            return Response::json([
                'source' => SourceLabels::local(),
                'types' => [],
                'channels' => [],
                'levels' => [],
            ]);
        }

        // Das Kuerzel des eigenen Systems liegt bei, damit die Maske die
        // Abschnitte benennen kann, ohne es aus der Antwort zu raten.
        //
        // `array_merge` und NICHT `+`: Der `+`-Operator behaelt den LINKEN
        // Wert, und genau das hat hier schon einmal Schaden angerichtet —
        // als das Paket selbst ein `source` lieferte, kippte still die
        // Zuordnung im Manager-Posteingang. So steht die Rangfolge
        // ausdruecklich da: Was `props()` liefert, fliesst vollstaendig
        // durch (ein spaeter ergaenztes Feld faellt nicht weg), und
        // `source` gewinnt, weil es hinten steht.
        return Response::json(array_merge(
            $einstellungen->props($person),
            ['source' => SourceLabels::local()],
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
