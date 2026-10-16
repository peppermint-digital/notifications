<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wer will was, auf welchem Weg.
 *
 * ## Warum diese Migration IMMER laeuft
 *
 * Die Migration fuer `notifications` ist hinter `run_migrations` verriegelt,
 * und zwar aus gutem Grund: Alle vier Produkte haben diese Tabelle seit
 * Monaten, AI Brain mit ueber 17.000 Zeilen. Ein Paket, das dort anlegen
 * will, scheitert entweder laut oder legt eine zweite an.
 *
 * Hier ist es umgekehrt. Diese Tabelle gibt es NIRGENDS — gemessen am
 * 16.10.2026: Der Manager hat einen Push-An/Aus-Schalter, im CRM steht im
 * Code „This would typically be stored in user preferences table", sonst
 * nichts. Es gibt also keinen Bestand, auf den Ruecksicht zu nehmen waere.
 *
 * ## Eine Zeile je Mensch und Art, drei Spalten fuer drei Wege
 *
 * Nicht eine Zeile je (Mensch, Art, Weg): Die Maske ist eine Matrix mit einer
 * Zeile je Art und drei Feldern darin. Drei Zeilen fuer eine Bildschirmzeile
 * zu schreiben und wieder einzusammeln waere Arbeit ohne Gewinn — die drei
 * Wege aendern sich zusammen.
 *
 * ## Es fehlen absichtlich Zeilen
 *
 * Wer nichts eingestellt hat, hat KEINE Zeile. Die Vorgabe steht in der
 * Konfiguration, nicht tausendfach in der Datenbank. Sonst muesste jede neue
 * Art fuer jeden Menschen nachgetragen werden, und eine geaenderte Vorgabe
 * erreichte niemanden mehr.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabelle = (string) config('notifications.tables.preferences', 'notification_preferences');

        if (Schema::hasTable($tabelle)) {
            return;
        }

        Schema::create($tabelle, function (Blueprint $table): void {
            $table->id();

            // Ohne Fremdschluessel-Zwang: Das Paket kennt den Namen der
            // Personentabelle des Produkts nicht — `users` ist haeufig, aber
            // nicht zugesichert.
            $table->unsignedBigInteger('user_id');

            // Der Schluessel aus dem Typ-Register des Produkts.
            $table->string('type');

            // Je Weg eine Stufe: instant | digest | off.
            //
            // `string` und kein ENUM: Auf SQLite gibt es keines, und ein
            // ENUM, das nur auf MySQL greift, laesst genau die Tests
            // durchlaufen, die den Fehler finden sollten.
            $table->string('push_level')->nullable();
            $table->string('inapp_level')->nullable();
            $table->string('email_level')->nullable();

            $table->timestamps();

            // Eine Zeile je Mensch und Art. Der Index traegt beide Aufgaben:
            // die Eindeutigkeit und das Nachschlagen beim Zustellen.
            $table->unique(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('notifications.tables.preferences', 'notification_preferences'));
    }
};
