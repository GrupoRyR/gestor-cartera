<?php

// Core settings, merged by CoreServiceProvider under the "core" key:
// read them with config('core.<setting>'). Only this file calls env().
return [

    // Seed defaults for the company parameters of the client statuses. The
    // live values are company parameters that admins edit; read those, not
    // these. grace_days: days after an invoice's due date before it counts
    // as overdue for the status. due_soon_days: days before the due date
    // that make a client "due soon".
    'companies' => [
        'SGX' => ['grace_days' => 15, 'due_soon_days' => 5],
        'SDC' => ['grace_days' => 5, 'due_soon_days' => 5],
    ],

    // Only e-mails of these domains are matched with SAP advisors at sign-in,
    // and so only they can get the "asesor" role on their own (audit S-17).
    // The domain must be exactly one of these: no subdomains.
    'auth' => [
        'company_domains' => ['segurex.com', 'derivadoscol.com'],
    ],

    // Who receives the alert of core:verificar-auditoria when the hash chain
    // of the audit trail is broken, and the notice of an "asesor" role given
    // at sign-in (IT and internal control), from a comma-separated list.
    'audit' => [
        'alert_recipients' => array_values(array_filter(
            array_map(trim(...), explode(',', (string) env('CORE_AUDIT_ALERT_RECIPIENTS', ''))),
            fn (string $recipient): bool => $recipient !== '',
        )),
    ],

];
