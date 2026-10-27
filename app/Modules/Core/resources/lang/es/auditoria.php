<?php

// Audit chain check (core:verificar-auditoria): the alert e-mail and the
// console lines, read with __('core::auditoria.<key>').
return [

    'asunto' => 'Alerta: la auditoría del gestor de cartera no pasó la verificación del :fecha',

    // Why the check failed; only ids and counts, never the contents of an entry.
    'motivos' => [
        'missing_hash' => 'la entrada no tiene hash: se agregó por fuera de la plataforma',
        'hash_mismatch' => 'el hash no coincide: la entrada se modificó, o se borró o insertó una entrada antes de ella',
        'head_mismatch' => 'la cabeza de la cadena no apunta a la última entrada: se borraron o agregaron entradas al final',
        'checkpoint_mismatch' => 'la última entrada verificada en la revisión anterior ya no existe o cambió: se recortó o reescribió el final',
        'checkpoint_unreadable' => 'no se pudo leer el punto de control de la revisión anterior',
    ],

    'correo' => [
        'titulo' => 'La auditoría no pasó la verificación',
        'intro' => 'La revisión del :fecha a las :hora (hora de Bogotá) encontró que la cadena de hashes de la auditoría está rota. Alguien pudo cambiar, borrar o agregar entradas directamente en la base de datos.',
        'entrada' => 'Primera entrada con problema',
        'entrada_detalle' => 'Id :id: :motivo.',
        'sin_entrada' => 'Ninguna en particular: :motivo.',
        'revisadas' => '{0} No alcanzó a verificar ninguna entrada antes del problema.|{1} Verificó 1 entrada antes del problema.|[2,*] Verificó :total entradas antes del problema.',
        'que_hacer' => 'Qué hacer',
        'pasos' => 'No borres ni corrijas nada en la tabla audit_logs. Revisa quién tuvo acceso a la base (phpMyAdmin, cPanel, copias de seguridad) y compara las entradas desde ese id con la última copia de seguridad. La revisión seguirá fallando cada día hasta que se aclare.',
        'pie' => 'Este aviso lo envía el gestor de cartera cuando la revisión diaria de la auditoría falla.',
    ],

    'consola' => [
        'integra' => '{0} La auditoría está vacía: no hay entradas para verificar.|{1} La cadena de la auditoría está íntegra: 1 entrada verificada, la última es la :id.|[2,*] La cadena de la auditoría está íntegra: :total entradas verificadas, la última es la :id.',
        'fallo' => 'La auditoría no pasó la verificación en la entrada :id: :motivo.',
        'fallo_sin_entrada' => 'La auditoría no pasó la verificación: :motivo.',
        'sin_destinatarios' => 'No hay destinatarios configurados para la alerta (CORE_AUDIT_ALERT_RECIPIENTS): el fallo solo queda en el log.',
        'enviada' => 'Se envió la alerta a :destinatarios.',
        'sin_mail_sender' => 'No se pudo enviar la alerta: MailSender sin implementación.',
        'no_enviada' => 'No se pudo enviar la alerta; el error quedó en el log.',
    ],

];
