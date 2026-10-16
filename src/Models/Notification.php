<?php

namespace Peppermint\Notifications\Models;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Peppermint\Notifications\Types\NotificationType;
use Peppermint\Notifications\Types\TypeRegistry;

/**
 * Eine Meldung an genau eine Person.
 *
 * ## Warum diese Form und nicht die von Laravel
 *
 * Laravels `notifications`-Tabelle legt alles in ein `data`-JSON. Das reicht,
 * solange man nur auflistet. Es reicht nicht mehr, sobald man buendeln,
 * sortieren und indexieren will — und genau das ist der Grund, warum es dieses
 * Paket gibt: Im AI Brain sind 94 % der 17.356 Meldungen ungelesen, weil
 * dieselbe Sache 604 Mal einzeln untereinander steht.
 *
 * Gruppieren nach Art und Gegenstand braucht `type`, `notifiable_type` und
 * `notifiable_id` als Spalten. Ein `json_extract()` in der GROUP-BY-Klausel
 * ist auf SQLite und MySQL verschieden schnell und auf beiden nicht
 * indexierbar.
 *
 * Deshalb ist die Zielform die des Peppermint Managers. `data` bleibt
 * trotzdem — fuer alles, was nur dieses eine Produkt angeht.
 *
 * ## Adoption
 *
 * Tabellen- und Spaltennamen kommen aus der Konfiguration. Kein Produkt zieht
 * um; die drei mit der Laravel-Standardform bekommen die Spalten additiv
 * nachgeruestet.
 */
class Notification extends Model
{
    public function getTable(): string
    {
        return config('notifications.tables.notifications', 'notifications');
    }

    /**
     * Leer statt ['id']: Ein nicht-leerer Schutz laesst Eloquent die Tabelle
     * nach ihren Spalten fragen und die Antwort statisch je Modellklasse
     * merken. Dieses Modell kann zur Laufzeit auf eine andere Tabelle zeigen —
     * eine gemerkte Spaltenliste der vorigen Tabelle liesse jedes Feld still
     * fallen, das die neue nicht teilt. Massenzuweisung ist Sache der
     * Anwendung; sie prueft ihre eigenen Eingaben.
     */
    protected $guarded = [];

    /**
     * Der Schlüsseltyp der adoptierten Tabelle.
     *
     * Nicht alle vier Produkte zählen hoch: AI Brain, CRM und Verwaltung
     * stehen auf der Laravel-Standardtabelle mit einem UUID-Schlüssel, nur der
     * Manager hat eine ganze Zahl. Ohne diese beiden Methoden castet Eloquent
     * einen UUID zu `int` — aus `9b1d…` wird `9`, und zwei Meldungen tragen
     * plötzlich dieselbe Kennung.
     */
    public function getKeyType(): string
    {
        return (string) config('notifications.key_type', 'int');
    }

    public function getIncrementing(): bool
    {
        return $this->getKeyType() === 'int';
    }

    /**
     * Einen Schluessel erzeugen, wo die Tabelle keinen vergibt.
     *
     * Bei `key_type = 'string'` ist `id` ein UUID und NOT NULL — die
     * Datenbank zaehlt nichts hoch. Solange das Paket nur liest und
     * aktualisiert, faellt das nicht auf: Geschrieben wird dann ueber
     * Laravels eigenen Notification-Weg, und der setzt den Schluessel selbst.
     *
     * Sobald das Paket SELBST anlegt — beim Spiegeln aus einem anderen
     * Produkt, oder wenn ein Produkt mit Standardform den Notifier benutzt —
     * fehlt er, und das INSERT scheitert an einer NOT-NULL-Bedingung.
     *
     * Genau dort ist es aufgefallen (16.10.2026, erste Spiegelung ins Brain).
     */
    protected static function booted(): void
    {
        static::creating(function (self $meldung): void {
            if ($meldung->getKeyType() === 'string'
                && ($meldung->getKey() === null || $meldung->getKey() === '')) {
                $meldung->setAttribute($meldung->getKeyName(), (string) Str::uuid());
            }

            $meldung->altSpaltenFuellen();
        });
    }

