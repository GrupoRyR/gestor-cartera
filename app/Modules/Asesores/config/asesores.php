<?php

// Asesores settings, merged by AsesoresServiceProvider under the "asesores" key:
// read them with config('asesores.<setting>'). Only this file calls env().
return [

    // Companies whose payment commitments are written by the advisor. In SDC
    // the portfolio analyst writes them (Part B, module 2, item 7). Core's
    // CompromisoService is the authority; this list only decides what the
    // screens offer.
    'commitment_companies' => ['SGX'],

    // A receivables cutoff older than this many days shows a warning: the
    // robot should publish every working day.
    'stale_after_days' => 2,

    // Rows per page in the client list.
    'per_page' => 25,

    // Days of history the trend chart and the history page can show, and the
    // default of each.
    'history' => [
        'ranges' => [30, 60, 90, 180, 365],
        'dashboard_days' => 60,
        'page_days' => 90,
    ],

    // The WhatsApp button (a wa.me link the advisor reviews and sends).
    'whatsapp' => [
        'country_code' => '57',
        'max_invoices' => 12,
    ],

];
