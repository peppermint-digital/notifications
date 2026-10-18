<?php

use Peppermint\Notifications\Support\Bruecke;

/**
 * „Gibt es eine Bruecke?" ist nicht „existiert die Klasse?" (#8186).
 *
 * AI Brain hat das Bruecken-Paket installiert — es braucht die
 * Umschaltleiste daraus. Eingerichtet ist es dort nicht: Das Brain ist der
 * Hub und spricht nicht mit sich selbst.
 *
 * Ein `class_exists`-Test sagt dort „ja", und der erste Aufruf wirft
 * `BindingResolutionException`. Bei den fragenden Produkten kam das als
 * „MCP tools/call error: Something went wrong" an — ein Fehlertext, der
 * nichts verraet.
 */
beforeEach(function (): void {
    Bruecke::vergessen();
});

it('meldet keine Bruecke, wenn das Paket gar nicht da ist', function (): void {
    expect(class_exists(Bruecke::FACADE))->toBeFalse()
        ->and(Bruecke::verfuegbar())->toBeFalse();
});

it('merkt sich die Antwort', function (): void {
    // Sie aendert sich innerhalb eines Aufrufs nicht, und die Pruefung
    // kostet einen Container-Zugriff — bei einer Zustellung an zwanzig
    // Menschen zwanzig davon.
    $erste = Bruecke::verfuegbar();

    expect(Bruecke::verfuegbar())->toBe($erste);
});

it('laesst sich fuer Tests zuruecksetzen', function (): void {
    Bruecke::verfuegbar();
    Bruecke::vergessen();

    expect(Bruecke::verfuegbar())->toBeFalse();
});
