<?php

namespace Peppermint\Notifications\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Peppermint\Notifications\Mcp\Concerns\FindetHandelndePerson;
use Peppermint\Notifications\Preferences\Channel;
use Peppermint\Notifications\Preferences\Level;
use Peppermint\Notifications\Preferences\PreferenceResolver;
use Peppermint\Notifications\Types\TypeRegistry;

/**
 * Eine Entscheidung fuer EINE Art in DIESEM System festhalten.
 *
 * Die schreibende Haelfte zu {@see ListNotificationPreferencesTool}, damit
 * die Pflegemaske nur noch im Brain stehen muss (#520).
 *
 * ## Drei Dinge, die hier anders sind als beim Lesen
 *
 * **1. Ohne Person eine Absage, keine leere Antwort.** Beim Lesen ist eine
 * leere Liste eine ehrliche Auskunft. Beim Schreiben waere ein stilles
 * „nichts getan" die schlimmere Variante: Die Maske drueben zeigte dann einen
 * gesetzten Wert, und ankommen wuerde weiter das Alte. Ein Schreibvorgang,
 * der nicht schreiben kann, muss das sagen.
 *
 * **2. Eine unbekannte Art ist ein Fehler.** Der Aufrufer sitzt in einem
 * anderen System und hat die Arten ueber das Lesewerkzeug geholt. Stimmt der
 * Schluessel nicht, ist entweder sein Stand alt oder er hat geraten — beides
 * gehoert gemeldet. Eine stumm angelegte Zeile fuer `task.asigned` wuerde nie
 * wirken und nie auffallen.
 *
 * **3. Ein weggelassener Weg bleibt unangetastet.** Nicht „auf Vorgabe
 * zuruecksetzen". Nur wer `default` ausdruecklich schickt, setzt zurueck.
 * Sonst koennte eine Maske, die nur den Push-Schalter zeigt, beim Speichern
 * die E-Mail-Entscheidung mit wegwerfen.
 *
 * ## „Vorgabe" ist kein Wert
 *
 * `default` loescht die Angabe und ist NICHT dasselbe wie „auf den aktuellen
 * Vorgabewert setzen": Wer zurueckgesetzt hat, folgt einer spaeteren
 * Aenderung der Vorgabe weiter. Deshalb gibt es dafuer keine Zeile — und
 * deshalb raeumt {@see PreferenceResolver::set()} eine Zeile weg, in der alle
 * drei Wege auf Vorgabe stehen.
 */
class SetNotificationPreferenceTool extends Tool
{
    use FindetHandelndePerson;

    protected string $description = 'Set the acting person\'s notification preference for one type in this system. Pass a level per channel; omit a channel to leave it untouched, or pass "default" to clear it so the system default applies again.';

    public function handle(Request $request, PreferenceResolver $einstellungen, TypeRegistry $arten): Response
    {
        $person = $this->handelnde();

        if ($person === null) {
            // Siehe Klassenkommentar, Punkt 1.
            return Response::error('No acting person for this call. A preference always belongs to exactly one human, so there is nothing to write.');
        }

        $art = trim((string) ($request->get('type') ?? ''));

        if ($art === '') {
            return Response::error('Missing "type": which notification type should be set?');
        }

        if (! $arten->has($art)) {
            // Siehe Klassenkommentar, Punkt 2. Die bekannten Arten liegen
            // bei — damit der Aufrufer den Tippfehler sieht, statt ein
            // zweites Werkzeug aufrufen zu muessen.
            return Response::error(sprintf(
                'Unknown notification type "%s" in this system. Known types: %s.',
                $art,
                implode(', ', $arten->keys()),
            ));
        }

        $wege = [];

        foreach (Channel::alle() as $weg) {
            $wert = $request->get($weg->value);

            // Siehe Klassenkommentar, Punkt 3: weggelassen heisst
            // unangetastet, nicht zurueckgesetzt.
            if ($wert === null || $wert === '') {
                continue;
            }

            $wert = (string) $wert;

            if ($wert === self::ZURUECKSETZEN) {
                $wege[$weg->value] = null;

                continue;
            }

            $stufe = Level::tryFrom($wert);

            if ($stufe === null) {
                // Hier bewusst streng, anders als `Level::ausWert()`. Dort
                // geht es um einen Wert, der schon in der Datenbank steht
                // und eine Zustellung nicht aufhalten darf; hier legt
                // jemand gerade einen neuen fest. Ein Tippfehler, der
                // stillschweigend zur Vorgabe wird, ist eine Einstellung,
                // die nicht haelt, was die Maske anzeigt.
                return Response::error(sprintf(
                    'Unknown level "%s" for channel "%s". Allowed: %s, or "%s" to clear.',
                    $wert,
                    $weg->value,
                    implode(', ', array_map(static fn (Level $l): string => $l->value, Level::cases())),
                    self::ZURUECKSETZEN,
                ));
            }

            $wege[$weg->value] = $stufe;
        }

        if ($wege === []) {
            return Response::error(sprintf(
                'Nothing to set. Pass at least one of: %s.',
                implode(', ', array_map(static fn (Channel $c): string => $c->value, Channel::alle())),
            ));
        }

        $einstellungen->set($person, $art, $wege);

        // Den NEUEN Stand zurueckgeben, nicht nur „ok". Der Aufrufer sitzt
        // in einem anderen System und kann sonst nicht wissen, was jetzt
        // gilt — eine zurueckgesetzte Angabe faellt auf die Vorgabe, und die
        // kennt nur dieses Produkt.
        return Response::json([
            'type' => $art,
            'channels' => $this->standFuer($einstellungen, $person, $art),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        $stufen = implode('", "', array_map(static fn (Level $l): string => $l->value, Level::cases()));

        return [
            'type' => $schema->string()
                ->description('The notification type key, as reported by list-notification-preferences-tool.')
                ->required(),
            'push' => $schema->string()
                ->description(sprintf('One of "%s", or "%s" to clear. Omit to leave unchanged.', $stufen, self::ZURUECKSETZEN)),
            'inapp' => $schema->string()
                ->description(sprintf('One of "%s", or "%s" to clear. Omit to leave unchanged.', $stufen, self::ZURUECKSETZEN)),
            'email' => $schema->string()
                ->description(sprintf('One of "%s", or "%s" to clear. Omit to leave unchanged.', $stufen, self::ZURUECKSETZEN)),
        ];
    }

    /**
     * Was nach dem Schreiben fuer diese Art gilt — je Weg.
     *
     * @return array<string, array{level: string, eigen: bool, vorgabe: string}>
     */
    private function standFuer(PreferenceResolver $einstellungen, mixed $person, string $art): array
    {
        $stand = [];

        foreach ($einstellungen->matrix($person) as $zeile) {
            if (($zeile['type'] ?? null) === $art) {
                /** @var array<string, array{level: string, eigen: bool, vorgabe: string}> $wege */
                $wege = $zeile['channels'] ?? [];

                return $wege;
            }
        }

        return $stand;
    }

    /** Das Wort, mit dem eine Angabe geloescht wird. */
    private const ZURUECKSETZEN = 'default';
}
