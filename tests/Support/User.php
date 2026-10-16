<?php

namespace Peppermint\Notifications\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Steht fuer das Nutzer-Modell des aufnehmenden Produkts.
 *
 * Erbt von `Foundation\Auth\User`, weil die mitgelieferten Routen einen
 * angemeldeten Nutzer brauchen — und ein Test-Modell, das sich nicht anmelden
 * kann, koennte sie gar nicht pruefen.
 */
class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
