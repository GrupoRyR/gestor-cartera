<?php

// Comisiones web routes. ComisionesServiceProvider adds the "web" middleware,
// the /comisiones URL prefix and the "comisiones." route-name prefix:
// Route::get('/', ...)->name('index')
// becomes GET /comisiones named comisiones.index.
//
// Each area of the module keeps its routes in routes/web/<area>.php, so the
// screens of one area are added without touching another area's file. A
// missing file is skipped: the area is not built yet.
foreach (['liquidacion', 'presupuesto', 'ajustes', 'parametros', 'informes', 'auditoria'] as $area) {
    if (is_file($file = __DIR__."/web/{$area}.php")) {
        require $file;
    }
}
