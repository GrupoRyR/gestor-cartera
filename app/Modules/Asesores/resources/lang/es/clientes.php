<?php

return [

    'titulo' => 'Clientes',
    'descripcion' => 'Tus clientes con saldo, de los que más deben a los que menos.',

    'buscar' => [
        'etiqueta' => 'Buscar cliente',
        'ayuda' => 'Nombre, NIT o código del cliente',
        'boton' => 'Buscar',
    ],
    'filtros' => [
        'estado' => 'Estado',
        'todos_estados' => 'Todos los estados',
        'vencidos' => 'Solo clientes con saldo vencido',
        'limpiar' => 'Quitar filtros',
    ],

    'resumen' => '{1} :clientes cliente · saldo :saldo · vencido :vencido|[0,*] :clientes clientes · saldo :saldo · vencido :vencido',

    'tabla' => 'Clientes con saldo',
    'columnas' => [
        'cliente' => 'Cliente',
        'estado' => 'Estado',
        'asesor' => 'Asesor',
        'vencido' => 'Vencido',
        'dias' => 'Días de mora',
        'saldo' => 'Saldo',
        'gestion' => 'Última gestión',
    ],
    'dias' => ':dias días',
    'cliente_detalle' => ':codigo · NIT :nit',

    'vacio' => [
        'titulo' => 'Aún no tienes clientes con saldo',
        'detalle' => 'Cuando el robot publique tu cartera, tus clientes aparecerán aquí.',
        'filtrado_titulo' => 'Ningún cliente coincide',
        'filtrado_detalle' => 'Cambia la búsqueda o quita algún filtro para ver más clientes.',
    ],

];
