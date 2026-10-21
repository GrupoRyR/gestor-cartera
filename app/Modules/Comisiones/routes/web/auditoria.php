<?php

use App\Modules\Comisiones\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

// The audit trail of the module (§7.9, §10): read only, the menu entry
// «Auditoría» (ComisionesServiceProvider::navigation()).

Route::middleware(['auth', 'can:comisiones.audit'])->group(function () {
    Route::get('auditoria', AuditController::class)->name('auditoria.index');
});
