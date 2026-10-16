<?php

use Peppermint\Notifications\Exceptions\UnknownNotificationType;
use Peppermint\Notifications\Types\NotificationType;
use Peppermint\Notifications\Types\TypeRegistry;

function registry(array $typen = [], array $rueckfall = [], bool $streng = false): TypeRegistry
{
    return new TypeRegistry($typen, $rueckfall, $streng);
}

it('liefert eine angemeldete Art mit ihren Feldern', function (): void {
    $register = registry([
        ['key' => 'task.assigned', 'label' => 'Aufgabe zugewiesen', 'icon' => 'user-plus', 'color' => 'blue'],
    ]);

    $art = $register->get('task.assigned');

    expect($art->label)->toBe('Aufgabe zugewiesen')
        ->and($art->icon)->toBe('user-plus')
        ->and($art->color)->toBe('blue');
});

it('faellt bei einer unbekannten Art zurueck statt zu werfen', function (): void {
    $register = registry(
        typen: [['key' => 'task.assigned', 'label' => 'Aufgabe zugewiesen']],
        rueckfall: ['label' => 'Benachrichtigung', 'icon' => 'bell', 'color' => 'gray'],
    );

    $art = $register->get('gibt.es.nicht');

    expect($art->key)->toBe('gibt.es.nicht')
        ->and($art->icon)->toBe('bell')
        ->and($art->color)->toBe('gray');
});

it('wirft bei einer unbekannten Art, wenn streng eingeschaltet ist', function (): void {
    $register = registry(
        typen: [['key' => 'task.assigned', 'label' => 'Aufgabe zugewiesen']],
        streng: true,
    );

    expect(fn () => $register->get('gibt.es.nicht'))
        ->toThrow(UnknownNotificationType::class);
});

it('nennt in der Ausnahme die Arten, die es gibt — sonst sucht man im Dunkeln', function (): void {
    $register = registry(
        typen: [['key' => 'task.assigned', 'label' => 'A'], ['key' => 'mention', 'label' => 'B']],
        streng: true,
    );

    expect(fn () => $register->get('tippfehler'))
        ->toThrow(UnknownNotificationType::class, 'task.assigned, mention');
});

it('nimmt Arten zur Laufzeit an — fuer Produkte, die ihr Enum behalten', function (): void {
    $register = registry();

    $register->register(new NotificationType('chat.message', 'Neue Nachricht', 'message-circle', 'blue'));

    expect($register->has('chat.message'))->toBeTrue()
        ->and($register->get('chat.message')->label)->toBe('Neue Nachricht');
});

it('laesst eine zur Laufzeit angemeldete Art die Konfiguration ueberschreiben', function (): void {
    $register = registry([['key' => 'mention', 'label' => 'Aus der Konfiguration']]);

    $register->register(new NotificationType('mention', 'Zur Laufzeit gesetzt'));

    expect($register->get('mention')->label)->toBe('Zur Laufzeit gesetzt');
});

it('reicht unbekannte Felder durch, statt sie still zu verwerfen', function (): void {
    // Der Fehler, der in diesem Haus schon zweimal teuer war: Ein Dienst zaehlt
    // die Felder auf, die er kennt, und wirft den Rest weg. Der Aufruf meldet
    // Erfolg, gespeichert ist nichts.
    $art = NotificationType::fromArray([
        'key' => 'invoice.approval',
        'label' => 'Rechnungsfreigabe',
        'reicht_an_kunden' => true,
    ]);

    expect($art->meta)->toBe(['reicht_an_kunden' => true])
        ->and($art->toArray())->toHaveKey('reicht_an_kunden');
});

it('verweigert eine Art ohne Beschriftung', function (): void {
    expect(fn () => new NotificationType('task.assigned', ''))
        ->toThrow(InvalidArgumentException::class, 'task.assigned');
});

it('gibt alle Arten sortiert zurueck — die Grundlage der Einstellungs-Matrix', function (): void {
    $register = registry([
        ['key' => 'mention', 'label' => 'Erwaehnung'],
        ['key' => 'chat.message', 'label' => 'Nachricht'],
        ['key' => 'task.assigned', 'label' => 'Zugewiesen'],
    ]);

    expect($register->keys())->toBe(['chat.message', 'mention', 'task.assigned']);
});

it('wirft nicht, wenn die Art LEER ist — der haeufigste Fall im Bestand', function (): void {
    // Der Rueckfall soll die Ausnahme verhindern; wirft er selbst, richtet er
    // genau den Schaden an, gegen den er gebaut ist. Im AI Brain traf das beim
    // Umstieg 17 Bestandszeilen — und eine davon haette die ganze Glocke
    // zerrissen, nicht nur ihre eigene Zeile.
    $register = registry(rueckfall: ['label' => 'Benachrichtigung', 'icon' => 'bell', 'color' => 'gray']);

    $art = $register->get('');

    expect($art->key)->toBe(TypeRegistry::UNBEKANNT)
        ->and($art->label)->toBe('Benachrichtigung')
        ->and($art->icon)->toBe('bell');
});
