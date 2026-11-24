<?php

namespace Peppermint\Notifications\Console;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * Ein eigenes VAPID-Paar erzeugen und in die .env schreiben.
 *
 * ## Warum der Befehl nichts anzeigt
 *
 * Der private Teil ist das Geheimnis, mit dem jeder Push signiert wird. Was
 * auf einem Bildschirm stand, steht auch in einem Terminal-Verlauf, einem
 * Forge-Protokoll oder einem Chat. Geschrieben wird deshalb nur in die Datei.
 *
 * ## Warum er ein vorhandenes Paar nicht ersetzt
 *
 * Ein neuer Schluessel macht JEDES bestehende Abo lautlos wertlos: Der
 * Push-Dienst lehnt die neue Signatur ab, die Oberflaeche zeigt weiter
 * „abonniert". Genau so sind im Manager einmal drei Monate Push verloren
 * gegangen (#8488). Ersetzen nur mit `--force`, also mit Absicht.
 *
 * Erzeugt wird mit OpenSSL (P-256), ohne zusaetzliche Abhaengigkeit.
 */
class PushSchluesselCommand extends Command
{
    protected $signature = 'notifications:push-schluessel
        {--force : Ein vorhandenes Paar ersetzen (jedes bestehende Abo wird ungueltig)}';

    protected $description = 'Erzeugt ein eigenes VAPID-Paar fuer den Push-Betrieb `local` und schreibt es in die .env.';

    public function handle(): int
    {
        $datei = $this->laravel->environmentFilePath();

        if (! is_file($datei) || ! is_writable($datei)) {
            $this->error('Die .env ist nicht vorhanden oder nicht beschreibbar: '.$datei);

            return self::FAILURE;
        }

        $inhalt = (string) file_get_contents($datei);

        if (! $this->option('force') && $this->hatPaar($inhalt)) {
            $this->warn('Es gibt schon ein VAPID-Paar. Nichts geaendert.');
            $this->line('Ersetzen nur mit --force — danach muss JEDES Geraet Push neu einschalten.');

            return self::FAILURE;
        }

        [$oeffentlich, $privat] = self::erzeugen();

        $inhalt = $this->setzen($inhalt, 'VAPID_PUBLIC_KEY', $oeffentlich);
        $inhalt = $this->setzen($inhalt, 'VAPID_PRIVATE_KEY', $privat);

        file_put_contents($datei, $inhalt);

        $this->info('VAPID-Paar in die .env geschrieben. Angezeigt wird es nicht.');
        $this->line('Danach: php artisan config:cache (falls die Konfiguration zwischengespeichert ist).');

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string} oeffentlich (65 Bytes, unkomprimiert), privat (32 Bytes), beide base64url
     */
    public static function erzeugen(): array
    {
        $schluessel = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($schluessel === false) {
            throw new RuntimeException('OpenSSL konnte kein P-256-Paar erzeugen.');
        }

        $teile = openssl_pkey_get_details($schluessel)['ec'] ?? null;

        if (! is_array($teile) || ! isset($teile['x'], $teile['y'], $teile['d'])) {
            throw new RuntimeException('OpenSSL hat kein vollstaendiges EC-Paar geliefert.');
        }

        $auffuellen = static fn (string $bytes): string => str_pad($bytes, 32, "\0", STR_PAD_LEFT);

        return [
            self::base64url("\x04".$auffuellen($teile['x']).$auffuellen($teile['y'])),
            self::base64url($auffuellen($teile['d'])),
        ];
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function hatPaar(string $inhalt): bool
    {
        return preg_match('/^VAPID_PRIVATE_KEY=\S+/m', $inhalt) === 1
            || preg_match('/^VAPID_PUBLIC_KEY=\S+/m', $inhalt) === 1;
    }

    private function setzen(string $inhalt, string $name, string $wert): string
    {
        $zeile = $name.'='.$wert;

        if (preg_match('/^'.$name.'=.*$/m', $inhalt) === 1) {
            return (string) preg_replace('/^'.$name.'=.*$/m', $zeile, $inhalt);
        }

        return rtrim($inhalt, "\n")."\n".$zeile."\n";
    }
}
