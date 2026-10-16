<?php

namespace Peppermint\Notifications\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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

    public function colorName(): string
    {
        return $this->typeDefinition()->color;
    }
}
