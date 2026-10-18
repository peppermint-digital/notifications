<?php

namespace Peppermint\Notifications\Console;

use Illuminate\Console\Command;
use Peppermint\Notifications\Mail\EmailDelivery;

/**
 * Die Sammel-Mails verschicken.
 *
 * Gehoert in den Zeitplan des Produkts, sonst passiert nichts:
 *
 *     Schedule::command('notifications:digest')->dailyAt('08:00');
 *
 * Ein Befehl, der nur von Hand laeuft, ist kein Weg — er ist eine
 * Moeglichkeit, die beim ersten Mal benutzt und dann vergessen wird.
 */
class SammelMailCommand extends Command
{
    protected $signature = 'notifications:digest
        {--dry-run : Nur zaehlen, nichts verschicken}';

    protected $description = 'Verschickt die gesammelten Benachrichtigungs-Mails (#8165).';

    public function handle(EmailDelivery $versand): int
    {
        $faellige = $versand->faellige();

        if ($this->option('dry-run')) {
            // Beide Zahlen, und die zweite ist die, auf die es ankommt:
            // „12 Menschen kaemen in Frage" klingt nach zwoelf Mails und
            // meint oft null — weil die Vorgabe `off` ist und niemand etwas
            // anderes eingestellt hat.
            $mails = 0;
            $meldungen = 0;

            foreach ($faellige as $mensch) {
                $anzahl = $versand->vorschau($mensch);

                if ($anzahl > 0) {
                    $mails++;
                    $meldungen += $anzahl;
                }
            }

            $this->info("{$faellige->count()} Menschen geprueft — {$mails} Mails mit zusammen {$meldungen} Meldungen gingen hinaus.");

            return self::SUCCESS;
        }

        $mails = 0;
        $meldungen = 0;

        foreach ($faellige as $mensch) {
            $anzahl = $versand->sammeln($mensch);

            if ($anzahl > 0) {
                $mails++;
                $meldungen += $anzahl;
            }
        }

        $this->info("{$mails} Mails mit zusammen {$meldungen} Meldungen verschickt.");

        return self::SUCCESS;
    }
}
