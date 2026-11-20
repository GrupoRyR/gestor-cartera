<?php

// Who signs each company's Cobro mails.
return [

    'titulo' => 'Firma del correo',
    'descripcion' => 'Los datos de quien firma el cobro semanal, la circularización y las respuestas de cada empresa. Cámbialos cuando otra persona lleve la cartera; la razón social, la dirección, el PBX y el correo de la empresa no cambian.',
    'empresas' => 'Empresas',

    'estado' => [
        'por_defecto' => 'Datos por defecto',
        'editada' => 'Editada por :nombre el :fecha',
    ],

    'campos' => [
        'nombre' => 'Nombre',
        'cargo' => 'Cargo',
        'movil' => 'Móvil',
        'ayuda_movil' => 'Con el indicativo si quieres, por ejemplo +57 315 365 4788.',
        'extension' => 'Extensión del PBX',
        'ayuda_extension' => 'Solo el número. Déjala vacía si no tiene extensión.',
    ],

    'acciones' => [
        'guardar' => 'Guardar la firma',
        'restaurar' => 'Volver a los datos por defecto',
    ],

    'mensajes' => [
        'guardada' => 'Guardaste la firma de :empresa.',
        'restaurada' => 'La firma de :empresa volvió a los datos por defecto.',
    ],

    'validacion' => [
        'nombre' => 'Escribe el nombre de quien firma.',
        'cargo' => 'Escribe el cargo de quien firma.',
        'largo' => 'Usa hasta 100 caracteres.',
        'movil' => 'Escribe un móvil válido, por ejemplo +57 315 365 4788.',
        'extension' => 'La extensión solo lleva números, hasta 6.',
    ],

];
