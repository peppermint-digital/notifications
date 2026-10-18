<?php

namespace Peppermint\Notifications\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

/**
 * Liefert den Service Worker aus dem Paket aus.
 *
 * ## Warum ueber eine Route und nicht per `vendor:publish`
 *
 * Eine veroeffentlichte Kopie im Produkt veraltet in dem Moment, in dem sich
 * das Paket aendert — und niemand merkt es, weil ein Service Worker still
 * weiterlaeuft. So traegt jedes Produkt die Fassung, die seine Paketversion
 * mitbringt.
 *
 * ## Warum der Pfad an der Wurzel liegen muss
 *
 * Ein Service Worker darf nur den Bereich bedienen, unter dem er ausgeliefert
 * wird. Laege er unter `/js/`, bekaeme er Push-Ereignisse nur fuer Seiten
 * unterhalb von `/js/` — also fuer keine.
 *
 * ## Zwischenspeicher
 *
 * Kurz, und mit einer Marke am Dateinamen. Browser halten einen Service
 * Worker hartnaeckig; ohne Marke liefe nach einer Aenderung tagelang die
 * alte Fassung weiter.
 */
class PushServiceWorkerController
{
    public function __invoke(): Response
    {
        $datei = __DIR__.'/../../../resources/sw/push-sw.js';

        return response(
            (string) file_get_contents($datei),
            200,
            [
                'Content-Type' => 'application/javascript; charset=utf-8',
                'Cache-Control' => 'public, max-age=3600',
                // Ohne diesen Kopf begrenzt der Browser den Worker auf das
                // Verzeichnis, aus dem er kommt. Hier ist das die Wurzel,
                // aber der Kopf macht es unabhaengig davon, wohin ein
                // Produkt die Route haengt.
                'Service-Worker-Allowed' => '/',
            ],
        );
    }

    /**
     * Eine Marke, die sich mit der Datei aendert.
     *
     * Haengt das Produkt sie an die Adresse, holt der Browser nach jeder
     * Aenderung neu — und nur dann.
     */
    public static function fassung(): string
    {
        $datei = __DIR__.'/../../../resources/sw/push-sw.js';

        return substr(hash('xxh128', (string) @filemtime($datei)), 0, 8);
    }
}
