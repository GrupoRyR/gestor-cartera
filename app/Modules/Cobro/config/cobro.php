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
        // Seconds to wait for Graph on each request.
        'timeout' => 30,
    ],

    // Mailbox each company sends from and reads replies in. An empty variable
    // falls back to the official mailbox too.
    'mailboxes' => [
        'SGX' => env('COBRO_MAILBOX_SGX') ?: 'segurex.info@segurex.com',
        'SDC' => env('COBRO_MAILBOX_SDC') ?: 'derivados.info@derivadoscol.com',
    ],

    // Inbox synchronization (cobro:sincronizar-buzones, every five minutes).
    'sync' => [
        // The first synchronization of a mailbox reads the messages received
        // in the last days only.
        'initial_days' => 30,
        // Graph pages read per mailbox and run; the rest waits for the next
        // run, so a run stays short on shared hosting.
        'max_pages' => 10,
    ],

    // Weekly collection of overdue invoices (4.1): Wednesdays at 17:00,
    // Bogotá time. Only SGX in phase 1 (Part D, item 12).
    'weekly' => [
        'companies' => ['SGX'],
        'day' => 3,
        'time' => '17:00',
    ],

];
