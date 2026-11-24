<?php

namespace Peppermint\Notifications\Push;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Ein Abo eines Geraets im eigenen Push-Betrieb.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $public_key
 * @property string $auth_token
 * @property string|null $user_agent
 */
class PushSubscription extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['public_key', 'auth_token'];

    public function getTable(): string
    {
        return (string) config('notifications.tables.push_subscriptions', 'push_subscriptions');
    }

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public static function fingerabdruck(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeFuer(Builder $query, int|string $userId): void
    {
        $query->where('user_id', $userId);
    }
}
