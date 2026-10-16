<?php

namespace Peppermint\Notifications\Preferences;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Was ein Mensch fuer EINE Art eingestellt hat.
 *
 * Eine fehlende Zeile ist der Normalfall und bedeutet „Vorgabe". Die
 * Entscheidung, welche Stufe daraus wird, faellt nicht hier, sondern im
 * {@see PreferenceResolver} — ein Modell, das die Konfiguration mitliest,
 * waere an jeder Stelle anders zu umgehen.
 *
 * @property int $user_id
 * @property string $type
 */
class NotificationPreference extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('notifications.tables.preferences', 'notification_preferences');
    }

    /**
     * Der Spaltenname fuer einen Weg.
     *
     * Ueber die Konfiguration umbiegbar wie bei den Meldungen selbst — ein
     * Produkt, das diese Tabelle eines Tages anders nennt, soll das Paket
     * nicht anfassen muessen.
     */
    public static function column(Channel $weg): string
    {
        $feld = $weg->value.'_level';

        return (string) config("notifications.columns.preferences.{$feld}", $feld);
    }

    public function level(Channel $weg): ?Level
    {
        $wert = $this->getAttribute(self::column($weg));

        return is_string($wert) ? Level::tryFrom($wert) : null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, Model|int|string $user): Builder
    {
        return $query->where('user_id', $user instanceof Model ? $user->getKey() : $user);
    }
}
