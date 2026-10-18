<?php

use Illuminate\Support\Facades\Route;
use Peppermint\Notifications\Http\Controllers\PushServiceWorkerController;

/*
|--------------------------------------------------------------------------
| Der Service Worker — an der Wurzel, ohne Anmeldung
|--------------------------------------------------------------------------
|
| Getrennt von `push.php`, weil beides nicht zusammenpasst:
|
| - **Ohne `auth`:** Der Browser holt den Worker beim Registrieren ohne
|   Sitzungskontext. Hinter einer Anmeldung bekaeme er die Anmeldemaske und
|   scheiterte mit „the script has an unsupported MIME type".
| - **Ohne Praefix:** Ein Service Worker bedient nur den Bereich, unter dem
|   er ausgeliefert wird.
|
| Ein Produkt bindet ihn so ein:
|
|     Route::middleware('web')
|         ->group(base_path('vendor/peppermint/notifications/routes/push-worker.php'));
|
*/

Route::get('push-sw.js', PushServiceWorkerController::class)->name('push.worker');
