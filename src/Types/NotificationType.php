<?php

namespace Peppermint\Notifications\Types;

use InvalidArgumentException;

/**
 * Eine Benachrichtigungsart, wie ein Produkt sie anmeldet.
 *
 * Bewusst ein Wertobjekt und keine Klasse je Art: Eine Art hat kein Verhalten,
 * nur eine Beschreibung. Bei den Aufgaben-Status ist das anders — dort haengt
 * an jedem Status eine Entscheidung ("was heisst erledigt"), und deshalb ist
 * dort jede Status eine eigene Klasse. Hier waeren 39 Klassen ohne Methoden
 * Aufwand ohne Gegenwert.
 *
 * Der `key` ist der Wert, der in der Spalte `type` steht. Er gehoert dem
 * Produkt: Der Manager schreibt seit Dezember 2025 `task_assigned` in diese
 * Spalte, und das Paket hat keinen Anlass, 5.019 Zeilen umzuschreiben, damit
 * dort `task.assigned` steht.
 */
final class NotificationType
{
    /**
     * @param  array<string, mixed>  $meta  Freiform fuer das Produkt — was das
     *                                      Paket nicht kennt, reicht es durch.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $icon = 'bell',
        public readonly string $color = 'gray',
        public readonly array $meta = [],
    ) {
        if (trim($key) === '') {
            throw new InvalidArgumentException('Eine Benachrichtigungsart braucht einen Schluessel.');
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException(
                "Die Art \"{$key}\" hat keine Beschriftung. Ohne sie steht in der Glocke ein technischer Schluessel."
            );
        }
    }

    /**
     * Aus der Konfiguration oder aus einem Aufruf bauen.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function fromArray(array $definition): self
    {
        $bekannt = ['key', 'label', 'icon', 'color'];

        return new self(
            key: (string) ($definition['key'] ?? ''),
            label: (string) ($definition['label'] ?? ''),
            icon: (string) ($definition['icon'] ?? 'bell'),
            color: (string) ($definition['color'] ?? 'gray'),
            // Alles, was das Paket nicht kennt, bleibt erhalten statt still zu
            // verschwinden. Ein Dienst, der Felder aufzaehlt und den Rest
            // wegwirft, meldet Erfolg und speichert nichts — der Fehler ist in
            // diesem Haus schon zweimal teuer gewesen.
            meta: array_diff_key($definition, array_flip($bekannt)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'icon' => $this->icon,
            'color' => $this->color,
        ] + $this->meta;
    }
}
