<?php

namespace Peppermint\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Peppermint\Notifications\Feed\NotificationActions;
use Peppermint\Notifications\Feed\NotificationFeed;

/**
 * Die Endpunkte hinter Glocke und Posteingang.
 *
 * Optional: Ein Produkt kann sie einbinden (siehe `routes/notifications.php`)
 * oder eigene schreiben. Eingebunden werden sie, weil die Routen-REIHENFOLGE
 * eine Falle enthaelt, die sonst jedes Produkt einzeln tritt — siehe dort.
 *
 * Es gibt hier bewusst KEINE Berechtigungspruefung ueber die Anmeldung
 * hinaus: Jede Abfrage ist auf den angemeldeten Nutzer eingeschraenkt, und
 * damit ist die Frage „darf ich das" schon beantwortet. Wer fremde Kennungen
 * mitschickt, bekommt kein 403, sondern eine Null in der Zahl der
 * betroffenen Zeilen.
 */
class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationFeed $feed,
        private readonly NotificationActions $actions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->feed->for($request->user()));
    }

    /**
     * Nur die Zahl — sie haengt an jeder Seite im Kopf, die Liste nur dort,
     * wo jemand hinsieht.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => $this->feed->unreadCount($request->user())]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $daten = $this->ids($request);

        return response()->json([
            'ok' => true,
            'affected' => $this->actions->markRead($request->user(), $daten),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'affected' => $this->actions->markAllRead($request->user()),
        ]);
    }

    public function markUnread(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'affected' => $this->actions->markUnread($request->user(), $this->ids($request)),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'affected' => $this->actions->delete($request->user(), $this->ids($request)),
        ]);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'affected' => $this->actions->deleteAll($request->user()),
        ]);
    }

    /**
     * Jede Aktion nimmt MEHRERE Kennungen — hinter einer gebuendelten Zeile
     * stecken mehrere Datensaetze.
     *
     * @return array<int, int|string>
     */
    private function ids(Request $request): array
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:'.NotificationActions::MAX_IDS],
            'ids.*' => ['required'],
        ]);

        return $validated['ids'];
    }
}
