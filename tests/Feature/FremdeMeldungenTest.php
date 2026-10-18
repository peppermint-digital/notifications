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

/* ------------------------------------- die Quelle ist austauschbar */

it('setzt die Herkunft an jede geholte Zeile', function (): void {
    // Das befragte System kennt seinen eigenen Slug im Verbund nicht
    // zwangslaeufig — eine Zeile ohne Herkunft fiele in der Ansicht unter
    // „von hier".
    app()->instance(\Peppermint\Notifications\Feed\RemoteSource::class, new class implements \Peppermint\Notifications\Feed\RemoteSource
    {
        public function anbieter(): array
        {
            return [['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM']];
        }

        public function holen(string $slug, string $adresse, int $grenze): array
        {
            return [['id' => 1, 'title' => 'Von drueben']];
        }
    });

    $r = app(\Peppermint\Notifications\Feed\FremdeMeldungen::class)->holen('ich@beispiel.test');

    expect($r['items'][0]['source'])->toBe('crm')
        ->and($r['items'][0]['source_label'])->toBe('CRM');
});

it('nennt ein System, das nicht antwortet, statt die Seite zu verlieren', function (): void {
    app()->instance(\Peppermint\Notifications\Feed\RemoteSource::class, new class implements \Peppermint\Notifications\Feed\RemoteSource
    {
        public function anbieter(): array
        {
            return [
                ['slug' => 'a', 'label' => 'System A'],
                ['slug' => 'b', 'label' => 'System B'],
            ];
        }

        public function holen(string $slug, string $adresse, int $grenze): array
        {
            if ($slug === 'a') {
                throw new \RuntimeException('Zeitueberschreitung');
            }

            return [['id' => 1, 'title' => 'Von B']];
        }
    });

    $r = app(\Peppermint\Notifications\Feed\FremdeMeldungen::class)->holen('ich@beispiel.test');

    // B ist da, A wird benannt — und nichts ist kaputt.
    expect($r['items'])->toHaveCount(1)
        ->and($r['unreachable'])->toBe(['System A']);
});

it('ueberlebt ein Verzeichnis, das gar nicht antwortet', function (): void {
    app()->instance(\Peppermint\Notifications\Feed\RemoteSource::class, new class implements \Peppermint\Notifications\Feed\RemoteSource
    {
        public function anbieter(): array
        {
            throw new \RuntimeException('Verzeichnis weg');
        }

        public function holen(string $slug, string $adresse, int $grenze): array
        {
            return [];
        }
    });

    // Die eigenen Meldungen stehen trotzdem da — das ist der Punkt.
    expect(app(\Peppermint\Notifications\Feed\FremdeMeldungen::class)->holen('ich@beispiel.test')['items'])->toBe([]);
});
