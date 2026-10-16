<?php

namespace Peppermint\Notifications\Feed;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Peppermint\Notifications\Models\Notification;

/**
 * Eine Quelle fuer die Liste — Glocke wie Posteingang.
 *
 * Im Manager war das einmal zwei Aufbereitungen, und der Zaehler im Kopf
 * widersprach der Liste darunter. Seit #3488 liegt beides in einer Hand; diese
 * Klasse ist dieselbe Idee im Paket.
 *
 * ## Gebuendelt wird in der Anzeige, nicht in der Datenbank
 *
 * Jede Zeile behaelt die Kennungen aller Meldungen dahinter (`ids`). Nur so
 * treffen „gelesen" und „loeschen" weiterhin jeden einzelnen Datensatz — sonst
 * verschwaende eine gebuendelte Zeile beim Wegklicken die Haelfte still.
 *
 * Gebuendelt wird nur Ungelesenes. Ein Verlauf, der Eintraege zusammenzieht,
 * ist keiner mehr.
 */
class NotificationFeed
{
    /**
     * Die Liste in der Form, die alle Ansichten erwarten.
     *
     * @return array{items: array<int, array<string, mixed>>, unread_count: int}
     */
    public function for(Model|int|string $user): array
    {
        $empfaenger = $user instanceof Model ? $user->getKey() : $user;

        $zeilen = $this->zeilen($empfaenger);

        return [
            'items' => $zeilen
                ->sortByDesc($this->sortierschluessel(...))
                ->take($this->maxItems())
                ->values()
                ->all(),
            'unread_count' => $this->unreadCount($empfaenger),
        ];
    }

    /**
     * Die Zahl am Abzeichen.
     *
     * Zaehlt EINZELMELDUNGEN, nicht gebuendelte Zeilen: Sie soll sagen, wie
     * viel ungesehen ist, nicht wie viele Kacheln daraus wurden.
     *
     * Laeuft ohne die Aufbereitung der ganzen Liste — die Zahl haengt an jeder
     * Seite im Kopf, die Liste nur dort, wo jemand hinsieht.
     */
    public function unreadCount(Model|int|string $user): int
    {
        $empfaenger = $user instanceof Model ? $user->getKey() : $user;

        return Notification::query()->forUser($empfaenger)->unread()->count();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function zeilen(int|string $empfaenger): Collection
    {
        // `notifiable` wird bewusst NICHT mitgeladen: Unter den Altbestaenden
        // liegen Klassen, die es im Code nicht mehr gibt (im Manager etwa
        // ProjectMessage). Ein `morphTo` darauf wirft beim Aufloesen und nimmt
        // dem Leser die ganze Liste. Was angezeigt wird, steht ohnehin in der
        // Zeile selbst.
        $meldungen = Notification::query()
            ->forUser($empfaenger)
            ->where(function ($query): void {
                $query
                    ->where(Notification::column('is_read'), false)
                    ->orWhere(
                        Notification::column('created_at'),
                        '>=',
                        now()->subDays($this->recentDays()),
                    );
            })
            ->orderBy(Notification::column('is_read'))
            ->orderByDesc(Notification::column('created_at'))
            // Der Zeitstempel allein genuegt nicht: Zwei Meldungen in
            // derselben Sekunde sind der Normalfall, nicht der Rand — und
            // ohne zweites Kriterium entscheidet die Datenbank, welche oben
            // steht. Dann traegt mal die aeltere die gebuendelte Zeile, mit
            // ihrem veralteten Text.
            ->orderByDesc(Notification::column('id'))
            // Doppelt so viele holen wie am Ende stehen duerfen: Buendeln
            // verkleinert die Menge, und ein Deckel VOR dem Buendeln liesse
            // die Liste kuerzer werden, je besser die Buendelung wirkt.
            ->limit($this->maxItems() * 2)
            ->get();

        return $this->buendeln($meldungen);
    }

    /**
     * @param  Collection<int, Notification>  $meldungen
     * @return Collection<int, array<string, mixed>>
     */
    private function buendeln(Collection $meldungen): Collection
    {
        [$ungelesen, $gelesen] = $meldungen->partition(
            fn (Notification $m): bool => ! $m->isRead()
        );

        if (! config('notifications.feed.bundle', true)) {
            return $meldungen->map(fn (Notification $m): array => $this->zeile($m, [$m->getKey()]));
        }

        $gebuendelt = $ungelesen
            ->groupBy(fn (Notification $m): string => $this->buendelSchluessel($m))
            ->map(function (Collection $gruppe): array {
                // Die juengste traegt die Zeile: Ihr Text ist der aktuelle
                // Stand. Die Kennung als zweites Kriterium ist nicht Kosmetik —
                // bei gleichem Zeitstempel (derselben Sekunde) entschiede sonst
                // der Zufall, und die Zeile zeigte den veralteten Text.
                $juengste = $gruppe->sortBy([
                    [Notification::column('created_at'), 'desc'],
                    [Notification::column('id'), 'desc'],
                ])->first();

                return $this->zeile($juengste, $gruppe->map->getKey()->all());
            })
            ->values();

        return $gebuendelt->concat(
            $gelesen->map(fn (Notification $m): array => $this->zeile($m, [$m->getKey()]))
        );
    }

    /**
     * Was fuer den Leser EINE Sache ist: gleiche Art am gleichen Gegenstand.
     *
     * Ohne Gegenstand — „Aufgabe geloescht" hat keinen mehr — gruppiert der
     * Titel. Sonst fielen unzusammenhaengende Meldungen derselben Art in einen
     * Topf, und aus drei geloeschten Aufgaben wuerde eine Zeile, die nur eine
     * davon nennt.
     */
    private function buendelSchluessel(Notification $m): string
    {
        $gegenstand = $m->field('notifiable_id') !== null
            ? $m->field('notifiable_type').'#'.$m->field('notifiable_id')
            : 't:'.$m->field('title');

        return $m->field('type').'|'.$gegenstand;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<string, mixed>
     */
    private function zeile(Notification $m, array $ids): array
    {
        return [
            'id' => $m->getKey(),
            'ids' => $ids,
            'bundle_count' => count($ids),
            'type' => $m->field('type'),
            'type_label' => $m->typeDefinition()->label,
            'title' => $m->field('title'),
            'message' => $m->field('message'),
            'icon' => $m->iconName(),
            'color' => $m->colorName(),
            'link' => $m->field('link'),
            'triggered_by_id' => $m->field('triggered_by'),
            // Woran die Meldung haengt — eine Ansicht blendet damit aus, was
            // sie an anderer Stelle ohnehin schon zeigt.
            'subject_type' => $m->field('notifiable_type'),
            'subject_id' => $m->field('notifiable_id'),
            'is_read' => $m->isRead(),
            'created_at' => $m->field('created_at')?->toIso8601String(),
        ];
    }

    /**
     * Ungelesenes zuerst, darin das Neueste — dann der Verlauf.
     *
     * @param  array<string, mixed>  $zeile
     */
    private function sortierschluessel(array $zeile): int
    {
        $zeitpunkt = strtotime((string) $zeile['created_at']);

        return $zeile['is_read'] ? $zeitpunkt : $zeitpunkt + 10_000_000_000;
    }

    private function recentDays(): int
    {
        return (int) config('notifications.feed.recent_days', 7);
    }

    private function maxItems(): int
    {
        return (int) config('notifications.feed.max_items', 50);
    }
}
