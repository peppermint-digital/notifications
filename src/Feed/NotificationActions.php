<?php

namespace Peppermint\Notifications\Feed;

use Illuminate\Database\Eloquent\Model;
use Peppermint\Notifications\Models\Notification;

/**
 * Was man mit Meldungen tun kann: lesen, wegklicken, loeschen.
 *
 * Jede Aktion nimmt MEHRERE Kennungen. Das ist keine Bequemlichkeit, sondern
 * die Folge der Buendelung: Hinter einer Zeile stecken oft mehrere Datensaetze,
 * und eine Aktion, die nur einen trifft, laesst die Zeile stehen und den
 * Zaehler falsch.
 *
 * ## Der Empfaenger-Filter IST die Berechtigung
 *
 * Jede Abfrage ist auf den Nutzer eingeschraenkt. Fremde Kennungen in der
 * Liste laufen damit ins Leere, statt einen Fehler fuer den ganzen Aufruf
 * auszuloesen — das ist bei gebuendelten Zeilen wichtig, wo eine veraltete
 * Kennung dabei sein kann, ohne dass jemand etwas falsch macht.
 *
 * Dass dabei nicht auffaellt, WIE VIELE Kennungen nicht trafen, ist Absicht:
 * Die Rueckgabe nennt die Zahl der tatsaechlich geaenderten Zeilen. Wer
 * genauer hinsehen will, vergleicht sie mit der Zahl der uebergebenen.
 */
class NotificationActions
{
    /**
     * Hoechstzahl Kennungen je Aufruf.
     *
     * Ein Deckel, damit eine Liste aus dem Browser nicht zu einer Abfrage mit
     * zehntausend Werten wird. 200 liegt weit ueber jeder gebuendelten Zeile
     * und weit unter dem, was eine Datenbank stoert.
     */
    public const MAX_IDS = 200;

    /**
     * @param  array<int, int|string>  $ids
     * @return int Wie viele Zeilen tatsaechlich auf gelesen gesetzt wurden.
     */
    public function markRead(Model|int|string $user, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return Notification::query()
            ->forUser($this->id($user))
            ->whereIn(Notification::column('id'), array_slice($ids, 0, self::MAX_IDS))
            // Nur Ungelesene: Sonst setzt ein zweiter Klick den Zeitpunkt neu,
            // und der Verlauf sagt, die Meldung sei eben erst gelesen worden.
            ->unread()
            ->update([
                Notification::column('is_read') => true,
                Notification::column('read_at') => now(),
            ]);
    }

    public function markAllRead(Model|int|string $user): int
    {
        return Notification::query()
            ->forUser($this->id($user))
            ->unread()
            ->update([
                Notification::column('is_read') => true,
                Notification::column('read_at') => now(),
            ]);
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public function markUnread(Model|int|string $user, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return Notification::query()
            ->forUser($this->id($user))
            ->whereIn(Notification::column('id'), array_slice($ids, 0, self::MAX_IDS))
            ->read()
            ->update([
                Notification::column('is_read') => false,
                Notification::column('read_at') => null,
            ]);
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public function delete(Model|int|string $user, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return Notification::query()
            ->forUser($this->id($user))
            ->whereIn(Notification::column('id'), array_slice($ids, 0, self::MAX_IDS))
            ->delete();
    }

    public function deleteAll(Model|int|string $user): int
    {
        return Notification::query()->forUser($this->id($user))->delete();
    }

    private function id(Model|int|string $user): int|string
    {
        return $user instanceof Model ? $user->getKey() : $user;
    }
}
