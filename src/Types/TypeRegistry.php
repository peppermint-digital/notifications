<?php

namespace Peppermint\Notifications\Types;

use Peppermint\Notifications\Exceptions\UnknownNotificationType;

/**
 * Das Verzeichnis der Arten, die dieses Produkt kennt.
 *
 * Gefuellt wird es aus `config('notifications.types')` und zur Laufzeit ueber
 * {@see register()} — etwa aus einem bestehenden Enum heraus, das ein Produkt
 * behalten will.
 *
 * ## Warum eine unbekannte Art nicht wirft
 *
 * Im Manager kannte die Glocke 15 der 39 Arten; der Rest fiel stillschweigend
 * auf das Glocken-Symbol zurueck, und eine Mail-Warnung sah aus wie eine
 * Erinnerung. Die Lehre daraus ist NICHT "dann eben werfen": Eine Ausnahme im
 * Anzeige-Pfad nimmt dem Leser die ganze Liste statt eines Symbols.
 *
 * Deshalb: Rueckfall als Normalverhalten, und ein Schalter
 * (`notifications.strict_types`) fuer Produkte und Tests, die
 * Vollstaendigkeit erzwingen wollen. Der Manager tut genau das heute schon
 * ueber einen eigenen Test.
 */
class TypeRegistry
{
    /** Der Schluessel, unter dem eine Meldung ohne Art gefuehrt wird. */
    public const UNBEKANNT = 'unknown';

    /** @var array<string, NotificationType> */
    private array $types = [];

    private bool $konfigurationGeladen = false;

    public function __construct(
        /** @var array<int, array<string, mixed>> */
        private readonly array $konfigurierteTypen = [],
        /** @var array<string, mixed> */
        private readonly array $rueckfall = [],
        private readonly bool $streng = false,
    ) {}

    /**
     * Eine Art anmelden. Eine bereits angemeldete wird ersetzt — so kann ein
     * Produkt eine Vorgabe aus der Konfiguration gezielt ueberschreiben.
     */
    public function register(NotificationType $type): self
    {
        $this->laden();

        $this->types[$type->key] = $type;

        return $this;
    }

    /**
     * Mehrere auf einmal anmelden.
     *
     * @param  iterable<NotificationType|array<string, mixed>>  $types
     */
    public function registerMany(iterable $types): self
    {
        foreach ($types as $type) {
            $this->register($type instanceof NotificationType ? $type : NotificationType::fromArray($type));
        }

        return $this;
    }

    public function has(string $key): bool
    {
        $this->laden();

        return isset($this->types[$key]);
    }

    /**
     * Die Art zu einem Schluessel — oder der Rueckfall.
     *
     * @throws UnknownNotificationType wenn `strict_types` eingeschaltet ist
     */
    public function get(string $key): NotificationType
    {
        $this->laden();

        if (isset($this->types[$key])) {
            return $this->types[$key];
        }

        if ($this->streng) {
            throw UnknownNotificationType::forKey($key, array_keys($this->types));
        }

        return new NotificationType(
            // Ein LEERER Schluessel ist der Normalfall, nicht der Rand: Eine
            // Meldung ohne Art hat dort null stehen, und im AI Brain traf das
            // beim Umstieg 17 Bestandszeilen.
            //
            // Ohne diesen Ersatz wirft der Konstruktor hier — ausgerechnet im
            // Rueckfall, der die Ausnahme ja verhindern soll. Das Ergebnis
            // waere genau der Schaden, gegen den der Rueckfall gebaut ist:
            // eine einzige unvollstaendige Zeile nimmt dem Leser die ganze
            // Liste.
            key: $key !== '' ? $key : self::UNBEKANNT,
            label: (string) ($this->rueckfall['label'] ?? 'Benachrichtigung'),
            icon: (string) ($this->rueckfall['icon'] ?? 'bell'),
            color: (string) ($this->rueckfall['color'] ?? 'gray'),
        );
    }

    /**
     * Alle angemeldeten Arten, nach Schluessel sortiert.
     *
     * Grundlage fuer die Einstellungs-Matrix: Was hier nicht steht, kann
     * niemand an- oder abwaehlen.
     *
     * @return array<string, NotificationType>
     */
    public function all(): array
    {
        $this->laden();

        $types = $this->types;
        ksort($types);

        return $types;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * Die Konfiguration erst beim ersten Zugriff lesen.
     *
     * Der Dienst entsteht beim Hochfahren; die Konfiguration eines Produkts
     * steht zu diesem Zeitpunkt nicht zwingend schon vollstaendig.
     */
    private function laden(): void
    {
        if ($this->konfigurationGeladen) {
            return;
        }

        $this->konfigurationGeladen = true;

        foreach ($this->konfigurierteTypen as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $type = NotificationType::fromArray($definition);
            $this->types[$type->key] = $type;
        }
    }
}
