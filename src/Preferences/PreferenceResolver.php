<?php

namespace Peppermint\Notifications\Preferences;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Peppermint\Notifications\Types\TypeRegistry;

/**
 * Die eine Stelle, an der „bekommt dieser Mensch das, und wie?" beantwortet wird.
 *
 * Drei Ebenen, von unten nach oben:
 *
 * 1. **Die Natur des Weges** — {@see Channel::vorgabe()}. E-Mail sammelt,
 *    die Glocke nicht.
 * 2. **Die Vorgabe des Produkts** — `notifications.preferences.defaults`,
 *    global oder je Art. Hier steht, was sinnvoll IST.
 * 3. **Die Entscheidung des Menschen** — eine Zeile in der Tabelle. Hier
 *    steht, was jemand WILL.
 *
 * Die unteren Ebenen sind keine Voreinstellung, die beim ersten Speichern
 * eingefroren wird: Wer nichts entschieden hat, folgt der Vorgabe weiter —
 * auch wenn sie sich aendert. Deshalb gibt es fuer „nicht entschieden" keine
 * Zeile und keinen Wert, sondern `null`.
 */
class PreferenceResolver
{
    /**
     * Zwischenspeicher je Mensch, nur fuer die Dauer eines Aufrufs.
     *
     * Eine Zustellung an zwanzig Menschen fragt sonst zwanzig Mal dieselbe
     * Tabelle — und ein Rundbrief an alle macht daraus hunderte Abfragen.
     *
     * @var array<string, Collection<string, NotificationPreference>>
     */
    private array $gelesen = [];

    public function __construct(private readonly TypeRegistry $arten) {}

    /**
     * Die geltende Stufe — nach allen drei Ebenen.
     */
    public function level(Model|int|string $user, string $art, Channel $weg): Level
    {
        $eigene = $this->zeile($user, $art)?->level($weg);

        return $eigene ?? $this->vorgabe($art, $weg);
    }

    /** Kommt auf diesem Weg etwas an? */
    public function zustellen(Model|int|string $user, string $art, Channel $weg): bool
    {
        return $this->level($user, $art, $weg)->zustellen();
    }

    /**
     * Die Vorgabe fuer eine Art und einen Weg — ohne die Person.
     *
     * Je Art schlaegt global, global schlaegt die Natur des Weges.
     */
    public function vorgabe(string $art, Channel $weg): Level
    {
        $jeArt = config("notifications.preferences.per_type.{$art}.{$weg->value}");

        if (is_string($jeArt)) {
            return Level::ausWert($jeArt, $weg->vorgabe());
        }

        return Level::ausWert(
            config("notifications.preferences.defaults.{$weg->value}"),
            $weg->vorgabe(),
        );
    }

    /**
     * Die ganze Matrix fuer eine Person — eine Zeile je angemeldeter Art.
     *
     * Die ARTEN kommen aus dem Register, nicht aus der Tabelle: Was kein
     * Produkt angemeldet hat, soll in der Maske nicht auftauchen, und was
     * neu angemeldet wurde, soll sofort dastehen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function matrix(Model|int|string $user): array
    {
        $zeilen = [];

        foreach ($this->arten->all() as $art) {
            $eigene = $this->zeile($user, $art->key);

            $wege = [];

            foreach (Channel::alle() as $weg) {
                $gewaehlt = $eigene?->level($weg);
                $vorgabe = $this->vorgabe($art->key, $weg);

                $wege[$weg->value] = [
                    // Was gilt — das, wonach zugestellt wird.
                    'level' => ($gewaehlt ?? $vorgabe)->value,
                    // Hat die Person das selbst entschieden? Die Maske zeigt
                    // damit „folgt der Vorgabe" statt einen Wert, der aussieht
                    // wie eine Entscheidung.
                    'eigen' => $gewaehlt !== null,
                    'vorgabe' => $vorgabe->value,
                ];
            }

            $zeilen[] = [
                'type' => $art->key,
                'label' => $art->label,
                'icon' => $art->icon,
                'color' => $art->color,
                'channels' => $wege,
            ];
        }

        // Nach der BESCHRIFTUNG, nicht nach dem Schluessel. Das Register
        // sortiert nach Schluessel, und der gehoert dem Produkt: Im Manager
        // steht `task_assigned`, anderswo `task.assigned`. Eine Maske, in der
        // „Rechnungsfreigabe" vor „Aufgabe zugewiesen" steht, weil das
        // technische Wort frueher im Alphabet liegt, ist nicht durchsuchbar.
        usort($zeilen, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $zeilen;
    }

    /**
     * Eine Entscheidung festhalten.
     *
     * `null` loescht sie — dann gilt wieder die Vorgabe. Das ist nicht
     * dasselbe wie „auf den Vorgabewert setzen": Wer zurueckgesetzt hat,
     * folgt einer spaeteren Aenderung der Vorgabe weiter.
     *
     * @param  array<string, Level|null>  $wege  Weg-Wert => Stufe
     */
    public function set(Model|int|string $user, string $art, array $wege): void
    {
        $id = $user instanceof Model ? $user->getKey() : $user;

        $zeile = NotificationPreference::query()
            ->where('user_id', $id)
            ->where('type', $art)
            ->first() ?? new NotificationPreference(['user_id' => $id, 'type' => $art]);

        foreach ($wege as $schluessel => $stufe) {
            $weg = Channel::tryFrom((string) $schluessel);

            if ($weg === null) {
                continue;
            }

            $zeile->setAttribute(NotificationPreference::column($weg), $stufe?->value);
        }

        // Eine Zeile, in der alle drei Wege auf „Vorgabe" stehen, sagt nichts
        // — und wer sie stehen laesst, friert die Vorgabe von heute ein.
        if ($this->istLeer($zeile)) {
            $zeile->exists ? $zeile->delete() : null;

            $this->vergessen($id);

            return;
        }

        $zeile->save();

        $this->vergessen($id);
    }

    /**
     * Den Zwischenspeicher leeren — nach jeder Aenderung.
     */
    public function vergessen(Model|int|string|null $user = null): void
    {
        if ($user === null) {
            $this->gelesen = [];

            return;
        }

        unset($this->gelesen[$this->schluessel($user)]);
    }

    private function istLeer(NotificationPreference $zeile): bool
    {
        foreach (Channel::alle() as $weg) {
            if ($zeile->getAttribute(NotificationPreference::column($weg)) !== null) {
                return false;
            }
        }

        return true;
    }

    private function zeile(Model|int|string $user, string $art): ?NotificationPreference
    {
        return $this->alle($user)->get($art);
    }

    /**
     * @return Collection<string, NotificationPreference>
     */
    private function alle(Model|int|string $user): Collection
    {
        $schluessel = $this->schluessel($user);

        return $this->gelesen[$schluessel] ??= NotificationPreference::query()
            ->forUser($user)
            ->get()
            ->keyBy('type');
    }

    private function schluessel(Model|int|string $user): string
    {
        return (string) ($user instanceof Model ? $user->getKey() : $user);
    }
}
