<?php

// Cobro settings, merged by CobroServiceProvider under the "cobro" key:
// read them with config('cobro.<setting>'). Only this file calls env().
return [

    // Microsoft Graph app registration that sends from and reads the company
    // mailboxes (application permissions limited to those mailboxes).
    'graph' => [
        'tenant_id' => env('GRAPH_TENANT_ID'),
        'client_id' => env('GRAPH_CLIENT_ID'),
        'client_secret' => env('GRAPH_CLIENT_SECRET'),
    ],

    // Mailbox each company sends from and reads replies in. An empty variable
    // falls back to the official mailbox too.
    'mailboxes' => [
        'SGX' => env('COBRO_MAILBOX_SGX') ?: 'segurex.info@segurex.com',
        'SDC' => env('COBRO_MAILBOX_SDC') ?: 'derivados.info@derivadoscol.com',
    ],

];
