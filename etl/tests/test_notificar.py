"""Pruebas del aviso por correo: nunca se conecta a un SMTP real."""
from __future__ import annotations

import ssl
import unittest
from unittest import mock

import notificar
from config import AlertaConfig


def _alertas(usuario: str = "robot@example.com") -> AlertaConfig:
    return AlertaConfig(
        "smtp.example.com", 587, usuario, "clave-smtp", ["ti@example.com"]
    )


class AvisarFalloTest(unittest.TestCase):
    def test_starttls_verifica_el_certificado_antes_del_login(self):
        with mock.patch("smtplib.SMTP") as smtp_clase:
            smtp = smtp_clase.return_value.__enter__.return_value
            notificar.avisar_fallo(_alertas(), "HANA", "sin conexion")

        smtp.starttls.assert_called_once()
        contexto = smtp.starttls.call_args.kwargs.get("context")
        self.assertIsInstance(contexto, ssl.SSLContext)
        self.assertEqual(contexto.verify_mode, ssl.CERT_REQUIRED)
        self.assertTrue(contexto.check_hostname)

        pasos = [llamada[0] for llamada in smtp.method_calls]
        self.assertLess(pasos.index("starttls"), pasos.index("login"))
        smtp.send_message.assert_called_once()

    def test_un_certificado_invalido_queda_en_el_log_sin_propagarse(self):
        with mock.patch("smtplib.SMTP") as smtp_clase:
            smtp = smtp_clase.return_value.__enter__.return_value
            smtp.starttls.side_effect = ssl.SSLCertVerificationError(
                "certificate verify failed: self-signed certificate"
            )
            with self.assertLogs("cartera.alerta", level="ERROR") as registro:
                notificar.avisar_fallo(_alertas(), "HANA", "sin conexion")

        smtp.login.assert_not_called()
        smtp.send_message.assert_not_called()
        self.assertIn("No se pudo enviar la alerta", "\n".join(registro.output))

    def test_sin_smtp_no_intenta_conectar(self):
        with mock.patch("smtplib.SMTP") as smtp_clase:
            notificar.avisar_fallo(AlertaConfig("", 587, "", ""), "HANA", "x")
        smtp_clase.assert_not_called()


if __name__ == "__main__":
    unittest.main()
