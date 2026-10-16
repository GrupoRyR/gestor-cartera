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

];
