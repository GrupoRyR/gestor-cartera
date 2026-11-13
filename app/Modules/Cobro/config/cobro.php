<?php

// Cobro settings, merged by CobroServiceProvider under the "cobro" key:
// read them with config('cobro.<setting>'). Only this file calls env().

// A comma-separated list of domains, trimmed and in lower case, without
// empty entries.
$domains = static fn (mixed $list): array => array_values(array_filter(
    array_map(fn (string $domain): string => mb_strtolower(trim($domain)), explode(',', is_string($list) ? $list : '')),
    fn (string $domain): bool => $domain !== '',
));

return [

    // Microsoft Graph app registration that sends from and reads the company
    // mailboxes (application permissions limited to those mailboxes).
    'graph' => [
        'tenant_id' => env('GRAPH_TENANT_ID'),
        'client_id' => env('GRAPH_CLIENT_ID'),
        'client_secret' => env('GRAPH_CLIENT_SECRET'),
        // Seconds to wait for Graph on each request.
        'timeout' => 30,
        // A company whose mailbox lives in another tenant (Derivados) uses
        // its own app registration. It applies only when its three values
        // are set; otherwise the company uses the default credential above.
        'companies' => [
            'SGX' => [
                'tenant_id' => env('GRAPH_SGX_TENANT_ID'),
                'client_id' => env('GRAPH_SGX_CLIENT_ID'),
                'client_secret' => env('GRAPH_SGX_CLIENT_SECRET'),
            ],
            'SDC' => [
                'tenant_id' => env('GRAPH_SDC_TENANT_ID'),
                'client_id' => env('GRAPH_SDC_CLIENT_ID'),
                'client_secret' => env('GRAPH_SDC_CLIENT_SECRET'),
            ],
        ],
    ],

    // Mailbox each company sends from and reads replies in. An empty variable
    // falls back to the official mailbox too.
    'mailboxes' => [
        'SGX' => env('COBRO_MAILBOX_SGX') ?: 'segurex.info@segurex.com',
        'SDC' => env('COBRO_MAILBOX_SDC') ?: 'derivados.info@derivadoscol.com',
    ],

    // Domains of the Microsoft 365 tenant (for example
    // gruporyr.onmicrosoft.com), comma-separated in COBRO_TENANT_DOMAINS.
    // They count as ours only for the sender of our Exchange's own delivery
    // reports (postmaster@… or MicrosoftExchange…@, audit S-24), never as a
    // company mailbox or a copy domain. Empty: only the mailbox domains.
    'tenant_domains' => $domains(env('COBRO_TENANT_DOMAINS')),

    // Tenant domains of one company only, for a company whose mailbox lives
    // in its own tenant (COBRO_TENANT_DOMAINS_SGX, COBRO_TENANT_DOMAINS_SDC,
    // comma-separated). They are added to tenant_domains for that company's
    // mailbox only, never for the other company's.
    'company_tenant_domains' => [
        'SGX' => $domains(env('COBRO_TENANT_DOMAINS_SGX')),
        'SDC' => $domains(env('COBRO_TENANT_DOMAINS_SDC')),
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
    // /cobro/copias: fixed addresses, the client's advisor, and the sales
    // managers of each SAP group (OCRG.GroupName, compared without regard to
    // case or accents). None by default, so no employee's address lives in
    // the code (audit S-31): save them in /cobro/copias before the first
    // run. A run that starts without saved copies logs cobro.copies.not_saved.
    'copias' => [
        'SGX' => ['fixed' => [], 'advisor' => false, 'managers' => []],
        'SDC' => ['fixed' => [], 'advisor' => false, 'managers' => []],
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
