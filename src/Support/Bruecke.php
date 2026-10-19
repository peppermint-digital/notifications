<?php

namespace Peppermint\Notifications\Support;

use Peppermint\AiBrainBridge\Verbund\Bruecke as GeteilteBruecke;
use Peppermint\AiBrainBridge\Facades\AiBrain;

/**
 * Ist die Bruecke hier eingerichtet?
 *
 * Nur noch ein Durchgang auf {@see GeteilteBruecke}. Die Pruefung gehoert
 * ins Bruecken-Paket, weil beide Pakete sie brauchen und sie sich nicht
 * auseinanderentwickeln darf.
 *
 * Seit dem 19.10.2026 fragt sie NICHT mehr, ob das Paket installiert ist —
 * es ist Pflicht. Sie fragt, ob es EINGERICHTET ist, und das ist eine
 * andere Frage: AI Brain hat es (fuer die Umschaltleiste), ohne es
 * einzurichten. Es ist der Hub und spricht nicht mit sich selbst.
 *
 * Der Name bleibt, damit die Aufrufer im Paket unveraendert bleiben.
 */
final class Bruecke
{
    public const FACADE = AiBrain::class;

    public static function verfuegbar(): bool
    {
        return GeteilteBruecke::eingerichtet();
    }

    /** Fuer Tests: die gemerkte Antwort vergessen. */
    public static function vergessen(): void
    {
        GeteilteBruecke::vergessen();
    }
}
