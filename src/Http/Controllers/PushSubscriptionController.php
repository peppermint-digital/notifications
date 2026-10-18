<?php

namespace Peppermint\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\Notifications\Push\PushForwarder;

/**
 * Ein Abo entgegennehmen und an den Hub weiterreichen.
 *
 * ## Warum hier nichts gespeichert wird
 *
 * Signieren kann nur, wer den privaten VAPID-Schluessel haelt, und das ist
 * seit #7993 AI Brain. Ein Produkt, das eine Abo-Adresse aufbewahrt, die es
 * nie bedienen kann, haette eine Tabelle, die aussieht wie eine laufende
 * Zustellung und keine ist.
 *
 * Der Manager hat seine eigene Tabelle und behaelt sie — dort gab es sie
 * vorher, und sie traegt den Einzelbetrieb. Produkte, die bei null anfangen,
 * brauchen sie nicht: Ob dieser Browser abonniert hat, weiss der Browser
 * selbst (`pushManager.getSubscription()`), und das ist die einzige Frage,
 * die die Oberflaeche stellt.
 *
 * ## Warum ein Fehlschlag am Hub trotzdem „ok" meldet
 *
 * Nein, tut er nicht — und das ist der Unterschied zum Manager. Dort steht
 * die eigene Zeile auch dann, wenn der Hub schweigt; hier gaebe es nichts,
 * was das Abo noch kennt. Ein „gespeichert" ohne Speicherort waere gelogen.
 */
class PushSubscriptionController
{
    public function __construct(private readonly PushForwarder $weiterleitung) {}

    public function store(Request $request): JsonResponse
    {
        $geprueft = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        $angekommen = $this->weiterleitung->subscribed(
            $request->user(),
            $geprueft,
            (string) $request->userAgent(),
        );

        if (! $angekommen) {
            // 503 und nicht 500: Es ist nichts kaputt, der Hub war nur nicht
            // erreichbar. Die Oberflaeche sagt das und laesst es erneut
            // versuchen, statt einen Haken zu setzen, hinter dem nichts ist.
            return response()->json([
                'ok' => false,
                'error' => 'hub_unreachable',
                'message' => 'Das Abo konnte nicht hinterlegt werden — AI Brain war nicht erreichbar.',
            ], 503);
        }

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $geprueft = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        $this->weiterleitung->unsubscribed($request->user(), $geprueft['endpoint']);

        // Abmelden meldet immer Erfolg: Der Browser hat sein Abo in jedem
        // Fall schon weggeworfen, bevor er hier anklopft. Ein Fehler hier
        // liesse die Oberflaeche „abonniert" anzeigen, obwohl nichts mehr
        // ankommt — die schlechtere von zwei Unwahrheiten.
        return response()->json(['ok' => true]);
    }
}