    /**
     * Spalten füllen, die die adoptierte Tabelle verlangt und das Paket nicht
     * kennt.
     *
     * Die Laravel-Standardtabelle führt `type`, `notifiable_type`,
     * `notifiable_id` und `data` als NOT NULL. Für das Paket sind das
     * Altlasten: `type` ist dort die Notification-Klasse (die fachliche Art
     * steht woanders), `notifiable_*` der Empfänger. Es befüllt sie nicht —
     * und solange nur Laravel schreibt, muss es das auch nicht.
     *
     * Sobald das Paket selbst anlegt, scheitert das INSERT. Dreimal dasselbe
     * im Produkt nachzubauen wäre dreimal dieselbe Gelegenheit, es falsch zu
     * machen; deshalb hier, konfigurierbar:
     *
     *     'legacy_columns' => [
     *         'type' => 'App\Notifications\AppNotification',
     *         'notifiable_type' => 'user',
     *         'notifiable_id' => fn ($m) => $m->field('user_id'),
     *         'data' => '{}',
     *     ],
     *
     * Ein Wert kann fest sein oder eine Funktion, die die Meldung bekommt.
     * Gesetzt wird nur, was noch leer ist.
     */
    protected function altSpaltenFuellen(): void
    {
        /** @var array<string, mixed> $alt */
        $alt = (array) config('notifications.legacy_columns', []);

        foreach ($alt as $spalte => $wert) {
            if ($this->getAttribute($spalte) !== null) {
                continue;
            }

            $this->setAttribute($spalte, $this->altWert($wert));
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // Auf die ECHTEN Spaltennamen geschluesselt: Eine adoptierte Tabelle
        // nennt sie anders, und eine Umwandlung auf einer Spalte, die es nicht
        // gibt, laeuft nie.
        return [
            static::column('is_read') => 'boolean',
            static::column('read_at') => 'datetime',
            static::column('data') => 'array',
        ];
    }

    /**
     * Einen Wert aus der Alt-Spalten-Konfiguration aufloesen.
     *
     * `@feldname` liest das Paketfeld; alles andere gilt wie angegeben. Eine
     * Closure wird weiterhin aufgerufen — wer sie programmatisch setzt
     * (`config()->set(...)` im Test) soll das koennen; aus einer
     * Konfigurationsdatei ueberlebt sie den Zwischenspeicher nicht.
     */
    private function altWert(mixed $wert): mixed
    {
        if ($wert instanceof Closure) {
            return $wert($this);
        }

        if (is_string($wert) && str_starts_with($wert, '@')) {
            return $this->field(mb_substr($wert, 1));
        }

        return $wert;
    }

    /**
     * Paket-Feldname => tatsaechlicher Spaltenname.
     */
    public static function column(string $field): string
    {
        return config("notifications.columns.notifications.{$field}")
            ?? config("notifications.columns.{$field}", $field);
    }

    /**
     * Ein Paketfeld lesen, ohne zu wissen, wie die Spalte heisst.
     */
    public function field(string $name): mixed
    {
        return $this->getAttribute(static::column($name));
    }

    public function setField(string $name, mixed $value): self
    {
        $this->setAttribute(static::column($name), $value);

        return $this;
    }

    /* ---------------------------------------------------------------- Bezüge */

    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('notifications.user_model', 'App\\Models\\User');

        return $this->belongsTo($model, static::column('user_id'));
    }

    public function triggeredBy(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('notifications.user_model', 'App\\Models\\User');

        return $this->belongsTo($model, static::column('triggered_by'));
    }

    /**
     * Der Gegenstand, um den es geht.
     *
     * Bewusst NICHT eager laden, wenn eine Liste aufgebaut wird: Unter den
     * Altbestaenden liegen Klassen, die es im Code nicht mehr gibt — im
     * Manager etwa ProjectMessage. Ein `morphTo` darauf wirft beim Aufloesen
     * und nimmt dem Leser die ganze Liste. Der Link entsteht deshalb aus Art
     * und Kennung allein.
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo(
            name: 'notifiable',
            type: static::column('notifiable_type'),
            id: static::column('notifiable_id'),
        );
    }

    /* --------------------------------------------------------------- Filter */

    public function scopeForUser(Builder $query, int|string $userId): Builder
    {
        return $query->where(static::column('user_id'), $userId);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where(static::column('is_read'), false);
    }

