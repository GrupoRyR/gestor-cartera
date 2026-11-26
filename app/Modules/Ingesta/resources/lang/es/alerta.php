<?php

// Missing robot load alert (ingesta:verificar-carga): the e-mail and the
// console lines, read with __('ingesta::alerta.<key>'). Under "falla", the
// e-mail of a load that failed for good (SendLoadFailureAlert); under
// "campana", its bell notice (LoadNotices), whose body is the cause text.
return [

    'campana' => [
        'titulo' => 'Falló la carga de :lote de :empresa de las :hora',
        'no_llego' => 'No llegó la carga de :lote de :empresa de las :hora',
    ],

    // ingesta:vigilar-carga (WatchLoadSlot): neither a load nor a final
    // report arrived for a slot. The cause is chosen by
    // ingesta.expected_source; "antes_de" is added when the slot has it.
    'no_llego' => [
        'asunto' => 'Alerta: no llegó la carga de :lote de :empresa de las :hora del :fecha',
        'titulo' => 'No llegó la carga de :lote de :empresa',
        'intro' => 'A las :revision del :fecha (hora de Bogotá) no había llegado la carga de :lote de :empresa programada para las :hora, ni un reporte de falla.',
        'que_paso' => 'Qué pasó',
        'causas' => [
            'robot' => 'No llegó la carga de las :hora ni un reporte del robot: el robot no corrió (¿PC apagado, sin sesión o sin internet?).',
            'bases_sap' => 'No llegó la carga de las :hora: el lector de Bases SAP no corrió.',
        ],
        'antes_de' => 'Si no llega a tiempo, :antes_de saldrá con los datos anteriores.',
        'enlace' => 'Ver el historial de cargas',
        'pie' => 'Este aviso lo envía el gestor de cartera una sola vez por carga programada, cuando no llegó ni la carga ni un reporte de falla.',
        'consola' => [
            'no_programada' => ':hora no es una hora programada de carga (etl/horarios.json); usa una de estas: :horas.',
            'no_hoy' => 'Hoy no hay carga programada a las :hora: no se revisa nada.',
            'antes_de_la_hora' => 'Aún no son las :hora (hora de Bogotá): no se revisa la carga.',
            'llego' => ':empresa: llegó la carga de las :hora.',
            'reportada' => ':empresa: el robot reportó la falla de la carga de las :hora; ya se avisó.',
            'enviada' => ':empresa: no llegó la carga de las :hora; se envió la alerta a :destinatarios.',
            'ya_enviada' => ':empresa: no llegó la carga de las :hora; la alerta ya se había enviado.',
            'sin_destinatarios' => ':empresa: no llegó la carga de las :hora, pero no hay destinatarios configurados (INGESTA_ALERT_RECIPIENTS).',
            'sin_mail_sender' => ':empresa: no llegó la carga de las :hora y no se pudo enviar la alerta: MailSender sin implementación.',
            'no_enviada' => ':empresa: no llegó la carga de las :hora y no se pudo enviar la alerta.',
        ],
    ],

    'falla' => [
        'asunto' => 'Alerta: falló la carga de :lote de :empresa de las :hora del :fecha',
        'titulo' => 'Falló la carga de :lote de :empresa',
        'intro' => 'La carga de :lote de :empresa de las :hora del :fecha (hora de Bogotá) falló y no se va a volver a intentar.',
        'que_paso' => 'Qué pasó',
        'causas' => [
            'conexion' => 'La carga de cartera no se pudo sacar de SAP: falló la conexión, ni después de :attempts intentos. Revisa en el PC de TI que la VPN Sophos conecte y vuelve a correr la tarea CarteraWeb-ETL.',
            'red' => 'La carga salió de SAP pero no llegó a la plataforma. Revisa el internet del PC de TI.',
            'credenciales' => 'SAP o la VPN rechazaron el usuario o la clave; actualízalos en el PC de TI.',
            'datos' => 'SAP devolvió datos con un formato inesperado; no se publicó nada para no dañar la cartera. Avisa a TI.',
            'otra' => 'La carga falló en la etapa de :etapa. Revisa el mensaje de abajo y, si no sabes qué hacer, avisa a TI.',
        ],
        'detalle' => 'Detalle',
        'etapa' => 'Etapa: :etapa',
        'intento' => 'Intento: :intento de :maximo',
        'cuando' => 'Cuándo: :cuando',
        'mensaje' => 'Mensaje: :mensaje',
        'etapas' => [
            'CONFIGURACION' => 'lectura de la configuración',
            'INICIO' => 'inicio de la corrida',
            'VPN' => 'conexión a la VPN',
            'HANA' => 'consulta a SAP',
            'VALIDACION' => 'revisión de los datos',
            'RESPALDO' => 'copia de respaldo',
            'CONTRATO' => 'revisión contra el esquema',
            'LARAVEL' => 'envío a la plataforma',
            'SUPABASE' => 'publicación en Supabase',
        ],
        'enlace' => 'Ver el historial de cargas',
        'pie' => 'Este aviso lo envía el gestor de cartera una sola vez por carga, cuando ya no quedan intentos.',
    ],

    'asunto' => 'Alerta: el robot no publicó la cartera de :empresa del :fecha',

    'correo' => [
        'titulo' => 'El robot no publicó la cartera de :empresa',
        'intro' => 'A las :hora del :fecha (hora de Bogotá) no había llegado la carga de cartera del robot de SAP para :empresa con corte de hoy.',
        'ultima' => 'Última cartera aceptada',
        'ultima_detalle' => 'Corte :corte, origen :origen, recibida el :recibida.',
        'sin_ultima' => 'Aún no hay ninguna carga de cartera aceptada de esta empresa.',
        'rechazo' => 'Rechazo del robot hoy',
        'rechazo_detalle' => 'El robot envió la cartera a las :hora y se rechazó: :error',
        'sin_rechazo' => 'Hoy no llegó ninguna carga de cartera del robot, ni siquiera rechazada: revisa el equipo del robot, la VPN y el Programador de tareas.',
        'manual' => 'Carga manual',
        'con_manual' => 'Sí hay una carga manual de cartera con corte de hoy: la plataforma está al día, pero el robot igual necesita revisión.',
        'sin_manual' => 'Tampoco hay una carga manual con corte de hoy. Si el robot no se puede arreglar pronto, sube el Excel de cartera en la carga manual.',
        'enlace' => 'Ver el historial de cargas',
        'pie' => 'Este aviso lo envía el gestor de cartera cada día a la hora límite cuando falta la carga del robot.',
    ],

    'consola' => [
        'antes_del_limite' => 'Aún no son las :hora (hora de Bogotá), la hora límite: no se revisa la carga ni se envía la alerta.',
        'llego' => ':empresa: la cartera del robot llegó a tiempo.',
        'sin_destinatarios' => ':empresa: falta la cartera del robot, pero no hay destinatarios configurados (INGESTA_ALERT_RECIPIENTS).',
        'enviada' => ':empresa: falta la cartera del robot; se envió la alerta a :destinatarios.',
        'sin_mail_sender' => ':empresa: falta la cartera del robot y no se pudo enviar la alerta: MailSender sin implementación.',
        'no_enviada' => ':empresa: falta la cartera del robot y no se pudo enviar la alerta.',
    ],

];
