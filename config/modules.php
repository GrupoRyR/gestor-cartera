<?php

/*
|--------------------------------------------------------------------------
| Modules
|--------------------------------------------------------------------------
|
| Reserved date prefix for each module's migrations (docs/prompt-gestor-
| cartera.md, Part C.6). Every module loads its own migrations, so the
| prefix keeps their order stable and their file names free of collisions.
| tests/Architecture/ModulesTest.php fails on a migration without it.
|
*/

return [

    'migration_prefixes' => [
        'Core' => '2026_10_01',
        'Ingesta' => '2026_10_02',
        'Cartera' => '2026_10_03',
        'Asesores' => '2026_10_04',
        'Cobro' => '2026_10_05',
        'Bancos' => '2026_10_06',
        'Gerencia' => '2026_10_07',
        'Comisiones' => '2026_10_08',
    ],

];
