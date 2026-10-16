<?php

use Peppermint\Notifications\Feed\SourceLabels;

it('nimmt den eingestellten Namen des eigenen Systems', function (): void {
    config()->set('notifications.labels.local', 'Verwaltung');

    expect(SourceLabels::local())->toBe('Verwaltung');
});

it('faellt auf den Anwendungsnamen zurueck', function (): void {
    // Ohne Rueckfall stuende am Filter-Reiter nichts — und ein Reiter ohne
    // Aufschrift ist schlimmer als einer mit dem falschen Wort.
    config()->set('notifications.labels.local', null);
    config()->set('app.name', 'Peppermint CRM');

    expect(SourceLabels::local())->toBe('Peppermint CRM');
});

it('ignoriert einen Eintrag, der keine Zeichenkette ist', function (): void {
    config()->set('notifications.labels.sources', [
        'peppermint-manager' => 'Manager',
        'kaputt' => ['Name' => 'verrutscht'],
    ]);

    expect(SourceLabels::sources())->toBe(['peppermint-manager' => 'Manager']);
});

it('vertraegt eine Einstellung, die gar keine Liste ist', function (): void {
    config()->set('notifications.labels.sources', 'Manager');

    expect(SourceLabels::sources())->toBe([]);
});
