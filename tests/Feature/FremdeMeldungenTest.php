<?php

use Peppermint\Notifications\Feed\FremdeMeldungen;

/**
 * Lesen statt Spiegeln (#8186).
 *
 * Die erste Fassung schob jede Meldung als Zeile in jedes Produkt. Das
 * widerspricht dem Prinzip, nach dem die uebrigen geteilten Systeme gebaut
 * sind: Es gibt eine Datenquelle, und niemand haelt Kopien.
 *
 * Diese Tests halten das Verhalten OHNE Bruecke fest — mit Bruecke greift
 * der Gateway, und der hat seine eigenen Tests im Bruecken-Paket. Geprueft
 * wird hier, dass der Einzelbetrieb kein Fehlerfall ist.
 */
it('liefert nichts und faellt nicht um, wenn es keine Bruecke gibt', function (): void {
    // Der Einzelbetrieb ist der zweite zugesicherte Zustand, kein Fehler.
    $ergebnis = app(FremdeMeldungen::class)->holen('ich@beispiel.test');

    expect($ergebnis['items'])->toBe([])
        ->and($ergebnis['unreachable'])->toBe([]);
});

it('fragt gar nicht erst ohne Adresse', function (): void {
    // Die E-Mail ist der einzige produktuebergreifende Schluessel. Ohne sie
    // koennte ein Aufruf nur „alle" meinen — ueber Systemgrenzen hinweg
    // waere das ein Datenleck.
    $ergebnis = app(FremdeMeldungen::class)->holen(null);

    expect($ergebnis['items'])->toBe([]);
});

it('nennt den Vorgang so, wie der Komm-Layer ihn kennt', function (): void {
    // Der Name steht im `capability_map` des Brains. Laufen die beiden
    // auseinander, findet `providers()` niemanden — und die Liste ist still
    // leer statt sichtbar kaputt.
    expect(FremdeMeldungen::CAPABILITY)->toBe('notifications.list');
});