    public function scopeRead(Builder $query): Builder
    {
        return $query->where(static::column('is_read'), true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where(static::column('type'), $type);
    }

    /**
     * Alle Meldungen am selben Gegenstand — die Grundlage der rollenden
     * Sammelmeldung: eine bestehende ungelesene Zeile aktualisieren, statt
     * eine zweite anzulegen.
     */
    public function scopeAbout(Builder $query, ?string $notifiableType, int|string|null $notifiableId): Builder
    {
        return $query
            ->where(static::column('notifiable_type'), $notifiableType)
            ->where(static::column('notifiable_id'), $notifiableId);
    }

    /* -------------------------------------------------------------- Zustand */

    public function isRead(): bool
    {
        return (bool) $this->field('is_read');
    }

    public function markAsRead(): bool
    {
        if ($this->isRead()) {
            return false;
        }

        return $this->forceFill([
            static::column('is_read') => true,
            static::column('read_at') => now(),
        ])->save();
    }

    public function markAsUnread(): bool
    {
        return $this->forceFill([
            static::column('is_read') => false,
            static::column('read_at') => null,
        ])->save();
    }

    /* ------------------------------------------------------------- Vokabular */

    /*
     * ACHTUNG bei den Namen hier unten.
     *
     * Keine dieser Methoden darf heissen wie ein Feld. Findet Eloquent zu
     * einem Attribut, das nicht in `$attributes` steht, eine gleichnamige
     * Methode, ruft es sie als BEZIEHUNG auf. Eine Methode `icon()`, die
     * `field('icon')` liest, ruft sich damit selbst auf — bis der Speicher
     * voll ist. Das Symptom (erschoepfter Speicher tief im Container) zeigt
     * nirgends auf die Ursache.
     *
     * Deshalb `typeDefinition()` statt `type()` und `iconName()` statt
     * `icon()` — dasselbe Muster wie `CalendarEvent::kindDefinition()`.
     */

    /**
     * Die angemeldete Art — oder der Rueckfall.
     */
    public function typeDefinition(): NotificationType
    {
        return app(TypeRegistry::class)->get((string) $this->field('type'));
    }

    /**
     * Die Beschriftung fuer die Anzeige.
     *
     * Bei einer gespiegelten Meldung steht sie in `data.mirror_label` — die
     * Art stammt aus einem anderen Produkt, und das eigene Verzeichnis kennt
     * sie nicht. „Aufgabe zugewiesen" aus dem Manager ist drueben besser als
     * das Rueckfall-Wort „Benachrichtigung".
     *
     * Ein eigenes Vokabular fuer fremde Arten zu pflegen waere der andere
     * Weg — und bei vier Produkten mit zusammen ueber 70 Arten einer, den
     * niemand aktuell haelt.
     */
    public function typeLabel(): string
    {
        $gespiegelt = ($this->field('data') ?? [])['mirror_label'] ?? null;

        if (is_string($gespiegelt) && $gespiegelt !== '') {
            return $gespiegelt;
        }

        return $this->typeDefinition()->label;
    }

    /**
     * Aus welchem System die Meldung stammt — oder `null` fuer „von hier".
     *
     * Steht in `data.mirror_origin`, seit der Empfaenger es dort hinterlegt.
     * Die Spalte dafuer gibt es bewusst nicht: Sie waere in vier Produkten
     * nachzuruesten, um eine Angabe zu tragen, die nur fuer gespiegelte
     * Zeilen existiert.
     *
     * Die Unterscheidung ist seit der Spiegelung nicht mehr kosmetisch. In
     * jedem System steht jetzt alles; ohne Herkunft ist „was ist HIER
     * passiert?" nicht mehr beantwortbar.
     */
    public function sourceName(): ?string
    {
        $herkunft = ($this->field('data') ?? [])['mirror_origin'] ?? null;

        return is_string($herkunft) && $herkunft !== '' ? $herkunft : null;
    }

    /**
     * Das Symbol: was an der Meldung steht, schlaegt die Vorgabe der Art.
     *
     * So kann eine einzelne Meldung abweichen, ohne dass dafuer eine eigene
     * Art angemeldet werden muss.
     */
    public function iconName(): string
    {
        $eigenes = $this->field('icon');

        return is_string($eigenes) && $eigenes !== '' ? $eigenes : $this->typeDefinition()->icon;
    }

    /**
     * Die Farbe: was an der Meldung steht, schlaegt die Vorgabe der Art.
     *
     * Im Manager bestimmt die ART die Farbe — „Aufgabe zugewiesen" ist immer
     * blau. Im AI Brain bestimmt der SCHWEREGRAD sie: Dieselbe Art meldet
     * „Server nicht erreichbar" (rot) und „Server wieder da" (gruen). Beides
     * ist richtig, also kann beides gesetzt werden.
     */
    public function colorName(): string
    {
        $eigene = $this->field('color');

        return is_string($eigene) && $eigene !== '' ? $eigene : $this->typeDefinition()->color;
    }
}
