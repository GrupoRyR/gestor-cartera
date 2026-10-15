"""Carga y validacion de la configuracion del ETL (archivo .env)."""
from __future__ import annotations

import os
from dataclasses import dataclass, field
from pathlib import Path

from dotenv import load_dotenv

BASE_DIR = Path(__file__).resolve().parent
ENV_PATH = BASE_DIR / ".env"
QUERIES_DIR = BASE_DIR / "queries"
LOGS_DIR = BASE_DIR / "logs"
OUT_DIR = BASE_DIR / "out"
STATE_DIR = BASE_DIR / "state"


class ConfigError(RuntimeError):
    """Falta configuracion obligatoria o tiene un valor invalido."""


def _bool(valor: str | None, por_defecto: bool = False) -> bool:
    if valor is None or valor.strip() == "":
        return por_defecto
    return valor.strip().lower() in {"1", "true", "si", "sí", "yes", "y"}


def _int(valor: str | None, por_defecto: int) -> int:
    try:
        return int(str(valor).strip())
    except (TypeError, ValueError):
        return por_defecto


@dataclass(frozen=True)
class VpnConfig:
    cli: Path
    conexion: str
    usuario: str
    password: str
    timeout: int
    desconectar_al_final: bool


@dataclass(frozen=True)
class HanaConfig:
    host: str
    port: int
    usuario: str
    password: str
    esquema: str
    timeout: int


@dataclass(frozen=True)
class AlertaConfig:
    smtp_host: str
    smtp_port: int
    smtp_user: str
    smtp_password: str
    destinatarios: list[str] = field(default_factory=list)

    @property
    def habilitada(self) -> bool:
        return bool(self.smtp_host and self.destinatarios)


@dataclass(frozen=True)
class Config:
    vpn: VpnConfig
    hana: HanaConfig
    supabase_url: str
    alertas: AlertaConfig

    def validar(
        self, *, requiere_supabase: bool = True, requiere_esquema: bool = True
    ) -> None:
        """Lanza ConfigError con un mensaje accionable si falta algo."""
        faltantes: list[str] = []
        if not self.vpn.usuario:
            faltantes.append("SOPHOS_USER (usuario de la VPN Sophos)")
        if not self.vpn.password:
            faltantes.append("SOPHOS_PASSWORD (contrasena de la VPN Sophos)")
        if not self.hana.host:
            faltantes.append("HANA_HOST (IP o nombre del servidor SAP HANA)")
        if not self.hana.usuario:
            faltantes.append("HANA_USER")
        if not self.hana.password:
            faltantes.append("HANA_PASSWORD")
        if requiere_esquema and not self.hana.esquema:
            faltantes.append("HANA_SCHEMA (esquema de SAP B1, ej: SBO_SEGUREX)")
        if requiere_supabase and not self.supabase_url:
            faltantes.append("SUPABASE_DB_URL (cadena de conexion de Supabase)")

        if not self.vpn.cli.exists():
            faltantes.append(
                f"SOPHOS_CLI apunta a un archivo inexistente: {self.vpn.cli}"
            )

        if faltantes:
            detalle = "\n  - ".join(faltantes)
            raise ConfigError(
                f"Faltan datos obligatorios en {ENV_PATH}:\n  - {detalle}\n\n"
                "Edita ese archivo y vuelve a ejecutar."
            )


def cargar_config() -> Config:
    load_dotenv(ENV_PATH, override=True)
    for carpeta in (LOGS_DIR, OUT_DIR, STATE_DIR):
        carpeta.mkdir(parents=True, exist_ok=True)

    destinatarios = [
        c.strip()
        for c in os.getenv("ALERTA_DESTINATARIOS", "").split(",")
        if c.strip()
    ]

    return Config(
        vpn=VpnConfig(
            cli=Path(
                os.getenv(
                    "SOPHOS_CLI",
                    r"C:\Program Files (x86)\Sophos\Connect\sccli.exe",
                )
            ),
            conexion=os.getenv("SOPHOS_CONNECTION", "").strip(),
            usuario=os.getenv("SOPHOS_USER", "").strip(),
            password=os.getenv("SOPHOS_PASSWORD", ""),
            timeout=_int(os.getenv("SOPHOS_TIMEOUT"), 90),
            desconectar_al_final=_bool(
                os.getenv("SOPHOS_DISCONNECT_AL_FINAL"), True
            ),
        ),
        hana=HanaConfig(
            host=os.getenv("HANA_HOST", "").strip(),
            port=_int(os.getenv("HANA_PORT"), 30015),
            usuario=os.getenv("HANA_USER", "").strip(),
            password=os.getenv("HANA_PASSWORD", ""),
            esquema=os.getenv("HANA_SCHEMA", "").strip(),
            timeout=_int(os.getenv("HANA_TIMEOUT"), 600),
        ),
        supabase_url=os.getenv("SUPABASE_DB_URL", "").strip(),
        alertas=AlertaConfig(
            smtp_host=os.getenv("ALERTA_SMTP_HOST", "").strip(),
            smtp_port=_int(os.getenv("ALERTA_SMTP_PORT"), 587),
            smtp_user=os.getenv("ALERTA_SMTP_USER", "").strip(),
            smtp_password=os.getenv("ALERTA_SMTP_PASSWORD", ""),
            destinatarios=destinatarios,
        ),
    )


def leer_query(nombre: str) -> str:
    ruta = QUERIES_DIR / nombre
    if not ruta.exists():
        raise ConfigError(f"No existe la consulta {ruta}")
    return ruta.read_text(encoding="utf-8")
