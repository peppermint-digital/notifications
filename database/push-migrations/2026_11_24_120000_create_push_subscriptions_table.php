<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Abo-Tabelle fuer den eigenen Push-Betrieb (`push.driver = local`).
 *
 * Geladen wird diese Migration NUR in diesem Betrieb, und sie legt nichts an,
 * was es schon gibt: Ein Produkt mit gewachsener Abo-Tabelle (AI Brain) darf
 * keine zweite bekommen und keine umgebaut.
 *
 * Die Adresse eines Abos kann laenger sein als jeder Index erlaubt (MySQL:
 * 3072 Bytes je Schluessel). Eindeutig ist deshalb ihr Fingerabdruck, nicht
 * sie selbst.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabelle = (string) config('notifications.tables.push_subscriptions', 'push_subscriptions');

        if (Schema::hasTable($tabelle)) {
            return;
        }

        Schema::create($tabelle, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('notifications.tables.push_subscriptions', 'push_subscriptions'));
    }
};
