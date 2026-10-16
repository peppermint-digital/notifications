<?php

use Illuminate\Support\Facades\Route;
use Peppermint\Notifications\Http\Controllers\NotificationController;

/*
|--------------------------------------------------------------------------
| Die Endpunkte hinter Glocke und Posteingang
|--------------------------------------------------------------------------
|
| Optional. Ein Produkt bindet sie ein:
|
|     Route::middleware(['web', 'auth'])
|         ->prefix('notifications')
|         ->name('notifications.')
|         ->group(base_path('vendor/peppermint/notifications/routes/notifications.php'));
|
| oder schreibt eigene — der Controller ist nur eine duenne Schicht ueber
| NotificationFeed und NotificationActions.
|
| ## Die Falle, deretwegen es diese Datei gibt
|
| Alle Mengen-Endpunkte stehen VOR einer etwaigen Route mit einem
| Platzhalter (`/{notification}`). Steht die Platzhalter-Route zuerst,
| schluckt die Modellbindung `read-many` und `destroy-many` als Kennung —
| der Aufruf landet auf der falschen Methode und scheitert mit einem
| 404 auf eine Meldung namens „destroy-many".
|
| Im Manager steht genau deshalb ein Kommentar in routes/web.php. Hier gibt
| es gar keine Platzhalter-Route mehr: Jede Aktion nimmt ohnehin eine LISTE
| von Kennungen, weil hinter einer gebuendelten Zeile mehrere Datensaetze
| stecken. Damit kann die Falle nicht wiederkommen.
|
*/

Route::get('/', [NotificationController::class, 'index'])->name('index');
Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');

Route::post('/read', [NotificationController::class, 'markRead'])->name('read');
Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
Route::post('/unread', [NotificationController::class, 'markUnread'])->name('unread');

Route::delete('/', [NotificationController::class, 'destroy'])->name('destroy');
Route::delete('/all', [NotificationController::class, 'destroyAll'])->name('destroy-all');
