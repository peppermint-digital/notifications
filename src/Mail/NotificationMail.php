<?php

namespace Peppermint\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Eine Benachrichtigung als E-Mail — einzeln oder gesammelt.
 *
 * ## Eine Mailable fuer beide Stufen
 *
 * `instant` ist die Sammlung mit genau einem Eintrag. Zwei Mailables waeren
 * zwei Vorlagen, die auseinanderlaufen, fuer einen Unterschied, der nur in
 * der Betreffzeile sichtbar ist.
 *
 * ## Warum die Vorlage im Paket liegt
 *
 * Aus demselben Grund wie die Liste und der Posteingang: damit sie in jedem
 * Produkt dieselbe ist. Ein Produkt, das es anders will, veroeffentlicht die
 * Ansicht (`vendor:publish --tag=notifications-views`) und aendert seine
 * Kopie — dann ist es eine bewusste Abweichung und kein Auseinanderlaufen.
 */
class NotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $zeilen  Aufbereitete Feed-Zeilen.
     */
    public function __construct(
        public readonly string $empfaengerName,
        public readonly array $zeilen,
        public readonly string $absenderSystem,
        public readonly ?string $posteingangUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        $anzahl = count($this->zeilen);

        // Der Betreff nennt die Zahl, nicht den Inhalt. Bei einer einzelnen
        // Meldung ist deren Titel die bessere Zeile — wer sie liest, weiss
        // sofort, ob es ihn jetzt betrifft.
        $betreff = $anzahl === 1
            ? (string) ($this->zeilen[0]['title'] ?? 'Benachrichtigung')
            : "{$anzahl} neue Benachrichtigungen";

        return new Envelope(subject: $betreff);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'notifications::mail.notifications',
            with: [
                'name' => $this->empfaengerName,
                'zeilen' => $this->zeilen,
                'system' => $this->absenderSystem,
                'posteingang' => $this->posteingangUrl,
            ],
        );
    }
}
