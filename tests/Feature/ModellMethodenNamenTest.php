<?php

use Peppermint\Notifications\Models\Notification;
use Peppermint\Notifications\Tests\Support\User;

/**
 * Der Waechter gegen einen Fehler, der sich nicht als das zeigt, was er ist.
 *
 * Findet Eloquent zu einem Attribut, das nicht in `$attributes` steht, eine
 * gleichnamige Methode, ruft es sie als BEZIEHUNG auf. Eine Methode `icon()`,
 * die `field('icon')` liest, ruft damit sich selbst — bis der Speicher voll
 * ist. Die Fehlermeldung lautet dann "Allowed memory size exhausted" tief im
 * Dienst-Container und zeigt nirgends auf das Modell.
 *
 * Genau das ist beim Bau dieses Pakets passiert (16.10.2026). Der Test ist
 * billiger als die zweite Suche.
 */

/** Die Felder, die das Paket kennt — Spalten wie Paketnamen. */
function paketFelder(): array
{
    return [
        'id', 'user_id', 'type', 'title', 'message', 'icon', 'link',
        'notifiable_type', 'notifiable_id', 'triggered_by', 'data',
        'is_read', 'read_at', 'created_at', 'updated_at',
    ];
}

it('hat keine Modellmethode, die heisst wie ein Feld', function (): void {
    $erlaubt = [
        // Echte Beziehungen DUERFEN so heissen — sie geben eine Beziehung
        // zurueck, und genau das erwartet Eloquent an dieser Stelle.
        'notifiable',
    ];

    $spiegel = new ReflectionClass(Notification::class);

    $kollisionen = [];

    foreach ($spiegel->getMethods(ReflectionMethod::IS_PUBLIC) as $methode) {
        if ($methode->getDeclaringClass()->getName() !== Notification::class) {
            continue;
        }

        $name = $methode->getName();

        if (in_array($name, $erlaubt, true)) {
            continue;
        }

        if (in_array($name, paketFelder(), true)) {
            $kollisionen[] = $name;
        }
    }

    expect($kollisionen)->toBe(
        [],
        'Diese Methoden heissen wie ein Feld und werden von Eloquent als Beziehung '
        .'aufgerufen — das endet in einer Rekursion bis zum Speicherende: '
        .implode(', ', $kollisionen)
    );
});

it('liest jedes Feld, ohne sich dabei selbst aufzurufen', function (): void {
    // Die Gegenprobe am lebenden Objekt: Was der Namenstest oben strukturell
    // ausschliesst, wird hier einmal wirklich durchlaufen. Eine Rekursion
    // faellt hier als erschoepfter Speicher auf, nicht als falscher Wert —
    // deshalb braucht es beide.
    $nutzer = User::create(['name' => 'Test']);

    $meldung = Notification::create([
        'user_id' => $nutzer->id,
        'type' => 'task.assigned',
        'title' => 'Zugewiesen',
    ]);

    foreach (paketFelder() as $feld) {
        expect(fn () => $meldung->field($feld))->not->toThrow(Throwable::class);
    }

    // Und die abgeleiteten Werte, die auf `field()` aufsetzen.
    expect($meldung->iconName())->toBeString()
        ->and($meldung->colorName())->toBeString()
        ->and($meldung->typeDefinition()->key)->toBe('task.assigned');
});
