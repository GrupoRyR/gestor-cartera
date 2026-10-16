<?php

// Cartera settings, merged by CarteraServiceProvider under the "cartera" key:
// read them with config('cartera.<setting>'). Only this file calls env().
return [
    // An overdue client counts as "unmanaged" (the dashboard figure and the
    // list filter) when nobody logged a call, note or commitment in this many
    // days. Optional: not listed in .env.example.
    'sin_gestion_dias' => (int) env('CARTERA_SIN_GESTION_DIAS', 7),

    // Cards shown per Kanban column. A column's count and total always cover
    // all its clients; the cap only keeps the page light.
    'kanban_max_por_columna' => 40,

    // Rows per page in the client list and the commitments inbox.
    'por_pagina' => 25,
];
