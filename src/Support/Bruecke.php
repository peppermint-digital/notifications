<?php

namespace Peppermint\Notifications\Support;

/**
 * Gibt es hier eine benutzbare Bruecke zu AI Brain?
 *
 * ## Warum `class_exists` dafuer nicht reicht
 *
 * AI Brain selbst hat das Bruecken-Paket installiert — es braucht die
 * Umschaltleiste daraus. Die Klasse existiert dort also, nur eingerichtet
 * ist sie nicht: Das Brain ist der Hub und spricht nicht mit sich selbst.
 *
 * Ein `class_exists`-Test sagt dort „ja" und der erste Aufruf wirft
 * `BindingResolutionException: Unresolvable dependency … array $config`.
 * Im Brain kam das als „MCP tools/call error: Something went wrong" bei
 * den fragenden Produkten an — ein Fehlertext, der nichts verraet.
 *
 * Deshalb wird hier nicht nach der Klasse gefragt, sondern danach, ob sie
 * sich aufloesen laesst.
 */
class Bruecke
{
    public const FACADE = 'Peppermint\\AiBrainBridge\\Facades\\AiBrain';

    private const MANAGER = 'Peppermint\\AiBrainBridge\\AiBrainManager';

    private static ?bool $gemerkt = null;

    /**
     * Steht eine eingerichtete Bruecke bereit?
     *
     * Die Antwort wird gemerkt: Sie aendert sich innerhalb eines Aufrufs
     * nicht, und die Pruefung kostet einen Container-Zugriff.
     */
    public static function verfuegbar(): bool
    {
        if (self::$gemerkt !== null) {
            return self::$gemerkt;
        }

        if (! class_exists(self::FACADE)) {
            return self::$gemerkt = false;
        }

        try {
            app(self::MANAGER);

            return self::$gemerkt = true;
        } catch (\Throwable) {
            // Installiert, aber nicht eingerichtet — das ist der Hub.
            return self::$gemerkt = false;
        }
    }

    /** Nur fuer Tests: die gemerkte Antwort verwerfen. */
    public static function vergessen(): void
    {
        self::$gemerkt = null;
    }
}
