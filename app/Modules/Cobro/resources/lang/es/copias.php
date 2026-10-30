<?php

// Carbon copies of the weekly collection and the monthly circularization
// mails, per company.
return [

    'titulo' => 'Copias del cobro semanal y la circularización',
    'descripcion' => 'Elige a quién se le envía copia de cada correo de cobro semanal y de la circularización mensual de la empresa. La prueba que te envías no lleva copias.',
    'empresas' => 'Empresas',

    'estado' => [
        'por_defecto' => 'Sin copias guardadas: los envíos salen sin copia. Guárdalas antes del primer envío.',
        'editada' => 'Editadas por :nombre el :fecha',
    ],

    'campos' => [
        'fijos' => 'Siempre con copia a',
        'ayuda_correos' => 'Escribe un correo por línea o sepáralos con comas.',
        'ayuda_dominios' => 'Solo se aceptan correos de :dominios.',
        'asesor' => 'Copiar al asesor del cliente',
        'ayuda_asesor' => 'Se usa el correo del asesor registrado en SAP, cuando lo tiene.',
    ],

    'gerentes' => [
        'titulo' => 'Gerentes por grupo',
        'descripcion' => 'Cada gerente recibe copia de los correos de los clientes de su grupo. Escribe el grupo tal como aparece en el campo «Grupo» del cliente en SAP, por ejemplo DISTRIBUCION o CONS. RESIDENCIAL; no importan mayúsculas, tildes ni espacios.',
        'ayuda_vacias' => 'Deja una fila en blanco si no la necesitas.',
        'grupo' => 'Grupo de SAP (fila :numero)',
        'correos' => 'Correos del gerente (fila :numero)',
    ],

    'acciones' => [
        'guardar' => 'Guardar copias',
        'restaurar' => 'Quitar las copias guardadas',
    ],

    'mensajes' => [
        'guardadas' => 'Guardaste las copias de :empresa.',
        'restauradas' => 'Quitaste las copias de :empresa: los envíos saldrán sin copia.',
    ],

    'validacion' => [
        'invalidos' => 'Estos correos no son válidos: :correos.',
        'dominio' => 'Solo se permiten copias a correos de :dominios: :correos.',
        'grupo_obligatorio' => 'Escribe el grupo de SAP de estos correos.',
        'correos_obligatorios' => 'Escribe al menos un correo para este grupo.',
        'fijos' => 'siempre con copia a',
        'grupo' => 'grupo',
        'correos' => 'correos',
    ],

];
