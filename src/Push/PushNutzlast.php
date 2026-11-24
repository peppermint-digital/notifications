<?php

namespace Peppermint\Notifications\Push;

use Illuminate\Support\Str;
use Peppermint\Notifications\Models\Notification;

/**
 * Was der Service Worker bekommt.
 *
 * Die Felder sind die, die die gewachsenen Worker lesen (`title`, `body`,
 * `url`, `tag`, `data.url`) — der Manager-Worker `/sw.js` ebenso wie
 * `push-sw.js` aus diesem Paket.
 *
 * Der Verweis wird absolut: Ein Klick auf die Mitteilung oeffnet ein Fenster,
 * und ein relativer Pfad hat dort keinen Bezug.
 */
final class PushNutzlast
{
    /**
     * @return array<string, mixed>
     */
    public static function aus(Notification $meldung): array
    {
        $verweis = (string) ($meldung->field('link') ?? '');

        if ($verweis === '') {
            $verweis = '/';
        }

        if (! Str::startsWith($verweis, ['http://', 'https://'])) {
            $verweis = rtrim((string) config('app.url'), '/').'/'.ltrim($verweis, '/');
        }

        return [
            'title' => (string) $meldung->field('title'),
            'body' => (string) ($meldung->field('message') ?? ''),
            'url' => $verweis,
            'tag' => 'notification-'.$meldung->getKey(),
            'data' => [
                'url' => $verweis,
                'notification_id' => $meldung->getKey(),
                'type' => (string) $meldung->field('type'),
            ],
        ];
    }
}
