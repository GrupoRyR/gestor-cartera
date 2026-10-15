<?php

// Bancos settings, merged by BancosServiceProvider under the "bancos" key:
// read them with config('bancos.<setting>'). Only this file calls env().
return [

    // Folder the importer reads dropped bank files from.
    'import_path' => env('BANCOS_IMPORT_PATH', 'bancos/entrada'),

    // Token Treasury sends to the read-only collections API (GET /api/recaudo).
    'api_token' => env('BANCOS_API_TOKEN'),

];
