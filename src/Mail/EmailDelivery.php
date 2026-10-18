<?php

namespace Peppermint\Notifications\Mail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Peppermint\Notifications\Feed\SourceLabels;
use Peppermint\Notifications\Models\Notification;
use Peppermint\Notifications\Preferences\Channel;
use Peppermint\Notifications\Preferences\Level;
use Peppermint\Notifications\Preferences\PreferenceResolver;

/**
 * Der E-Mail-Weg — bis zum 18.10.2026 eine Spalte ohne Funktion.
 *
 * Die Einstellungsmatrix bot drei Stufen für E-Mail an, und `Channel::Email`
 * wurde an keiner einzigen Stelle gelesen: nicht im Paket, in keinem der
 * vier Produkte. Wer dort „Sofort" stellte, bekam nichts — und glaubte, er
 * hätte es eingestellt.
 *
 * ## Zwei Stufen, die sich nicht überschneiden können
 *
 * Die Stufe steht je ART fest. Eine Art ist entweder `instant` oder
 * `digest`, nie beides — deshalb braucht die Sammel-Mail keinen Merker an
 * jeder Meldung, um zu wissen, was schon einzeln hinausging.
 *
 * Die eine Lücke: Wer mitten in einem Fenster von `instant` auf `digest`
 * umstellt, bekommt eine Meldung zweimal. Einmal. Dafür eine Spalte an
 * 17.000 Zeilen zu hängen, wäre der falsche Tausch.
 *
 * ## Warum nichts davon in der Vorgabe steht
 *
 * Die globale Vorgabe für E-Mail ist `off`. Push und Glocke kosten eine
 * Zeile und einen Ton; eine E-Mail kostet eine E-Mail. Hätte dieser Weg die
 * alte Vorgabe `digest` geerbt, bekäme mit dem Einschalten jeder Kollege
 * täglich Post, ohne je etwas eingestellt zu haben — und wer zugespammt
 * wird, schaltet nicht die Mails ab, sondern alles.
 */
class EmailDelivery
{
    /**
     * Gibt es die Tabelle? Einmal je Aufruf beantwortet. `null` heisst
     * „noch nicht nachgesehen" und ist deshalb nicht dasselbe wie `false`.
     */
    private ?bool $tabelleGeprueft = null;

    public function __construct(private readonly PreferenceResolver $einstellungen) {}

    /**
     * Eine einzelne Meldung sofort per Mail — wenn so eingestellt.
     *
     * Gibt zurück, ob etwas hinausging. `false` ist der Normalfall.
     */
    public function sofort(Notification $meldung): bool
    {
        $empfaenger = $meldung->user;
        $adresse = $this->adresse($empfaenger);

        if ($empfaenger === null || $adresse === null) {
            return false;
        }

        $art = (string) $meldung->field('type');

        if ($this->einstellungen->level($empfaenger, $art, Channel::Email) !== Level::Instant) {
            return false;
        }

        return $this->schicken($empfaenger, $adresse, [$this->zeile($meldung)]);
    }

    /**
     * Die Sammel-Mail für einen Menschen — alles seit dem letzten Versand.
     *
     * Gibt die Zahl der enthaltenen Meldungen zurück; `0` heißt, es ging
     * nichts hinaus.
     */
    public function sammeln(Model $empfaenger): int
    {
        $adresse = $this->adresse($empfaenger);

        if ($adresse === null) {
            return 0;
        }

        if (! $this->tabelleDa()) {
            // Zwischen „neuer Code ist scharf" und „Migration ist gelaufen"
            // liegt immer ein Fenster. Der Zeitplan laeuft darin weiter und
            // wuerde jede Nacht mit einer Ausnahme abbrechen — sichtbar nur
            // im Protokoll, das in so einem Fenster niemand liest.
            return 0;
        }

        $meldungen = $this->auswahl($empfaenger, $this->letzterVersand($empfaenger));

        // Den Zeitpunkt IMMER fortschreiben, auch wenn nichts dabei war.
        // Sonst wächst das Fenster bei jemandem, der nur Arten mit `off`
        // bekommt, unbegrenzt — und die erste passende Meldung brächte
        // Monate alte Begleitung mit.
        $this->versandNotieren($empfaenger);

        if ($meldungen->isEmpty()) {
            return 0;
        }

        $zeilen = $meldungen->map($this->zeile(...))->values()->all();

        return $this->schicken($empfaenger, $adresse, $zeilen) ? count($zeilen) : 0;
    }

    /**
     * Wie viele Meldungen jetzt in die Sammel-Mail gingen — ohne zu schicken.
     *
     * Fuer den Probelauf. Ohne diese Zahl meldete er „12 Menschen kaemen in
     * Frage", waehrend die Antwort „null Mails" lautet — weil die Vorgabe
     * `off` ist und niemand etwas anderes eingestellt hat. Eine Zahl, die
     * nach zwoelf Mails klingt und keine meint, ist genau die Art Auskunft,
     * nach der jemand den Zeitplan vorsichtshalber abschaltet.
     */
    public function vorschau(Model $empfaenger): int
    {
        if ($this->adresse($empfaenger) === null || ! $this->tabelleDa()) {
            return 0;
        }

        return $this->auswahl($empfaenger, $this->letzterVersand($empfaenger))->count();
    }

