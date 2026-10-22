<?php

// E-mails the module sends. Their readers are outside the app (payroll,
// accounting), so the body is neutral professional Spanish, not «tú».
return [
    // ApprovalMail: a settlement was approved; the consolidated PDF goes attached.
    'aprobacion' => [
        'asunto' => 'Liquidación de comisiones aprobada – :mes',
        'titulo' => 'Liquidación de comisiones aprobada',
        'intro' => 'La liquidación de comisiones de :mes fue aprobada en el gestor de cartera.',
        'periodo' => 'Período',
        'aprobada_por' => 'Aprobada por',
        'aprobada_el' => 'Fecha de aprobación',
        'total_regular' => 'Total equipo regular',
        'total_nomina' => 'Total para nómina',
        'adjunto' => 'Se adjunta el consolidado de esta versión en PDF (:archivo).',
        'enlace' => 'Ver la liquidación en el gestor de cartera',
        'pie' => 'Este correo se envió automáticamente al aprobar la liquidación. La versión aún no está cerrada.',
    ],
];
