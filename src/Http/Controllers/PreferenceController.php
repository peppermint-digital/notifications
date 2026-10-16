<?php

namespace Peppermint\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\Notifications\Preferences\Channel;
use Peppermint\Notifications\Preferences\Level;
use Peppermint\Notifications\Preferences\PreferenceResolver;

/**
 * Die Maske „wer bekommt was, auf welchem Weg" — Serverseite.
 *
 * Duenn wie der Rest: Die Entscheidungen liegen im {@see PreferenceResolver},
 * hier wird nur geprueft und durchgereicht.
 */
class PreferenceController
{
    public function __construct(private readonly PreferenceResolver $loeser) {}

    /**
     * Die Matrix — eine Zeile je angemeldeter Art, drei Wege darin.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'types' => $this->loeser->matrix($request->user()),
            // Damit die Maske die Auswahl aufbauen kann, ohne die Stufen zu
            // kennen. Sonst stuenden dieselben drei Woerter noch einmal im
            // Frontend — und wuerden beim naechsten Mal nur dort ergaenzt.
            'channels' => array_map(
                static fn (Channel $weg): array => ['key' => $weg->value, 'label' => $weg->label()],
                Channel::alle(),
            ),
            'levels' => array_map(
                static fn (Level $stufe): array => ['key' => $stufe->value, 'label' => $stufe->label()],
                Level::cases(),
            ),
        ]);
    }

    /**
     * Eine Zeile der Matrix speichern.
     *
     * Ein leerer Wert ist kein Fehler, sondern „folgt der Vorgabe" — und das
     * ist etwas anderes, als die Stufe auf den heutigen Vorgabewert zu
     * setzen: Wer zurueckgesetzt hat, folgt einer spaeteren Aenderung weiter.
     */
    public function update(Request $request): JsonResponse
    {
        $stufen = array_column(Level::cases(), 'value');

        $geprueft = $request->validate([
            'type' => ['required', 'string', 'max:191'],
            'channels' => ['required', 'array'],
            'channels.push' => ['nullable', 'string', 'in:'.implode(',', $stufen)],
            'channels.inapp' => ['nullable', 'string', 'in:'.implode(',', $stufen)],
            'channels.email' => ['nullable', 'string', 'in:'.implode(',', $stufen)],
        ]);

        $wege = [];

        foreach ($geprueft['channels'] as $schluessel => $wert) {
            $wege[$schluessel] = is_string($wert) ? Level::tryFrom($wert) : null;
        }

        $this->loeser->set($request->user(), $geprueft['type'], $wege);

        return response()->json([
            'ok' => true,
            'types' => $this->loeser->matrix($request->user()),
        ]);
    }
}