    /**
     * Alle, für die eine Sammel-Mail in Frage kommt.
     *
     * Bewusst nur Menschen, die überhaupt eine ungelesene Meldung seit dem
     * letzten Versand haben — sonst liefe der Zeitplan über jeden Datensatz
     * in der Personentabelle, um fast immer nichts zu tun.
     *
     * @return \Illuminate\Support\Collection<int, Model>
     */
    public function faellige(): \Illuminate\Support\Collection
    {
        $modell = (string) config('notifications.user_model');

        $ids = Notification::query()
            ->where(Notification::column('created_at'), '>', now()->subDays(7))
            ->distinct()
            ->pluck(Notification::column('user_id'));

        return $modell::query()->whereIn((new $modell)->getKeyName(), $ids)->get();
    }

    /**
     * Was fuer diesen Menschen in die Sammel-Mail gehoert.
     *
     * @return \Illuminate\Support\Collection<int, Notification>
     */
    private function auswahl(Model $empfaenger, ?Carbon $seit): \Illuminate\Support\Collection
    {
        return Notification::query()
            ->forUser($empfaenger->getKey())
            ->when($seit !== null, fn ($q) => $q->where(Notification::column('created_at'), '>', $seit))
            ->orderBy(Notification::column('created_at'))
            ->get()
            // Erst hier filtern und nicht in SQL: Welche Stufe für eine Art
            // gilt, steht in der Konfiguration UND in der Tabelle, und die
            // Vorgabe hat gar keine Zeile. Das ist keine Bedingung, die sich
            // in ein WHERE schreiben lässt.
            ->filter(fn (Notification $m): bool => $this->einstellungen->level(
                $empfaenger,
                (string) $m->field('type'),
                Channel::Email,
            ) === Level::Digest);
    }

    /**
     * @param  array<int, array<string, mixed>>  $zeilen
     */
    private function schicken(Model $empfaenger, string $adresse, array $zeilen): bool
    {
        try {
            Mail::to($adresse)->send(new NotificationMail(
                empfaengerName: (string) ($empfaenger->getAttribute('name') ?? 'du'),
                zeilen: $zeilen,
                absenderSystem: SourceLabels::local(),
                posteingangUrl: $this->posteingang(),
            ));

            return true;
        } catch (\Throwable $fehler) {
            // Fail-open: Eine Mail, die nicht hinausgeht, darf nicht den
            // ganzen Durchlauf abbrechen — die übrigen Empfänger können
            // nichts dafür.
            Log::warning('Benachrichtigungs-Mail nicht zugestellt.', [
                'empfaenger' => $empfaenger->getKey(),
                'error' => $fehler->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function zeile(Notification $meldung): array
    {
        return [
            'title' => (string) $meldung->field('title'),
            'message' => $meldung->field('message'),
            'link' => $this->absolut($meldung->field('link')),
            'type_label' => $meldung->typeLabel(),
        ];
    }

    /**
     * Ein Verweis in einer Mail muss absolut sein — relativ zeigt er ins Nichts.
     */
    private function absolut(mixed $link): ?string
    {
        if (! is_string($link) || $link === '') {
            return null;
        }

        return str_starts_with($link, 'http') ? $link : rtrim((string) config('app.url'), '/').'/'.ltrim($link, '/');
    }

    private function posteingang(): ?string
    {
        $basis = (string) config('app.url');

        return $basis === '' ? null : rtrim($basis, '/').'/notifications/inbox';
    }

    private function adresse(?Model $empfaenger): ?string
    {
        $adresse = $empfaenger?->getAttribute('email');

        return is_string($adresse) && trim($adresse) !== '' ? $adresse : null;
    }

    private function letzterVersand(Model $empfaenger): ?Carbon
    {
        $wert = DB::table($this->tabelle())
            ->where('user_id', $empfaenger->getKey())
            ->value('last_sent_at');

        return $wert === null ? null : Carbon::parse($wert);
    }

    private function versandNotieren(Model $empfaenger): void
    {
        DB::table($this->tabelle())->updateOrInsert(
            ['user_id' => $empfaenger->getKey()],
            ['last_sent_at' => now(), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    private function tabelle(): string
    {
        return (string) config('notifications.tables.digests', 'notification_digests');
    }

    /**
     * Steht die Tabelle fuer die Versand-Zeitpunkte schon?
     *
     * Dieselbe Nachsicht wie beim {@see PreferenceResolver}, aus demselben
     * Grund — und einmal je Aufruf beantwortet: `hasTable` fragt den
     * Katalog, und ein Durchlauf ueber hundert Menschen soll das nicht
     * hundertmal tun.
     */
    private function tabelleDa(): bool
    {
        if ($this->tabelleGeprueft !== null) {
            return $this->tabelleGeprueft;
        }

        try {
            $da = Schema::hasTable($this->tabelle());
        } catch (\Throwable) {
            $da = false;
        }

        if (! $da) {
            Log::warning('Die Tabelle fuer die Sammel-Mails fehlt — es geht nichts hinaus.', [
                'tabelle' => $this->tabelle(),
            ]);
        }

        return $this->tabelleGeprueft = $da;
    }
}
