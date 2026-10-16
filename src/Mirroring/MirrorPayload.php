<?php

namespace Peppermint\Notifications\Mirroring;

use Peppermint\Notifications\Models\Notification;

/**
 * Die Form, in der eine Meldung zwischen Produkten reist.
 *
 * Bewusst flach und ohne Objekte: Was hier steht, muss ein Produkt verstehen,
 * das die Klassen des Absenders nicht kennt. Eine Aufgabe aus dem Manager ist
 * im CRM nichts — ihr Titel und ihr Verweis schon.
 *
 * ## Der Empfänger ist eine E-Mail-Adresse
 *
 * Nicht die Kennung. Nutzer-Nummern gelten je Produkt; dieselbe `5` ist in
 * vier Datenbanken vier Menschen. Die Adresse ist der einzige Schlüssel, den
 * alle vier gleich lesen — und derselbe, über den der Komm-Layer schon heute
 * die handelnde Person durchreicht.
 *
 * ## Der Verweis muss absolut sein
 *
 * Ein `/tasks/5` zeigt im CRM auf eine Seite, die es dort nicht gibt. Beim
 * Spiegeln wird deshalb die Adresse des ABSENDERS davorgesetzt — die Meldung
 * führt zurück zu der Stelle, an der die Sache wirklich liegt.
 */
final class MirrorPayload
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $kind,
        public readonly string $title,
        public readonly ?string $message = null,
        public readonly ?string $link = null,
        public readonly ?string $icon = null,
        public readonly ?string $color = null,
        /** Produkt-Slug des Absenders — steht später in `source`. */
        public readonly ?string $origin = null,
        /** Die Kennung beim Absender, damit dieselbe Meldung nicht zweimal landet. */
        public readonly int|string|null $originId = null,
        public readonly array $data = [],
    ) {}

    /**
     * Aus einer lokalen Meldung — alles, was ein fremdes Produkt braucht.
     */
    public static function fromNotification(
        Notification $meldung,
        string $recipientEmail,
        ?string $origin = null,
        ?string $baseUrl = null,
    ): self {
        return new self(
            recipientEmail: $recipientEmail,
            kind: (string) $meldung->field('type'),
            title: (string) $meldung->field('title'),
            message: $meldung->field('message'),
            link: self::absolut($meldung->field('link'), $baseUrl),
            icon: $meldung->field('icon'),
            color: $meldung->field('color'),
            origin: $origin,
            originId: $meldung->getKey(),
            data: (array) ($meldung->field('data') ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $roh
     */
    public static function fromArray(array $roh): self
    {
        return new self(
            recipientEmail: (string) ($roh['recipient_email'] ?? ''),
            kind: (string) ($roh['kind'] ?? ''),
            title: (string) ($roh['title'] ?? ''),
            message: $roh['message'] ?? null,
            link: $roh['link'] ?? null,
            icon: $roh['icon'] ?? null,
            color: $roh['color'] ?? null,
            origin: $roh['origin'] ?? null,
            originId: $roh['origin_id'] ?? null,
            data: (array) ($roh['data'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'recipient_email' => $this->recipientEmail,
            'kind' => $this->kind,
            'title' => $this->title,
            'message' => $this->message,
            'link' => $this->link,
            'icon' => $this->icon,
            'color' => $this->color,
            'origin' => $this->origin,
            'origin_id' => $this->originId,
            'data' => $this->data,
        ];
    }

    /**
     * Taugt die Nutzlast überhaupt?
     *
     * Ohne Empfänger und ohne Titel ist sie nichts wert — und eine Zeile ohne
     * beides in einer fremden Datenbank ist schlimmer als keine: Sie zählt im
     * Abzeichen mit und lässt sich nicht zuordnen.
     */
    public function istVollstaendig(): bool
    {
        return trim($this->recipientEmail) !== '' && trim($this->title) !== '';
    }

    /**
     * Eine Kennung, die dieselbe Meldung über Produktgrenzen hinweg
     * wiedererkennt — die Grundlage dafür, dass eine Spiegelung nicht
     * doppelt ankommt.
     */
    public function spiegelSchluessel(): string
    {
        return ($this->origin ?? 'unbekannt').':'.($this->originId ?? md5($this->title.$this->recipientEmail));
    }

    private static function absolut(?string $link, ?string $baseUrl): ?string
    {
        if ($link === null || $link === '' || $baseUrl === null) {
            return $link;
        }

        // Schon absolut? Dann in Ruhe lassen.
        if (str_starts_with($link, 'http://') || str_starts_with($link, 'https://')) {
            return $link;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($link, '/');
    }
}
