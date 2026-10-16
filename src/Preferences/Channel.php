<?php

namespace Peppermint\Notifications\Preferences;

/**
 * Auf welchem Weg eine Meldung ankommt.
 *
 * Drei Wege, und sie kosten sehr Unterschiedliches. Push und die Glocke sind
 * billig: eine Zeile, ein Ton. Eine E-Mail kostet eine E-Mail — bei 243
 * Meldungen am Tag allein aus AI Brain ist „bei jeder Art eine Mail" ein Weg,
 * sich selbst zuzuspammen. Und wer zugespammt wird, schaltet nicht die Mails
 * ab, sondern alles.
 *
 * Deshalb hat {@see Level} drei Stufen und nicht zwei, und deshalb ist die
 * Voreinstellung fuer E-Mail die Zusammenfassung.
 */
enum Channel: string
{
    case Push = 'push';

    /** Die Glocke beziehungsweise der Posteingang — die Zeile in der Anwendung. */
    case InApp = 'inapp';

    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Push => 'Push',
            self::InApp => 'In der Anwendung',
            self::Email => 'E-Mail',
        };
    }

    /**
     * Die Stufe, die gilt, wenn weder Mensch noch Produkt etwas gesagt haben.
     *
     * In-App steht auf „sofort": Die Glocke ist der Ort, an dem man NACHSIEHT,
     * und eine Meldung, die dort fehlt, ist unauffindbar — sie steht ja sonst
     * nirgends. Ein Posteingang, der standardmaessig Dinge verschweigt, waere
     * kein Posteingang.
     */
    public function vorgabe(): Level
    {
        return match ($this) {
            self::InApp => Level::Instant,
            self::Push => Level::Instant,
            self::Email => Level::Digest,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function alle(): array
    {
        return self::cases();
    }
}
