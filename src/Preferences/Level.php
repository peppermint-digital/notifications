<?php

namespace Peppermint\Notifications\Preferences;

/**
 * Wie dringend ein Weg bedient wird.
 *
 * Die drei Stufen stammen aus dem einzigen Vorbild, das es im Bestand schon
 * gab: `EmailAccount.notification_mode` im Manager (instant/digest/off). Es
 * hat sich dort bewaehrt, und ein zweites Vokabular fuer dieselbe Frage waere
 * nur Verwirrung.
 */
enum Level: string
{
    /** Sofort, einzeln. */
    case Instant = 'instant';

    /**
     * Gesammelt. Was seit dem letzten Versand auflief, kommt in einem Stueck.
     *
     * Fuer die Glocke ergibt das keinen Sinn — sie sammelt ohnehin, das ist
     * ihre Natur. Gemeint ist der Weg nach draussen: eine Mail mit n
     * Meldungen statt n Mails.
     */
    case Digest = 'digest';

    case Off = 'off';

    public function label(): string
    {
        return match ($this) {
            self::Instant => 'Sofort',
            self::Digest => 'Zusammenfassung',
            self::Off => 'Aus',
        };
    }

    /** Kommt auf diesem Weg ueberhaupt etwas an? */
    public function zustellen(): bool
    {
        return $this !== self::Off;
    }

    /**
     * Aus einer Zeichenkette — unbekanntes faellt auf die Vorgabe zurueck.
     *
     * Bewusst kein Fehler: Die Stufe kommt aus einer Datenbankspalte, die ein
     * aelterer Stand des Pakets oder eine Migration von Hand gefuellt haben
     * kann. Eine Ausnahme mitten in der Zustellung waere der falsche Preis
     * fuer ein Wort, das keiner der drei ist.
     */
    public static function ausWert(mixed $wert, self $vorgabe): self
    {
        return is_string($wert) ? (self::tryFrom($wert) ?? $vorgabe) : $vorgabe;
    }
}
