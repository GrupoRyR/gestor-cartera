<?php

// Comisiones settings, merged by ComisionesServiceProvider under the "comisiones" key:
// read them with config('comisiones.<setting>'). Only this file calls env().
return [
    // Supports of the manual captures (OBR, adjustments, manual crossings, reassignments).
    'supports_disk' => 'local',
    'supports_directory' => 'comisiones/soportes',

    // Reports generated from a closed settlement (collection PDFs, consolidated, payroll file).
    'documents_disk' => 'local',
    'documents_directory' => 'comisiones/informes',

    // Approving a settlement mails its consolidated PDF from the Segurex
    // mailbox to these addresses, from the comma-separated
    // COMISIONES_APPROVAL_RECIPIENTS (audit S-31). Without any, the approval
    // still goes through and the mail is logged and audited as not sent.
    'approval_mail' => [
        'recipients' => array_values(array_filter(
            array_map(trim(...), explode(',', (string) env('COMISIONES_APPROVAL_RECIPIENTS', ''))),
            fn (string $recipient): bool => $recipient !== '',
        )),
    ],
];
