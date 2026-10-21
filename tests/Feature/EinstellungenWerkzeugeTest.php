<?php

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Peppermint\Notifications\Mcp\ListNotificationPreferencesTool;
use Peppermint\Notifications\Mcp\SetNotificationPreferenceTool;
use Peppermint\Notifications\Preferences\Channel;
use Peppermint\Notifications\Preferences\Level;
use Peppermint\Notifications\Preferences\NotificationPreference;
use Peppermint\Notifications\Preferences\PreferenceResolver;
use Peppermint\Notifications\Tests\Support\User;
use Peppermint\Notifications\Types\TypeRegistry;

/**
 * Die Pflegemaske soll nur noch an EINER Stelle stehen — im Brain (#520).
 *
 * Damit sie auch die Arten der anderen Systeme stellen kann, braucht sie zwei
 * Werkzeuge je Produkt: eines, das die Arten und den Stand herausgibt, und
 * eines, das eine Entscheidung festhaelt.
 */
beforeEach(function (): void {
    app(TypeRegistry::class)->registerMany([
        ['key' => 'task.assigned', 'label' => 'Aufgabe zugewiesen', 'icon' => 'user-plus', 'color' => 'blue'],
        ['key' => 'invoice.approval', 'label' => 'Rechnungsfreigabe', 'icon' => 'euro', 'color' => 'amber'],
    ]);

    $this->ich = User::create(['name' => 'Ich', 'email' => 'ich@beispiel.test']);
    $this->loeser = app(PreferenceResolver::class);
});

/* ------------------------------------------------------------------ lesen */

it('liefert ohne handelnde Person eine leere Matrix statt der halben Wahrheit', function (): void {
    // Die Arten alleine herauszugeben waere denkbar — aber der Aufrufer
    // will die Matrix, und eine halbe Antwort sieht aus wie eine ganze.
    $antwort = lesen();

    expect($antwort['types'])->toBe([])
        ->and($antwort['channels'])->toBe([])
        ->and($antwort['levels'])->toBe([]);
});

it('liefert die Arten aus dem Register, nicht aus der Tabelle', function (): void {
    $this->actingAs($this->ich);

    $arten = array_column(lesen()['types'], 'type');

    // Beide, obwohl die Person zu keiner etwas entschieden hat: Was kein
    // Produkt angemeldet hat, soll nicht auftauchen — was angemeldet ist,
    // sofort.
    expect($arten)->toContain('task.assigned')
        ->and($arten)->toContain('invoice.approval');
});

it('unterscheidet eine Entscheidung von der Vorgabe', function (): void {
    // Der ganze Witz der Sache. Ohne dieses Feld kann die Maske „folgt der
    // Vorgabe" nicht von „steht auf sofort" unterscheiden — und wer nichts
    // entschieden hat, soll einer spaeteren Aenderung weiter folgen.
    $this->loeser->set($this->ich, 'task.assigned', [Channel::Push->value => Level::Off]);
    $this->actingAs($this->ich);

    $zeilen = collect(lesen()['types'])->keyBy('type');

    expect($zeilen['task.assigned']['channels']['push']['eigen'])->toBeTrue()
        ->and($zeilen['task.assigned']['channels']['push']['level'])->toBe('off')
        ->and($zeilen['invoice.approval']['channels']['push']['eigen'])->toBeFalse();
});

it('legt das Kuerzel des eigenen Systems bei', function (): void {
    config()->set('notifications.labels.local', 'Verwaltung');
    $this->actingAs($this->ich);

    expect(lesen()['source'])->toBe('Verwaltung');
});

it('zeigt nur die Entscheidungen der handelnden Person', function (): void {
    $andere = User::create(['name' => 'Andere', 'email' => 'andere@beispiel.test']);

    $this->loeser->set($andere, 'task.assigned', [Channel::Push->value => Level::Off]);
    $this->actingAs($this->ich);

    $zeilen = collect(lesen()['types'])->keyBy('type');

    expect($zeilen['task.assigned']['channels']['push']['eigen'])->toBeFalse();
});

/* --------------------------------------------------------------- schreiben */

it('haelt eine Entscheidung fest und gibt den neuen Stand zurueck', function (): void {
    $this->actingAs($this->ich);

    $antwort = schreiben(['type' => 'task.assigned', 'email' => 'instant']);

    expect($antwort['type'])->toBe('task.assigned')
        ->and($antwort['channels']['email']['level'])->toBe('instant')
        ->and($antwort['channels']['email']['eigen'])->toBeTrue()
        ->and($this->loeser->level($this->ich, 'task.assigned', Channel::Email))->toBe(Level::Instant);
});

it('laesst einen weggelassenen Weg unangetastet', function (): void {
    // Sonst wuerfe eine Maske, die nur den Push-Schalter zeigt, beim
    // Speichern die E-Mail-Entscheidung mit weg.
    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => Level::Instant]);
    $this->actingAs($this->ich);

    schreiben(['type' => 'task.assigned', 'push' => 'off']);

    expect($this->loeser->level($this->ich, 'task.assigned', Channel::Email))->toBe(Level::Instant)
        ->and($this->loeser->level($this->ich, 'task.assigned', Channel::Push))->toBe(Level::Off);
});

