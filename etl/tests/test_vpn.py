"""Pruebas del robot de la VPN con un sccli falso: nunca toca Sophos."""
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path
from unittest import mock

from config import VpnConfig
from vpn import RobotVPN, VpnCredenciales, VpnError

CFG = VpnConfig(Path("sccli.exe"), "vpn", "usuario", "clave-vpn", 1, True)


class SccliFalso:
    """Anota cada subcomando de sccli y responde con la salida dada."""

    def __init__(self, salida: str = "") -> None:
        self.comandos: list[str] = []
        self.salida = salida

    def __call__(self, *args, timeout=60):
        self.comandos.append(args[0])
        return subprocess.CompletedProcess([], 0, self.salida, "")


class VpnTest(unittest.TestCase):
    def _parches(self, sccli, conectado, tunel=True):
        pila = [
            mock.patch.object(RobotVPN, "_sccli", side_effect=sccli),
            mock.patch.object(RobotVPN, "esta_conectado", side_effect=conectado),
            mock.patch.object(RobotVPN, "conexion_existe", return_value=True),
            mock.patch.object(RobotVPN, "_esperar_tunel", return_value=tunel),
        ]
        for parche in pila:
            parche.start()
            self.addCleanup(parche.stop)

    def test_reconectar_baja_nuestro_tunel_y_lo_vuelve_a_levantar(self):
        sccli = SccliFalso()
        # Abajo despues del disable.
        self._parches(sccli, [False])
        robot = RobotVPN(CFG)
        robot._la_conectamos_nosotros = True
        robot.reconectar()
        self.assertEqual(sccli.comandos, ["disable", "enable"])
        self.assertTrue(robot._la_conectamos_nosotros)

    def test_reconectar_no_baja_un_tunel_que_no_abrimos(self):
        # El robot de cuentas por pagar comparte la VPN: su tunel no se toca.
        sccli = SccliFalso()
        self._parches(sccli, [True, True])
        robot = RobotVPN(CFG)
        robot.reconectar()
        self.assertEqual(sccli.comandos, [])
        self.assertFalse(robot._la_conectamos_nosotros)

    def test_reconectar_con_el_tunel_abajo_conecta_y_queda_nuestro(self):
        sccli = SccliFalso()
        self._parches(sccli, [False, False])
        robot = RobotVPN(CFG)
        robot.reconectar()
        self.assertEqual(sccli.comandos, ["enable"])
        self.assertTrue(robot._la_conectamos_nosotros)

    def test_credenciales_rechazadas_levantan_vpn_credenciales(self):
        self._parches(SccliFalso("authentication failed"), [False], tunel=False)
        with self.assertRaises(VpnCredenciales) as error:
            RobotVPN(CFG).conectar()
        self.assertIsInstance(error.exception, VpnError)
        self.assertIn("rechazo el usuario o la contrasena", str(error.exception))


if __name__ == "__main__":
    unittest.main()
