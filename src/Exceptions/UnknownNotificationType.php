<?php

namespace Peppermint\Notifications\Exceptions;

use Peppermint\Notifications\Types\TypeRegistry;
use RuntimeException;

/**
 * Nur mit `notifications.strict_types`. Im Normalbetrieb faellt eine
 * unbekannte Art auf eine Vorgabe zurueck — siehe {@see TypeRegistry}.
 */
class UnknownNotificationType extends RuntimeException
{
    /**
     * @param  array<int, string>  $bekannt
     */
    public static function forKey(string $key, array $bekannt = []): self
    {
        $hinweis = $bekannt === []
            ? 'Es ist ueberhaupt keine Art angemeldet — pruefe config/notifications.php.'
            : 'Angemeldet sind: '.implode(', ', $bekannt);

        return new self("Die Benachrichtigungsart \"{$key}\" ist nicht angemeldet. {$hinweis}");
    }
}
