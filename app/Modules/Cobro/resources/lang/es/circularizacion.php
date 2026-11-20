<?php

// Circularization (4.2): console messages of cobro:circularizacion, and the
// fixed blocks of the Segurex statement mail, word for word as the company
// sends it today (client-facing: they keep "usted"), in sentence case as
// brand v2 asks.
return [

    // A company held back because its loaded cartera is not the month close.
    'consola' => [
        'sin_cierre' => 'La circularización de :empresa no se envió: todavía no llega la foto del cierre del :cierre. '
            .'Cuando llegue, ejecuta php artisan cobro:circularizacion --empresa=:codigo. '
            .'Si no va a llegar, agrega --con-corte-actual para enviarla con la última carga.',
    ],

    'segurex' => [
        // Under the invoice table. :dia_mes, :mes_siguiente and :mes_corte
        // are written as given, in lowercase.
        'aviso_cierre' => '"Aclaramos que este estado de cartera corresponde al cierre del :dia_mes. Si realizó el pago durante el mes de :mes_siguiente, '
            .'tenga en cuenta que dicho pago no se ve reflejado en esta circularización, ya que la información corresponde exclusivamente al cierre de '
            .':mes_corte. Agradecemos su atención."',
    ],

];
