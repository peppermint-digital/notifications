<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wann dieser Mensch zuletzt eine Sammel-Mail bekommen hat.
 *
 * ## Warum eine Zeile je Mensch und kein Merker je Meldung
 *
 * Der naheliegende Bau waere eine Spalte `emailed_at` an jeder Meldung. Sie
 * muesste in vier gewachsenen Tabellen nachgeruestet werden, um eine Angabe
 * zu tragen, die nur fuer eine Minderheit der Zeilen ueberhaupt gilt — und
 * sie waere bei 17.000 Zeilen allein im Brain reiner Ballast.
 *
 * Hier reicht ein Zeitpunkt je Mensch: Die Sammel-Mail nimmt alles, was seit
 * dem letzten Versand entstanden ist. Was per Einzelmail ging, kann dabei
 * nicht mitgehen — die Stufe steht je ART fest, und eine Art ist entweder
 * `instant` oder `digest`, nie beides.
 *
 * Die eine Luecke in dieser Argumentation: Wer waehrend eines Fensters von
 * `instant` auf `digest` umstellt, bekommt eine Meldung zweimal. Einmal.
 * Dafuer eine Spalte an 17.000 Zeilen zu haengen, waere der falsche Tausch.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabelle = (string) config('notifications.tables.digests', 'notification_digests');

        if (Schema::hasTable($tabelle)) {
            return;
        }

        Schema::create($tabelle, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('notifications.tables.digests', 'notification_digests'));
    }
};
