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
 * ## Tabellen und Inline-Styles, kein huebsches HTML
 *
 * Verbindlicher Standard (Wiki „E-Mail-Template-Standard: bulletproof
 * HTML-Mails"). Die erste Fassung benutzte Laravels Markdown-Vorlage — die
 * ist nicht falsch, bringt aber `border-radius`, einen OS-abhaengigen
 * Schriftstapel und keinen Plain-Text-Teil mit. Der fehlende Text-Teil
 * kostet Spam-Punkte.
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
        $daten = [
            'name' => $this->empfaengerName,
            'zeilen' => $this->zeilen,
            'system' => $this->absenderSystem,
            'posteingang' => $this->posteingangUrl,
            'einstellungen' => $this->einstellungenUrl(),
            'betreff' => $this->envelope()->subject,
            'vorschau' => $this->vorschau(),
        ];

        // Beide Teile. Der Text-Teil ist Pflicht laut Standard und nicht aus
        // Nostalgie: Eine Mail ohne Text-Alternative bekommt bei den meisten
        // Filtern Spam-Punkte, und bei einer Mail, die regelmaessig an
        // dieselben Leute geht, entscheidet das ueber Posteingang oder
        // Werbeordner.
        return new Content(
            view: 'notifications::mail.notifications',
            text: 'notifications::mail.notifications_text',
            with: $daten,
        );
    }

    /**
     * Die Zeile, die im Posteingang neben dem Betreff steht.
     *
     * Ohne sie zeigt der Client den Anfang des sichtbaren Textes — also die
     * Anrede, und die ist in jeder Mail dieselbe.
     */
    private function vorschau(): string
    {
        $erste = $this->zeilen[0] ?? [];
        $text = trim((string) ($erste['message'] ?? $erste['title'] ?? ''));

        if (count($this->zeilen) > 1) {
            $weitere = count($this->zeilen) - 1;
            $text .= " (und {$weitere} weitere)";
        }

        return mb_substr($text, 0, 140);
    }

    private function einstellungenUrl(): ?string
    {
        $basis = (string) config('app.url');

        return $basis === '' ? null : rtrim($basis, '/').'/settings/notifications';
    }
}
