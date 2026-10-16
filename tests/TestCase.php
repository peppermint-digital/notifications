<?php

namespace Peppermint\Notifications\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Base;
use Peppermint\Notifications\NotificationsServiceProvider;

abstract class TestCase extends Base
{
    protected function getPackageProviders($app): array
    {
        return [NotificationsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('notifications.user_model', Support\User::class);

        // Die Paket-Migration laeuft im Test ausdruecklich — im Betrieb ist
        // `false` die Vorgabe, weil jedes Produkt seine Tabelle schon hat.
        $app['config']->set('notifications.run_migrations', true);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => env('DB_DRIVER', 'sqlite'),
            'database' => env('DB_DATABASE', ':memory:'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'prefix' => '',
            // Ohne das erzwingt SQLite keine Fremdschluessel — und ein Test
            // auf „die Anhaengsel gehen mit" bleibt gruen, ohne etwas zu
            // messen. In MySQL greift die Regel, hier also auch.
            'foreign_key_constraints' => true,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Bei SQLite im Arbeitsspeicher ist jede Verbindung frisch; bei MySQL
        // ueberlebt die Datenbank den Test. Ohne dieses Aufraeumen scheitert
        // der ZWEITE Test an „Table 'users' already exists" — und man haelt
        // den MySQL-Lauf faelschlich fuer unmoeglich, statt ihn zu reparieren.
        Schema::dropAllTables();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Im Betrieb laedt der Provider diese Migration selbst — hier wird
        // sie ausdruecklich geladen, weil `defineDatabaseMigrations` alle
        // Tabellen vorher wegwirft.
        $this->loadMigrationsFrom(__DIR__.'/../database/preferences-migrations');

        // Eine Nutzer-Tabelle, wie sie jedes aufnehmende Produkt mitbringt.
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // Die E-Mail ist der einzige produktuebergreifende Schluessel —
            // ohne sie laesst sich eine gespiegelte Meldung nicht zuordnen.
            $table->string('email')->nullable()->unique();
            $table->timestamps();
        });

        // Ein Gegenstand, an dem Meldungen haengen koennen — steht hier fuer
        // Aufgabe, Projekt, Unterhaltung.
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
    }
}
