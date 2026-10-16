<?php

use Illuminate\Support\Facades\Route;
use Peppermint\Notifications\Http\Controllers\PreferenceController;

/*
|--------------------------------------------------------------------------
| Die Endpunkte hinter der Einstellungsmaske
|--------------------------------------------------------------------------
|
| Ein Produkt bindet sie ein:
|
|     Route::middleware(['web', 'auth'])
|         ->prefix('api/notification-preferences')
|         ->name('notification-preferences.')
|         ->group(base_path('vendor/peppermint/notifications/routes/preferences.php'));
|
| Keine Platzhalter-Route: Gespeichert wird eine ZEILE der Matrix, und die
| Art steht im Rumpf. Ein Schluessel wie `task.assigned` im Pfad waere der
| naechste Streit mit der Routen-Aufloesung — der Punkt darin sieht fuer sie
| aus wie eine Dateiendung.
|
*/

Route::get('/', [PreferenceController::class, 'index'])->name('index');
Route::post('/', [PreferenceController::class, 'update'])->name('update');
