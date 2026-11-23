<?php

use App\Modules\Soportes\Http\Controllers\InboxController;
use App\Modules\Soportes\Http\Controllers\ReceiptController;
use App\Modules\Soportes\Http\Controllers\UploadController;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use Illuminate\Support\Facades\Route;

// Soportes web routes. SoportesServiceProvider adds the "web" middleware,
// the /soportes URL prefix and the "soportes." route-name prefix:
// Route::get('nuevo', ...)->name('create')
// becomes GET /soportes/nuevo named soportes.create.

// The inbox (ReceiptPolicy::viewAny: soportes.view), and one receipt with
// its files (ReceiptPolicy::view).
Route::middleware(['auth', 'can:viewAny,'.Receipt::class])->group(function () {
    Route::get('/', [InboxController::class, 'index'])->name('index');

    Route::middleware('can:view,soporte')->group(function () {
        Route::get('{soporte}', [ReceiptController::class, 'show'])->whereNumber('soporte')->name('show');
        Route::get('{soporte}/archivos/{archivo}', [ReceiptController::class, 'download'])
            ->whereNumber(['soporte', 'archivo'])->middleware('throttle:60,1')->name('files.download');
    });
});

// Upload of a payment receipt (ReceiptPolicy::create: soportes.upload).
// Step 1 picks an overdue client; step 2 (?cliente={id}) is the form.
Route::middleware(['auth', 'can:create,'.Receipt::class])->group(function () {
    Route::get('nuevo', [UploadController::class, 'create'])->name('create');
    Route::post('/', [UploadController::class, 'store'])->middleware('throttle:20,1')->name('store');
});
