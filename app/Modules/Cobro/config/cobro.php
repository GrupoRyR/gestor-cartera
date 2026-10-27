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

    // Who receives the notice with the differences each time a company's
    // weekly collection template is saved with other texts or restored
    // (audit S-08): the same comma-separated list as Core's audit chain
    // alert (IT and internal control).
    'template_alert_recipients' => array_values(array_filter(
        array_map(trim(...), explode(',', (string) env('CORE_AUDIT_ALERT_RECIPIENTS', ''))),
        fn (string $recipient): bool => $recipient !== '',
    )),

    // Collection mails handed to Graph per minute across both mailboxes;
    // Exchange Online accepts 30 a minute per mailbox.
    'send_per_minute' => 25,

    // Weekly collection of overdue invoices (4.1): Wednesdays at 17:00,
    // Bogotá time, for both companies, each with its own template and copies.
    'weekly' => [
        'companies' => ['SGX', 'SDC'],
        'day' => 3,
        'time' => '17:00',
    ],

    // Carbon copies (Cc) of the weekly collection and the monthly
    // circularization mails of each company, until someone saves them in
    // /cobro/copias: fixed addresses, the
    // client's advisor, and the sales managers of each SAP group
    // (OCRG.GroupName, compared without regard to case or accents).
    'copias' => [
        'SGX' => [
            'fixed' => ['miguel.martinez@segurex.com'],
            'advisor' => true,
            'managers' => [
                ['group' => 'DISTRIBUCION', 'emails' => ['jessika.quintero@segurex.com']],
                ['group' => 'CONS. RESIDENCIAL', 'emails' => ['andrea.hincapie@segurex.com']],
            ],
        ],
        // SDC has a single advisor (Diana Mora), already a fixed copy, and
        // no sales managers by group.
        'SDC' => [
            'fixed' => ['miguel.martinez@derivadoscol.com', 'diana.mora@derivadoscol.com', 'servicioalcliente@derivadoscol.com'],
            'advisor' => false,
            'managers' => [],
        ],
    ],

    // The only domains a carbon copy may go to, for both companies: an exact
    // match without regard to case (no subdomains). /cobro/copias rejects
    // any other address, and the send drops any other copy (an address
    // saved before this rule, or a client's advisor with another domain).
    'copy_domains' => ['segurex.com', 'derivadoscol.com'],

    // Monthly circularization at close (4.2): the 1st at 09:00, Bogotá
    // time, for both companies, in the format each one sends today. The
    // robot dates each snapshot with the day it extracts it (about 19:00),
    // so at that hour the latest cutoff is the last day of the closed
    // month. The mail copies (Cc) the weekly collection's copies above.
    // "draft" off: the scheduled run really sends. Turned on, it would hold
    // the scheduled run back and mark the mails "Borrador" (for a future
    // format under review); the preview and the test send work either way.
    'circularizacion' => [
        'companies' => ['SGX', 'SDC'],
        'day' => 1,
        'time' => '09:00',
        'draft' => false,
    ],

];
