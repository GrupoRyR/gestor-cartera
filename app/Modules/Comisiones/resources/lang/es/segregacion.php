<?php

// Segregation of duties (ApprovalGuard, §10).
return [
    'rechazada' => 'No puedes aprobar la liquidación de :periodo: quien carga datos o cambia parámetros del período no la aprueba.',
    'presupuesto' => 'Cargaste o editaste el presupuesto de :periodo.',
    'obr' => 'Registraste ventas OBR de :periodo.',
    'ajustes' => 'Registraste reintegros o descuentos de :periodo.',
    'cruces' => 'Registraste cruces manuales de :periodo.',
    'reasignaciones' => 'Registraste reasignaciones de cruces de :periodo.',
    'parametros' => 'Agregaste versiones de parámetros vigentes en :periodo.',
];
