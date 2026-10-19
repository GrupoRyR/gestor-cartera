<?php

// Missing robot load alert (ingesta:verificar-carga): the e-mail and the
// console lines, read with __('ingesta::alerta.<key>').
return [

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
