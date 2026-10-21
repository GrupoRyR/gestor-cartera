<?php

// Bancos settings, merged by BancosServiceProvider under the "bancos" key:
// read them with config('bancos.<setting>'). Only this file calls env().
return [

    // Folder the importer reads dropped bank files from. Relative to the
    // "local" disk (storage/app/private) unless it is an absolute path.
    // Layout: <folder>/<SGX|SDC>/<bank>/<file>.(csv|txt|xlsx|xls)
    'import_path' => env('BANCOS_IMPORT_PATH', 'bancos/entrada'),

    // Time (America/Bogota) the daily command picks up the folder: the
    // statements of yesterday are there in the morning (día vencido).
    'import_at' => env('BANCOS_IMPORT_AT', '06:30'),

    // Token Treasury sends to the read-only collections API (GET /api/recaudo).
    'api_token' => env('BANCOS_API_TOKEN'),

    // Token Treasury sends to push movements (POST /api/movimientos). Apart from
    // the read token so that a leaked read token cannot write.
    'ingest_token' => env('BANCOS_INGEST_TOKEN'),

    // Currency of each account when it differs from what the reader of the bank
    // assumes (null = the reader's: COP, and USD for the Miami account).
    'currencies' => [
        'bogota' => null,
        'davivienda' => null,
        'bancolombia' => null,
        'miami' => env('BANCOS_MIAMI_CURRENCY'),
    ],

    // Only credits in this currency add up to the collections (recaudo, in
    // pesos). Movements in another currency are stored and listed, but there is
    // no exchange rate to convert them.
    'collection_currency' => 'COP',

];
