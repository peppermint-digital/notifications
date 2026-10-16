<?php

namespace Peppermint\Notifications\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein beliebiger Gegenstand, an dem eine Meldung haengt. Steht in den Tests
 * fuer das, was im Produkt eine Aufgabe, ein Projekt oder eine Unterhaltung
 * ist.
 */
class Widget extends Model
{
    protected $table = 'widgets';

    protected $guarded = [];
}