it('setzt mit "default" zurueck, statt den Vorgabewert einzufrieren', function (): void {
    $this->loeser->set($this->ich, 'task.assigned', [Channel::Email->value => Level::Instant]);
    $this->actingAs($this->ich);

    $antwort = schreiben(['type' => 'task.assigned', 'email' => 'default']);

    // Keine eigene Angabe mehr — und damit folgt die Person einer spaeteren
    // Aenderung der Vorgabe weiter.
    expect($antwort['channels']['email']['eigen'])->toBeFalse();

    config()->set('notifications.preferences.defaults.email', 'instant');

    expect(app(PreferenceResolver::class)->level($this->ich, 'task.assigned', Channel::Email))
        ->toBe(Level::Instant);
});

it('raeumt die Zeile weg, wenn alle drei Wege auf Vorgabe stehen', function (): void {
    $this->actingAs($this->ich);

    schreiben(['type' => 'task.assigned', 'push' => 'off', 'inapp' => 'off', 'email' => 'off']);
    expect(NotificationPreference::query()->count())->toBe(1);

    schreiben(['type' => 'task.assigned', 'push' => 'default', 'inapp' => 'default', 'email' => 'default']);

    // Eine Zeile, in der nichts entschieden ist, friert die Vorgabe von
    // heute ein.
    expect(NotificationPreference::query()->count())->toBe(0);
});

/* ------------------------------------------------- schreiben: die Absagen */

it('sagt ab, wenn es keine handelnde Person gibt — und tut nicht stillschweigend nichts', function (): void {
    // Beim Lesen ist eine leere Antwort ehrlich. Beim Schreiben waere ein
    // stilles „nichts getan" schlimmer: Die Maske zeigte einen gesetzten
    // Wert, und ankommen wuerde weiter das Alte.
    $antwort = app(SetNotificationPreferenceTool::class)->handle(
        new Request(['type' => 'task.assigned', 'push' => 'off']),
        app(PreferenceResolver::class),
        app(TypeRegistry::class),
    );

    expect($antwort->isError())->toBeTrue()
        ->and(NotificationPreference::query()->count())->toBe(0);
});

it('weist eine unbekannte Art ab und nennt die bekannten', function (): void {
    $this->actingAs($this->ich);

    $antwort = rohSchreiben(['type' => 'task.asigned', 'push' => 'off']);

    expect($antwort->isError())->toBeTrue()
        // Der Tippfehler soll sichtbar werden, ohne ein zweites Werkzeug.
        ->and((string) $antwort->content())->toContain('task.assigned')
        ->and(NotificationPreference::query()->count())->toBe(0);
});

it('weist eine unbekannte Stufe ab, statt sie zur Vorgabe zu machen', function (): void {
    // Hier bewusst streng, anders als `Level::ausWert()`: Dort geht es um
    // einen Wert, der schon in der Datenbank steht und eine Zustellung nicht
    // aufhalten darf. Hier legt jemand gerade einen neuen fest, und ein
    // Tippfehler waere eine Einstellung, die nicht haelt, was sie anzeigt.
    $this->actingAs($this->ich);

    $antwort = rohSchreiben(['type' => 'task.assigned', 'push' => 'vielleicht']);

    expect($antwort->isError())->toBeTrue()
        ->and(NotificationPreference::query()->count())->toBe(0);
});

it('weist einen Aufruf ohne jeden Weg ab', function (): void {
    $this->actingAs($this->ich);

    expect(rohSchreiben(['type' => 'task.assigned'])->isError())->toBeTrue();
});

it('weist einen Aufruf ohne Art ab', function (): void {
    $this->actingAs($this->ich);

    expect(rohSchreiben(['push' => 'off'])->isError())->toBeTrue();
});

/* ------------------------------------------------------------------ Helfer */

function lesen(): array
{
    $antwort = app(ListNotificationPreferencesTool::class)->handle(
        new Request([]),
        app(PreferenceResolver::class),
    );

    return nutzlast($antwort);
}

function rohSchreiben(array $argumente): Response
{
    return app(SetNotificationPreferenceTool::class)->handle(
        new Request($argumente),
        app(PreferenceResolver::class),
        app(TypeRegistry::class),
    );
}

function schreiben(array $argumente): array
{
    return nutzlast(rohSchreiben($argumente));
}

/**
 * Die Nutzlast herausschaelen.
 *
 * `Response::json()` legt sie als Text ab, und der Weg dorthin hat sich
 * zwischen den SDK-Fassungen schon geaendert — deshalb zu Text machen und
 * lesen, statt eine Methodenkette festzuschreiben, die beim naechsten Update
 * bricht.
 */
function nutzlast(Response $antwort): array
{
    $daten = json_decode((string) $antwort->content(), true);

    return is_array($daten) ? $daten : [];
}
