"""Aviso por correo cuando el robot falla. Opcional: si no hay SMTP, no hace nada."""
from __future__ import annotations

import smtplib
import socket
import ssl
from email.message import EmailMessage

from config import AlertaConfig
from registro import obtener, registrar_secreto

log = obtener("alerta")


def avisar_fallo(cfg: AlertaConfig, etapa: str, error: str, detalle: str = "") -> None:
    if not cfg.habilitada:
        log.info("Alertas por correo desactivadas; el fallo queda solo en el log.")
        return

    registrar_secreto(cfg.smtp_password)
    equipo = socket.gethostname()

    msg = EmailMessage()
    msg["Subject"] = f"[Cartera Web] Fallo la actualizacion - {etapa}"
    msg["From"] = cfg.smtp_user or "robot-cartera@localhost"
    msg["To"] = ", ".join(cfg.destinatarios)
    msg.set_content(
        "El robot de actualizacion de cartera no pudo completar su ejecucion.\n\n"
        f"Equipo:  {equipo}\n"
        f"Etapa:   {etapa}\n"
        f"Error:   {error}\n\n"
        f"{detalle}\n\n"
        "Los asesores siguen viendo los datos de la ultima actualizacion "
        "correcta; la web les mostrara un aviso de que el dato no es de hoy.\n"
        "Revisa etl/logs/etl.log para el detalle completo."
    )

    try:
        with smtplib.SMTP(cfg.smtp_host, cfg.smtp_port, timeout=30) as smtp:
            # Sin context, smtplib no verifica el certificado ni el nombre del
            # servidor: un intermediario capturaria el usuario y la contrasena.
            smtp.starttls(context=ssl.create_default_context())
            if cfg.smtp_user:
                smtp.login(cfg.smtp_user, cfg.smtp_password)
            smtp.send_message(msg)
        log.info("Alerta enviada a: %s", ", ".join(cfg.destinatarios))
    except Exception:
        # Nunca dejar que el fallo del aviso oculte el fallo real.
        log.exception("No se pudo enviar la alerta por correo.")
