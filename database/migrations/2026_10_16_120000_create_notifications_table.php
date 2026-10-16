<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Tabelle fuer ein Produkt, das bei null anfaengt.
 *
 * Laeuft NUR mit `notifications.run_migrations = true`, und das ist nicht die
 * Vorgabe: Alle vier bestehenden Produkte haben ihre Tabelle laengst, AI Brain
 * allein mit ueber 17.000 Zeilen. Wer adoptiert, ruestet seine fehlenden
 * Spalten mit einer eigenen, additiven Migration nach — siehe README.
 *
 * Die Form ist die des Peppermint Managers: echte Spalten statt alles in einem
 * JSON. Buendeln, Sortieren und Indexieren braucht Spalten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabelle = config('notifications.tables.notifications', 'notifications');

        if (Schema::hasTable($tabelle)) {
            return;
        }

        Schema::create($tabelle, function (Blueprint $table): void {
            $table->id();

            // Der Empfaenger. Bewusst ohne Fremdschluessel-Zwang auf eine
            // Tabelle, deren Namen das Paket nicht kennt — ein Produkt mit
            // `accounts` statt `users` koennte die Migration sonst nicht
            // fahren. Die Beziehung steht im Modell.
            $table->unsignedBigInteger('user_id')->index();

            // Der Schluessel aus dem Typ-Register des Produkts.
            $table->string('type');

            $table->string('title');
            $table->text('message')->nullable();

            // Was an DIESER Meldung abweicht. Leer heisst: nimm die Vorgabe
            // der Art.
            $table->string('icon')->nullable();
            // Die Farbe kann an der Meldung haengen statt an der Art: Im AI
            // Brain meldet dieselbe Art „Server nicht erreichbar" (rot) und
            // „Server wieder da" (gruen).
            $table->string('color')->nullable();
            $table->string('link')->nullable();

            // Der Gegenstand. Nullable, weil es Meldungen ohne gibt
            // ("Aufgabe geloescht" hat keine Aufgabe mehr).
            $table->string('notifiable_type')->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();

            // Wer sie ausgeloest hat — fuer den Einmal-Riegel (niemanden ueber
            // die eigene Handlung benachrichtigen) und fuer die Anzeige.
            $table->unsignedBigInteger('triggered_by')->nullable();

            // Freiform fuer das, was nur dieses Produkt angeht.
            $table->json('data')->nullable();

            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // Die Glocke fragt: meine, ungelesen, neueste zuerst.
            $table->index(['user_id', 'is_read', 'created_at'], 'notifications_bell_idx');

            // Die rollende Sammelmeldung fragt: gibt es zu diesem Gegenstand
            // schon eine ungelesene Meldung dieser Art fuer diese Person?
            $table->index(
                ['user_id', 'type', 'notifiable_type', 'notifiable_id'],
                'notifications_rolling_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('notifications.tables.notifications', 'notifications'));
    }
};
