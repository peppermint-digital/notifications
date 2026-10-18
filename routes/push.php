<?php

use Illuminate\Support\Facades\Route;
use Peppermint\Notifications\Http\Controllers\PushKeyController;
use Peppermint\Notifications\Http\Controllers\PushSubscriptionController;

/*
|--------------------------------------------------------------------------
| Web-Push: Schluessel, Service Worker, Abo
|--------------------------------------------------------------------------
|
| Ein Produkt bindet sie ein:
|
|     Route::middleware(['web', 'auth'])
|         ->group(base_path('vendor/peppermint/notifications/routes/push.php'));
|
| ACHTUNG beim Service Worker: Die Route darf NICHT in eine Gruppe mit
| Praefix. Ein Service Worker bedient nur den Bereich, unter dem er
| ausgeliefert wird — unter `/js/` bekaeme er Push-Ereignisse fuer Seiten
| unterhalb von `/js/`, also fuer keine.
|
| Und er darf NICHT hinter `auth`: Der Browser holt ihn beim Registrieren
| ohne Sitzungskontext. Deshalb steht er hier ausserhalb und wird vom
| Produkt in eine Gruppe ohne `auth` gehaengt.
|
*/

Route::get('api/push/public-key', PushKeyController::class)->name('push.public-key');

Route::post('api/push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
Route::delete('api/push/subscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
