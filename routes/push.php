<?php

use Illuminate\Support\Facades\Route;
use Peppermint\Notifications\Http\Controllers\PushKeyController;

/*
|--------------------------------------------------------------------------
| Der oeffentliche Push-Schluessel
|--------------------------------------------------------------------------
|
| Ein Produkt bindet ihn ein:
|
|     Route::middleware(['web', 'auth'])
|         ->group(base_path('vendor/peppermint/notifications/routes/push.php'));
|
| Nur LESEN. Das Anlegen eines Abos bleibt beim Produkt — es haengt an
| dessen Tabelle, dessen Service Worker und dessen Anmeldung. Was das Paket
| beisteuert, ist die Antwort auf die eine Frage, die ueberall dieselbe sein
| muss: mit WELCHEM Schluessel wird abonniert.
|
*/

Route::get('api/push/public-key', PushKeyController::class)->name('push.public-key');
