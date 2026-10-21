<?php

namespace Peppermint\Notifications\Mcp\Concerns;

use Peppermint\Notifications\Support\Bruecke;

/**
 * Wer ruft hier eigentlich an?
 *
 * ## Warum das eine eigene Stelle ist
 *
 * Eine Benachrichtigung und eine Einstellung dazu gehoeren immer genau einem
 * Menschen. Jedes Werkzeug dieses Pakets muss ihn also kennen — und bis zum
 * dritten Werkzeug stand dieselbe Auflösung dreimal da.
 *
 * ## Der Weg ueber die Bruecke
 *
 * Das Brain ruft ein Produkt als CLIENT auf, und ein Client hat keine
 * Identitaet — der Dienst-Modus ist userlos. „Volkers Einstellung im Manager
 * schreiben" braucht deshalb eine ausdrueckliche Person, und die kommt als
 * E-MAIL-ADRESSE in der Kopfzeile des Aufrufs.
 *
 * Die Adresse ist dabei der richtige Schluessel und nicht die Nummer: IDs
 * sind je Datenbank verschieden, ein Mensch ist in vier Systemen vier Mal
 * angelegt. Dieselbe Entscheidung wie beim Kalender, wo der Slug einer
 * Kategorie der gemeinsame Schluessel ist.
 *
 * Ohne Bruecke ist es die angemeldete Person. Beides abzufragen kostet
 * nichts und macht die Werkzeuge auch ohne Hub benutzbar.
 */
trait FindetHandelndePerson
{
    /**
     * Die handelnde Person — oder `null`, wenn es keine gibt.
     *
     * `null` ist ein gueltiges Ergebnis und kein Fehler. Was daraus folgt,
     * entscheidet das aufrufende Werkzeug: Beim Lesen eine leere Antwort,
     * beim Schreiben eine Absage. Siehe dort.
     */
    protected function handelnde(): mixed
    {
        if (Bruecke::verfuegbar()) {
            $adresse = (Bruecke::FACADE)::actingUserEmail();

            if (is_string($adresse) && trim($adresse) !== '') {
                $modell = (string) config('notifications.user_model');

                return $modell::query()->where('email', $adresse)->first();
            }
        }

        return auth()->user();
    }
}
